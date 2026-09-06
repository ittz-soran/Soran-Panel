<?php

namespace Tests\Feature;

use App\Models\Action;
use App\Models\Customer;
use App\Models\User;
use App\Services\ShopControls;
use Illuminate\Foundation\Testing\RefreshDatabase;
use RuntimeException;
use Tests\TestCase;

/**
 * A shop's backup, on demand, and downloaded — PANEL_DOC Section 7.
 *
 * The panel already took one before migrating a shop and before removing one.
 * This is the same dump with no second half — the answer to "send me their
 * data", and to "I am about to do something by hand and want a copy first".
 *
 * ⚠️ **Downloading is the only copy that leaves the server.** A backup sitting
 * beside the database it came from survives a mistake and not a dead disk, so
 * the download is not a convenience — and it is why this route hands over a
 * whole customer's database, which is why it takes an action id and never a
 * path.
 */
class ShopBackupTest extends TestCase
{
    use RefreshDatabase;

    private string $root;

    protected function setUp(): void
    {
        parent::setUp();

        $this->actingAs(User::factory()->create(['name' => 'Soran']));

        $this->root = sys_get_temp_dir().'/shop-backup-'.bin2hex(random_bytes(6));
        mkdir($this->root, 0755, true);
    }

    protected function tearDown(): void
    {
        $this->rmrf($this->root ?? '');

        parent::tearDown();
    }

    /** A shop whose backup:run writes into `daily`, where the real one does. */
    private function shop(): Customer
    {
        $home = $this->root.'/bazaar';

        mkdir($home.'/storage/app/backups/daily', 0755, true);

        file_put_contents($home.'/artisan', "<?php\n\$home = ".var_export($home, true).";\n".<<<'PHP'
        if (($argv[1] ?? '') === 'backup:run') {
            if (getenv('DUMP_MUST_FAIL')) { fwrite(STDERR, 'mysqldump is not installed'); exit(1); }
            $path = $home.'/storage/app/backups/daily/backup-2026-09-06-090000.sql.gz';
            file_put_contents($path, str_repeat('INSERT;', 100));
            echo "Backing up…\n  ".$path."  (700 B)\n";
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

    // ------------------------------------------------------------ taking one

    public function test_it_takes_a_backup_through_the_shops_own_tooling_and_writes_it_down(): void
    {
        $customer = $this->shop();

        $result = app(ShopControls::class)->backUp($customer);

        $this->assertFileExists($result['path']);
        $this->assertSame(700, $result['bytes']);
        $this->assertStringContainsString('daily/backup-2026-09-06-090000.sql.gz', $result['path']);

        $action = Action::where('action', 'shop.backed_up')->firstOrFail();

        $this->assertSame('Soran', $action->user->name);
        $this->assertSame('Bazaar', $action->customer->name);
        $this->assertSame($result['path'], $action->detail['path']);
    }

    public function test_a_backup_that_fails_says_so_and_writes_no_row(): void
    {
        $customer = $this->shop();

        $this->withEnvironment('DUMP_MUST_FAIL', function () use ($customer) {
            try {
                app(ShopControls::class)->backUp($customer);
                $this->fail('a failed backup was reported as a success');
            } catch (RuntimeException $e) {
                $this->assertStringContainsString('no backup to hand you', $e->getMessage());
            }
        });

        $this->assertDatabaseMissing('actions', ['action' => 'shop.backed_up']);
    }

    public function test_the_button_works_and_says_where_it_went(): void
    {
        $customer = $this->shop();

        $this->post(route('customers.backup', $customer))
            ->assertRedirect()
            ->assertSessionHas('success', fn (string $said) => str_contains($said, 'is backed up')
                && str_contains($said, 'backup-2026-09-06-090000.sql.gz'));
    }

    // --------------------------------------------------------- downloading one

    public function test_a_backup_can_be_downloaded(): void
    {
        $customer = $this->shop();

        $result = app(ShopControls::class)->backUp($customer);

        $this->get(route('customers.backup.download', [$customer, $result['action']]))
            ->assertOk()
            ->assertDownload(basename($result['path']));
    }

    /**
     * ⚠️ The action id, never a path.
     *
     * This route sends a whole customer's database to whoever asks. Nothing the
     * operator types may reach the filesystem, so the row is looked up, it has
     * to belong to THIS customer, and the file is whatever that row says the
     * panel wrote.
     */
    public function test_it_will_not_hand_over_another_customers_backup(): void
    {
        $customer = $this->shop();
        $result = app(ShopControls::class)->backUp($customer);

        $somebodyElse = Customer::factory()->create(['host' => 'other.soranstore.com']);

        $this->get(route('customers.backup.download', [$somebodyElse, $result['action']]))
            ->assertNotFound();
    }

    public function test_an_action_that_names_no_file_is_not_a_download(): void
    {
        $customer = $this->shop();

        $suspended = Action::record('shop.suspended', $customer, ['why' => 'late']);

        $this->get(route('customers.backup.download', [$customer, $suspended]))->assertNotFound();
    }

    /** Their own retention prunes backups, so an old row names a file that has gone. */
    public function test_a_backup_that_has_since_been_pruned_is_a_404_and_not_a_crash(): void
    {
        $customer = $this->shop();
        $result = app(ShopControls::class)->backUp($customer);

        unlink($result['path']);

        $this->get(route('customers.backup.download', [$customer, $result['action']]))->assertNotFound();
    }

    public function test_downloading_is_behind_the_sign_in(): void
    {
        $customer = $this->shop();
        $result = app(ShopControls::class)->backUp($customer);

        auth()->logout();

        $this->get(route('customers.backup.download', [$customer, $result['action']]))
            ->assertRedirect(route('login'));
    }

    // ------------------------------------------------------------ on the page

    public function test_the_page_lists_what_can_be_downloaded(): void
    {
        $customer = $this->shop();

        app(ShopControls::class)->backUp($customer);

        $this->get(route('customers.show', $customer))
            ->assertOk()
            ->assertSee('Back up now')
            ->assertSee('backup-2026-09-06-090000.sql.gz')
            ->assertSee('Download')
            ->assertDontSee('Not built yet');
    }

    /** A migration's backup is the copy from the minute before the schema changed. */
    public function test_a_migrations_backup_is_offered_too_and_says_what_it_was_for(): void
    {
        $customer = $this->shop();

        $path = $customer->shop_home.'/storage/app/backups/daily/before-migrating.sql.gz';
        file_put_contents($path, 'x');

        Action::record('shop.migrated', $customer, ['backup' => $path, 'was' => 28, 'now' => 32]);

        $this->get(route('customers.show', $customer))
            ->assertOk()
            ->assertSee('before migrating')
            ->assertSee('before-migrating.sql.gz');
    }

    /**
     * A removed shop's final dump is the only thing left holding their data,
     * and it can never be taken again — so its page has to reach it.
     */
    public function test_a_removed_shops_last_backup_is_still_downloadable(): void
    {
        $customer = $this->shop();

        $kept = $this->root.'/removed-shops/bazaar.sql.gz';
        mkdir(dirname($kept), 0750, true);
        file_put_contents($kept, str_repeat('X', 42));

        $action = Action::record('shop.removed', $customer, ['backup' => $kept, 'left' => []]);
        $customer->delete();

        $this->get(route('customers.show', $customer))
            ->assertOk()
            ->assertSee('Download the backup it was dumped to');

        $this->get(route('customers.backup.download', [$customer, $action]))
            ->assertOk()
            ->assertDownload('bazaar.sql.gz');
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
