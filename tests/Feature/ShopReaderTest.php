<?php

namespace Tests\Feature;

use App\Contracts\ShopReader;
use App\Models\Customer;
use App\Services\LocalShopReader;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Symfony\Component\Process\Process;
use Tests\TestCase;

/**
 * Reading a shop — PANEL_DOC Section 8.
 *
 * A shop here is a real folder with a real `.env`, real storage, and an
 * `artisan` that is a real PHP script printing what a real one prints. The
 * shop system itself is another repository and is not installed beside this
 * one, so what is held here is everything the panel does with a shop's answers
 * — finding them, parsing them, surviving them being wrong — rather than the
 * shop system's ability to produce them, which its own suite holds.
 *
 * The reading against a genuinely provisioned shop is done by hand and recorded
 * in the task log: 32 of 32 migrations, 17 of 17 assertions, `unlicensed`.
 */
class ShopReaderTest extends TestCase
{
    use RefreshDatabase;

    private string $home;

    protected function setUp(): void
    {
        parent::setUp();

        $this->home = sys_get_temp_dir().'/shop-read-'.bin2hex(random_bytes(6));

        foreach (['storage/app/backups', 'storage/logs'] as $directory) {
            mkdir($this->home.'/'.$directory, 0755, true);
        }
    }

    protected function tearDown(): void
    {
        $this->rmrf($this->home);

        parent::tearDown();
    }

    private function shop(array $env = [], ?string $artisan = null): Customer
    {
        $lines = [
            'DB_HOST=127.0.0.1',
            'DB_PORT=3306',
            'DB_DATABASE=bazaar_shop',
            'DB_USERNAME=bazaar_user',
            'DB_PASSWORD=secret',
            'STORAGE_LIMIT_MB=2048',
            ...$env,
        ];

        file_put_contents($this->home.'/.env', implode("\n", $lines)."\n");

        file_put_contents($this->home.'/artisan', $artisan ?? $this->artisanThatAnswers());
        chmod($this->home.'/artisan', 0755);

        return Customer::factory()->create([
            'shop_home' => $this->home,
            'database_name' => 'bazaar_shop',
            'database_user' => 'bazaar_user',
        ]);
    }

    /** A stand-in for the shop's own artisan, printing what the real one prints. */
    private function artisanThatAnswers(): string
    {
        return <<<'PHP'
        <?php
        $command = $argv[1] ?? '';

        if ($command === 'licence:show') {
            echo json_encode(['state' => 'valid', 'allows_writing' => true]), PHP_EOL;
            exit(0);
        }

        if ($command === 'data:check') {
            echo json_encode(['total' => 17, 'passed' => 16, 'serious' => 1]), PHP_EOL;
            exit(1);   // non-zero is the ANSWER here, not a failure
        }

        if ($command === 'migrate:status') {
            echo PHP_EOL, ' Migration name .. Batch / Status ', PHP_EOL;
            foreach (range(1, 30) as $i) { echo " migration_{$i} .. [1] Ran ", PHP_EOL; }
            foreach (range(31, 32) as $i) { echo " migration_{$i} .. Pending ", PHP_EOL; }
            exit(0);
        }

        exit(1);
        PHP;
    }

    private function read(Customer $customer)
    {
        return app(ShopReader::class)->read($customer);
    }

    public function test_the_reader_is_the_local_one(): void
    {
        $this->assertInstanceOf(LocalShopReader::class, app(ShopReader::class));
    }

    public function test_it_reads_what_the_shop_says_about_itself(): void
    {
        $reading = $this->read($this->shop());

        $this->assertSame('valid', $reading->licenceState);
        $this->assertSame(16, $reading->dataCheckPassed);
        $this->assertSame(17, $reading->dataCheckTotal);
        $this->assertSame(30, $reading->migrationsRun);
        $this->assertSame(32, $reading->migrationsTotal);
    }

    /** The limit comes from the shop's own .env, which is the thing that is true. */
    public function test_it_reads_the_storage_limit_off_the_shops_env(): void
    {
        $this->assertSame(2048, $this->read($this->shop())->storageLimitMb);
    }

    public function test_no_limit_in_the_env_is_no_limit_rather_than_zero(): void
    {
        $customer = $this->shop();
        file_put_contents($this->home.'/.env', "DB_DATABASE=bazaar_shop\nSTORAGE_LIMIT_MB=\n");

        $this->assertNull($this->read($customer)->storageLimitMb);
    }

    public function test_it_measures_what_the_shop_is_using_on_disk(): void
    {
        $customer = $this->shop();

        file_put_contents($this->home.'/storage/app/backups/monday.zip', str_repeat('a', 5000));
        file_put_contents($this->home.'/storage/logs/laravel.log', str_repeat('b', 300));

        $reading = $this->read($customer);

        $this->assertSame(5000, $reading->backupsBytes);
        $this->assertGreaterThanOrEqual(300, $reading->uploadsBytes);
        $this->assertLessThan(5000, $reading->uploadsBytes, 'backups are not counted twice');
    }

    /**
     * The bug that made this whole file worth writing.
     *
     * A child process inherits its parent's environment, and Laravel exports
     * every key of its .env into it — so the shop's artisan booted with the
     * PANEL's database credentials, and an environment variable beats the .env
     * file beside it. The shop reported 3 of 32 migrations run, which was true:
     * it was counting the panel's database, where three of its migration names
     * also exist.
     *
     * Read-only commands made that a wrong reading. Section 7 has the panel run
     * a shop's migrations and its backups too, and the same leak there would
     * have pointed `migrate` at the panel's own database.
     *
     * The child writes down what it was handed, and the file is the evidence.
     */
    public function test_the_panels_own_environment_never_reaches_the_shop(): void
    {
        $seenAt = $this->home.'/what-the-shop-was-handed.json';

        $tell = <<<PHP
        <?php
        file_put_contents({$this->quoted($seenAt)}, json_encode([
            'DB_DATABASE' => getenv('DB_DATABASE'),
            'DB_USERNAME' => getenv('DB_USERNAME'),
            'DB_PASSWORD' => getenv('DB_PASSWORD'),
            'APP_KEY' => getenv('APP_KEY'),
            'LICENCE_KEY' => getenv('LICENCE_KEY'),
            'PANEL_ADMIN_PASSWORD' => getenv('PANEL_ADMIN_PASSWORD'),
        ]));
        echo json_encode(['state' => 'valid']), PHP_EOL;
        exit(0);
        PHP;

        $customer = $this->shop(artisan: $tell);

        $panels = [
            'DB_DATABASE' => 'soran_panel',
            'DB_USERNAME' => 'panel',
            'DB_PASSWORD' => 'panelpass',
            'APP_KEY' => 'base64:the-panels-own-key',
            'LICENCE_KEY' => 'the-panels-own-licence',
            'PANEL_ADMIN_PASSWORD' => 'the-panels-own-password',
        ];

        $this->withEnvironment($panels, fn () => $this->read($customer));

        $this->assertFileExists($seenAt, 'the stand-in shop never ran');

        $seen = json_decode((string) file_get_contents($seenAt), true);

        foreach ($panels as $key => $value) {
            $this->assertFalse(
                $seen[$key],
                "the panel's {$key} reached the shop's process, and an environment variable beats the .env beside it",
            );
        }
    }

    /**
     * Set environment variables the way Laravel's Dotenv does, and put back
     * exactly what was there.
     *
     * Restored rather than unset: phpunit.xml sets DB_DATABASE to :memory:, and
     * an earlier version of this test unset it instead, which left every test
     * that ran afterwards looking for a SQLite file called soran_panel.
     */
    private function withEnvironment(array $values, callable $body): void
    {
        $before = [];

        foreach (array_keys($values) as $key) {
            $before[$key] = [getenv($key), $_ENV[$key] ?? null, $_SERVER[$key] ?? null];
        }

        foreach ($values as $key => $value) {
            putenv("{$key}={$value}");
            $_ENV[$key] = $_SERVER[$key] = $value;
        }

        try {
            $body();
        } finally {
            foreach ($before as $key => [$env, $inEnv, $inServer]) {
                if ($env === false) {
                    putenv($key);
                } else {
                    putenv("{$key}={$env}");
                }

                if ($inEnv === null) {
                    unset($_ENV[$key]);
                } else {
                    $_ENV[$key] = $inEnv;
                }

                if ($inServer === null) {
                    unset($_SERVER[$key]);
                } else {
                    $_SERVER[$key] = $inServer;
                }
            }
        }
    }

    private function quoted(string $value): string
    {
        return var_export($value, true);
    }

    /** A reading that failed is still a reading. The hourly check must not stop. */
    public function test_a_shop_that_is_not_there_is_reported_rather_than_thrown(): void
    {
        $customer = Customer::factory()->create(['shop_home' => '/no/such/shop']);

        $reading = $this->read($customer);

        $this->assertFalse($reading->reachable);
        $this->assertNotEmpty($reading->problems);
        $this->assertNull($reading->productsCount);
        $this->assertNotEmpty($reading->toHealthCheck()['error']);
    }

    /** A shop with no artisan of its own cannot be asked anything, and says so. */
    public function test_a_shop_with_no_artisan_says_so(): void
    {
        $customer = $this->shop();
        unlink($this->home.'/artisan');

        $reading = $this->read($customer);

        $this->assertNull($reading->licenceState);
        $this->assertStringContainsString('no artisan of its own', implode(' ', $reading->problems));
    }

    /** A shop that answers with rubbish is reported, not parsed into nulls silently. */
    public function test_a_shop_that_answers_with_rubbish_is_reported(): void
    {
        $customer = $this->shop(artisan: "<?php echo 'something went wrong', PHP_EOL; exit(0);");

        $reading = $this->read($customer);

        $this->assertNull($reading->licenceState);
        $this->assertStringContainsString('did not answer in JSON', implode(' ', $reading->problems));
    }

    /**
     * Its database being down does not stop the disk being measured. That is
     * the reading somebody wants when they come to find out why it went down.
     */
    public function test_a_stopped_database_still_leaves_the_rest_of_the_reading(): void
    {
        $customer = $this->shop(['DB_HOST=127.0.0.1', 'DB_PORT=1']);
        file_put_contents($this->home.'/storage/app/backups/monday.zip', str_repeat('a', 4096));

        $reading = $this->read($customer);

        $this->assertFalse($reading->reachable);
        $this->assertSame(4096, $reading->backupsBytes);
        $this->assertSame('valid', $reading->licenceState, 'it can still say what it thinks of its licence');
        $this->assertStringContainsString('would not answer', implode(' ', $reading->problems));
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
