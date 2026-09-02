<?php

namespace Tests\Feature;

use App\Contracts\DatabaseMaker;
use App\Contracts\ShopReader;
use App\Models\Action;
use App\Models\Customer;
use App\Models\User;
use App\Services\ShopProvisioner;
use App\Support\ShopReading;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use RuntimeException;
use Tests\TestCase;

/**
 * Taking on a shop whose database already exists — build order step 10.
 *
 * This is Halabja-phone. PANEL_DOC Section 4 records its install folder being
 * deleted after it was found serving its own `.env` to anyone, and Section 13
 * records the decision that followed: "its database must be kept", "because
 * that is what a rebuilt install restores from".
 *
 * So every test here is really the same test asked differently — **does their
 * data survive?** A real MariaDB database stands in for theirs, with rows in
 * it, and after every failure path this file checks the rows are still there.
 */
class TakeOnShopTest extends TestCase
{
    use RefreshDatabase;

    private string $root;

    private string $theirDatabase;

    /** Whether the stand-in database really got made, so tearDown knows. */
    private bool $theirDatabaseExists = false;

    protected function setUp(): void
    {
        parent::setUp();

        if (DB::connection()->getDriverName() !== 'mysql') {
            $this->markTestSkipped('Taking on a shop reads a real MySQL database; this run is on SQLite.');
        }

        $this->actingAs(User::factory()->create(['name' => 'Soran']));

        $this->root = sys_get_temp_dir().'/take-on-'.bin2hex(random_bytes(6));
        mkdir($this->root.'/shops', 0755, true);
        mkdir($this->root.'/public_html', 0755, true);

        config([
            'panel.shops.home_root' => $this->root.'/shops',
            'panel.shops.public_root' => $this->root.'/public_html',
            'panel.shops.shared_artisan' => $this->fakeSharedArtisan(),
        ]);

        $this->swap(DatabaseMaker::class, new class implements DatabaseMaker
        {
            public function realName(string $wanted): string
            {
                return $wanted;
            }

            public function create(string $database, string $user, string $password): void
            {
                throw new RuntimeException('Taking on a shop must never create a database.');
            }

            public function drop(string $database, string $user): array
            {
                throw new RuntimeException('Taking on a shop must never drop a database.');
            }
        });

        $this->swap(ShopReader::class, new class implements ShopReader
        {
            public function read(Customer $customer): ShopReading
            {
                return new ShopReading(reachable: true, licenceState: 'missing');
            }

            public function licenceState(Customer $customer): ?string
            {
                return 'missing';
            }
        });

        $this->theirDatabase = 'takeon_'.bin2hex(random_bytes(4));
        $this->makeTheirShopDatabase();
    }

    protected function tearDown(): void
    {
        if ($this->theirDatabaseExists) {
            DB::statement("drop database if exists `{$this->theirDatabase}`");
        }

        $this->rmrf($this->root ?? '');

        parent::tearDown();
    }

    /** A database shaped like a shop that has been trading for years. */
    private function makeTheirShopDatabase(array $options = []): void
    {
        try {
            DB::statement("create database `{$this->theirDatabase}` character set utf8mb4 collate utf8mb4_unicode_ci");
        } catch (\Throwable $e) {
            /*
             * Standing in for Halabja's database means making one, and PANEL_DOC
             * Section 4 measured that a cPanel account is denied plain CREATE
             * DATABASE — which is the whole reason the panel goes through UAPI.
             * A connection user without the right is a normal way to run this
             * suite, so say so once rather than reporting seventeen errors that
             * look like the panel is broken.
             */
            $this->markTestSkipped(
                'This test builds a stand-in for a customer\'s database, and ['
                .DB::connection()->getConfig('username').'] may not CREATE DATABASE: '.$e->getMessage(),
            );
        }

        DB::statement("use `{$this->theirDatabase}`");

        DB::statement('create table users (id int primary key auto_increment, name varchar(255), two_factor_secret text null)');
        DB::statement('create table products (id int primary key auto_increment, name varchar(255))');
        DB::statement('create table sales (id int primary key auto_increment, total int)');
        DB::statement('create table settings (id int primary key auto_increment, k varchar(255))');
        DB::statement('create table migrations (id int primary key auto_increment, migration varchar(255), batch int)');

        DB::statement("insert into users (name, two_factor_secret) values ('Halabja Admin', ?)",
            [$options['authenticator'] ?? null]);
        DB::statement("insert into products (name) values ('A phone'), ('A case')");
        DB::statement('insert into sales (total) values (25000), (40000), (15000)');
        DB::statement("insert into settings (k) values ('shop_name')");
        DB::statement("insert into migrations (migration, batch) values ('0001_01_01_000000_create_users_table', 1)");

        DB::statement('use '.DB::connection()->getConfig('database'));

        $this->theirDatabaseExists = true;
    }

    /** What is still in their database now. */
    private function theirRows(string $table): int
    {
        $rows = DB::select("select count(*) as n from `{$this->theirDatabase}`.`{$table}`");

        return (int) $rows[0]->n;
    }

    private function fakeSharedArtisan(): string
    {
        $path = $this->root.'/artisan';

        /*
         * `shop:provision` COPIES this file into the shop's folder, and the
         * copy is what every later command runs. So a marker written relative
         * to __FILE__ lands somewhere different depending on which copy wrote
         * it — the shared one or the shop's — and the assertions here would
         * pass by looking in an empty place. The root is baked in instead, so
         * both copies write to the one directory the test watches.
         */
        file_put_contents($path, "<?php\n\$markers = ".var_export($this->root, true).";\n".<<<'PHP'
        $command = $argv[1] ?? '';
        $options = [];
        foreach (array_slice($argv, 2) as $argument) {
            if (str_starts_with($argument, '--') && str_contains($argument, '=')) {
                [$name, $value] = explode('=', substr($argument, 2), 2);
                $options[$name] = $value;
            }
        }

        if ($command === 'shop:provision') {
            foreach ([$options['home'], $options['home'].'/storage', $options['public']] as $directory) {
                mkdir($directory, 0755, true);
            }

            file_put_contents($options['home'].'/.env', implode("\n", [
                'APP_NAME="'.$options['shop-name'].'"',
                'APP_KEY=base64:'.base64_encode(random_bytes(32)),
                'DB_DATABASE='.$options['db-database'],
                'DB_USERNAME='.$options['db-username'],
                'DB_PASSWORD='.$options['db-password'],
                'LICENCE_KEY=',
            ])."\n");

            copy(__FILE__, $options['home'].'/artisan');
            exit(0);
        }

        if ($command === 'backup:check') { exit(getenv('BACKUPS_BROKEN') ? 1 : 0); }
        if ($command === 'backup:run')   {
            if (getenv('DUMP_MUST_FAIL')) { fwrite(STDERR, 'the dump failed'); exit(1); }
            file_put_contents($markers.'/backup-was-taken', '1');
            echo "Backed up.\n"; exit(0);
        }
        if ($command === 'migrate') {
            if (getenv('MIGRATE_MUST_FAIL')) { fwrite(STDERR, 'migration blew up'); exit(1); }
            file_put_contents($markers.'/migrate-was-run', '1');
            exit(0);
        }
        if ($command === 'migrate:status') {
            $ran = file_exists($markers.'/migrate-was-run') ? 4 : 1;
            foreach (range(1, $ran) as $i) { echo " migration_{$i} .. [1] Ran \n"; }
            exit(0);
        }
        if ($command === 'db:seed') {
            file_put_contents($markers.'/SEED-WAS-RUN', 'this must never happen');
            exit(0);
        }

        exit(0);
        PHP);

        chmod($path, 0755);

        return $path;
    }

    /** @return array<string, mixed> */
    private function wanted(array $extra = []): array
    {
        return [
            'name' => 'Halabja Phone',
            'short_name' => 'halabja',
            'host' => 'halabja.soranstore.com',
            'contact_name' => 'Rebin',
            'phone' => '07701234567',
            'email' => null,
            'monthly_fee' => 75000,
            'storage_limit_mb' => 2048,
            'database' => $this->theirDatabase,
            'database_user' => config('database.connections.mysql.username'),
            'database_password' => config('database.connections.mysql.password'),
            'app_key' => null,
            'backup' => true,
            'trial' => false,
            'licence' => null,
            'notes' => null,
            ...$extra,
        ];
    }

    private function takeOn(array $extra = []): array
    {
        return app(ShopProvisioner::class)->takeOn($this->wanted($extra));
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

    // ---- It works ---------------------------------------------------------

    public function test_it_builds_a_shop_around_a_database_that_already_exists(): void
    {
        $made = $this->takeOn();

        $customer = $made['customer'];

        $this->assertSame('Halabja Phone', $customer->name);
        $this->assertSame($this->theirDatabase, $customer->database_name);
        $this->assertDirectoryExists($customer->shop_home);
        $this->assertFileExists($customer->shop_home.'/.env');

        // Their data, untouched and counted.
        $this->assertSame(1, $made['found']['users']);
        $this->assertSame(2, $made['found']['products']);
        $this->assertSame(3, $made['found']['sales']);
        $this->assertSame(3, $this->theirRows('sales'));
    }

    /**
     * Seeding a live database adds a second administrator, rewrites the
     * settings and resets the document counters.
     */
    public function test_it_never_seeds(): void
    {
        $this->takeOn();

        $this->assertFileDoesNotExist($this->root.'/SEED-WAS-RUN', 'db:seed was run on a real customer’s data');
    }

    /** Section 7: a backup before anything irreversible. */
    public function test_it_backs_their_database_up_before_migrating(): void
    {
        $this->takeOn();

        $this->assertFileExists($this->root.'/backup-was-taken');
        $this->assertFileExists($this->root.'/migrate-was-run');
    }

    public function test_it_brings_their_schema_up_to_date_and_says_how_far(): void
    {
        $made = $this->takeOn();

        $this->assertSame(3, $made['migrations_run']);
    }

    /**
     * They were trading long before the panel existed. Dating "started" today
     * would forgive every unpaid month on the Subscriptions screen.
     */
    public function test_it_does_not_pretend_they_started_today(): void
    {
        $this->assertNull($this->takeOn()['customer']->started_on);
    }

    public function test_taking_a_shop_on_is_logged(): void
    {
        $this->takeOn();

        $logged = Action::where('action', 'customer.taken_on')->first();

        $this->assertNotNull($logged);
        $this->assertSame(auth()->id(), $logged->user_id);
        $this->assertSame($this->theirDatabase, $logged->detail['database']);
        $this->assertTrue($logged->detail['backed_up']);
    }

    // ---- It refuses the wrong database ------------------------------------

    public function test_a_database_that_is_not_a_shop_is_refused(): void
    {
        DB::statement("drop table `{$this->theirDatabase}`.`sales`");

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('not a shop system database');

        $this->takeOn();
    }

    /**
     * An empty database is a NEW customer, and this flow never seeds — sending
     * one down it would leave a shop nobody can sign in to.
     */
    public function test_a_database_with_no_users_is_sent_to_new_customer_instead(): void
    {
        DB::statement("delete from `{$this->theirDatabase}`.`users`");

        try {
            $this->takeOn();
            $this->fail('an empty database was taken on');
        } catch (RuntimeException $e) {
            $this->assertStringContainsString('use New customer instead', $e->getMessage());
        }
    }

    public function test_credentials_that_do_not_work_are_refused_before_anything_is_made(): void
    {
        try {
            $this->takeOn(['database_password' => 'not-the-password']);
            $this->fail('bad credentials were accepted');
        } catch (RuntimeException $e) {
            $this->assertStringContainsString('Could not read', $e->getMessage());
        }

        $this->assertDirectoryDoesNotExist($this->root.'/shops/halabja');
        $this->assertSame(0, Customer::count());
    }

    // ---- The APP_KEY trap --------------------------------------------------

    /**
     * The shop system encrypts two_factor_secret with APP_KEY, and
     * `shop:provision` writes a fresh one. Halabja's original died with the
     * install folder Section 4 had deleted — so a new key would leave every
     * staff authenticator as ciphertext nothing can read.
     */
    public function test_it_refuses_a_fresh_key_that_would_lock_their_staff_out(): void
    {
        DB::statement("update `{$this->theirDatabase}`.`users` set two_factor_secret = 'ciphertext'");

        try {
            $this->takeOn();
            $this->fail('a fresh APP_KEY was written over their encrypted secrets');
        } catch (RuntimeException $e) {
            $this->assertStringContainsString('ORIGINAL APP_KEY', $e->getMessage());
        }

        $this->assertSame(0, Customer::count());
        $this->assertSame(1, $this->theirRows('users'));
    }

    public function test_their_original_key_is_put_back_when_it_is_given(): void
    {
        DB::statement("update `{$this->theirDatabase}`.`users` set two_factor_secret = 'ciphertext'");

        $theirs = 'base64:'.base64_encode(random_bytes(32));

        $made = $this->takeOn(['app_key' => $theirs]);

        $this->assertStringContainsString(
            'APP_KEY='.$theirs,
            file_get_contents($made['customer']->shop_home.'/.env'),
            'their own key was not written back, so their authenticators are unreadable',
        );
    }

    /** No authenticators, nothing to lose — a fresh key is fine. */
    public function test_a_shop_with_no_authenticators_needs_no_key(): void
    {
        $this->assertSame(0, $this->takeOn()['found']['authenticators']);
    }

    // ---- Their data survives every failure --------------------------------

    /** The whole point. A rollback here must never take the database with it. */
    public function test_a_failed_migration_leaves_their_database_alone(): void
    {
        $this->withEnvironment('MIGRATE_MUST_FAIL', function () {
            try {
                $this->takeOn();
                $this->fail('a failed migration was not reported');
            } catch (RuntimeException $e) {
                $this->assertStringContainsString('has not been touched', $e->getMessage());
            }
        });

        $this->assertSame(1, $this->theirRows('users'));
        $this->assertSame(2, $this->theirRows('products'));
        $this->assertSame(3, $this->theirRows('sales'));

        // And the half-made folder is gone.
        $this->assertDirectoryDoesNotExist($this->root.'/shops/halabja');
        $this->assertSame(0, Customer::count());
    }

    /** No backup, no migration. */
    public function test_it_will_not_migrate_when_their_backups_are_broken(): void
    {
        $this->withEnvironment('BACKUPS_BROKEN', function () {
            try {
                $this->takeOn();
                $this->fail('it migrated without a working backup');
            } catch (RuntimeException $e) {
                $this->assertStringContainsString('backups are not working', $e->getMessage());
            }
        });

        $this->assertFileDoesNotExist($this->root.'/migrate-was-run');
        $this->assertSame(3, $this->theirRows('sales'));
    }

    /**
     * ⚠️ Not `BACKUP_MUST_FAIL`. `ShopEnvironment::withoutThePanel()` strips
     * every variable starting `BACKUP_` from a shop's process — deliberately,
     * so the panel's own backup settings can never point a shop's `backup:run`
     * at the panel's destination. Named that way this test set a variable the
     * child never saw, so the backup "succeeded", nothing threw, and the test
     * failed while the code under it was fine.
     */
    public function test_a_backup_that_fails_stops_everything(): void
    {
        $this->withEnvironment('DUMP_MUST_FAIL', function () {
            try {
                $this->takeOn();
                $this->fail('a failed backup did not stop the migration');
            } catch (RuntimeException $e) {
                $this->assertStringContainsString('has not been touched', $e->getMessage());
            }
        });

        $this->assertFileDoesNotExist($this->root.'/migrate-was-run');
        $this->assertSame(3, $this->theirRows('sales'));
    }

    /** Skipping it is allowed, said out loud, and recorded. */
    public function test_the_backup_can_be_skipped_and_it_is_said_so(): void
    {
        $made = $this->takeOn(['backup' => false]);

        $this->assertFileDoesNotExist($this->root.'/backup-was-taken');
        $this->assertStringContainsString('No backup was taken', implode(' ', $made['warnings']));
        $this->assertFalse(Action::where('action', 'customer.taken_on')->first()->detail['backed_up']);
    }

    /** A host already sold is refused before their database is even opened. */
    public function test_a_host_already_taken_is_refused(): void
    {
        Customer::factory()->create(['host' => 'halabja.soranstore.com']);

        $this->expectException(RuntimeException::class);

        $this->takeOn();
    }

    private function rmrf(string $path): void
    {
        if ($path === '' || ! is_dir($path)) {
            return;
        }

        foreach (array_diff((array) scandir($path), ['.', '..']) as $entry) {
            is_dir($path.'/'.$entry) && ! is_link($path.'/'.$entry)
                ? $this->rmrf($path.'/'.$entry)
                : @unlink($path.'/'.$entry);
        }

        @rmdir($path);
    }
}
