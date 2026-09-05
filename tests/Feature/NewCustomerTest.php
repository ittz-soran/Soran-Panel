<?php

namespace Tests\Feature;

use App\Contracts\DatabaseMaker;
use App\Contracts\DnsMaker;
use App\Contracts\DomainMaker;
use App\Contracts\ShopReader;
use App\Models\Action;
use App\Models\Customer;
use App\Models\User;
use App\Support\ShopReading;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\TestResponse;
use RuntimeException;
use Tests\TestCase;

/**
 * A new customer from nothing — PANEL_DOC Section 7, build order step 7.
 *
 * Real folders on disk, and a stand-in for the shared codebase's `artisan` that
 * behaves the way the real one does: it makes a folder, and `db:seed` prints an
 * administrator. The database maker is a fake, because the real ones are cPanel
 * UAPI (which exists only on the server) and live MySQL.
 *
 * Most of this file is about Section 7's four words: **"Rolls back what it made
 * on failure."** A half-made shop is worse than no shop — a database with
 * nobody's data in it counts against the account's database limit, which
 * Section 13 records as the only real ceiling left on how many customers fit
 * here, and a folder that looks provisioned is one somebody later points a
 * domain at.
 */
class NewCustomerTest extends TestCase
{
    use RefreshDatabase;

    private string $root;

    /** @var list<array{string, string, string}> */
    public static array $created = [];

    /** @var list<array{string, string}> host and document root, as the panel gave them */
    public static array $pointed = [];

    /** @var list<string> hosts taken off again by a rollback */
    public static array $unpointed = [];

    /** @var list<string> hosts a certificate was asked for */
    public static array $secured = [];

    /** @var list<array{string, string}> host and address, as published */
    public static array $published = [];

    /** @var list<string> published names withdrawn by a rollback */
    public static array $unpublished = [];

    /** @var list<array{string, string}> */
    public static array $dropped = [];

    protected function setUp(): void
    {
        parent::setUp();

        $this->actingAs(User::factory()->create(['name' => 'Soran']));

        $this->root = sys_get_temp_dir().'/panel-new-'.bin2hex(random_bytes(6));
        mkdir($this->root.'/shops', 0755, true);
        mkdir($this->root.'/public_html', 0755, true);

        self::$created = [];
        self::$pointed = [];
        self::$unpointed = [];
        self::$secured = [];
        self::$published = [];
        self::$unpublished = [];
        self::$dropped = [];

        config([
            'panel.shops.home_root' => $this->root.'/shops',
            'panel.shops.public_root' => $this->root.'/public_html',
            'panel.shops.shared_artisan' => $this->fakeSharedArtisan(),
        ]);

        $this->swap(DatabaseMaker::class, new class implements DatabaseMaker
        {
            public function realName(string $wanted): string
            {
                return 'soransto_'.$wanted;
            }

            public function create(string $database, string $user, string $password): void
            {
                if (str_contains($database, 'refuse')) {
                    throw new RuntimeException('cPanel refused Mysql::create_database — that name is taken.');
                }

                NewCustomerTest::$created[] = [$database, $user, $password];
            }

            public function drop(string $database, string $user): array
            {
                NewCustomerTest::$dropped[] = [$database, $user];

                return [];
            }
        });

        $this->swap(DomainMaker::class, new class implements DomainMaker
        {
            public function create(string $host, string $documentRoot): void
            {
                NewCustomerTest::$pointed[] = [$host, $documentRoot];
            }

            public function remove(string $host): array
            {
                NewCustomerTest::$unpointed[] = $host;

                return [];
            }

            public function secure(string $host): ?string
            {
                NewCustomerTest::$secured[] = $host;

                return null;
            }

            public function describe(): string
            {
                return 'a spy';
            }

            public function isAutomatic(): bool
            {
                return true;
            }
        });

        config(['panel.dns.address' => '192.0.2.7']);

        $this->swap(DnsMaker::class, new class implements DnsMaker
        {
            public function create(string $host, string $address): void
            {
                NewCustomerTest::$published[] = [$host, $address];
            }

            public function remove(string $host): array
            {
                NewCustomerTest::$unpublished[] = $host;

                return [];
            }

            public function describe(): string
            {
                return 'a spy';
            }

            public function isAutomatic(): bool
            {
                return true;
            }
        });

        $this->swap(ShopReader::class, new class implements ShopReader
        {
            public function read(Customer $customer): ShopReading
            {
                return new ShopReading(reachable: true, licenceState: 'unlicensed');
            }

            public function licenceState(Customer $customer): ?string
            {
                return 'unlicensed';
            }
        });
    }

    protected function tearDown(): void
    {
        $this->rmrf($this->root);

        parent::tearDown();
    }

    /**
     * Stands in for the shop system's artisan: `shop:provision` makes the
     * folders and a per-shop artisan, and that per-shop artisan answers
     * `migrate` and `db:seed` the way the real seeder does.
     */
    private function fakeSharedArtisan(): string
    {
        $path = $this->root.'/artisan';

        file_put_contents($path, <<<'PHP'
        <?php
        $command = $argv[1] ?? '';
        $options = [];
        foreach (array_slice($argv, 2) as $argument) {
            if (str_starts_with($argument, '--') && str_contains($argument, '=')) {
                [$name, $value] = explode('=', substr($argument, 2), 2);
                $options[$name] = $value;
            }
        }

        if ($command === 'shop:provision') {
            if (getenv('PROVISION_MUST_FAIL')) {
                fwrite(STDERR, 'shop:provision fell over making the public folder.');
                exit(1);
            }

            foreach ([$options['home'], $options['home'].'/storage', $options['public']] as $directory) {
                mkdir($directory, 0755, true);
            }

            file_put_contents($options['home'].'/.env', implode("\n", [
                'APP_NAME="'.$options['shop-name'].'"',
                'APP_KEY=base64:'.base64_encode(random_bytes(32)),
                'DB_DATABASE='.$options['db-database'],
                'DB_USERNAME='.$options['db-username'],
                'DB_PASSWORD='.$options['db-password'],
                'STORAGE_LIMIT_MB='.$options['storage-limit'],
                in_array('--trial', $argv, true) ? 'LICENCE_PUBLIC_KEY=' : '',
                'LICENCE_KEY=',
            ])."\n");

            // Its own entry point, exactly as the real one gives each shop.
            copy(__FILE__, $options['home'].'/artisan');
            exit(0);
        }

        if ($command === 'migrate') {
            exit(getenv('MIGRATE_MUST_FAIL') ? 1 : 0);
        }

        if ($command === 'db:seed') {
            if (getenv('SEED_SAYS_NOTHING')) { echo "Done.\n"; exit(0); }
            echo " Administrator account: admin@example.com\n";
            echo " Password: 7cD9vE7l1RQDctRC\n";
            exit(0);
        }

        exit(0);
        PHP);

        chmod($path, 0755);

        return $path;
    }

    /**
     * Set a variable the stand-in shop reads, and put it back afterwards.
     *
     * putenv() alone is not enough: Symfony builds a child's environment from
     * $_ENV and $_SERVER, so a putenv-only value never reaches the process —
     * which is the same asymmetry that made the panel's own environment leak
     * into shops in step 4, seen from the other side.
     */
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

    private function make(array $fields = []): TestResponse
    {
        return $this->post(route('customers.store'), [
            'name' => 'Hawler Computer',
            'short_name' => 'hawler',
            'host' => 'hawler.soranstore.com',
            'contact_name' => 'Karwan',
            'phone' => '07501234567',
            'monthly_fee' => 50000,
            'storage_limit_mb' => 2048,
            'start' => 'trial',
            ...$fields,
        ]);
    }

    // ---- The happy path ---------------------------------------------------

    public function test_it_makes_a_shop_from_nothing(): void
    {
        $this->make()->assertSessionHas('success');

        $customer = Customer::firstOrFail();

        $this->assertSame('Hawler Computer', $customer->name);
        $this->assertSame('hawler.soranstore.com', $customer->host);
        $this->assertSame(Customer::TRIAL, $customer->status);
        $this->assertDirectoryExists($customer->shop_home);
        $this->assertDirectoryExists($customer->public_path);
        $this->assertFileExists($customer->shop_home.'/.env');
        $this->assertFileExists($customer->shop_home.'/artisan');
    }

    /**
     * cPanel prefixes every database and user with the account name. The
     * customer row must record the REAL one, or the panel cannot read that
     * shop again.
     */
    public function test_the_customer_records_the_name_the_host_really_gave_it(): void
    {
        $this->make();

        $customer = Customer::firstOrFail();

        $this->assertSame('soransto_hawler_shop', $customer->database_name);
        $this->assertSame('soransto_hawler_user', $customer->database_user);
        $this->assertSame('soransto_hawler_shop', self::$created[0][0]);
    }

    /** The shop connects with what was actually created, not a guess. */
    public function test_the_shop_env_carries_the_database_it_was_given(): void
    {
        $this->make();

        $env = file_get_contents(Customer::firstOrFail()->shop_home.'/.env');

        $this->assertStringContainsString('DB_DATABASE=soransto_hawler_shop', $env);
        $this->assertStringContainsString('DB_PASSWORD='.self::$created[0][2], $env);
    }

    /**
     * PANEL_DOC Section 6: a trial is what blanks LICENCE_PUBLIC_KEY in the
     * shop's own .env, and that is the only thing that makes it run unlicensed
     * rather than read-only from its first minute.
     */
    public function test_a_trial_shop_gets_its_public_key_blanked(): void
    {
        $this->make(['start' => 'trial']);

        $this->assertStringContainsString('LICENCE_PUBLIC_KEY=', file_get_contents(Customer::firstOrFail()->shop_home.'/.env'));
    }

    /** Shown once, and never stored anywhere. */
    public function test_the_administrator_password_is_handed_over_once(): void
    {
        $this->make()->assertSessionHas('made');

        $made = session('made');

        $this->assertSame('admin@example.com', $made['email']);
        $this->assertSame('7cD9vE7l1RQDctRC', $made['password']);
    }

    /** A log that carries a password hands over every shop it describes. */
    public function test_the_password_is_never_written_to_the_log(): void
    {
        $this->make();

        $this->assertStringNotContainsString(
            '7cD9vE7l1RQDctRC',
            json_encode(Action::all()->pluck('detail')->all()),
        );
    }

    public function test_creating_a_customer_is_logged_with_who_did_it(): void
    {
        $this->make();

        $logged = Action::where('action', 'customer.created')->first();

        $this->assertSame(auth()->id(), $logged->user_id);
        $this->assertSame('hawler.soranstore.com', $logged->detail['host']);
        $this->assertTrue($logged->detail['trial']);
    }

    // ---- Refusing before it starts ----------------------------------------

    public function test_a_domain_already_sold_is_refused(): void
    {
        Customer::factory()->create(['host' => 'hawler.soranstore.com']);

        $this->make()->assertSessionHasErrors('host');

        $this->assertSame([], self::$created, 'nothing should have been created');
    }

    public function test_a_short_name_that_is_not_a_folder_name_is_refused(): void
    {
        foreach (['Hawler', 'hawler shop', '9hawler', 'hawler-shop', ''] as $bad) {
            $this->make(['short_name' => $bad])->assertSessionHasErrors('short_name');
        }

        $this->assertSame(0, Customer::count());
    }

    /** Never over the top of a folder that is already there. */
    public function test_a_shop_folder_that_already_exists_is_refused(): void
    {
        mkdir($this->root.'/shops/hawler', 0755, true);

        $this->make()->assertSessionHas('warning');

        $this->assertSame(0, Customer::count());
        $this->assertSame([], self::$created, 'the database was made before the folder was checked');
    }

    /**
     * ⚠️ The deadlock, and the reason this is three tests rather than one.
     *
     * Creating a subdomain in cPanel CREATES its document root, and that step
     * has to come first — the domain must point somewhere before a shop is
     * built for it. The first version refused any public folder that existed,
     * which meant: make the subdomain first and the panel refuses, make the
     * customer first and cPanel finds the folder taken. Neither order worked
     * and the flow was unreachable on a real account. It could not have been
     * found here, because nothing in a test creates a document root the way
     * cPanel does.
     */
    public function test_the_empty_document_root_cpanel_makes_is_written_into(): void
    {
        mkdir($this->root.'/public_html/hawler', 0755, true);

        $this->make()->assertSessionHas('success');

        $this->assertSame(1, Customer::count());
        $this->assertFileExists($this->root.'/shops/hawler/.env');
    }

    /** cPanel's own leftovers are not content, and neither is Let's Encrypt's. */
    public function test_cgi_bin_and_well_known_do_not_count_as_something_being_there(): void
    {
        mkdir($this->root.'/public_html/hawler/cgi-bin', 0755, true);
        mkdir($this->root.'/public_html/hawler/.well-known', 0755, true);

        $this->make()->assertSessionHas('success');

        $this->assertSame(1, Customer::count());
    }

    /** But a folder with somebody's site in it is still somebody's site. */
    public function test_a_public_folder_with_anything_in_it_is_still_refused(): void
    {
        mkdir($this->root.'/public_html/hawler', 0755, true);
        file_put_contents($this->root.'/public_html/hawler/index.html', 'somebody’s site');

        $this->make()->assertSessionHas('warning');

        $this->assertSame(0, Customer::count());
        $this->assertSame([], self::$created, 'the database was made before the folder was checked');
        $this->assertFileExists($this->root.'/public_html/hawler/index.html', 'it was written over');
    }

    /**
     * And the other half: a rollback must not take a document root cPanel made
     * with it. Removing it leaves the subdomain pointing at nothing, which is a
     * worse mess than the half-made shop being cleaned up.
     */
    public function test_a_rollback_empties_a_document_root_it_did_not_make(): void
    {
        mkdir($this->root.'/public_html/hawler', 0755, true);

        $this->withEnvironment('MIGRATE_MUST_FAIL', function () {
            $this->make()->assertSessionHas('warning');
        });

        $this->assertSame(0, Customer::count());
        $this->assertDirectoryDoesNotExist($this->root.'/shops/hawler');

        // Still there for the subdomain, and empty.
        $this->assertDirectoryExists($this->root.'/public_html/hawler');
        $this->assertSame(
            [],
            array_diff((array) scandir($this->root.'/public_html/hawler'), ['.', '..']),
            'the rolled-back shop’s files were left in the document root',
        );
    }

    /** One the panel made itself is taken away completely, as before. */
    public function test_a_rollback_removes_a_public_folder_it_did_make(): void
    {
        $this->withEnvironment('MIGRATE_MUST_FAIL', function () {
            $this->make()->assertSessionHas('warning');
        });

        $this->assertDirectoryDoesNotExist($this->root.'/public_html/hawler');
    }

    // ---- The domain -------------------------------------------------------

    /**
     * The panel points the domain itself, and passes the path it already knows.
     *
     * Typing that path by hand is what cost a night on the live account:
     * cPanel's Document Root field is relative to the home folder, so an
     * absolute path serves the domain from `/home/x/home/x/public_html/...`,
     * which does not exist. Every request 404s, including static files, and it
     * reads like a missing vhost. Passing it in code means the conversion
     * happens once, where it is tested.
     */
    public function test_it_points_the_domain_at_the_shop(): void
    {
        $this->make()->assertSessionHas('success');

        $this->assertSame(
            [['hawler.soranstore.com', $this->root.'/public_html/hawler']],
            self::$pointed,
        );
    }

    /** The domain comes off with everything else, or it is left serving nothing. */
    public function test_a_failure_takes_the_domain_back_off(): void
    {
        $this->withEnvironment('MIGRATE_MUST_FAIL', function () {
            $this->make()->assertSessionHas('warning');
        });

        $this->assertSame(['hawler.soranstore.com'], self::$unpointed);
        $this->assertSame(0, Customer::count());
    }

    /** Nothing is pointed at a shop that was never made. */
    public function test_a_database_that_cannot_be_made_points_no_domain(): void
    {
        $this->make(['short_name' => 'refuse'])->assertSessionHas('warning');

        $this->assertSame([], self::$pointed);
        $this->assertSame([], self::$unpointed);
    }

    // ---- Publishing the name ----------------------------------------------

    /**
     * Pointing the domain and publishing the name are two different jobs at two
     * different authorities. Doing only the first gives a correctly configured
     * site nobody can reach — which is exactly what happened on the live
     * account: "panel.soranstore.com's server IP address could not be found".
     */
    public function test_it_publishes_the_name_as_well_as_pointing_the_domain(): void
    {
        $this->make()->assertSessionHas('success');

        $this->assertSame([['hawler.soranstore.com', '192.0.2.7']], self::$published);
    }

    /** A live address serving a half-made shop is the only wreckage strangers see. */
    public function test_a_failure_withdraws_the_published_name(): void
    {
        $this->withEnvironment('MIGRATE_MUST_FAIL', function () {
            $this->make()->assertSessionHas('warning');
        });

        $this->assertSame(['hawler.soranstore.com'], self::$unpublished);
    }

    /**
     * Publishing a name pointing nowhere is worse than not publishing one: it
     * looks like a shop that is broken rather than one not finished.
     */
    public function test_it_refuses_to_publish_without_knowing_what_to_point_at(): void
    {
        config(['panel.dns.address' => '']);

        $this->make()->assertSessionHas('warning', fn (string $said) => str_contains($said, 'PANEL_SERVER_IP'));

        $this->assertSame([], self::$published);
        $this->assertSame(0, Customer::count());
    }

    /** A certificate is asked for once the shop is real, and never before. */
    public function test_it_asks_for_a_certificate_for_the_new_shop(): void
    {
        $this->make()->assertSessionHas('success');

        $this->assertSame(['hawler.soranstore.com'], self::$secured);
    }

    public function test_a_licence_is_required_when_that_is_how_they_start(): void
    {
        $this->make(['start' => 'licence', 'licence' => ''])->assertSessionHasErrors('licence');

        $this->assertSame(0, Customer::count());
    }

    // ---- Section 7: rolls back what it made -------------------------------

    public function test_a_database_that_cannot_be_made_leaves_nothing_behind(): void
    {
        $this->make(['short_name' => 'refuse'])->assertSessionHas('warning');

        $this->assertSame(0, Customer::count());
        $this->assertDirectoryDoesNotExist($this->root.'/shops/refuse');
        $this->assertStringContainsString('cPanel refused', session('warning'));
    }

    /** The database must not be left counting against the account's limit. */
    public function test_a_failed_provision_takes_the_database_back(): void
    {
        $this->withEnvironment('PROVISION_MUST_FAIL', fn () => $this->make()->assertSessionHas('warning'));

        $this->assertSame(0, Customer::count());
        $this->assertSame([['soransto_hawler_shop', 'soransto_hawler_user']], self::$dropped);
        $this->assertStringContainsString('taken back', session('warning'));
    }

    /** And the folders with it, so nothing looks provisioned that is not. */
    public function test_a_failed_migration_takes_the_folders_and_the_database_back(): void
    {
        $this->withEnvironment('MIGRATE_MUST_FAIL', fn () => $this->make()->assertSessionHas('warning'));

        $this->assertSame(0, Customer::count());
        $this->assertDirectoryDoesNotExist($this->root.'/shops/hawler');
        $this->assertDirectoryDoesNotExist($this->root.'/public_html/hawler');
        $this->assertNotSame([], self::$dropped);
    }

    /**
     * A shop nobody can sign in to is not a finished shop. If the seeder ever
     * stops printing the administrator, that must be a failure rather than a
     * customer handed over with no way in.
     */
    public function test_a_seed_that_names_no_administrator_is_a_failure(): void
    {
        $this->withEnvironment('SEED_SAYS_NOTHING', fn () => $this->make()->assertSessionHas('warning'));

        $this->assertSame(0, Customer::count());
        $this->assertStringContainsString('administrator', session('warning'));
        $this->assertDirectoryDoesNotExist($this->root.'/shops/hawler');
    }

    /** A rollback must never reach outside the folder shops live in. */
    public function test_the_rollback_will_not_delete_outside_the_shops_folder(): void
    {
        $elsewhere = $this->root.'/not-a-shop';
        mkdir($elsewhere, 0755, true);
        file_put_contents($elsewhere.'/important.txt', 'x');

        config(['panel.shops.home_root' => $this->root.'/shops']);

        $this->withEnvironment('MIGRATE_MUST_FAIL', fn () => $this->make());

        $this->assertFileExists($elsewhere.'/important.txt');
    }

    // ---- The screen -------------------------------------------------------

    public function test_the_form_needs_signing_in(): void
    {
        auth()->logout();

        $this->get(route('customers.create'))->assertRedirect(route('login'));
        $this->post(route('customers.store'), [])->assertRedirect(route('login'));
    }

    public function test_the_form_explains_what_it_is_about_to_do(): void
    {
        $this->get(route('customers.create'))
            ->assertOk()
            ->assertSee('What this does, in order')
            ->assertSee('Type the short name to create the shop')
            ->assertSee('On a free trial');
    }

    private function rmrf(string $path): void
    {
        if (! is_dir($path)) {
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
