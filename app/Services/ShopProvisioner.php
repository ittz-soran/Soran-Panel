<?php

namespace App\Services;

use App\Contracts\DatabaseMaker;
use App\Contracts\ShopWriter;
use App\Models\Action;
use App\Models\Customer;
use App\Support\ReadOnlyConnection;
use App\Support\ShopEnvironment;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use RuntimeException;
use Symfony\Component\Process\Process;
use Throwable;

/**
 * A new customer, from nothing — PANEL_DOC Section 7, build order step 7.
 *
 * Five things happen, in this order, and every one of them can fail:
 *
 *   1. a database and a user for it
 *   2. `shop:provision` — the folder, its .env with a fresh APP_KEY, its
 *      storage, its own artisan, and the public folder the domain points at
 *   3. the shop's own `migrate` and `db:seed`
 *   4. a trial, or a licence pasted in
 *   5. the customer row
 *
 * **Section 7: "Rolls back what it made on failure."** That is the hard part
 * and most of this class. A half-made shop is worse than no shop: a database
 * with nobody's data in it counts against the account's database limit — which
 * PANEL_DOC Section 13 records as the only real ceiling left on how many
 * customers fit here — and a folder that looks provisioned is one somebody
 * later points a domain at.
 *
 * So nothing is created without being written down first, and the rollback
 * undoes exactly what this run made and nothing it found already there.
 *
 * ⚠️ **Not `install:sql`, which build order step 7 named.** That command works
 * by creating a scratch database, filling it, dumping it and dropping it — its
 * own help says "This needs an account that may CREATE DATABASE — your local
 * MySQL root, usually" — and Section 4 measured that this cPanel account denies
 * exactly that. It exists for shops with no terminal, and the panel has one.
 * `migrate` and `db:seed` on the shop's own artisan is what `install:sql`'s own
 * docblock calls the equivalent, and it is what runs here.
 */
class ShopProvisioner
{
    /** Long enough for migrations and a seed on a slow shared host. */
    private const TIMEOUT = 300;

    public function __construct(
        private readonly DatabaseMaker $databases,
        private readonly LicenceDelivery $delivery,
        private readonly ShopWriter $writer,
    ) {}

    /**
     * @param  array{
     *     name: string, short_name: string, host: string, contact_name: ?string,
     *     phone: ?string, email: ?string, monthly_fee: int, storage_limit_mb: ?int,
     *     trial: bool, licence: ?string, notes: ?string
     * }  $wanted
     * @return array{customer: Customer, admin_email: string, admin_password: string, warnings: list<string>}
     */
    public function create(array $wanted): array
    {
        $short = Str::lower(Str::of($wanted['short_name'])->replaceMatches('/[^A-Za-z0-9_]/', '')->value());

        if ($short === '') {
            throw new RuntimeException('That short name has no letters or numbers in it.');
        }

        $paths = $this->paths($short);

        // The real names, prefix and all, because the customer row must record
        // what the shop will actually connect to.
        $database = $this->databases->realName($short.'_shop');
        $user = $this->databases->realName($short.'_user');
        $password = Str::random(24);

        // What this run has made, in the order to undo it.
        $made = ['database' => false, 'folder' => false];
        $warnings = [];

        try {
            $this->refuseIfAnythingIsInTheWay($paths, $wanted['host']);

            $this->databases->create($database, $user, $password);
            $made['database'] = true;

            $this->provision($short, $paths, $wanted, $database, $user, $password);
            $made['folder'] = true;

            $admin = $this->fillTheDatabase($paths['home'], $wanted['name']);

            $customer = Customer::create([
                'name' => $wanted['name'],
                'contact_name' => $wanted['contact_name'] ?? null,
                'phone' => $wanted['phone'] ?? null,
                'email' => $wanted['email'] ?? null,
                'host' => $wanted['host'],
                'shop_home' => $paths['home'],
                'public_path' => $paths['public'],
                'database_name' => $database,
                'database_user' => $user,
                'status' => $wanted['trial'] ? Customer::TRIAL : Customer::ACTIVE,
                'monthly_fee' => $wanted['monthly_fee'],
                'storage_limit_mb' => $wanted['storage_limit_mb'] ?? null,
                'language' => 'ckb',
                'started_on' => now(),
                'notes' => $wanted['notes'] ?? null,
            ]);
        } catch (Throwable $e) {
            $left = $this->rollBack($made, $database, $user, $paths);

            throw new RuntimeException(
                $e->getMessage()
                .($left === [] ? ' Everything this made has been taken back.' : ' Left behind: '.implode(', ', $left).'.'),
                previous: $e,
            );
        }

        Action::record('customer.created', $customer, [
            'host' => $wanted['host'],
            'database' => $database,
            'trial' => $wanted['trial'],
            'shop_home' => $paths['home'],
        ]);

        // The licence, if one was pasted. Deliberately AFTER the customer row
        // exists and outside the rollback: by this point the shop is real and
        // working, and a licence that will not verify is a reason to try again
        // with a better paste, not to destroy a shop that is fine without one.
        if (! $wanted['trial'] && filled($wanted['licence'] ?? null)) {
            $result = $this->delivery->deliver($customer, $wanted['licence']);

            if (! $result->confirmed) {
                $warnings[] = 'The shop is made, and the licence did not go on: '.$result->problem;
            }
        }

        return [
            'customer' => $customer,
            'admin_email' => $admin['email'],
            'admin_password' => $admin['password'],
            'warnings' => $warnings,
        ];
    }

    /**
     * Take on a shop whose database already exists — build order step 10.
     *
     * The opposite of create(), and the dangerous one. Halabja-phone's install
     * folder was deleted and its database was deliberately KEPT — PANEL_DOC
     * Section 13: "its database must be kept", "because that is what a rebuilt
     * install restores from". So the panel has to be able to build a shop
     * AROUND data that is already there, rather than making data of its own.
     *
     * Three things are different from create(), and each is a way to destroy a
     * real customer's trading history:
     *
     *   - **No database is created.** It exists, it is theirs, and the panel
     *     verifies it looks like a shop before touching anything.
     *   - **`db:seed` is never run.** Seeding a live database adds a second
     *     administrator, rewrites the settings and resets the document
     *     counters. `migrate` alone, to bring an old schema up to the shared
     *     codebase.
     *   - **The rollback never drops the database.** On any failure it removes
     *     the folder this run made and stops. A rollback that took the database
     *     with it would destroy exactly what this flow exists to preserve.
     *
     * And a backup is taken first, through the shop's own `backup:run`, because
     * Section 7 requires one before anything irreversible and a migration on
     * somebody's real records is the definition of that.
     *
     * @param  array{
     *     name: string, short_name: string, host: string, contact_name: ?string,
     *     phone: ?string, email: ?string, monthly_fee: int, storage_limit_mb: ?int,
     *     database: string, database_user: string, database_password: string,
     *     app_key: ?string, backup: bool, trial: bool, licence: ?string, notes: ?string
     * }  $wanted
     * @return array{customer: Customer, found: array<string, int>, migrations_run: int, warnings: list<string>}
     */
    public function takeOn(array $wanted): array
    {
        $short = Str::lower(Str::of($wanted['short_name'])->replaceMatches('/[^A-Za-z0-9_]/', '')->value());

        if ($short === '') {
            throw new RuntimeException('That short name has no letters or numbers in it.');
        }

        $paths = $this->paths($short);
        $warnings = [];
        $madeFolder = false;

        try {
            $this->refuseIfAnythingIsInTheWay($paths, $wanted['host']);

            // Before anything is written: is this really a shop's database, and
            // does it have anything in it? An empty one is a NEW customer, and
            // sending it down this path would leave them with no administrator
            // and no settings, because this flow never seeds.
            $found = $this->lookAtTheirDatabase($wanted);

            if ($found['authenticators'] > 0 && blank($wanted['app_key'] ?? null)) {
                throw new RuntimeException(sprintf(
                    '%d of their staff have an authenticator, and its secret is encrypted with the shop’s '
                    .'APP_KEY. `shop:provision` writes a fresh key, which would leave those secrets unreadable '
                    .'and break their sign-in. Give the ORIGINAL APP_KEY from the old install’s .env — it is in '
                    .'your backup — or clear two_factor_secret and two_factor_recovery_codes on those users '
                    .'yourself first, so they enrol again. Nothing has been changed.',
                    $found['authenticators'],
                ));
            }

            $this->provision(
                $short, $paths, $wanted,
                $wanted['database'], $wanted['database_user'], $wanted['database_password'],
            );
            $madeFolder = true;

            // Their own key back, so what it encrypted stays readable.
            // shop:provision has no option for this and should not: a new shop
            // must always get a fresh key, and this is the one case that is not
            // a new shop.
            if (filled($wanted['app_key'] ?? null)) {
                $this->writer->putEnv(
                    new Customer(['shop_home' => $paths['home']]),
                    ['APP_KEY' => $wanted['app_key']],
                );
            }

            $artisan = rtrim($paths['home'], '/').'/artisan';

            if ($wanted['backup']) {
                $warnings = [...$warnings, ...$this->backUpFirst($artisan)];
            } else {
                $warnings[] = 'No backup was taken before migrating, because you said you already had one.';
            }

            $before = $this->migrationsAlreadyRun($artisan);
            $this->run([PHP_BINARY, $artisan, 'migrate', '--force'], 'bringing their database up to date');
            $after = $this->migrationsAlreadyRun($artisan);

            $customer = Customer::create([
                'name' => $wanted['name'],
                'contact_name' => $wanted['contact_name'] ?? null,
                'phone' => $wanted['phone'] ?? null,
                'email' => $wanted['email'] ?? null,
                'host' => $wanted['host'],
                'shop_home' => $paths['home'],
                'public_path' => $paths['public'],
                'database_name' => $wanted['database'],
                'database_user' => $wanted['database_user'],
                'status' => $wanted['trial'] ? Customer::TRIAL : Customer::ACTIVE,
                'monthly_fee' => $wanted['monthly_fee'],
                'storage_limit_mb' => $wanted['storage_limit_mb'] ?? null,
                'language' => 'ckb',

                // Not today. They were trading long before the panel existed,
                // and "started" is what the Subscriptions screen counts unpaid
                // months from — dating it today would forgive everything owed.
                'started_on' => null,
                'notes' => $wanted['notes'] ?? null,
            ]);
        } catch (Throwable $e) {
            /*
             * The folder only. Never the database.
             *
             * create() takes its database back on failure because it made it.
             * This one did not: it belongs to a customer with years of trading
             * in it, and a rollback that dropped it would destroy the exact
             * thing this flow exists to preserve.
             */
            $left = [];

            if ($madeFolder) {
                foreach ([$paths['public'], $paths['home']] as $path) {
                    if (! $this->deleteFolder($path)) {
                        $left[] = "the folder [{$path}]";
                    }
                }
            }

            throw new RuntimeException(
                $e->getMessage()
                .' Their database has not been touched.'
                .($left === [] ? '' : ' Left behind: '.implode(', ', $left).'.'),
                previous: $e,
            );
        }

        Action::record('customer.taken_on', $customer, [
            'host' => $wanted['host'],
            'database' => $wanted['database'],
            'found' => $found,
            'migrations_run' => max(0, $after - $before),
            'backed_up' => $wanted['backup'],
        ]);

        if (! $wanted['trial'] && filled($wanted['licence'] ?? null)) {
            $result = $this->delivery->deliver($customer, $wanted['licence']);

            if (! $result->confirmed) {
                $warnings[] = 'The shop is taken on, and the licence did not go on: '.$result->problem;
            }
        }

        return [
            'customer' => $customer,
            'found' => $found,
            'migrations_run' => max(0, $after - $before),
            'warnings' => $warnings,
        ];
    }

    /**
     * Is this really a shop's database, with a shop's data in it?
     *
     * Read-only, through the connection that cannot write — the same guard the
     * hourly check uses. Nothing here may change a byte of it.
     *
     * @param  array<string, mixed>  $wanted
     * @return array<string, int>
     */
    private function lookAtTheirDatabase(array $wanted): array
    {
        $connection = 'takeon:'.bin2hex(random_bytes(4));

        Config::set("database.connections.{$connection}", [
            'driver' => 'mysql',
            'host' => config('panel.maker_probe.host', config('panel.database_maker.host', '127.0.0.1')),
            'port' => config('panel.maker_probe.port', '3306'),
            'database' => $wanted['database'],
            'username' => $wanted['database_user'],
            'password' => $wanted['database_password'],
            'charset' => 'utf8mb4',
            'collation' => 'utf8mb4_unicode_ci',
            'prefix' => '',
        ]);

        try {
            $live = DB::connection($connection);

            $shop = new ReadOnlyConnection(
                $live->getPdo(), $wanted['database'], $live->getTablePrefix(), $live->getConfig(),
            );

            $found = [];

            // The tables a shop system database always has. A database missing
            // them is something else entirely, and pointing a shop at it would
            // be pointing a shop at somebody's other application.
            foreach (['users', 'products', 'sales', 'settings', 'migrations'] as $table) {
                try {
                    $found[$table] = (int) $shop->selectOne("select count(*) as n from `{$table}`")->n;
                } catch (Throwable) {
                    throw new RuntimeException(
                        "[{$wanted['database']}] has no `{$table}` table, so it is not a shop system database. "
                        .'Nothing was changed.',
                    );
                }
            }

            /*
             * How many people would be locked out by a new APP_KEY.
             *
             * The shop system encrypts two_factor_secret and
             * two_factor_recovery_codes with APP_KEY. `shop:provision` writes a
             * FRESH one, and Halabja-phone's original died with the install
             * folder Section 4 had deleted — so every staff authenticator would
             * become ciphertext nothing can read, and the cast would throw on
             * sign-in rather than politely asking them to enrol again.
             */
            try {
                $found['authenticators'] = (int) $shop->selectOne(
                    'select count(*) as n from `users` where `two_factor_secret` is not null',
                )->n;
            } catch (Throwable) {
                // An older schema without the column. Nothing to lose, then.
                $found['authenticators'] = 0;
            }

            if ($found['users'] === 0) {
                throw new RuntimeException(
                    "[{$wanted['database']}] has no users in it, so nobody could sign in to the shop this "
                    .'would build. If this is a new customer, use New customer instead — that one seeds an '
                    .'administrator, and this one deliberately never does.',
                );
            }

            return $found;
        } catch (\PDOException $e) {
            /*
             * ⚠️ Before the RuntimeException arm, not after it. PHP's
             * PDOException EXTENDS RuntimeException, so a `catch (RuntimeException)`
             * placed first catches a wrong password too and rethrows the raw
             * driver text — "SQLSTATE[HY000] [1045] Access denied for user
             * 'panelmaker'@'localhost'" — which names the PANEL's database user
             * in a message about the CUSTOMER's credentials, and reads as the
             * panel being broken rather than the details being wrong.
             */
            throw new RuntimeException(
                "Could not read [{$wanted['database']}] with those credentials: ".$e->getMessage(),
            );
        } catch (RuntimeException $e) {
            // Everything above that refused on purpose. Its wording is the
            // answer; wrapping it again would bury it.
            throw $e;
        } catch (Throwable $e) {
            throw new RuntimeException(
                "Could not read [{$wanted['database']}] with those credentials: ".$e->getMessage(),
            );
        } finally {
            DB::purge($connection);
            Config::set("database.connections.{$connection}", null);
        }
    }

    /**
     * Their own backup, through their own tooling — Section 7.
     *
     * Not the panel's idea of a backup: the shop system already knows how to
     * dump itself and where its copies go, and a restore later will expect that
     * shape.
     *
     * ⚠️ **Deliberately not gated on `backup:check`.** That was the first
     * attempt, and driving it against a real shop showed why it cannot be:
     * `backup:check` diagnoses the ongoing REGIME — is an off-machine folder
     * set, have backups run before — and both are necessarily false for a
     * folder the panel built ninety seconds ago, however perfectly dumpable
     * their database is. So it refused every single take-on, which leaves the
     * operator one way forward: untick the box. A rule that can only be
     * satisfied by skipping the backup teaches people to skip backups, in the
     * one flow that most needs one.
     *
     * What matters here is narrower and testable: **a dump, now, that landed.**
     * That is `backup:run` succeeding. If their database cannot be dumped at
     * all — no mysqldump, an unwritable folder — `backup:run` is what fails,
     * and its own output says which.
     *
     * The regime is still worth knowing about, so it is asked afterwards and
     * comes back as a warning. Something for the operator to fix, not a reason
     * to refuse a migration that has already been backed up.
     *
     * @return list<string> anything worth saying afterwards
     */
    private function backUpFirst(string $artisan): array
    {
        $backup = $this->run(
            [PHP_BINARY, $artisan, 'backup:run'],
            'backing their database up before touching it',
        );

        $check = new Process([PHP_BINARY, $artisan, 'backup:check'], env: ShopEnvironment::withoutThePanel());
        $check->setTimeout(self::TIMEOUT);
        $check->run();

        $warnings = [];

        if (! $check->isSuccessful()) {
            $warnings[] = 'Their database was backed up, but their backups are not set up to keep running — '
                .'no off-machine copy, most likely, because this folder is new. Run `backup:check` on the shop '
                .'and set it up before they rely on it.';
        }

        /*
         * Where it went, said out loud. If the migration goes wrong an hour
         * from now, the first question is "where is the backup" and the answer
         * should not be somewhere in a log nobody kept.
         */
        if (preg_match('#^\s*(\S+\.(?:sql|zip|gz|sql\.gz))\s#mi', $backup, $where) === 1) {
            $warnings[] = 'Their backup is at '.$where[1].'.';
        }

        return $warnings;
    }

    /** How many migrations the shop has run, so the difference can be reported. */
    private function migrationsAlreadyRun(string $artisan): int
    {
        $process = new Process([PHP_BINARY, $artisan, 'migrate:status'], env: ShopEnvironment::withoutThePanel());
        $process->setTimeout(self::TIMEOUT);
        $process->run();

        return preg_match_all('/\bRan\s*$/m', $process->getOutput());
    }

    /**
     * Nothing may be created over the top of something that is already there.
     *
     * @param  array{home: string, public: string}  $paths
     */
    private function refuseIfAnythingIsInTheWay(array $paths, string $host): void
    {
        if (Customer::withTrashed()->where('host', $host)->exists()) {
            throw new RuntimeException("There is already a customer on {$host}.");
        }

        foreach (['home' => 'shop folder', 'public' => 'public folder'] as $key => $what) {
            if (file_exists($paths[$key])) {
                throw new RuntimeException(
                    "The {$what} [{$paths[$key]}] already exists. Nothing was created — "
                    .'move it aside first, or use a different short name.',
                );
            }
        }
    }

    /**
     * @param  array{home: string, public: string}  $paths
     * @param  array<string, mixed>  $wanted
     */
    private function provision(string $short, array $paths, array $wanted, string $database, string $user, string $password): void
    {
        $command = [
            PHP_BINARY, $this->sharedArtisan(), 'shop:provision', $short,
            '--home='.$paths['home'],
            '--public='.$paths['public'],
            '--url=https://'.$wanted['host'],
            '--shop-name='.$wanted['name'],
            '--db-database='.$database,
            '--db-username='.$user,
            '--db-password='.$password,
            '--storage-limit='.($wanted['storage_limit_mb'] ?? ''),
        ];

        // PANEL_DOC Section 6: a trial is what blanks LICENCE_PUBLIC_KEY in the
        // shop's own .env, and that is the only thing that makes a shop
        // actually run unlicensed rather than read-only from its first minute.
        if ($wanted['trial']) {
            $command[] = '--trial';
        }

        $this->run($command, 'making the shop’s folder');
    }

    /**
     * The tables, the permissions, the settings, the counters, the Cash
     * Customer, and one administrator.
     *
     * @return array{email: string, password: string}
     */
    private function fillTheDatabase(string $home, string $shopName): array
    {
        $artisan = rtrim($home, '/').'/artisan';

        $this->run([PHP_BINARY, $artisan, 'migrate', '--force'], 'running the shop’s migrations');

        $seeded = $this->run([PHP_BINARY, $artisan, 'db:seed', '--force'], 'seeding the shop');

        // The seeder prints the administrator it made, once. If that ever stops
        // being printed, Soran gets a shop nobody can sign in to — so it is
        // read back rather than assumed, and said out loud if it is missing.
        preg_match('/account:\s*(\S+@\S+)/i', $seeded, $email);
        preg_match('/Password:\s*(\S+)/i', $seeded, $password);

        if (! isset($email[1], $password[1])) {
            throw new RuntimeException(
                'The shop was seeded and it did not print an administrator to sign in as. '
                .'Its own output was: '.mb_substr(trim($seeded), -300),
            );
        }

        return ['email' => $email[1], 'password' => $password[1]];
    }

    /**
     * Undo exactly what this run made.
     *
     * @param  array{database: bool, folder: bool}  $made
     * @param  array{home: string, public: string}  $paths
     * @return list<string> what could not be taken back
     */
    private function rollBack(array $made, string $database, string $user, array $paths): array
    {
        $left = [];

        if ($made['folder']) {
            foreach ([$paths['public'], $paths['home']] as $path) {
                if (! $this->deleteFolder($path)) {
                    $left[] = "the folder [{$path}]";
                }
            }
        }

        if ($made['database']) {
            $left = [...$left, ...$this->databases->drop($database, $user)];
        }

        return $left;
    }

    private function deleteFolder(string $path): bool
    {
        if (! is_dir($path)) {
            return true;
        }

        /*
         * Only under a folder this panel was told to use. A bug in the path
         * building must not be able to hand a recursive delete a shorter path
         * than it meant to.
         *
         * BOTH roots, because Section 4 forced them apart: a shop's private
         * folder is outside public_html and its public folder must be inside
         * it, so they are two different trees. The first version checked only
         * the shops root, which quietly left every rolled-back shop's public
         * folder standing — a folder that looks provisioned is one somebody
         * later points a domain at, which is the exact thing rolling back is
         * for.
         */
        $roots = array_filter([
            rtrim((string) config('panel.shops.home_root'), '/'),
            rtrim((string) config('panel.shops.public_root'), '/'),
        ]);

        $real = realpath($path) ?: $path;

        $allowed = false;

        foreach ($roots as $root) {
            if (str_starts_with($real, $root.'/')) {
                $allowed = true;
            }
        }

        if (! $allowed) {
            return false;
        }

        $entries = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($path, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST,
        );

        foreach ($entries as $entry) {
            $entry->isDir() && ! $entry->isLink() ? @rmdir($entry->getPathname()) : @unlink($entry->getPathname());
        }

        return @rmdir($path);
    }

    /** @return array{home: string, public: string} */
    private function paths(string $short): array
    {
        return [
            'home' => rtrim((string) config('panel.shops.home_root'), '/').'/'.$short,
            'public' => rtrim((string) config('panel.shops.public_root'), '/').'/'.$short,
        ];
    }

    private function sharedArtisan(): string
    {
        $path = (string) config('panel.shops.shared_artisan');

        if ($path === '' || ! is_file($path)) {
            throw new RuntimeException(
                "The shop system's artisan is not at [{$path}]. Set PANEL_SHARED_ARTISAN in the panel's .env "
                .'to the shared codebase, or no shop can be created.',
            );
        }

        return $path;
    }

    /** @param  list<string>  $command */
    private function run(array $command, string $what): string
    {
        $process = new Process($command, env: ShopEnvironment::withoutThePanel());
        $process->setTimeout(self::TIMEOUT);
        $process->run();

        if (! $process->isSuccessful()) {
            throw new RuntimeException(sprintf(
                'Failed while %s: %s', $what,
                mb_substr(trim($process->getErrorOutput() ?: $process->getOutput()) ?: 'it said nothing at all', -400),
            ));
        }

        return $process->getOutput();
    }
}
