<?php

namespace App\Console\Commands;

use App\Services\BorrowedLook;
use Illuminate\Console\Command;
use RuntimeException;

/**
 * Put the shop system's compiled look back — PANEL_DOC Section 10.
 *
 * The panel wears the shop system's `public/build`, copied in. When that copy
 * goes missing the panel serves unstyled HTML, and until now the way back was
 * two shell commands in an order that cannot survive the second one failing.
 * `BorrowedLook` holds the safe version; this is it on the command line, for the
 * case where the panel's own screens are what is broken.
 */
class PanelAssets extends Command
{
    protected $signature = 'panel:assets
                            {--from= : The shop system’s public/build, if it is not where the .env says}';

    protected $description = 'Take the shop system’s compiled look into the panel, and into the folder the domain serves';

    public function handle(BorrowedLook $look): int
    {
        $source = ($given = (string) $this->option('from')) !== '' ? rtrim($given, '/') : $look->source();

        // Every reason at once, before anything is touched. A command that names
        // one problem, gets fixed, then names a second is a command run twice.
        if (($problems = $look->problems($source)) !== []) {
            $this->newLine();
            $this->components->error('The shop system’s compiled look is not there to copy.');

            foreach ($problems as $problem) {
                $this->line('  <fg=red>•</> '.$problem);
            }

            $this->newLine();
            $this->line('  <fg=gray>public/build is committed in the shop system, so a missing one there is</>');
            $this->line('  <fg=gray>usually a pull that did not finish. In that folder:</>');
            $this->line('  <fg=gray>  git status && git checkout -- public/build</>');
            $this->newLine();
            $this->components->info('Nothing was changed. The panel still wears whatever it was wearing.');

            return self::FAILURE;
        }

        try {
            $written = $look->refresh($source);
        } catch (RuntimeException $e) {
            $this->newLine();
            $this->components->error($e->getMessage());

            return self::FAILURE;
        }

        $this->newLine();
        $this->components->info('The panel is wearing the shop system’s compiled look again.');
        $this->components->twoColumnDetail('Taken from', $source);

        foreach ($written as $folder) {
            $this->components->twoColumnDetail('Written to', $folder);
        }

        if (count($written) === 1) {
            $this->newLine();
            $this->components->warn(sprintf(
                'Nothing under %s is serving this panel, so only its own public/ was refreshed. If the site '
                .'still looks unstyled, the folder the domain points at has not been written — run '
                .'`php artisan panel:public <that folder>`.',
                rtrim((string) config('panel.shops.public_root'), '/'),
            ));
        }

        $this->newLine();

        return self::SUCCESS;
    }
}
