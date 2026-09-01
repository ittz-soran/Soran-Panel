<?php

namespace App\Services;

use App\Contracts\ShopReader;
use App\Models\Customer;
use App\Support\ReadOnlyConnection;
use App\Support\ShopEnvironment;
use App\Support\ShopReading;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;
use Symfony\Component\Process\Process;
use Throwable;

/**
 * Reading a shop that lives on this same server — PANEL_DOC Section 8.
 *
 * Directly, with no HTTP endpoint and no remote-control door opened in every
 * customer's install. Soran's decision, and the safer one for as long as he
 * hosts everyone himself.
 *
 * Four ways in, and Section 8 names all four:
 *
 *   its data       a second connection, read-only by construction
 *   its storage    the filesystem under shop_home
 *   its opinion    its own artisan commands, with SHOP_HOME set
 *   its integrity  `data:check`, which reports and never repairs
 *
 * Nothing here throws. Every part is attempted even when an earlier part
 * failed, because the parts fail independently and a shop whose database is
 * stopped can still say how much disk it is using — which is exactly the
 * reading somebody will want when they come to find out why it stopped.
 */
class LocalShopReader implements ShopReader
{
    /**
     * How long any one of the shop's commands may take.
     *
     * The data check walks every row a shop has, so this is generous. It is
     * also finite: the hourly check runs over every customer in turn, and one
     * shop wedged on a lock must not stop the others being looked at at all.
     */
    private const TIMEOUT = 120;

    public function read(Customer $customer): ShopReading
    {
        $problems = [];

        // The shop's own .env is the only place its database password exists.
        // The panel deliberately does not keep a copy: PANEL_DOC Section 5
        // records the database NAME and USER on the customer row and stops
        // there, so a dump of the panel's database hands over the customer
        // list and not the keys to six shops.
        $env = $this->readEnv($customer, $problems);

        $storage = $this->readStorage($customer, $problems);
        $database = $this->readDatabase($customer, $env, $problems);
        $opinion = $this->askTheShop($customer, $problems);

        return new ShopReading(
            reachable: $database !== null,
            databaseBytes: $database['bytes'] ?? null,
            backupsBytes: $storage['backups'] ?? null,
            uploadsBytes: $storage['uploads'] ?? null,
            storageLimitMb: isset($env['STORAGE_LIMIT_MB']) && $env['STORAGE_LIMIT_MB'] !== ''
                ? (int) $env['STORAGE_LIMIT_MB']
                : null,
            migrationsRun: $opinion['migrations_run'] ?? null,
            migrationsTotal: $opinion['migrations_total'] ?? null,
            lastActivityAt: $database['last_activity_at'] ?? null,
            usersCount: $database['users'] ?? null,
            productsCount: $database['products'] ?? null,
            salesCount: $database['sales'] ?? null,
            licenceState: $opinion['licence_state'] ?? null,
            dataCheckPassed: $opinion['data_check_passed'] ?? null,
            dataCheckTotal: $opinion['data_check_total'] ?? null,
            problems: $problems,
        );
    }

    public function licenceState(Customer $customer): ?string
    {
        $problems = [];
        $artisan = rtrim($customer->shop_home, '/').'/artisan';

        if (! is_file($artisan)) {
            return null;
        }

        return $this->json($artisan, ['licence:show', '--json'], $problems)['state'] ?? null;
    }

    /**
     * The shop's .env, parsed just enough.
     *
     * Not Dotenv, because Dotenv wants to put things into the environment and
     * this is somebody else's environment. Only the handful of keys the panel
     * asks about, read as text.
     *
     * @param  list<string>  $problems
     * @return array<string, string>
     */
    private function readEnv(Customer $customer, array &$problems): array
    {
        $path = rtrim($customer->shop_home, '/').'/.env';

        if (! is_readable($path)) {
            $problems[] = "Its .env is not readable at [{$path}].";

            return [];
        }

        $values = [];

        foreach (file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $line) {
            $line = trim($line);

            if ($line === '' || str_starts_with($line, '#') || ! str_contains($line, '=')) {
                continue;
            }

            [$key, $value] = explode('=', $line, 2);

            $values[trim($key)] = trim(trim($value), '"\'');
        }

        return $values;
    }

    /**
     * What the shop is using on disk, under shop_home.
     *
     * Backups and uploads separately, because they grow for different reasons
     * and the answer to each is different: backups are pruned, uploads are the
     * shop's own doing.
     *
     * @param  list<string>  $problems
     * @return array{backups: ?int, uploads: ?int}
     */
    private function readStorage(Customer $customer, array &$problems): array
    {
        $home = rtrim($customer->shop_home, '/');

        if (! is_dir($home)) {
            $problems[] = "Its folder is not there: [{$home}].";

            return ['backups' => null, 'uploads' => null];
        }

        $backups = $home.'/storage/app/backups';

        return [
            'backups' => $this->bytesIn($backups),

            // Everything the shop stores that is not a backup: the logo, any
            // exports, the logs. Measured by walking storage/ and taking the
            // backups off, so a folder added later is counted rather than
            // silently missed.
            'uploads' => max(0, ($this->bytesIn($home.'/storage') ?? 0) - ($this->bytesIn($backups) ?? 0)),
        ];
    }

    private function bytesIn(?string $path): ?int
    {
        if ($path === null || ! is_dir($path)) {
            return null;
        }

        $bytes = 0;

        $files = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($path, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::SELF_FIRST,
        );

        foreach ($files as $file) {
            if ($file->isFile() && ! $file->isLink()) {
                $bytes += $file->getSize();
            }
        }

        return $bytes;
    }

    /**
     * The shop's data, over a connection that cannot write to it.
     *
     * Configured at runtime from the customer row and the shop's own .env, and
     * forgotten afterwards so nothing later in the request can reach for it by
     * accident.
     *
     * @param  array<string, string>  $env
     * @param  list<string>  $problems
     * @return array{bytes: ?int, users: ?int, products: ?int, sales: ?int, last_activity_at: ?Carbon}|null
     */
    private function readDatabase(Customer $customer, array $env, array &$problems): ?array
    {
        $name = $customer->database_name;
        $connection = 'shop:'.$customer->id;

        Config::set("database.connections.{$connection}", [
            'driver' => 'mysql',
            'host' => $env['DB_HOST'] ?? '127.0.0.1',
            'port' => $env['DB_PORT'] ?? '3306',
            'database' => $name,
            'username' => $env['DB_USERNAME'] ?? $customer->database_user,
            'password' => $env['DB_PASSWORD'] ?? '',
            'charset' => 'utf8mb4',
            'collation' => 'utf8mb4_unicode_ci',
            'prefix' => '',
            'strict' => true,
        ]);

        try {
            $shop = DB::connection($connection);

            // Every query below goes through this, and it refuses anything
            // that is not a read. See ReadOnlyConnection for why the refusal
            // is here rather than in the discipline of the caller.
            $shop = new ReadOnlyConnection(
                $shop->getPdo(),
                $name,
                $shop->getTablePrefix(),
                $shop->getConfig(),
            );

            $bytes = (int) ($shop->selectOne(
                'select sum(data_length + index_length) as bytes
                   from information_schema.tables where table_schema = ?',
                [$name],
            )->bytes ?? 0);

            return [
                'bytes' => $bytes,
                'users' => $this->countOf($shop, 'users'),
                'products' => $this->countOf($shop, 'products'),
                'sales' => $this->countOf($shop, 'sales'),
                'last_activity_at' => $this->lastActivity($shop),
            ];
        } catch (Throwable $e) {
            $problems[] = 'Its database would not answer: '.$e->getMessage();

            return null;
        } finally {
            DB::purge($connection);
            Config::set("database.connections.{$connection}", null);
        }
    }

    /**
     * A count, or null if the table is not there.
     *
     * A shop mid-migration genuinely has no `sales` table, and that is a
     * different thing from a shop with no sales.
     */
    private function countOf(ReadOnlyConnection $shop, string $table): ?int
    {
        try {
            return (int) $shop->selectOne("select count(*) as n from `{$table}`")->n;
        } catch (Throwable) {
            return null;
        }
    }

    /** Whether anybody is actually using it — the shop's own activity log. */
    private function lastActivity(ReadOnlyConnection $shop): ?Carbon
    {
        try {
            $at = $shop->selectOne('select max(created_at) as at from `activity_logs`')->at;

            return $at ? Carbon::parse($at) : null;
        } catch (Throwable) {
            return null;
        }
    }

    /**
     * What the shop makes of itself, asked of the shop.
     *
     * Its own artisan, in its own folder, against the shared codebase — which
     * is exactly how it runs when a shopkeeper opens it, so the answer is the
     * shop's own and not the panel's opinion of the shop.
     *
     * @param  list<string>  $problems
     * @return array<string, int|string|null>
     */
    private function askTheShop(Customer $customer, array &$problems): array
    {
        $artisan = rtrim($customer->shop_home, '/').'/artisan';

        if (! is_file($artisan)) {
            $problems[] = "It has no artisan of its own at [{$artisan}], so it cannot be asked anything.";

            return [];
        }

        $found = [];

        if ($licence = $this->json($artisan, ['licence:show', '--json'], $problems)) {
            $found['licence_state'] = $licence['state'] ?? null;
        }

        if ($check = $this->json($artisan, ['data:check', '--json'], $problems)) {
            $found['data_check_passed'] = $check['passed'] ?? null;
            $found['data_check_total'] = $check['total'] ?? null;
        }

        $found += $this->migrations($artisan, $problems);

        return $found;
    }

    /**
     * `migrate:status`, counted.
     *
     * Laravel offers no --json here, so its own table is read: every migration
     * is one line ending in Ran or Pending. Counted rather than parsed for
     * names, so a format change costs the two numbers and not the whole
     * reading.
     *
     * @param  list<string>  $problems
     * @return array<string, int>
     */
    private function migrations(string $artisan, array &$problems): array
    {
        $output = $this->run($artisan, ['migrate:status'], $problems);

        if ($output === null) {
            return [];
        }

        $ran = preg_match_all('/\bRan\s*$/m', $output);
        $pending = preg_match_all('/\bPending\s*$/m', $output);

        if ($ran + $pending === 0) {
            $problems[] = 'Its migrate:status said nothing this could count.';

            return [];
        }

        return ['migrations_run' => $ran, 'migrations_total' => $ran + $pending];
    }

    /**
     * @param  list<string>  $command
     * @param  list<string>  $problems
     * @return array<string, mixed>|null
     */
    private function json(string $artisan, array $command, array &$problems): ?array
    {
        $output = $this->run($artisan, $command, $problems);

        if ($output === null) {
            return null;
        }

        // The last line: a shop with a deprecation notice or a warning still
        // prints the JSON, and it prints it last.
        $lines = array_values(array_filter(array_map('trim', explode("\n", $output)), fn ($l) => $l !== ''));
        $decoded = json_decode((string) end($lines), true);

        if (! is_array($decoded)) {
            $problems[] = 'Its '.$command[0].' did not answer in JSON: '.mb_substr(trim($output), 0, 200);

            return null;
        }

        return $decoded;
    }

    /**
     * @param  list<string>  $command
     * @param  list<string>  $problems
     */
    private function run(string $artisan, array $command, array &$problems): ?string
    {
        $process = new Process(
            [PHP_BINARY, $artisan, ...$command],
            env: ShopEnvironment::withoutThePanel(),
        );
        $process->setTimeout(self::TIMEOUT);

        try {
            $process->run();
        } catch (Throwable $e) {
            $problems[] = 'Its '.$command[0].' would not run: '.$e->getMessage();

            return null;
        }

        // Not isSuccessful(): `data:check` exits non-zero precisely when it has
        // something to report, and that is the answer rather than a failure.
        // What matters is whether anything came back at all.
        $output = $process->getOutput();

        if (trim($output) === '') {
            $problems[] = 'Its '.$command[0].' said nothing: '
                .mb_substr(trim($process->getErrorOutput()) ?: 'no output at all', 0, 200);

            return null;
        }

        return $output;
    }
}
