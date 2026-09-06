<?php

namespace App\Services;

use App\Contracts\ShopReader;
use App\Models\Action;
use App\Models\Customer;
use App\Models\HealthCheck;
use App\Support\ShopBackup;
use App\Support\ShopEnvironment;
use App\Support\ShopReading;
use RuntimeException;
use Symfony\Component\Process\Process;

/**
 * Bringing one shop's database up to date with the shared code — Section 7,
 * "Run a shop's migrations. Hold to confirm. Backup taken first."
 *
 * **This is the half of Section 3 that was missing.** One codebase, many shops
 * means updating the code once — which the Updates screen does — and it means
 * every shop's database is then behind until somebody runs its migrations. A
 * shop's `migrate` ran only when the shop was created or taken on, so after an
 * update the Health screen counted shops "behind on migrations" and offered
 * nothing to press. `Updater` says so in as many words: it reports which shops
 * are now behind rather than quietly migrating other people's databases as a
 * side effect of a code update. This is where that deliberate gap is closed —
 * per shop, on purpose, with a backup first.
 *
 * ⚠️ **Section 7's last line still holds.** "The panel may never write to a
 * shop's business tables" is about the panel reaching into their data with SQL
 * of its own; `ReadOnlyConnection` enforces it and nothing here goes near it.
 * What runs is the shop's OWN `migrate`, through the shop's own `artisan` — the
 * same command the shopkeeper would run, doing what the shop system's own
 * migrations say to do.
 */
class ShopMigrator
{
    /** Migrations on a large database, on a slow shared host. */
    private const TIMEOUT = 900;

    public function __construct(private readonly ShopReader $reader) {}

    /**
     * @return array{was: ?int, now: ?int, total: ?int, backup: string, said: string}
     */
    public function run(Customer $customer): array
    {
        $artisan = rtrim((string) $customer->shop_home, '/').'/artisan';

        if (! is_file($artisan)) {
            throw new RuntimeException(
                "This shop has no artisan at [{$artisan}], so there is nothing to run its migrations with. "
                .'Nothing has been changed.',
            );
        }

        // Asked before, so the report can say what actually changed rather than
        // only what the shop looks like afterwards.
        $before = $this->reader->read($customer);

        // Section 7: a backup before anything irreversible, and a migration is
        // irreversible. It throws if there is no dump at the end of it, and
        // then nothing below has run.
        $backup = ShopBackup::take((string) $customer->shop_home, 'so nothing has been migrated');

        $process = new Process(
            [PHP_BINARY, $artisan, 'migrate', '--force'],
            env: ShopEnvironment::withoutThePanel(),
        );
        $process->setTimeout(self::TIMEOUT);
        $process->run();

        /*
         * A failed migration is recorded either way. Laravel stops at the
         * migration that threw and keeps the ones before it, so "it failed" is
         * never the same as "nothing happened" — and the row saying which shop,
         * when, and where its backup is, is what a restore is decided from.
         */
        $after = $this->reader->read($customer);

        $this->writeItDown($customer, $after);

        Action::record('shop.migrated', $customer, [
            'backup' => $backup,
            'was' => $before->migrationsRun,
            'now' => $after->migrationsRun,
            'total' => $after->migrationsTotal,
            'ok' => $process->isSuccessful(),
        ]);

        if (! $process->isSuccessful()) {
            throw new RuntimeException(sprintf(
                'Their migrations stopped part-way. %s ran before it stopped, and their backup from just '
                .'before is at %s. The shop said: %s',
                $this->howMany($before->migrationsRun, $after->migrationsRun),
                $backup,
                mb_substr(trim($process->getErrorOutput() ?: $process->getOutput()) ?: 'nothing at all', -400),
            ));
        }

        return [
            'was' => $before->migrationsRun,
            'now' => $after->migrationsRun,
            'total' => $after->migrationsTotal,
            'backup' => $backup,
            'said' => sprintf(
                '%s is up to date: %s. Their backup from just before is at %s.',
                $customer->name,
                $this->howMany($before->migrationsRun, $after->migrationsRun),
                $backup,
            ),
        ];
    }

    /**
     * The reading taken afterwards, kept.
     *
     * Without this the Health screen goes on counting the shop as behind until
     * the next hourly run — a button that fixes something and leaves the screen
     * saying it is still broken is one nobody trusts the second time. Written
     * as a new snapshot and never over an old one: Section 5 keeps them as a
     * series so growth stays visible.
     */
    private function writeItDown(Customer $customer, ShopReading $reading): void
    {
        HealthCheck::create([
            'customer_id' => $customer->id,
            'checked_at' => now(),
            ...$reading->toHealthCheck(),
        ]);
    }

    private function howMany(?int $was, ?int $now): string
    {
        if ($was === null || $now === null) {
            return 'the panel could not count them';
        }

        $ran = max($now - $was, 0);

        return $ran === 0
            ? 'nothing was pending'
            : sprintf('%d %s ran', $ran, $ran === 1 ? 'migration' : 'migrations');
    }
}
