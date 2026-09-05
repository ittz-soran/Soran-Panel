<?php

namespace App\Console\Commands;

use App\Contracts\DatabaseMaker;
use App\Contracts\DnsMaker;
use App\Contracts\DomainMaker;
use App\Models\Customer;
use App\Models\HealthCheck;
use App\Models\User;
use App\Services\CpanelDatabaseMaker;
use App\Support\HomeFolder;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
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
        $this->pointingDomains();
        $this->publishingNames();
        $this->theSellersKey();
        $this->theLook();
        $this->notServingItsOwnSecrets();
        $this->readyToBeUsedInAnger();

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
            : sprintf('Everything this panel needs is here. %d %s worth reading above.',
                $this->worthKnowing, $this->worthKnowing === 1 ? 'thing' : 'things'));

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

    /**
     * Who points a shop's domain at its folder.
     *
     * A warning rather than a fault when the panel does not do it: a shop
     * without its domain pointed is finished except for one step somewhere
     * else, and calling that broken would be wrong. But it is worth saying on
     * every check, because the manual version is where a night went — cPanel's
     * document root field is relative to the home folder, and an absolute path
     * put there serves the domain from a folder that does not exist.
     */
    /**
     * Who publishes a shop's name.
     *
     * A warning when it is manual, like pointing the domain: a shop whose name
     * is not published is finished except for one step somewhere else. What
     * this must catch is the half-configured case — set to publish, and missing
     * the address or the credentials, which would fail in the middle of making
     * a customer rather than here.
     */
    private function publishingNames(): void
    {
        $maker = app(DnsMaker::class);

        $this->check('Publishing a shop’s name', function () use ($maker) {
            if (! $maker->isAutomatic()) {
                return ['warn', $maker->describe()];
            }

            $address = (string) config('panel.dns.address');

            if ($address === '') {
                throw new \RuntimeException(
                    'PANEL_SERVER_IP is not set, so the panel does not know what to point records at',
                );
            }

            if (filter_var($address, FILTER_VALIDATE_IP) === false) {
                throw new \RuntimeException("[{$address}] is not an IP address");
            }

            if ((string) config('panel.dns.cloudflare.token') === '') {
                throw new \RuntimeException('PANEL_CLOUDFLARE_TOKEN is not set');
            }

            if ((string) config('panel.dns.cloudflare.zone_id') === '') {
                throw new \RuntimeException('PANEL_CLOUDFLARE_ZONE_ID is not set');
            }

            // And ask Cloudflare, rather than only reading the settings: a
            // wrong or expired token looks identical in a config file.
            return ['ok', $maker->verify().", pointing at [{$address}]"];
        }, $maker->isAutomatic()
            ? 'Set PANEL_SERVER_IP, PANEL_CLOUDFLARE_TOKEN and PANEL_CLOUDFLARE_ZONE_ID.'
            : 'Set PANEL_DNS_MAKER=cloudflare to have the panel publish names itself — and read the note in '
              .'config/panel.php first, because that token can repoint every domain you own.');
    }

    private function pointingDomains(): void
    {
        $maker = app(DomainMaker::class);

        $this->check('Pointing a shop’s domain', function () use ($maker) {
            if (! $maker->isAutomatic()) {
                return ['warn', $maker->describe().' — in cPanel the Document Root is RELATIVE to your '
                    .'home folder, so type public_html/<short>, never the full path'];
            }

            $uapi = (string) config('panel.cpanel.uapi');

            if (! is_file($uapi)) {
                throw new \RuntimeException("cPanel’s uapi is not at [{$uapi}]");
            }

            $home = HomeFolder::find();
            $public = rtrim((string) config('panel.shops.public_root'), '/');

            if ($home === '') {
                throw new \RuntimeException(
                    'the home folder is not known, and cPanel wants the document root relative to it',
                );
            }

            if (! str_starts_with($public.'/', $home.'/')) {
                throw new \RuntimeException(
                    "[{$public}] is not inside [{$home}], so cPanel cannot serve a shop from it",
                );
            }

            return ['ok', $maker->describe().", document roots under [{$home}]"];
        }, $maker->isAutomatic()
            ? 'Set PANEL_UAPI, and PANEL_CPANEL_HOME if the home folder above is not the account’s.'
            : 'Set PANEL_DOMAIN_MAKER=cpanel on the server to have the panel point domains itself.');
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
            if (is_file(public_path('build/manifest.json'))) {
                return ['ok', 'public/build is in place'];
            }

            /*
             * Missing assets are a fault on a server and ordinary anywhere else.
             *
             * Section 10: public/build is the shop system's compiled output,
             * copied in at DEPLOY time, and .gitignore keeps it out of the
             * repository — so it is legitimately absent on a fresh clone and in
             * CI. On a server it means every screen serves unstyled, which is
             * the panel looking broken rather than undeployed.
             *
             * This is the same split as APP_DEBUG above, and it was CI that
             * asked for it: five checks failed there on a machine that was
             * never going to have the assets.
             */
            if (config('app.env') === 'production') {
                throw new \RuntimeException(
                    'public/build is not there, so every screen would serve unstyled',
                );
            }

            return ['warn', 'public/build is not there — expected on a fresh clone, and copied in at '
                .'deploy time. On a server this is a failure.'];
        }, 'Copy the shop system’s compiled public/build into this panel’s public/. Section 10: the panel has '
           .'no stylesheet of its own and no npm build.');
    }

    /**
     * Whether the panel's own `.env` could be fetched with a browser.
     *
     * Section 4 records finding exactly this on a real customer's install:
     * Halabja-phone was serving its `.env` and `laravel.log` to anyone. The
     * panel's `.env` is worse than one shop's — it holds the database with
     * every customer, licence and payment, the admin password, and the account
     * that may create and drop databases.
     *
     * Two things make that safe, and this asks for both: the deny-all
     * `.htaccess` beside the code, and the code not being under a public folder
     * in the first place.
     */
    private function notServingItsOwnSecrets(): void
    {
        $this->check('Its own .env is not on the web', function () {
            if (! is_file(base_path('.htaccess'))) {
                throw new \RuntimeException(
                    'there is no deny-all .htaccess beside the code — if this folder is inside public_html, '
                    .'.env is a URL away',
                );
            }

            if (! str_contains((string) file_get_contents(base_path('.htaccess')), 'denied')) {
                throw new \RuntimeException('the .htaccess beside the code does not deny anything');
            }

            // Being outside a public folder is the real protection; the
            // .htaccess is only the net for when it is not.
            $base = base_path();
            $inPublic = (bool) preg_match('#/public_html(/|$)#', $base);

            return [$inPublic ? 'warn' : 'ok', $inPublic
                ? "the panel's code is inside public_html [{$base}] — the .htaccess is denying it, but "
                    .'`panel:public` and a folder outside is the arrangement that does not depend on Apache'
                : 'the code is outside public_html, and the .htaccess is there as well'];
        }, 'Move the panel outside public_html and run `php artisan panel:public <folder inside public_html>`.');
    }

    /**
     * The handful of things that are fine on a laptop and wrong on a server.
     *
     * Kept apart from the settings above because these are about the machine
     * rather than about what the panel can reach — and because every one of
     * them is the right answer locally and the wrong one in production.
     */
    private function readyToBeUsedInAnger(): void
    {
        $isServer = config('app.env') === 'production';

        $this->check($isServer ? 'Ready to be used in anger' : 'Not set up as a server (which is fine here)', function () use ($isServer) {
            $wrong = [];

            // Only ever wrong. An unwritable storage folder breaks a laptop
            // exactly as thoroughly as it breaks a server.
            if ((string) config('app.key') === '') {
                $wrong[] = 'APP_KEY is empty, so nothing encrypted can be read back';
            }

            foreach (['storage', 'bootstrap/cache'] as $writable) {
                if (! is_writable(base_path($writable))) {
                    $wrong[] = "[{$writable}] cannot be written to";
                }
            }

            if ($wrong !== []) {
                throw new \RuntimeException(implode('; ', $wrong));
            }

            /*
             * And the ones that depend on where this is.
             *
             * APP_DEBUG on is the right answer on a laptop and a hole on a
             * server, so which of those this is decides whether these are
             * failures or just worth saying. A check that goes red on a
             * developer's machine for being a developer's machine is a check
             * they stop reading.
             */
            $forAServer = [];

            if (config('app.debug')) {
                $forAServer[] = 'APP_DEBUG is on, so a crash shows the .env on screen';
            }

            if (! str_starts_with((string) config('app.url'), 'https://')) {
                $forAServer[] = 'APP_URL is not https — sessions would travel in the open';
            }

            if (! $isServer) {
                return ['warn', 'APP_ENV is ['.config('app.env').'], so this is not being judged as a server. '
                    .'Before deploying: '.(
                        $forAServer === [] ? 'set APP_ENV=production' : implode('; ', $forAServer)
                    )];
            }

            if ($forAServer !== []) {
                throw new \RuntimeException(implode('; ', $forAServer));
            }

            // The hourly check only runs if cron is calling schedule:run.
            $lastCheck = HealthCheck::max('checked_at');

            if (Customer::live()->exists()) {
                if ($lastCheck === null) {
                    return ['warn', 'no shop has ever been checked — is cron calling `schedule:run`?'];
                }

                $ago = Carbon::parse($lastCheck);

                if ($ago->lt(now()->subHours(3))) {
                    return ['warn', 'the last health check was '.$ago->diffForHumans()
                        .' — the schedule asks hourly, so cron may not be running'];
                }
            }

            return ['ok', 'debug off, key set, https, folders writable'];
        }, 'Set APP_DEBUG=false, APP_ENV=production and an https APP_URL; run `php artisan key:generate` if '
           .'the key is empty; and add the cron entry that calls `schedule:run` every minute.');
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
