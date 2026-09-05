<?php

namespace App\Services;

use App\Contracts\DatabaseMaker;
use App\Contracts\DnsMaker;
use App\Contracts\DomainMaker;
use App\Models\Action;
use App\Models\Customer;
use App\Support\ShopEnvironment;
use App\Support\ShopFolder;
use RuntimeException;
use Symfony\Component\Process\Process;

/**
 * Taking a shop off this server for good — PANEL_DOC Section 7.
 *
 * The panel could create and never delete, which meant every trial, every
 * rehearsal and every mistake stayed on the account for ever: a database, two
 * folders, a subdomain and a DNS record each. Worse, `refuseIfAnythingIsInTheWay`
 * does its job — so the wreckage of a shop is also the thing that stops the
 * same name being used again.
 *
 * ⚠️ **This drops the shop's database, and Section 7 used to say the panel
 * never would.** Soran changed that rule deliberately and it is recorded there.
 * The old rule was written when the panel had no way to remove anything, so
 * "never drop a database" cost nothing; once removal exists, a rule that leaves
 * every removed shop's data on the disk is not caution, it is litter that
 * eventually gets deleted by hand in a hurry — which is the dangerous way.
 *
 * What replaces the old rule is a sequence, and the order is the whole design:
 *
 *   0. it must not be trading — a shop is suspended before it is removed
 *   1. **a dump of their database, copied somewhere that survives this**
 *   2. the DNS record
 *   3. the subdomain
 *   4. the public folder, then the private one
 *   5. the database and its user
 *   6. the customer row is KEPT, marked ended, and hidden
 *
 * Step 1 is a gate: if it fails, nothing else runs and nothing has been
 * touched. Everything after it is teardown, and teardown never stops half-way
 * — each step reports what it could not do and the next one still runs, because
 * stopping at the first failure is how you get a shop with no folder, a live
 * subdomain and a database nobody can account for.
 *
 * Step 1 also has to MOVE the dump. The shop system writes its backups to
 * `storage/app/backups` inside the shop's own folder, which step 4 deletes —
 * so a backup left where it was made is a backup that does not survive the
 * thing it was taken for.
 */
class ShopRemover
{
    /** A dump of a real shop's database on a slow shared host. */
    private const TIMEOUT = 300;

    public function __construct(
        private readonly DatabaseMaker $databases,
        private readonly DomainMaker $domains,
        private readonly DnsMaker $dns,
    ) {}

    /**
     * Why this shop may not be removed, or null when it may.
     *
     * Section 7's rail is that the reason lives on the button before the press,
     * never after it, so this is asked by the screen as well as by `remove()`.
     */
    public function blocked(Customer $customer): ?string
    {
        if (in_array($customer->status, [Customer::ACTIVE, Customer::TRIAL], true)) {
            return 'Suspend it first. A trading shop is somebody’s till, and suspending is the '
                .'reversible half of this — if the wrong shop goes quiet you hear about it in minutes '
                .'and can put the licence straight back.';
        }

        return null;
    }

    /**
     * @param  string|null  $why  for the record
     * @return array{backup: string, done: list<string>, left: list<string>}
     */
    public function remove(Customer $customer, ?string $why = null): array
    {
        if ($reason = $this->blocked($customer)) {
            throw new RuntimeException($reason);
        }

        // The gate. Throws, and nothing below has run.
        $backup = $this->keepACopyOfTheirDatabase($customer);

        $done = ["their database was dumped to {$backup}"];
        $left = [];

        // The published name first: a record pointing at a shop being taken
        // apart is a live address serving wreckage, and it is the only piece of
        // this a stranger can see.
        $left = [...$left, ...$this->dns->remove($customer->host)];
        $done[] = 'the DNS record was removed'.($this->dns->isAutomatic() ? '' : ' (by hand — nothing to do here)');

        $left = [...$left, ...$this->domains->remove($customer->host)];
        $done[] = "the subdomain {$customer->host} was removed";

        // Public before private, and only after the subdomain has gone, so
        // there is never a moment where a live domain points at nothing.
        foreach ([$customer->public_path, $customer->shop_home] as $folder) {
            if ($folder === null || $folder === '') {
                continue;
            }

            if (ShopFolder::delete($folder)) {
                $done[] = "the folder [{$folder}] was deleted";
            } else {
                $left[] = "the folder [{$folder}]";
            }
        }

        $left = [...$left, ...$this->databases->drop($customer->database_name, $customer->database_user)];
        $done[] = "the database [{$customer->database_name}] and its user were dropped";

        /*
         * The row stays. Section 5: licences and payments outlive a customer,
         * and a shop that has gone is still a shop that was paid for — deleting
         * the row to tidy the list would destroy the money record with it.
         *
         * So: marked ended, written down, then soft-deleted, which takes it out
         * of every list and every hourly check while leaving all of it readable
         * at the same address.
         */
        $customer->update(['status' => Customer::ENDED]);

        Action::record('shop.removed', $customer, [
            'why' => $why,
            'backup' => $backup,
            'done' => $done,
            'left' => $left,
        ]);

        $customer->delete();

        return ['backup' => $backup, 'done' => $done, 'left' => $left];
    }

    /**
     * A dump, taken now, copied out of the way.
     *
     * Their own tooling, for the same reason `ShopProvisioner::backUpFirst`
     * uses it: the shop system knows how to dump itself and a restore later
     * expects that shape. And their own artisan, because it is the one that
     * knows which shop it is.
     *
     * ⚠️ Copied, not moved. A `rename()` across two filesystems fails, and the
     * one moment to find that out is not while dismantling somebody's shop. The
     * original is left where it was and dies with the folder, which is fine —
     * it is the copy that matters, and its size is checked against the source
     * before anything is allowed to proceed.
     *
     * @return string where the copy went
     *
     * @throws RuntimeException if there is no dump at the end of this
     */
    private function keepACopyOfTheirDatabase(Customer $customer): string
    {
        $home = rtrim((string) $customer->shop_home, '/');
        $artisan = $home.'/artisan';

        if (! is_file($artisan)) {
            throw new RuntimeException(
                "This shop has no artisan at [{$artisan}], so the panel cannot ask it for a dump — and "
                .'without a dump nothing here will drop its database. If its folder is already gone, take it '
                .'on again first (Take on an existing shop rebuilds the folder against the database that is '
                .'still there), then remove it. Nothing has been touched.',
            );
        }

        $process = new Process([PHP_BINARY, $artisan, 'backup:run'], env: ShopEnvironment::withoutThePanel());
        $process->setTimeout(self::TIMEOUT);
        $process->run();

        if (! $process->isSuccessful()) {
            throw new RuntimeException(
                'Their database could not be backed up, so nothing has been removed. The shop said: '
                .mb_substr(trim($process->getErrorOutput() ?: $process->getOutput())
                    ?: 'nothing at all', -400),
            );
        }

        $dump = $this->newestBackup($home);

        if ($dump === null) {
            throw new RuntimeException(
                "`backup:run` finished without error and left no file in [{$home}/storage/app/backups]. "
                .'That is not a backup, so nothing has been removed.',
            );
        }

        return $this->copySomewhereThatSurvives($customer, $dump);
    }

    /** The newest file the shop's own backups folder holds. */
    private function newestBackup(string $home): ?string
    {
        $folder = $home.'/storage/app/backups';

        if (! is_dir($folder)) {
            return null;
        }

        $newest = null;
        $when = -1;

        foreach ((array) scandir($folder) as $entry) {
            $path = $folder.'/'.$entry;

            if ($entry === '.' || $entry === '..' || ! is_file($path)) {
                continue;
            }

            if (($at = (int) filemtime($path)) > $when) {
                $newest = $path;
                $when = $at;
            }
        }

        return $newest;
    }

    /** @throws RuntimeException if the copy did not land, or landed short */
    private function copySomewhereThatSurvives(Customer $customer, string $dump): string
    {
        $keep = $this->whereRemovedShopsAreKept();

        /*
         * It must not be under either shop root, because this removal deletes
         * both. A backup that dies with the thing it was insurance for is not
         * one, and the moment to notice is now — before anything has gone.
         */
        foreach ([
            rtrim((string) config('panel.shops.home_root'), '/'),
            rtrim((string) config('panel.shops.public_root'), '/'),
        ] as $root) {
            if ($root !== '' && str_starts_with($keep.'/', $root.'/')) {
                throw new RuntimeException(
                    "[{$keep}] is inside [{$root}], which removing a shop deletes — their backup would be "
                    .'destroyed by the removal it is insurance against. Point PANEL_REMOVED_SHOPS somewhere '
                    .'outside both shop folders. Nothing has been removed.',
                );
            }
        }

        if (! is_dir($keep) && ! @mkdir($keep, 0750, recursive: true) && ! is_dir($keep)) {
            throw new RuntimeException(
                "[{$keep}] could not be created, so there is nowhere to put their backup and nothing has "
                .'been removed. Set PANEL_REMOVED_SHOPS to a folder the panel can write to.',
            );
        }

        $to = sprintf('%s/%s-%s-%s', $keep, $customer->host, now()->format('Y-m-d-His'), basename($dump));

        if (! @copy($dump, $to)) {
            throw new RuntimeException(
                "Their backup [{$dump}] could not be copied to [{$to}], so nothing has been removed.",
            );
        }

        // A copy that ran out of disk half way still returns true from some
        // stream wrappers, and a short dump is worse than no dump: it looks
        // like insurance and is not.
        clearstatcache(true, $to);

        if (filesize($to) !== filesize($dump)) {
            @unlink($to);

            throw new RuntimeException(
                'Their backup copied short — out of disk, most likely. Nothing has been removed.',
            );
        }

        return $to;
    }

    /**
     * Where a removed shop's last backup is kept.
     *
     * Anywhere except inside the shop, which is about to stop existing. The
     * default is the panel's own storage: on the same account, outside
     * public_html, and somewhere Soran already knows the path to.
     */
    public function whereRemovedShopsAreKept(): string
    {
        $set = rtrim((string) config('panel.shops.removed_root'), '/');

        return $set !== '' ? $set : storage_path('app/removed-shops');
    }
}
