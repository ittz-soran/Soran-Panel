<?php

namespace App\Services;

use App\Models\Action;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use RuntimeException;
use SplFileInfo;
use Symfony\Component\Process\Process;
use Throwable;

/**
 * The panel's own database, backed up — PANEL_DOC Section 13.
 *
 * Every other backup in this system is a shop's. This is the one nobody else
 * takes: the customer list, every licence ever issued, and the whole payment
 * record. Losing a shop's database loses one shop. Losing this loses who your
 * customers are, what they are licensed for and what they have paid — and no
 * shop on the server can tell you any of it back.
 *
 * ⚠️ **Section 13 said this would "reuse the shop system's `BackupService`",
 * and it does not.** That class is 919 lines built around the shop system's
 * `Setting` model, its `ActivityLogger` and its storage meter, none of which
 * exist here, and it reaches for a `settings` table the panel has no migration
 * for. Depending on another application's internals for the one thing that has
 * to work when everything else is broken is the wrong direction. What is reused
 * is what should be: its shape — nightly, daily and monthly copies, an
 * off-machine copy, pruning — and the two things it learned the hard way.
 *
 *   - **The password never goes on the command line**, where `ps` shows it to
 *     every other account on a shared host. It goes in a 0600 file that
 *     mysqldump reads through `--defaults-extra-file`, which must be the FIRST
 *     argument or mysqldump ignores it.
 *   - **`--single-transaction`**, so the dump is consistent without locking the
 *     panel out of itself while it runs.
 *
 * One thing is deliberately different. The shop system records "when did this
 * last run" in its settings table; the panel reads it off the **files**. A
 * stored timestamp saying last night, with no file next to it, is precisely the
 * failure a backup exists to survive — so the folder is the record, and it
 * cannot disagree with itself.
 */
class PanelBackup
{
    /** Long enough for a real dump on a slow shared host. */
    private const TIMEOUT = 900;

    /**
     * Where cron can find the tools when its PATH is shorter than a shell's.
     *
     * "mysqldump: command not found" at 3am, from a job that works perfectly
     * when you type it, is this.
     */
    private const TOOL_DIRECTORIES = ['/usr/bin', '/usr/local/bin', '/opt/cpanel/ea-php83/root/usr/bin', '/bin'];

    /**
     * Dump, keep the month's first copy, send one off the machine, prune.
     *
     * @return array{path: string, offsite: ?string, bytes: int, warnings: list<string>}
     */
    public function run(): array
    {
        $at = now();
        $warnings = [];

        $path = $this->folder('daily').'/panel-'.$at->format('Y-m-d-His').'.sql.gz';

        try {
            $written = $this->dump($path);
        } catch (Throwable $e) {
            /*
             * A failed nightly backup is silent by nature — nobody is watching
             * at 3am, and there is no file to notice the absence of until the
             * day it is needed. So it goes in the log, which is a screen Soran
             * actually reads.
             */
            Action::record('panel.backup_failed', null, ['said' => $e->getMessage()]);

            throw $e;
        }

        $bytes = (int) filesize($path);

        /*
         * A dump that ran, exited zero and wrote nothing is the worst kind: it
         * looks like insurance and restores an empty database.
         *
         * ⚠️ Measured on what mysqldump WROTE, not on the size of the file.
         * The first version checked `filesize($path) === 0` and could never
         * fire: gzip writes a header and a footer whatever you give it, so a
         * completely empty dump lands as a perfectly valid twenty-byte archive
         * and passes. Found by trying to write the test for it.
         */
        if ($written === 0) {
            @unlink($path);

            Action::record('panel.backup_failed', null, ['said' => 'the dump came out empty']);

            throw new RuntimeException(
                'The backup came out empty and has been thrown away — mysqldump finished without error and '
                .'produced nothing. Check the panel’s own database credentials.',
            );
        }

        // The first backup of a calendar month is also that month's keeper, so
        // a year of month-ends survives the daily copies rolling off.
        $this->keepTheMonths($path, $at);

        $offsite = $this->copyOffTheMachine($path, $warnings);

        $this->prune('daily', (int) config('panel.backups.keep_daily'));
        $this->prune('monthly', (int) config('panel.backups.keep_monthly'));

        return ['path' => $path, 'offsite' => $offsite, 'bytes' => $bytes, 'warnings' => $warnings];
    }

    /**
     * The copies on disk, newest first.
     *
     * @return list<SplFileInfo>
     */
    public function copies(string $kind = 'daily'): array
    {
        $folder = rtrim($this->where(), '/').'/'.$kind;

        if (! is_dir($folder)) {
            return [];
        }

        $files = [];

        foreach ((array) scandir($folder) as $entry) {
            $path = $folder.'/'.$entry;

            if (is_string($entry) && str_ends_with($entry, '.sql.gz') && is_file($path)) {
                $files[] = new SplFileInfo($path);
            }
        }

        usort($files, fn (SplFileInfo $a, SplFileInfo $b) => $b->getMTime() <=> $a->getMTime());

        return $files;
    }

    /** When the panel last backed itself up, read off the files themselves. */
    public function lastRunAt(): ?Carbon
    {
        $newest = $this->copies()[0] ?? null;

        return $newest === null ? null : Carbon::createFromTimestamp($newest->getMTime());
    }

    /**
     * Whether the newest backup is old enough to worry about.
     *
     * Two days rather than one: a nightly job and a screen looked at in the
     * morning are hours apart, and a warning that is right for a few hours
     * every day is one nobody reads by the end of the week.
     */
    public function isStale(): bool
    {
        $last = $this->lastRunAt();

        return $last === null || $last->lt(now()->subDays(2));
    }

    /** Where the copies are kept. */
    public function where(): string
    {
        $set = rtrim((string) config('panel.backups.path'), '/');

        /*
         * Beside the panel folder rather than inside it. `~/panel` is a git
         * checkout that gets pulled, and one day re-cloned; the backups of the
         * panel's own database must not be the thing that goes with it.
         */
        return $set !== '' ? $set : dirname(base_path()).'/panel-backups';
    }

    /** The second folder a copy goes to, if one is set. */
    public function offsite(): ?string
    {
        $set = rtrim((string) config('panel.backups.offsite'), '/');

        return $set === '' ? null : $set;
    }

    /**
     * Put a backup back.
     *
     * ⚠️ This REPLACES the panel's database with the file's contents, and
     * everything since that file was written is gone. It is here rather than in
     * a note somewhere because an untested backup is not a backup, and a
     * restore nobody can run is not a restore — `panel:restore` is the drill.
     */
    public function restore(string $path): void
    {
        if (! is_file($path)) {
            throw new RuntimeException("There is no backup at [{$path}].");
        }

        $this->refuseAnythingButMysql();

        $credentials = $this->credentialsFile();

        try {
            $process = Process::fromShellCommandline(sprintf(
                '%s -dc %s | %s --defaults-extra-file=%s --default-character-set=utf8mb4 %s',
                escapeshellarg($this->tool('gzip', 'gzip')),
                escapeshellarg($path),
                escapeshellarg($this->tool('mysql', 'mysql', 'mariadb')),
                escapeshellarg($credentials),
                escapeshellarg((string) DB::connection()->getConfig('database')),
            ));

            $process->setTimeout(self::TIMEOUT);
            $process->run();

            if (! $process->isSuccessful()) {
                throw new RuntimeException('The restore failed: '.(mb_substr(
                    trim($process->getErrorOutput()) ?: 'it said nothing at all', -400)));
            }
        } finally {
            @unlink($credentials);
        }
    }

    // ------------------------------------------------------------------ dumping

    /** @return int how many bytes mysqldump actually produced */
    private function dump(string $path): int
    {
        $this->refuseAnythingButMysql();

        $credentials = $this->credentialsFile();

        try {
            return $this->pipeToGzip([
                $this->tool('mysqldump', 'mysqldump', 'mariadb-dump'),

                // FIRST, or mysqldump ignores it — the shop system's
                // BackupService records the same, and it is easy to move while
                // tidying arguments into alphabetical order.
                '--defaults-extra-file='.$credentials,

                // Consistent without locking the panel out of itself for the
                // length of the dump.
                '--single-transaction',
                '--quick',
                '--default-character-set=utf8mb4',
                (string) DB::connection()->getConfig('database'),
            ], $path);
        } finally {
            @unlink($credentials);
        }
    }

    /**
     * @param  list<string>  $command
     * @return int the uncompressed bytes the command produced
     */
    private function pipeToGzip(array $command, string $path): int
    {
        $handle = gzopen($path, 'wb6');

        if ($handle === false) {
            throw new RuntimeException("Could not write to [{$path}].");
        }

        $process = new Process($command, base_path());
        $process->setTimeout(self::TIMEOUT);

        $written = 0;

        try {
            // Streamed, not buffered: a dump can be far larger than PHP's
            // memory limit, and the panel's own is the one that only grows.
            $process->run(function (string $type, string $chunk) use ($handle, &$written) {
                if ($type === Process::OUT) {
                    gzwrite($handle, $chunk);
                    $written += mb_strlen($chunk, '8bit');
                }
            });
        } finally {
            gzclose($handle);
        }

        if (! $process->isSuccessful()) {
            @unlink($path);

            throw new RuntimeException('The backup failed: '.(mb_substr(
                trim($process->getErrorOutput()) ?: 'mysqldump could not be run.', -400)));
        }

        return $written;
    }

    /**
     * The connection details, in a file mysqldump reads and nobody else needs.
     *
     * ⚠️ **Not on the command line.** `mysqldump -p<password>` puts the
     * panel's database password in the process list, where every other account
     * on a shared cPanel machine can read it — and this is the database holding
     * every customer, licence and payment.
     */
    private function credentialsFile(): string
    {
        $config = DB::connection()->getConfig();

        $path = tempnam(sys_get_temp_dir(), 'panel-db');

        if ($path === false) {
            throw new RuntimeException('Could not write a temporary file to '.sys_get_temp_dir().'.');
        }

        @chmod($path, 0600);

        $quote = fn (?string $value) => '"'.str_replace(['\\', '"'], ['\\\\', '\\"'], (string) $value).'"';

        file_put_contents($path, implode("\n", [
            '[client]',
            'host='.$quote($config['host'] ?? '127.0.0.1'),
            'port='.(int) ($config['port'] ?? 3306),
            'user='.$quote($config['username'] ?? ''),
            'password='.$quote($config['password'] ?? ''),
            '',
        ]));

        return $path;
    }

    private function refuseAnythingButMysql(): void
    {
        $driver = DB::connection()->getDriverName();

        if (! in_array($driver, ['mysql', 'mariadb'], true)) {
            throw new RuntimeException(
                "The panel's backup dumps MySQL, and this panel is on [{$driver}]. On the server it is MariaDB; "
                .'a laptop running something else can back its panel up with whatever that database offers.',
            );
        }
    }

    /**
     * Find a tool, whatever cron's PATH happens to be.
     *
     * A configured full path is used as given, so a wrong one is REPORTED
     * rather than quietly worked around — the shop system's BackupService makes
     * the same choice and gives the reason: a search that silently succeeds
     * elsewhere hides the setting that is wrong.
     */
    private function tool(string $key, string ...$names): string
    {
        $configured = (string) config('panel.backups.'.$key);

        if (str_contains($configured, '/')) {
            return $configured;
        }

        foreach ($names as $name) {
            foreach (self::TOOL_DIRECTORIES as $directory) {
                if (is_file($directory.'/'.$name)) {
                    return $directory.'/'.$name;
                }
            }

            if ($this->runs($name)) {
                return $name;
            }
        }

        throw new RuntimeException(sprintf(
            '%s was not found. It ships with the database server. Put it on PATH, or set PANEL_%s in the '
            .'panel’s .env to the full path — cron’s PATH is much shorter than yours.',
            $names[0], mb_strtoupper($key),
        ));
    }

    private function runs(string $binary): bool
    {
        try {
            $process = new Process([$binary, '--version']);
            $process->setTimeout(15);
            $process->run();

            return $process->isSuccessful();
        } catch (Throwable) {
            // Missing executables throw on some platforms and exit non-zero on
            // others. Both mean the same thing here.
            return false;
        }
    }

    // ------------------------------------------------------------------ keeping

    private function keepTheMonths(string $path, Carbon $at): void
    {
        $target = $this->folder('monthly').'/panel-'.$at->format('Y-m').'.sql.gz';

        if (! is_file($target)) {
            @copy($path, $target);
        }
    }

    /** @param  list<string>  $warnings */
    private function copyOffTheMachine(string $path, array &$warnings): ?string
    {
        $offsite = $this->offsite();

        if ($offsite === null) {
            // Loud, not silent. A backup on the same disk as the database
            // survives a mistake and not a dead disk, and the whole point of
            // this one is the day the server is gone.
            $warnings[] = 'No off-machine copy is set, so this backup is on the same disk as the database it '
                .'came from. Set PANEL_BACKUPS_OFFSITE, or download a copy from the Health screen.';

            return null;
        }

        if (! is_dir($offsite) && ! @mkdir($offsite, 0750, recursive: true) && ! is_dir($offsite)) {
            $warnings[] = "The off-machine folder [{$offsite}] could not be reached. The local copy was still written.";

            return null;
        }

        $target = rtrim($offsite, '/').'/'.basename($path);

        if (! @copy($path, $target)) {
            $warnings[] = "The backup could not be copied to [{$offsite}]. The local copy was still written.";

            return null;
        }

        return $target;
    }

    private function prune(string $kind, int $keep): void
    {
        foreach (array_slice($this->copies($kind), max($keep, 1)) as $file) {
            @unlink($file->getPathname());
        }
    }

    private function folder(string $kind): string
    {
        $path = rtrim($this->where(), '/').'/'.$kind;

        if (! is_dir($path) && ! @mkdir($path, 0750, recursive: true) && ! is_dir($path)) {
            throw new RuntimeException(
                "[{$path}] could not be created, so there is nowhere to put the backup. Set PANEL_BACKUPS to a "
                .'folder the panel can write to — and one outside ~/panel, which gets pulled and one day recloned.',
            );
        }

        return $path;
    }
}
