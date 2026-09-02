<?php

namespace Tests\Feature;

use App\Models\User;
use Dotenv\Dotenv;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * `panel:check` — whether this panel can actually do its job where it is.
 *
 * It exists because the panel is developed on a local machine and runs on
 * cPanel, and the two need different answers to nearly every setting: where
 * shops go, how a database gets made, where the shared codebase is. A wrong one
 * otherwise shows up halfway through creating a customer, after a database has
 * been made and rolled back, reported as whichever step happened to hit it.
 */
class PanelCheckTest extends TestCase
{
    use RefreshDatabase;

    private string $root;

    protected function setUp(): void
    {
        parent::setUp();

        $this->root = sys_get_temp_dir().'/panel-check-'.bin2hex(random_bytes(6));
        mkdir($this->root.'/shops', 0755, true);
        mkdir($this->root.'/public_html', 0755, true);

        file_put_contents($this->root.'/artisan', "<?php echo \"  shop:provision  Make a shop\\n\";\n");

        config([
            'panel.shops.home_root' => $this->root.'/shops',
            'panel.shops.public_root' => $this->root.'/public_html',
            'panel.shops.shared_artisan' => $this->root.'/artisan',
            'panel.database_maker.driver' => 'direct',
            'panel.database_maker.connection' => config('database.default'),
        ]);

        User::factory()->create(['is_active' => true, 'two_factor_confirmed_at' => now()]);
    }

    protected function tearDown(): void
    {
        foreach (glob($this->root.'/*') ?: [] as $entry) {
            is_dir($entry) ? @rmdir($entry) : @unlink($entry);
        }
        @rmdir($this->root);

        parent::tearDown();
    }

    public function test_it_passes_when_everything_is_in_place(): void
    {
        $this->artisan('panel:check')
            ->expectsOutputToContain('Everything this panel needs is here')
            ->assertSuccessful();
    }

    public function test_it_names_a_shared_artisan_that_is_not_there(): void
    {
        config(['panel.shops.shared_artisan' => '/no/such/artisan']);

        $this->artisan('panel:check')
            ->expectsOutputToContain('/no/such/artisan')
            ->expectsOutputToContain('PANEL_SHARED_ARTISAN')
            ->assertFailed();
    }

    /** A Laravel that is not the shop system cannot provision anything. */
    public function test_it_notices_an_artisan_that_is_the_wrong_project(): void
    {
        file_put_contents($this->root.'/artisan', "<?php echo \"  migrate  Run migrations\\n\";\n");

        $this->artisan('panel:check')
            ->expectsOutputToContain('no shop:provision')
            ->assertFailed();
    }

    public function test_it_names_a_shops_folder_that_is_not_there(): void
    {
        config(['panel.shops.home_root' => '/no/such/folder']);

        $this->artisan('panel:check')
            ->expectsOutputToContain('/no/such/folder')
            ->expectsOutputToContain('PANEL_SHOPS_HOME')
            ->assertFailed();
    }

    public function test_it_refuses_a_private_key_on_this_server(): void
    {
        $private = '';
        openssl_pkey_export(openssl_pkey_new([
            'private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA,
        ]), $private);

        config(['licence.public_key' => $private]);

        $this->artisan('panel:check')
            ->expectsOutputToContain('must never be on this server')
            ->assertFailed();
    }

    public function test_it_notices_a_missing_public_key(): void
    {
        config(['licence.public_key' => '']);

        $this->artisan('panel:check')
            ->expectsOutputToContain('no licence could ever be verified')
            ->assertFailed();
    }

    /** cPanel prefixes every database, so an empty prefix records wrong names. */
    public function test_it_warns_when_the_cpanel_prefix_is_missing(): void
    {
        config([
            'panel.database_maker.driver' => 'cpanel',
            'panel.cpanel.uapi' => $this->root.'/artisan',   // any real file
            'panel.cpanel.prefix' => '',
        ]);

        $this->artisan('panel:check')
            ->expectsOutputToContain('PANEL_CPANEL_PREFIX is empty')
            ->assertSuccessful();
    }

    public function test_it_names_a_missing_uapi_on_a_cpanel_setup(): void
    {
        config([
            'panel.database_maker.driver' => 'cpanel',
            'panel.cpanel.uapi' => '/no/such/uapi',
        ]);

        $this->artisan('panel:check')
            ->expectsOutputToContain('/no/such/uapi')
            ->assertFailed();
    }

    /** There is no sign-up page, so a panel with nobody in it cannot be opened. */
    public function test_it_fails_when_nobody_can_sign_in(): void
    {
        User::query()->forceDelete();

        $this->artisan('panel:check')
            ->expectsOutputToContain('db:seed')
            ->assertFailed();
    }

    /**
     * A warning is not a misconfiguration. A check that goes red for both
     * teaches you to ignore it.
     */
    public function test_a_missing_authenticator_is_said_but_does_not_fail(): void
    {
        User::query()->update(['two_factor_confirmed_at' => null]);

        $this->artisan('panel:check')
            ->expectsOutputToContain('0 with an authenticator')
            ->assertSuccessful();
    }
    // ---- The things that are only wrong on a server ----------------------

    /**
     * A laptop is not a misconfigured server.
     *
     * APP_DEBUG on is the right answer locally and a hole in production, so a
     * check that goes red for it on a developer's machine is one they stop
     * reading.
     */
    public function test_a_development_machine_is_not_judged_as_a_server(): void
    {
        config(['app.env' => 'local', 'app.debug' => true, 'app.url' => 'http://localhost']);

        $this->artisan('panel:check')
            ->expectsOutputToContain('not being judged as a server')
            ->assertSuccessful();
    }

    public function test_a_server_with_debug_on_fails(): void
    {
        config(['app.env' => 'production', 'app.debug' => true, 'app.url' => 'https://panel.soranstore.com']);

        $this->artisan('panel:check')
            ->expectsOutputToContain('APP_DEBUG is on')
            ->assertFailed();
    }

    public function test_a_server_not_on_https_fails(): void
    {
        config(['app.env' => 'production', 'app.debug' => false, 'app.url' => 'http://panel.soranstore.com']);

        $this->artisan('panel:check')
            ->expectsOutputToContain('not https')
            ->assertFailed();
    }

    /** An empty APP_KEY breaks a laptop exactly as thoroughly as a server. */
    public function test_an_empty_app_key_fails_anywhere(): void
    {
        config(['app.env' => 'local', 'app.key' => '']);

        $this->artisan('panel:check')
            ->expectsOutputToContain('APP_KEY is empty')
            ->assertFailed();
    }

    // ---- Its own secrets --------------------------------------------------

    /**
     * Section 4 records finding a real customer's install serving its .env to
     * anyone. The panel's is worse: the customer list, the admin password, and
     * an account that may create and drop databases.
     */
    public function test_it_checks_the_panel_is_not_serving_its_own_env(): void
    {
        $this->artisan('panel:check')
            ->expectsOutputToContain('Its own .env is not on the web')
            ->assertSuccessful();
    }

    public function test_the_deny_all_htaccess_is_really_there(): void
    {
        $this->assertFileExists(base_path('.htaccess'), 'the net for a panel uploaded inside public_html is gone');
        $this->assertStringContainsString('denied', file_get_contents(base_path('.htaccess')));
    }

    /** And public/ grants itself back, or the panel serves nothing at all. */
    public function test_the_public_folder_grants_itself_back(): void
    {
        $this->assertStringContainsString(
            'Require all granted',
            file_get_contents(public_path('.htaccess')),
            'the root denial cascades into public/, so it has to be granted back',
        );
    }
    // ---- The template itself ----------------------------------------------

    /**
     * `.env.example` has to be a file dotenv can actually read.
     *
     * It is not documentation: CI does `cp .env.example .env` before anything
     * else, and DEPLOY.md step 2 tells Soran to do the same on the server. One
     * bad value does not spoil one setting — dotenv refuses the WHOLE file, so
     * `key:generate` fails and nothing after it runs.
     *
     * That happened. A Windows path was written in double quotes, where dotenv
     * reads \s and \p as escape sequences it does not recognise, and both CI
     * jobs went red on a file no test had ever opened.
     */
    public function test_the_env_example_is_a_file_dotenv_can_read(): void
    {
        $path = base_path('.env.example');

        $this->assertFileExists($path, 'DEPLOY.md step 2 is `cp .env.example .env`');

        try {
            $values = Dotenv::parse((string) file_get_contents($path));
        } catch (\Throwable $e) {
            $this->fail('.env.example does not parse, so `cp .env.example .env` breaks: '.$e->getMessage());
        }

        // And the keys the panel cannot start without are named in it.
        foreach ([
            'APP_KEY', 'DB_DATABASE', 'PANEL_ADMIN_EMAIL', 'PANEL_ADMIN_PASSWORD',
            'PANEL_SHOPS_HOME', 'PANEL_SHOPS_PUBLIC', 'PANEL_SHARED_ARTISAN', 'PANEL_DATABASE_MAKER',
        ] as $key) {
            $this->assertArrayHasKey($key, $values, "[{$key}] is not in .env.example");
        }
    }

    /**
     * A key that config/licence.php has a default for must not be SET-BUT-EMPTY
     * in the template.
     *
     * `env('X', 'default')` returns the default when X is absent and an empty
     * string when X is present and blank. So a commented-out line falls back to
     * the committed key, and `LICENCE_PUBLIC_KEY=` leaves the panel holding no
     * key at all — refusing every licence pasted into it as "not signed by your
     * key", with no hint why.
     *
     * The .env.example comment claimed the opposite until CI, running with the
     * template as its .env, failed on it.
     */
    public function test_the_env_example_does_not_blank_a_key_that_has_a_default(): void
    {
        $values = Dotenv::parse((string) file_get_contents(base_path('.env.example')));

        $this->assertArrayNotHasKey(
            'LICENCE_PUBLIC_KEY',
            $values,
            'LICENCE_PUBLIC_KEY is set in .env.example. Blank, it overrides the committed default and the '
            .'panel can verify nothing. Comment the line out instead.',
        );
    }

    /** A template that shipped a real secret would be a secret in the repository. */
    public function test_the_env_example_carries_no_credentials(): void
    {
        $values = Dotenv::parse((string) file_get_contents(base_path('.env.example')));

        foreach ([
            'APP_KEY', 'DB_PASSWORD', 'DB_USERNAME', 'PANEL_ADMIN_PASSWORD',
            'PANEL_ADMIN_EMAIL', 'PANEL_MAKER_PASSWORD', 'PANEL_MAKER_USERNAME',
        ] as $key) {
            $this->assertSame('', (string) ($values[$key] ?? ''), "[{$key}] has a real value in .env.example");
        }
    }
}
