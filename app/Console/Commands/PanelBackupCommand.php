<?php

namespace App\Console\Commands;

use App\Services\PanelBackup;
use Illuminate\Console\Command;
use Throwable;

/**
 * Back the panel's own database up — PANEL_DOC Section 13.
 *
 * Run nightly by the scheduler (routes/console.php) and by hand whenever
 * something big is about to happen. `--list` says what is already kept, which
 * is the question actually asked at 9am: is there a backup, and how old.
 */
class PanelBackupCommand extends Command
{
    protected $signature = 'panel:backup {--list : Show the copies already kept, and stop}';

    protected $description = 'Back up the panel’s own database — the customer list, the licences and the payments';

    public function handle(PanelBackup $backups): int
    {
        if ($this->option('list')) {
            return $this->list($backups);
        }

        $this->newLine();
        $this->components->info('Backing up the panel’s own database.');

        try {
            $result = $backups->run();
        } catch (Throwable $e) {
            $this->components->error($e->getMessage());

            return self::FAILURE;
        }

        $this->components->twoColumnDetail('Written', $result['path']);
        $this->components->twoColumnDetail('Size', $this->size($result['bytes']));
        $this->components->twoColumnDetail('Off the machine', $result['offsite'] ?? 'nowhere');

        foreach ($result['warnings'] as $warning) {
            $this->components->warn($warning);
        }

        $this->newLine();

        // Warnings do not fail it. The backup was taken; something about how it
        // is kept is worth reading, and a command that goes red for both is one
        // whose red stops meaning anything.
        $this->components->info('The panel is backed up.');

        return self::SUCCESS;
    }

    private function list(PanelBackup $backups): int
    {
        $this->newLine();
        $this->components->twoColumnDetail('<fg=gray>Kept in</>', $backups->where());
        $this->components->twoColumnDetail('<fg=gray>Off the machine</>', $backups->offsite() ?? '<fg=red>nowhere</>');
        $this->newLine();

        foreach (['daily', 'monthly'] as $kind) {
            $copies = $backups->copies($kind);

            $this->components->twoColumnDetail(
                "<options=bold>{$kind}</>",
                count($copies) === 1 ? '1 copy' : count($copies).' copies',
            );

            foreach (array_slice($copies, 0, 5) as $file) {
                $this->components->twoColumnDetail(
                    '  '.$file->getFilename(),
                    $this->size($file->getSize()),
                );
            }
        }

        $this->newLine();

        if ($backups->isStale()) {
            $this->components->warn($backups->lastRunAt() === null
                ? 'The panel has never been backed up. Run `php artisan panel:backup`.'
                : 'The newest backup is from '.$backups->lastRunAt()->diffForHumans()
                    .'. Check that cron is running the scheduler.');

            return self::FAILURE;
        }

        return self::SUCCESS;
    }

    private function size(int $bytes): string
    {
        return $bytes >= 1048576
            ? number_format($bytes / 1048576, 1).' MB'
            : number_format(max($bytes / 1024, 0.1), 1).' KB';
    }
}
