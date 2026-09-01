<?php

namespace App\Services;

use App\Contracts\DatabaseMaker;
use App\Models\Action;
use App\Models\Customer;
use App\Support\ShopEnvironment;
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
