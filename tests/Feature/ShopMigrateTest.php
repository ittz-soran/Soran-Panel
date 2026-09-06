<?php

namespace Tests\Feature;

use App\Contracts\ShopReader;
use App\Models\Action;
use App\Models\Customer;
use App\Models\HealthCheck;
use App\Models\User;
use App\Services\ShopMigrator;
use App\Support\ShopReading;
use Illuminate\Foundation\Testing\RefreshDatabase;
use RuntimeException;
use Tests\TestCase;

/**
 * Bringing one shop's database up to date — PANEL_DOC Section 7.
 *
 * **This is Section 3's other half.** One codebase, many shops means the code
 * is updated once; it also means every shop's database is behind until somebody
 * runs its migrations. `Updater` deliberately does not do it as a side effect of
 * a code update — "rather than quietly running `migrate` on other people's
 * databases" — and until this existed nothing did it at all: the Health screen
 * counted shops behind and the button beside them said "Not built yet".
 *
 * The rule being held here is Section 7's: **a backup first, and if the backup
 * fails nothing runs.**
 */
class ShopMigrateTest extends TestCase
{
    use RefreshDatabase;

    private string $root;

    /** What the fake reader answers, before and then after. */
    private array $readings = [];

    protected function setUp(): void
    {
        parent::setUp();

        $this->actingAs(User::factory()->create(['name' => 'Soran']));

        $this->root = sys_get_temp_dir().'/migrate-'.bin2hex(random_bytes(6));
        mkdir($this->root, 0755, true);

        $readings = &$this->readings;

        $this->swap(ShopReader::class, new class($readings) implements ShopReader
        {
            public function __construct(public array &$readings) {}

            public function read(Customer $customer): ShopReading
            {
                return array_shift($this->readings) ?? new ShopReading(reachable: true);
            }

            public function licenceState(Customer $customer): ?string
            {
                return 'valid';
            }
        });
    }

    protected function tearDown(): void
    {
        $this->rmrf($this->root ?? '');

        parent::tearDown();
    }

    /**
     * A shop as `shop:provision` leaves it: its own artisan, and a backups
     * folder its `backup:run` writes into — one level down, in `daily`, which
     * is where the real shop system puts it.
     */
    private function shop(): Customer
    {
        $home = $this->root.'/bazaar';

        mkdir($home.'/storage/app/backups/daily', 0755, true);

        file_put_contents($home.'/artisan', "<?php\n\$home = ".var_export($home, true).";\n".<<<'PHP'
        $command = $argv[1] ?? '';

        if ($command === 'backup:run') {
            if (getenv('DUMP_MUST_FAIL')) { fwrite(STDERR, 'mysqldump is not installed'); exit(1); }
            $path = $home.'/storage/app/backups/daily/backup-2026-09-06-070000.sql.gz';
            file_put_contents($path, str_repeat('INSERT;', 100));
            echo "Backing up…\n  ".$path."  (700 B)\n";
            exit(0);
        }

        if ($command === 'migrate') {
            if (getenv('MIGRATE_MUST_FAIL')) { fwrite(STDERR, 'migration 5 blew up'); exit(1); }
            file_put_contents($home.'/MIGRATED', '1');
            exit(0);
        }

        exit(0);
        PHP);

        chmod($home.'/artisan', 0755);

        return Customer::factory()->create([
            'name' => 'Bazaar',
            'host' => 'bazaar.soranstore.com',
            'shop_home' => $home,
            'public_path' => $this->root.'/public/bazaar',
        ]);
    }

    private function migrator(): ShopMigrator
    {
        return app(ShopMigrator::class);
    }

    /** Symfony builds a child's environment from $_ENV and $_SERVER, not getenv(). */
    private function withEnvironment(string $key, callable $body): void
    {
        putenv("{$key}=1");
        $_ENV[$key] = $_SERVER[$key] = '1';

        try {
            $body();
        } finally {
            putenv($key);
            unset($_ENV[$key], $_SERVER[$key]);
        }
    }

    private function behind(int $ran, int $total): ShopReading
    {
        return new ShopReading(reachable: true, migrationsRun: $ran, migrationsTotal: $total);
    }

    // ------------------------------------------------------------- it works

    public function test_it_runs_the_shops_own_migrate_and_says_how_many_ran(): void
    {
        $customer = $this->shop();

        $this->readings = [$this->behind(28, 32), $this->behind(32, 32)];

        $result = $this->migrator()->run($customer);

        $this->assertFileExists($customer->shop_home.'/MIGRATED', 'the shop’s migrate never ran');
        $this->assertSame(28, $result['was']);
        $this->assertSame(32, $result['now']);
        $this->assertStringContainsString('4 migrations ran', $result['said']);
    }

    /** Section 7: a backup first, and the operator is told where it went. */
    public function test_a_backup_is_taken_first_and_named_in_the_answer(): void
    {
        $customer = $this->shop();

        $this->readings = [$this->behind(28, 32), $this->behind(32, 32)];

        $result = $this->migrator()->run($customer);

        $this->assertFileExists($result['backup']);
        $this->assertStringContainsString('daily/backup-2026-09-06-070000.sql.gz', $result['backup']);
        $this->assertStringContainsString($result['backup'], $result['said']);
    }

    /**
     * The rule that matters. A migration cannot be undone, so a backup that
     * did not happen means the migration must not either.
     */
    public function test_a_backup_that_fails_means_the_migration_does_not_run(): void
    {
        $customer = $this->shop();

        $this->readings = [$this->behind(28, 32), $this->behind(28, 32)];

        $said = '';

        $this->withEnvironment('DUMP_MUST_FAIL', function () use ($customer, &$said) {
            try {
                $this->migrator()->run($customer);
                $this->fail('a shop was migrated without a backup');
            } catch (RuntimeException $e) {
                $said = $e->getMessage();
            }
        });

        $this->assertStringContainsString('nothing has been migrated', $said);
        $this->assertFileDoesNotExist($customer->shop_home.'/MIGRATED');
    }

    public function test_a_shop_with_no_artisan_is_refused_before_anything(): void
    {
        $customer = $this->shop();

        unlink($customer->shop_home.'/artisan');

        try {
            $this->migrator()->run($customer);
            $this->fail('a shop with no artisan was migrated');
        } catch (RuntimeException $e) {
            $this->assertStringContainsString('nothing to run its migrations with', $e->getMessage());
        }
    }

    // ------------------------------------------------------- when it goes wrong

    /**
     * Laravel stops at the migration that threw and keeps the ones before it,
     * so "it failed" is never "nothing happened". The row saying which shop,
     * when, and where its backup is, is what a restore is decided from — so it
     * is written before the failure is thrown, not after.
     */
    public function test_a_migration_that_stops_part_way_is_still_written_down(): void
    {
        $customer = $this->shop();

        $this->readings = [$this->behind(28, 32), $this->behind(30, 32)];

        $said = '';

        $this->withEnvironment('MIGRATE_MUST_FAIL', function () use ($customer, &$said) {
            try {
                $this->migrator()->run($customer);
                $this->fail('a failed migration was reported as a success');
            } catch (RuntimeException $e) {
                $said = $e->getMessage();
            }
        });

        $this->assertStringContainsString('stopped part-way', $said);
        $this->assertStringContainsString('2 migrations ran', $said);
        $this->assertStringContainsString('migration 5 blew up', $said);

        $action = Action::where('action', 'shop.migrated')->firstOrFail();

        $this->assertFalse($action->detail['ok']);
        $this->assertSame(30, $action->detail['now']);
        $this->assertStringContainsString('backup-2026-09-06-070000', $action->detail['backup']);
    }

    // ------------------------------------------------------------ afterwards

    /**
     * A button that fixes something and leaves the screen saying it is still
     * broken is one nobody trusts twice. The Health screen counts from the
     * newest check, so a fresh one is taken and kept.
     */
    public function test_a_fresh_reading_is_recorded_so_the_screens_stop_saying_behind(): void
    {
        $customer = $this->shop();

        $this->readings = [$this->behind(28, 32), $this->behind(32, 32)];

        $this->migrator()->run($customer);

        $check = HealthCheck::where('customer_id', $customer->id)->latest('checked_at')->firstOrFail();

        $this->assertSame(32, $check->migrations_run);
        $this->assertSame(0, $check->migrationsPending());
    }

    public function test_it_is_logged_with_who_ran_it(): void
    {
        $customer = $this->shop();

        $this->readings = [$this->behind(28, 32), $this->behind(32, 32)];

        $this->migrator()->run($customer);

        $action = Action::where('action', 'shop.migrated')->firstOrFail();

        $this->assertSame('Soran', $action->user->name);
        $this->assertSame('Bazaar', $action->customer->name);
        $this->assertSame(28, $action->detail['was']);
        $this->assertSame(32, $action->detail['now']);
        $this->assertTrue($action->detail['ok']);
    }

    // ------------------------------------------------------------- the screen

    public function test_the_button_says_how_many_are_pending(): void
    {
        $customer = $this->shop();

        HealthCheck::create([
            'customer_id' => $customer->id,
            'checked_at' => now(),
            'reachable' => true,
            'migrations_run' => 28,
            'migrations_total' => 32,
        ]);

        $this->get(route('customers.show', $customer))
            ->assertOk()
            ->assertSee('Run 4 migrations')
            ->assertSee('4 migrations pending')
            ->assertDontSee('Not built yet — build order step 7');
    }

    public function test_running_it_from_the_screen_works(): void
    {
        $customer = $this->shop();

        $this->readings = [$this->behind(28, 32), $this->behind(32, 32)];

        $this->post(route('customers.migrate', $customer))
            ->assertRedirect()
            ->assertSessionHas('success', fn (string $said) => str_contains($said, '4 migrations ran'));

        $this->assertFileExists($customer->shop_home.'/MIGRATED');
    }

    public function test_a_removed_shop_cannot_be_migrated(): void
    {
        $customer = $this->shop();
        $customer->delete();

        $this->post(route('customers.migrate', $customer))->assertNotFound();
    }

    public function test_it_is_behind_the_sign_in(): void
    {
        $customer = $this->shop();

        auth()->logout();

        $this->post(route('customers.migrate', $customer))->assertRedirect(route('login'));
    }

    private function rmrf(string $path): void
    {
        if ($path === '' || ! is_dir($path)) {
            return;
        }

        $entries = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($path, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST,
        );

        foreach ($entries as $entry) {
            $entry->isDir() && ! $entry->isLink() ? @rmdir($entry->getPathname()) : @unlink($entry->getPathname());
        }

        @rmdir($path);
    }
}
