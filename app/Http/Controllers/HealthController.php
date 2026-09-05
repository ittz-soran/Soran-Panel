<?php

namespace App\Http\Controllers;

use App\Contracts\ShopReader;
use App\Models\Action;
use App\Models\Customer;
use App\Models\HealthCheck;
use App\Services\PanelBackup;
use Illuminate\Http\RedirectResponse;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Throwable;

/**
 * Each shop's own report on itself, read hourly — PANEL_DOC Section 9.
 *
 * Not the panel's opinion of a shop: the shop's own answers, gathered by
 * `shops:check` through the shop's own `artisan` (Section 8). What the screen
 * adds is the comparison — every shop side by side, so a problem common to all
 * of them looks different from a problem with one.
 *
 * Nothing here writes. Section 8's data check is read-only "deliberately: a
 * contradiction is evidence, and repairing it before it has been read destroys
 * the record of what went wrong", and this screen keeps that promise by having
 * nothing to press except "look again".
 */
class HealthController extends Controller
{
    public function index(PanelBackup $backups): View
    {
        $customers = Customer::query()
            ->with(['latestHealthCheck', 'lastGoodHealthCheck'])
            ->orderBy('name')
            ->get();

        $live = $customers->filter(fn (Customer $customer) => $customer->isLive());

        $newest = $backups->copies()[0] ?? null;

        return view('health.index', [
            'customers' => $customers,
            'lastRun' => HealthCheck::max('checked_at'),

            /*
             * The panel's own backup, on the same screen as the shops' health
             * — Section 13. It belongs here because this is the page Soran
             * opens to ask "is anything wrong", and a backup that stopped
             * running two weeks ago is the most wrong thing there can be while
             * every other number on the page is green.
             */
            'backup' => [
                'at' => $backups->lastRunAt(),
                'stale' => $backups->isStale(),
                'name' => $newest?->getFilename(),
                'bytes' => $newest?->getSize(),
                'where' => $backups->where(),
                'offsite' => $backups->offsite(),
                'daily' => count($backups->copies('daily')),
                'monthly' => count($backups->copies('monthly')),
            ],
            'counts' => [
                'live' => $live->count(),
                'unreachable' => $live->filter(
                    fn (Customer $c) => $c->latestHealthCheck && ! $c->latestHealthCheck->reachable,
                )->count(),
                'never' => $live->filter(fn (Customer $c) => $c->latestHealthCheck === null)->count(),
                'contradicting' => $live->filter(
                    fn (Customer $c) => $c->lastGoodHealthCheck?->dataCheckPassed() === false,
                )->count(),
                'behind' => $live->filter(
                    fn (Customer $c) => ($c->lastGoodHealthCheck?->migrationsPending() ?? 0) > 0,
                )->count(),
            ],
        ]);
    }

    /**
     * Look at one shop now, rather than waiting for the hour.
     *
     * The same reading the schedule takes, written as another snapshot — never
     * over the top of one. Section 5 keeps them as a series so storage growing
     * over weeks stays visible, and a "refresh" that overwrote the last row
     * would quietly destroy that.
     */
    public function recheck(Customer $customer, ShopReader $reader): RedirectResponse
    {
        $reading = $reader->read($customer);

        HealthCheck::create([
            'customer_id' => $customer->id,
            'checked_at' => now(),
            ...$reading->toHealthCheck(),
        ]);

        return back()->with(
            $reading->reachable ? 'success' : 'warning',
            $reading->reachable
                ? "{$customer->name} answered. This is what it says right now."
                : "{$customer->name} could not be read: ".implode(' ', $reading->problems),
        );
    }

    /**
     * Back the panel up now — Section 13.
     *
     * Not destructive, so no hold and no typed name: it writes a new file and
     * touches nothing else. The one thing it can do wrong is take a while, and
     * a button that is slow is better than a database nobody dumped.
     */
    public function backUp(PanelBackup $backups): RedirectResponse
    {
        try {
            $result = $backups->run();
        } catch (Throwable $e) {
            return back()->with('warning', 'The panel was not backed up: '.$e->getMessage());
        }

        // Recorded because a person asked for it. The nightly run is not logged
        // — it would be a row a night for ever in a log that exists to say who
        // did what — and its evidence is the file itself.
        Action::record('panel.backed_up', null, ['path' => $result['path'], 'bytes' => $result['bytes']]);

        $said = 'The panel is backed up: '.basename($result['path']).'.';

        return back()->with(
            $result['warnings'] === [] ? 'success' : 'warning',
            $result['warnings'] === [] ? $said : $said.' '.implode(' ', $result['warnings']),
        );
    }

    /**
     * Send a backup to whoever is signed in.
     *
     * ⚠️ **This is the off-machine copy, in practice.** A second folder on the
     * same server survives a mistake and not a dead disk; a file on Soran's own
     * laptop survives the server. So this is not a convenience.
     *
     * The name is taken apart and rebuilt rather than trusted: `basename` and a
     * whitelist of the two folders the panel writes, so no `..` reaches the
     * filesystem. This route hands over the customer list, every licence and
     * the whole payment record, which is the single most valuable file on the
     * account — it is behind `auth`, and it is worth the paranoia.
     */
    public function downloadBackup(string $kind, string $name, PanelBackup $backups): BinaryFileResponse
    {
        abort_unless(in_array($kind, ['daily', 'monthly'], true), 404);

        $path = rtrim($backups->where(), '/').'/'.$kind.'/'.basename($name);

        abort_unless(str_ends_with($path, '.sql.gz') && is_file($path), 404);

        return response()->download($path);
    }
}
