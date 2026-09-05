<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;
use Throwable;

use function Laravel\Prompts\password;
use function Laravel\Prompts\text;

/**
 * Fill in the `.env` by being asked, rather than by editing it.
 *
 * `panel:check` says what is wrong. This is the other half: it asks for the
 * handful of things only the account owner knows, tests each one AS IT IS
 * GIVEN, and writes them.
 *
 * Written after watching a real deploy. The failures were never conceptual —
 * they were a password with a character the shell ate, a cPanel prefix guessed
 * at instead of read off the page, and a database name that came from the
 * template and looked plausible enough that `Access denied` read as a broken
 * panel rather than an unedited setting. Every one of those costs a round trip
 * to somebody who can read the error, and none of them is interesting.
 *
 * So: nothing here is typed into a file, nothing is quoted by hand, and a
 * database credential that does not work is rejected while the person who
 * knows the right one is still sitting there.
 */
class PanelSetup extends Command
{
    protected $signature = 'panel:setup';

    protected $description = 'Ask for what this panel needs and write it into the .env';

    public function handle(): int
    {
        $path = base_path('.env');

        if (! is_file($path)) {
            $this->components->error('There is no .env here. Run `cp .env.example .env` first, then this.');

            return self::FAILURE;
        }

        $this->newLine();
        $this->components->info('What this panel needs. Nothing is written until the end.');
        $this->newLine();

        $set = [];

        // ---- Its own database ------------------------------------------------
        //
        // Asked first and tested immediately, because everything else the panel
        // does is behind it, and because the template ships a plausible-looking
        // name that is nobody's.

        $this->line(' <fg=gray>From cPanel → MySQL Databases. Copy the names exactly, prefix and all.</>');

        while (true) {
            /*
             * A placeholder, not a default. Laravel Prompts pre-fills a default
             * INTO the input, and the template's database name is a guess that
             * is wrong on every account — so a default there means everyone
             * must delete it before typing, and anyone who presses Enter gets
             * the exact stale value that made `Access denied` read as a broken
             * panel. The user CAN default to the database, because naming them
             * the same is what cPanel encourages and Enter is then the right
             * answer.
             */
            $database = text(
                'The panel’s database',
                placeholder: (string) config('database.connections.mysql.database'),
                required: true,
                hint: 'The full name cPanel shows, prefix and all.',
            );

            $username = text('Its user', default: $database, required: true);
            $secret = password('Its password', required: true);

            $problem = $this->cannotConnect($database, $username, $secret);

            if ($problem === null) {
                $this->components->info("Connected to [{$database}].");
                break;
            }

            $this->components->error($problem);
            $this->line(' <fg=gray>Nothing has been written. Check the name, the user, and that the user is</>');
            $this->line(' <fg=gray>added to the database with ALL PRIVILEGES, then try again.</>');
            $this->newLine();
        }

        $set['DB_DATABASE'] = $database;
        $set['DB_USERNAME'] = $username;
        $set['DB_PASSWORD'] = $secret;

        // ---- The cPanel prefix ----------------------------------------------
        //
        // cPanel puts the account name in front of every database and user it
        // makes. The panel has to record the name a shop will really connect
        // to, so a wrong prefix means a shop the panel cannot read again.

        $this->newLine();

        $guess = $this->prefixFrom($database);

        $set['PANEL_CPANEL_PREFIX'] = text(
            'The cPanel prefix',
            placeholder: $guess ?: 'soransto',
            default: $guess,
            required: true,
            hint: 'cPanel makes soransto_bazaar when asked for bazaar. Just the part before the underscore.',
        );

        // ---- A way in --------------------------------------------------------

        $this->newLine();
        $this->line(' <fg=gray>There is no sign-up page and no password-reset email, so this is the only way in.</>');

        $set['PANEL_ADMIN_NAME'] = text('Your name', default: (string) config('panel.first_operator.name', 'Soran'), required: true);
        $set['PANEL_ADMIN_EMAIL'] = text('Your email', required: true, validate: fn (string $v) => filter_var($v, FILTER_VALIDATE_EMAIL) ? null : 'That is not an email address.');
        $set['PANEL_ADMIN_PASSWORD'] = password('A password for it', required: true, validate: fn (string $v) => mb_strlen($v) >= 12 ? null : 'At least twelve characters — this one is reachable from the internet.');

        // ---- Where it will be ------------------------------------------------

        $this->newLine();

        $set['APP_URL'] = text(
            'The panel’s address',
            default: (string) config('app.url'),
            required: true,
            validate: fn (string $v) => str_starts_with($v, 'https://') ? null : 'It must start with https:// — sessions would otherwise travel in the open.',
        );

        // ---- Write ------------------------------------------------------------

        $this->write($path, $set);

        /*
         * ⚠️ A cached config reads the old file for ever.
         *
         * DEPLOY.md's last step is `config:cache`, so by the time anyone runs
         * this a second time — a moved database, a changed password — the
         * answers they just typed would be written correctly and then ignored,
         * with `panel:check` still reporting the old value and nothing on
         * screen to explain the disagreement.
         *
         * This is the same trap the panel already clears for a shop after
         * delivering a licence. It clears it for itself here.
         */
        $this->callSilently('config:clear');

        $this->newLine();
        $this->components->info('Written to .env.');
        $this->newLine();
        $this->line(' Next, in this order:');
        $this->line('   <fg=green>php artisan migrate --force</>     <fg=gray>the panel’s tables</>');
        $this->line('   <fg=green>php artisan db:seed --force</>     <fg=gray>you, so you can sign in</>');
        $this->line('   <fg=green>php artisan panel:check</>         <fg=gray>what is still missing</>');
        $this->newLine();

        return self::SUCCESS;
    }

    /**
     * Can these credentials actually open that database?
     *
     * On a connection of its own, so a failure leaves the configured one alone
     * and a second attempt is not talking to a cached handle from the first.
     */
    private function cannotConnect(string $database, string $username, string $secret): ?string
    {
        $name = 'setup:'.bin2hex(random_bytes(4));

        Config::set("database.connections.{$name}", [
            ...(array) config('database.connections.mysql'),
            'database' => $database,
            'username' => $username,
            'password' => $secret,
        ]);

        try {
            DB::connection($name)->select('select 1');

            return null;
        } catch (Throwable $e) {
            return $e->getMessage();
        } finally {
            DB::purge($name);
            Config::set("database.connections.{$name}", null);
        }
    }

    /**
     * The prefix, read off a database name that already carries it.
     *
     * They have just typed `soransto_panel`, and the answer to the next
     * question is sitting in it. Offered rather than assumed — a name without
     * an underscore says nothing, and guessing there would be worse than
     * asking.
     */
    private function prefixFrom(string $database): string
    {
        $current = (string) config('panel.cpanel.prefix', '');

        if ($current !== '') {
            return $current;
        }

        return str_contains($database, '_') ? explode('_', $database, 2)[0] : '';
    }

    /**
     * @param  array<string, string>  $set
     *
     * Replaces the line if it is there and appends it if it is not — never both,
     * because Laravel's dotenv is immutable: the FIRST definition of a key wins,
     * so a duplicate appended at the end is silently ignored and the setting
     * appears not to have been saved.
     *
     * Written through a temporary file and renamed, so an interrupted write
     * cannot leave the panel with half an .env and no way in.
     */
    private function write(string $path, array $set): void
    {
        $env = (string) file_get_contents($path);

        foreach ($set as $key => $value) {
            $line = $key.'='.$this->quote($value);
            $pattern = '/^'.preg_quote($key, '/').'=.*$/m';

            $env = preg_match($pattern, $env) === 1
                ? (string) preg_replace($pattern, str_replace('\\', '\\\\', $line), $env, 1)
                : rtrim($env, "\n")."\n".$line."\n";
        }

        $temporary = $path.'.setup-'.bin2hex(random_bytes(6));

        file_put_contents($temporary, $env);
        @chmod($temporary, fileperms($path) & 0777);
        rename($temporary, $path);
    }

    /**
     * ⚠️ How a value is quoted decides whether the .env parses at all.
     *
     * A DOUBLE-quoted value has its escape sequences processed, so a password
     * containing `\s` or `\p` is not merely read wrongly — dotenv rejects the
     * WHOLE FILE, and the panel loses every setting at once. That has already
     * happened here, to a Windows path in .env.example, and CI is what caught
     * it. A generated password is exactly the kind of thing that contains a
     * backslash.
     *
     * So single quotes are preferred: inside them dotenv treats everything as
     * literal — no escapes, no `${…}` interpolation. What they cannot hold is
     * an apostrophe, and unlike a shell there is no way to escape one. Such a
     * value goes in double quotes with its backslashes and quotes escaped.
     *
     * `${` is the one shape neither can carry: it interpolates inside double
     * quotes and there is nothing to escape it with. Rather than write a file
     * that silently reads back as something else, that is refused out loud.
     */
    private function quote(string $value): string
    {
        if ($value === '' || preg_match('/^[A-Za-z0-9_.\/:@-]+$/', $value) === 1) {
            return $value;
        }

        if (! str_contains($value, "'")) {
            return "'".$value."'";
        }

        if (str_contains($value, '${')) {
            throw new \RuntimeException(
                'That value contains both an apostrophe and `${`, and an .env file cannot hold both — '
                .'the second would be read as another setting’s value. Change it to something without one of them.',
            );
        }

        return '"'.str_replace(['\\', '"'], ['\\\\', '\\"'], $value).'"';
    }
}
