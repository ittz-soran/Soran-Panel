<?php

namespace App\Console\Commands;

use App\Services\PanelBackup;
use Illuminate\Console\Command;
use Throwable;

/**
 * Put a backup of the panel back — PANEL_DOC Section 13.
 *
 * This exists because of the sentence Section 13 borrowed from the shop
 * system's Section 8b: **an untested backup is not a backup, and a restore
 * nobody can run is not a restore.** A folder of `.sql.gz` files and a plan to
 * work it out on the day is the second of those.
 *
 * It is a command and not a screen on purpose. The day this is needed, the
 * panel is very likely the thing that is broken — a database that will not
 * answer has no Health page to press a button on. What it needs is to work from
 * a shell with nothing else running.
 */
class PanelRestore extends Command
{
    protected $signature = 'panel:restore {file? : The .sql.gz to put back; the newest if left out}
                            {--force : Skip the confirmation, for a scripted drill}';

    protected $description = 'Replace the panel’s database with one of its backups';

    public function handle(PanelBackup $backups): int
    {
        $file = (string) ($this->argument('file') ?? '');

        if ($file === '') {
            $newest = $backups->copies()[0] ?? null;

            if ($newest === null) {
                $this->components->error(
                    'There are no backups in '.$backups->where().'. Name a file, or run `php artisan panel:backup`.',
                );

                return self::FAILURE;
            }

            $file = $newest->getPathname();
        }

        $this->newLine();
        $this->components->warn('This REPLACES the panel’s database with '.basename($file).'.');
        $this->components->warn('Every customer, licence and payment recorded since then will be gone.');
        $this->newLine();

        if (! $this->option('force') && ! $this->confirm('Put this backup back?', false)) {
            $this->components->info('Nothing was changed.');

            return self::SUCCESS;
        }

        try {
            $backups->restore($file);
        } catch (Throwable $e) {
            $this->components->error($e->getMessage());

            return self::FAILURE;
        }

        $this->newLine();
        $this->components->info('The panel’s database is back to '.basename($file).'.');
        $this->components->warn('Run `php artisan migrate` — a backup from an older version of the panel '
            .'restores that version’s schema with it.');

        return self::SUCCESS;
    }
}
