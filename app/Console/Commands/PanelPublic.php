<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\File;

/**
 * The panel's public folder, for hosting that will not let a document root
 * leave `public_html` — PANEL_DOC Section 4.
 *
 * "Document roots cannot leave `public_html`. Tested: cPanel silently created
 * its own folder inside `public_html` and ignored the path typed in." So the
 * panel cannot simply point `panel.soranstore.com` at its own `public/`. Its
 * code lives outside, and the six files the web is allowed to see are copied
 * to a folder inside `public_html` — exactly the arrangement every shop
 * already has, and for the same reason.
 *
 * The one thing that has to change in the copy is `index.php`. Laravel's own
 * reaches its application with `__DIR__.'/../vendor/autoload.php'`, and once
 * the folder has moved, `..` is `public_html`. So the generated one names the
 * panel's base path absolutely.
 *
 * Run it again after every deploy: `build/` is the shop system's compiled
 * assets (Section 10) and the panel has no npm build of its own, so a stale
 * copy here is a panel whose stylesheet does not match its markup.
 */
class PanelPublic extends Command
{
    protected $signature = 'panel:public
                            {path : The folder the domain points at, e.g. /home/soransto/public_html/panel}
                            {--force : Overwrite the files this would write, if they are already there}';

    protected $description = 'Write the panel’s public folder where the domain can reach it';

    public function handle(): int
    {
        $target = rtrim($this->argument('path'), '/');
        $base = base_path();

        if ($target === '') {
            $this->components->error('Give the folder the domain points at.');

            return self::FAILURE;
        }

        if (realpath($target) === realpath(public_path())) {
            $this->components->error(
                'That is the panel’s own public/ folder. This command is for hosting where the document root '
                .'cannot be pointed at it — give the folder inside public_html instead.',
            );

            return self::FAILURE;
        }

        if (! is_dir($target) && ! @mkdir($target, 0755, true)) {
            $this->components->error("Could not make [{$target}].");

            return self::FAILURE;
        }

        /*
         * Never over the top of something that is not ours. A folder with a
         * customer's shop in it, or somebody's website, is not a place to
         * scatter an index.php.
         *
         * ⚠️ But cPanel's own leavings are not somebody's website. Creating the
         * subdomain CREATES this folder — that step has to come first, because
         * the domain must point somewhere — and cPanel leaves `cgi-bin` in it,
         * with Let's Encrypt later adding `.well-known`. Counted as strangers,
         * they refused every panel that had a domain pointed at it, which is
         * every panel; and having got past that, a folder holding only those
         * still read as "already has a panel in it".
         *
         * The same mistake was made in the shop provisioner and in the shop
         * system's own `shop:provision`. This was the third place, and the one
         * the deploy would have hit first.
         */
        $cpanelsOwn = ['cgi-bin', '.well-known'];

        $existing = array_diff((array) scandir($target), ['.', '..'], $cpanelsOwn);
        $ours = ['index.php', '.htaccess', 'build', 'favicon.ico', 'robots.txt'];
        $strangers = array_diff($existing, $ours);

        if ($strangers !== [] && ! $this->option('force')) {
            $this->components->error(sprintf(
                'There are things in [%s] this did not put there: %s. Move them aside first.',
                $target, implode(', ', $strangers),
            ));

            return self::FAILURE;
        }

        if ($existing !== [] && ! $this->option('force')) {
            $this->components->warn("[{$target}] already has a panel in it. Use --force to write over it.");

            return self::FAILURE;
        }

        File::put($target.'/index.php', $this->indexPhp($base));

        foreach (['.htaccess', 'favicon.ico', 'robots.txt'] as $file) {
            if (is_file(public_path($file))) {
                File::copy(public_path($file), $target.'/'.$file);
            }
        }

        // Section 10: the look is the shop system's compiled build/, copied in
        // at deploy time. The panel has no stylesheet of its own.
        if (is_dir(public_path('build'))) {
            File::deleteDirectory($target.'/build');
            File::copyDirectory(public_path('build'), $target.'/build');
        } else {
            $this->components->warn(
                'public/build is not there, so the panel will serve unstyled HTML. Copy the shop system’s '
                .'compiled public/build into this panel’s public/ first, then run this again.',
            );
        }

        $this->newLine();
        $this->components->info("The panel's public folder is written to [{$target}].");
        $this->components->twoColumnDetail('Point the domain at', $target);
        $this->components->twoColumnDetail('It will reach the panel at', $base);
        $this->newLine();
        $this->line('  <fg=gray>Run this again after every deploy — build/ is copied, not linked.</>');
        $this->newLine();

        return self::SUCCESS;
    }

    /**
     * The one file that has to be different.
     *
     * Absolute paths, because `__DIR__.'/..'` from inside `public_html` is
     * `public_html`, not the panel.
     */
    private function indexPhp(string $base): string
    {
        return <<<PHP
        <?php

        /*
         * The panel — the only file of it on the web.
         *
         * Written by `php artisan panel:public`. PANEL_DOC Section 4: this
         * hosting will not let a document root leave public_html, so the
         * panel's code lives outside and this folder is what the domain
         * points at. The paths below are absolute for that reason — `..` from
         * here is public_html, not the panel.
         *
         * Do not edit this by hand. Run the command again.
         */

        use Illuminate\\Foundation\\Application;
        use Illuminate\\Http\\Request;

        define('LARAVEL_START', microtime(true));

        if (file_exists(\$maintenance = '{$base}/storage/framework/maintenance.php')) {
            require \$maintenance;
        }

        require '{$base}/vendor/autoload.php';

        /** @var Application \$app */
        \$app = require_once '{$base}/bootstrap/app.php';

        \$app->handleRequest(Request::capture());

        PHP;
    }
}
