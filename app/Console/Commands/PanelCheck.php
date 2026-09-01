<?php

namespace App\Console\Commands;

use App\Contracts\DatabaseMaker;
use App\Models\User;
use App\Services\CpanelDatabaseMaker;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * Whether this panel can actually do its job here.
 *
 * The panel depends on several things outside itself — a shared codebase to run
 * `shop:provision` through, two folders to put shops in, a database account
 * that may create databases, and the seller's public key. Every one of them is
 * a setting, and every one of them is wrong by default on a machine that has
 * just cloned this.
 *
 * Without this command, a wrong setting shows up as a failure in the middle of
 * creating a customer — after a database has been made and rolled back, with a
 * message about whichever step happened to hit it first. This asks all of them
 * up front and says which one is wrong and what to put in the `.env`.
 *
 * Written because the panel is developed and tested on a local machine and runs
 * on cPanel, and the two need different answers to nearly all of it.
 */
class PanelCheck extends Command
{
    protected $signature = 'panel:check';

    protected $description = 'Say whether this panel can create and read shops here';

    /** Things that will stop the panel working. */
    private int $wrong = 0;

    /** Things worth knowing that will not stop it. */
    private int $worthKnowing = 0;

    public function handle(): int
    {
        $this->newLine();
        $this->components->info('Checking what this panel needs.');
        $this->newLine();

        $this->itsOwnDatabase();
        $this->somebodyToSignIn();
        $this->theSharedCodebase();
        $this->whereShopsGo();
        $this->makingDatabases();
        $this->theSellersKey();
        $this->theLook();

        $this->newLine();

        if ($this->wrong > 0) {
            $this->components->error(sprintf(
                '%d %s wrong. Nothing looks broken until you try to use it, which is the worst time to find out.',
                $this->wrong,
                $this->wrong === 1 ? 'thing is' : 'things are',
            ));

            return self::FAILURE;
        }

        // A warning does not fail the command. Something being worth saying is
        // not the same as something being set up wrongly, and a check that goes
        // red for both teaches you to ignore it.
        $this->components->info($this->worthKnowing === 0
            ? 'Everything this panel needs is here.'
            : 'Everything this panel needs is here. '.$this->worthKnowing.' thing worth reading above.');

        return self::SUCCESS;
    }

    private function itsOwnDatabase(): void
    {
        $this->check('The panel’s own database', function () {
            $name = DB::connection()->getDatabaseName();
            DB::connection()->select('select 1');

            return ['ok', $name];
        }, 'Set DB_DATABASE, DB_USERNAME and DB_PASSWORD, then run `php artisan migrate`.');
    }

    private function somebodyToSignIn(): void
    {
        $this->check('Somebody who can sign in', function () {
            $operators = User::where('is_active', true)->count();

            if ($operators === 0) {
                throw new \RuntimeException('no active operator');
            }

            $withAuthenticator = User::whereNotNull('two_factor_confirmed_at')->count();

            return [$withAuthenticator > 0 ? 'ok' : 'warn', sprintf(
                '%d %s, %d with an authenticator',
                $operators, $operators === 1 ? 'operator' : 'operators', $withAuthenticator,
            )];
        }, 'Set PANEL_ADMIN_EMAIL and PANEL_ADMIN_PASSWORD, then run `php artisan db:seed`. '
           .'There is no sign-up page, so this is the only way in.');
    }

    private function theSharedCodebase(): void
    {
        $this->check('The shop system’s artisan', function () {
            $path = (string) config('panel.shops.shared_artisan');

            if ($path === '' || ! is_file($path)) {
                throw new \RuntimeException("not at [{$path}]");
            }

            // It has to be the shop system's, not some other Laravel — the one
            // that carries shop:provision.
            $listed = shell_exec(escapeshellcmd(PHP_BINARY).' '.escapeshellarg($path).' list 2>&1') ?: '';

            if (! str_contains($listed, 'shop:provision')) {
                throw new \RuntimeException("[{$path}] has no shop:provision — is it the shop system?");
            }

            return ['ok', $path];
        }, 'Set PANEL_SHARED_ARTISAN to the shared codebase’s artisan, e.g. /home/soransto/smart-store/artisan. '
           .'New customer cannot work without it.');
    }

    private function whereShopsGo(): void
    {
        foreach ([
            'Where shops live' => ['panel.shops.home_root', 'PANEL_SHOPS_HOME'],
            'Where their public folders go' => ['panel.shops.public_root', 'PANEL_SHOPS_PUBLIC'],
        ] as $label => [$key, $env]) {
            $this->check($label, function () use ($key) {
                $path = rtrim((string) config($key), '/');

                if ($path === '') {
                    throw new \RuntimeException('not set');
                }

                if (! is_dir($path)) {
                    throw new \RuntimeException("[{$path}] is not there");
                }

                if (! is_writable($path)) {
                    throw new \RuntimeException("[{$path}] cannot be written to");
                }

                return ['ok', $path];
            }, "Set {$env} to a folder that exists and can be written to.");
        }
    }

    private function makingDatabases(): void
    {
        $maker = app(DatabaseMaker::class);
        $isCpanel = $maker instanceof CpanelDatabaseMaker;

        $this->check('Making a shop’s database', function () use ($isCpanel) {
            if ($isCpanel) {
                $uapi = (string) config('panel.cpanel.uapi');

                if (! is_file($uapi)) {
                    throw new \RuntimeException("cPanel’s uapi is not at [{$uapi}]");
                }

                $prefix = (string) config('panel.cpanel.prefix');

                return [$prefix === '' ? 'warn' : 'ok', $prefix === ''
                    ? "uapi at [{$uapi}], and PANEL_CPANEL_PREFIX is empty — cPanel prefixes every "
                        .'database with the account name, so this must be set'
                    : "uapi at [{$uapi}], names prefixed [{$prefix}_]"];
            }

            // Plain SQL. Ask the connection whether it may really create one,
            // rather than trusting that it can.
            $connection = (string) config('panel.database_maker.connection');
            $driver = DB::connection($connection)->getDriverName();

            if (! in_array($driver, ['mysql', 'mariadb'], true)) {
                // The suite runs on SQLite, and so can a first look at the
                // panel. Nothing here can create a shop's database, and saying
                // that plainly beats a red cross for a machine that was never
                // going to make one.
                return ['warn', "the [{$connection}] connection is {$driver}, not MySQL — "
                    .'no shop database can be made through it'];
            }

            $grants = DB::connection($connection)->select('show grants for current_user()');
            $text = strtoupper(json_encode($grants) ?: '');

            if (! str_contains($text, 'ALL PRIVILEGES') && ! str_contains($text, 'CREATE')) {
                throw new \RuntimeException(
                    "the [{$connection}] connection may not CREATE DATABASE — a new customer would fail half-made",
                );
            }

            return ['ok', "plain SQL over the [{$connection}] connection"];
        }, $isCpanel
            ? 'Set PANEL_UAPI and PANEL_CPANEL_PREFIX.'
            : 'Set PANEL_DATABASE_MAKER_CONNECTION and the PANEL_MAKER_* credentials to an account that may '
              .'CREATE DATABASE. On the real cPanel host set PANEL_DATABASE_MAKER=cpanel instead — Section 4: '
              .'plain CREATE DATABASE is denied there.');
    }

    private function theSellersKey(): void
    {
        $this->check('The seller’s public key', function () {
            $key = trim((string) config('licence.public_key'));

            if ($key === '') {
                throw new \RuntimeException('not set — no licence could ever be verified');
            }

            if (str_contains($key, 'PRIVATE')) {
                throw new \RuntimeException('this is a PRIVATE key. It must never be on this server.');
            }

            $armoured = "-----BEGIN PUBLIC KEY-----\n".chunk_split(preg_replace('/\s+/', '', $key), 64, "\n")
                .'-----END PUBLIC KEY-----';

            if (openssl_pkey_get_public($armoured) === false) {
                throw new \RuntimeException('openssl will not read it');
            }

            return ['ok', 'readable, and it is the public half'];
        }, 'Set LICENCE_PUBLIC_KEY to the same key the shop system ships. If the two differ, the panel accepts '
           .'a licence the customer’s shop then rejects.');
    }

    private function theLook(): void
    {
        $this->check('The borrowed stylesheet', function () {
            if (! is_file(public_path('build/manifest.json'))) {
                throw new \RuntimeException('public/build is not there');
            }

            return ['ok', 'public/build is in place'];
        }, 'Copy the shop system’s compiled public/build into this panel’s public/. Section 10: the panel has '
           .'no stylesheet of its own and no npm build.');
    }

    /**
     * @param  callable(): array{string, string}  $ask
     */
    private function check(string $label, callable $ask, string $howToFix): void
    {
        try {
            [$state, $detail] = $ask();
        } catch (Throwable $e) {
            $this->wrong++;
            $this->components->twoColumnDetail("<fg=red>✗</> {$label}", '<fg=red>'.$e->getMessage().'</>');
            $this->line('    <fg=gray>'.$howToFix.'</>');
            $this->newLine();

            return;
        }

        if ($state === 'warn') {
            $this->worthKnowing++;
            $this->components->twoColumnDetail("<fg=yellow>!</> {$label}", "<fg=yellow>{$detail}</>");
            $this->line('    <fg=gray>'.$howToFix.'</>');
            $this->newLine();

            return;
        }

        $this->components->twoColumnDetail("<fg=green>✓</> {$label}", "<fg=gray>{$detail}</>");
    }
}
