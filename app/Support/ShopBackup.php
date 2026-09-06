<?php

namespace App\Support;

use RuntimeException;
use Symfony\Component\Process\Process;

/**
 * Making a shop back itself up, and finding out where it put the file.
 *
 * Three things in the panel take a shop's backup before doing something to it —
 * taking one on, migrating one, removing one — and Section 7 requires it in
 * every case. This is the one place that knows how.
 *
 * **Their own tooling, never the panel's idea of a dump.** The shop system
 * knows how to dump itself and a restore later expects that shape. And their
 * own `artisan`, because the shared one serves every shop and can name none of
 * them.
 *
 * ⚠️ **Where the file went is ASKED, not guessed**, and the first version
 * guessed. It looked directly in `<shop>/storage/app/backups`, and
 * `BackupService::directory()` appends `daily` or `monthly` — so every real
 * backup is one level further down, and every attempt to remove a shop failed
 * with "left no file" while pointing at the folder that contained it.
 *
 * Guessing was wrong twice over, because that folder is not fixed either: the
 * shop system reads `setting('backup_path')` first and `BACKUP_PATH` from the
 * shop's own `.env` after that, so a shop backing up to an external drive was
 * never going to be found under its own folder however deep the search went.
 *
 * So the path comes from `backup:run`'s own output, which prints it. The search
 * underneath is the fallback for the day that output changes shape.
 */
final class ShopBackup
{
    /** A dump of a real shop's database on a slow shared host. */
    private const TIMEOUT = 300;

    /**
     * @param  string  $andSo  finishes every refusal, so the caller's own
     *                         promise is kept in the message: "so nothing has
     *                         been removed", "so nothing has been migrated".
     *                         What the operator needs to know first is what did
     *                         NOT happen.
     * @return string the file the shop wrote
     *
     * @throws RuntimeException if there is no dump at the end of it
     */
    public static function take(string $shopHome, string $andSo): string
    {
        $home = rtrim($shopHome, '/');
        $artisan = $home.'/artisan';

        if (! is_file($artisan)) {
            throw new RuntimeException(
                "This shop has no artisan at [{$artisan}], so the panel cannot ask it for a dump — {$andSo}. "
                .'If its folder is gone, take it on again first: Take on an existing shop rebuilds the folder '
                .'against the database that is still there.',
            );
        }

        $process = new Process([PHP_BINARY, $artisan, 'backup:run'], env: ShopEnvironment::withoutThePanel());
        $process->setTimeout(self::TIMEOUT);
        $process->run();

        if (! $process->isSuccessful()) {
            throw new RuntimeException(
                "Their database could not be backed up, {$andSo}. The shop said: "
                .mb_substr(trim($process->getErrorOutput() ?: $process->getOutput()) ?: 'nothing at all', -400),
            );
        }

        $dump = self::whereItWent($process->getOutput(), $home);

        if ($dump === null) {
            throw new RuntimeException(sprintf(
                '`backup:run` finished without error and the panel cannot find what it wrote. It named no '
                .'path, and there is nothing under [%s/storage/app/backups]. That is not a backup, %s. '
                .'The shop said: %s',
                $home, $andSo,
                mb_substr(trim($process->getOutput()) ?: 'nothing at all', -300),
            ));
        }

        return $dump;
    }

    private static function whereItWent(string $output, string $home): ?string
    {
        // `  /home/soransto/shops/x/storage/app/backups/daily/backup-….sql.gz  (2.6 MB)`
        if (preg_match('#(/\S+\.(?:sql|sql\.gz|gz|zip))#', $output, $said) === 1 && is_file($said[1])) {
            return $said[1];
        }

        return self::newestUnder($home.'/storage/app/backups');
    }

    /** The newest file anywhere under a folder, however deep. */
    private static function newestUnder(string $folder): ?string
    {
        if (! is_dir($folder)) {
            return null;
        }

        $newest = null;
        $when = -1;

        $entries = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($folder, \FilesystemIterator::SKIP_DOTS),
        );

        foreach ($entries as $entry) {
            if ($entry->isFile() && ($at = (int) $entry->getMTime()) > $when) {
                $newest = $entry->getPathname();
                $when = $at;
            }
        }

        return $newest;
    }
}
