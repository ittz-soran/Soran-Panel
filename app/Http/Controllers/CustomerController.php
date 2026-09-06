<?php

namespace App\Http\Controllers;

use App\Models\Action;
use App\Models\Customer;
use App\Services\ShopControls;
use App\Services\ShopMigrator;
use App\Services\ShopRemover;
use App\Support\Bytes;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Throwable;

/**
 * The working screen, and one shop in full — PANEL_DOC Section 9.
 *
 * Both only read. Everything that changes a shop is Section 7's, and arrives
 * with build order steps 6 to 8; until then the danger zone shows what will be
 * there with the reason on the disabled button, which is Section 7's guard rail
 * rather than an apology for the button not working yet.
 */
class CustomerController extends Controller
{
    public function index(Request $request): View
    {
        $chasing = $request->query('show') === 'chasing';

        return view('customers.index', [
            'chasing' => $chasing,

            'customers' => Customer::query()
                // Both are one-of-many subqueries; without this the table runs
                // two more queries per row, which is fine at six customers and
                // is not the habit to build.
                ->with(['currentLicence', 'latestHealthCheck', 'lastGoodHealthCheck'])
                ->when($chasing, fn ($query) => $query->needsChasing())
                ->orderBy('name')
                ->get(),

            // Shown on the filter itself, so the count on the button and the
            // Overview's three lists are visibly the same number.
            'chasingCount' => Customer::needsChasing()->count(),
            'allCount' => Customer::count(),
        ]);
    }

    public function show(Customer $customer, ShopRemover $remover): View
    {
        return view('customers.show', [
            // Section 7's rail: the reason lives on the button before the
            // press. The screen asks the same method `remove()` asks, so a
            // button that is enabled is one that will not be refused.
            'removalBlocked' => $customer->trashed() ? null : $remover->blocked($customer),
            'removedShopsGoTo' => $remover->whereRemovedShopsAreKept(),

            /*
             * What the removal could not finish, kept readable after the
             * flash message has gone.
             *
             * It was only ever on the redirect: press the button, read the
             * green line, click away, and the fact that a subdomain is still
             * pointing at a deleted folder is lost — recoverable only from a
             * JSON blob truncated to 140 characters on the log. This is the
             * page anybody looks at to ask "what happened to that shop", so
             * the unfinished part belongs here.
             */
            'leftBehind' => $customer->trashed()
                ? (Action::where('customer_id', $customer->id)
                    ->where('action', 'shop.removed')->latest('id')->first()?->detail['left'] ?? [])
                : [],

            'customer' => $customer->load([
                'latestHealthCheck',
                'lastGoodHealthCheck',
                'currentLicence.issuedBy',

                // The full history, newest first — Section 9. A renewal is a
                // new row, so this is the record of every licence this shop has
                // ever run on, including the ones that were revoked.
                'licences' => fn ($query) => $query->with('issuedBy')
                    ->orderByDesc('issued_on')->orderByDesc('id'),
            ]),

            'paidUpTo' => $customer->paidUpTo(),

            /*
             * The backups this panel has a record of taking for this shop —
             * whether it took them on purpose, before a migration, or on the
             * way to removing it. Each one is a download link, and a link is
             * the only off-machine copy there is: a file on the server dies
             * with the server.
             */
            'backups' => Action::where('customer_id', $customer->id)
                ->whereIn('action', ['shop.backed_up', 'shop.migrated', 'shop.removed'])
                ->orderByDesc('id')->limit(5)->get(),

            // Enough to see a trend without turning the page into a chart.
            'recentChecks' => $customer->healthChecks()
                ->orderByDesc('checked_at')->limit(12)->get(),
        ]);
    }

    /** Section 7: logged, from → to. */
    public function storageLimit(Request $request, Customer $customer, ShopControls $controls): RedirectResponse
    {
        $fields = $request->validate([
            // Null is allowed and means no ceiling at all — which is a real
            // choice for Soran's own shop, and a dangerous one for anybody
            // else's, so the screen says so rather than the validator refusing.
            'storage_limit_mb' => ['nullable', 'integer', 'min:64', 'max:1048576'],
        ]);

        try {
            $controls->setStorageLimit($customer, $fields['storage_limit_mb'] ?? null);
        } catch (Throwable $e) {
            return back()->with('warning', 'Nothing was changed: '.$e->getMessage());
        }

        return back()->with('success', $fields['storage_limit_mb'] === null
            ? "{$customer->name} now has no storage limit at all."
            : "{$customer->name}'s limit is now ".number_format((int) $fields['storage_limit_mb']).' MB.');
    }

    /** Section 7: hold to confirm, typed shop name. */
    public function suspend(Request $request, Customer $customer, ShopControls $controls): RedirectResponse
    {
        $request->validate(['why' => ['nullable', 'string', 'max:255']]);

        try {
            $result = $controls->suspend($customer, $request->input('why'));
        } catch (Throwable $e) {
            return back()->with('warning', 'Nothing was changed: '.$e->getMessage());
        }

        // Suspending successfully is still a warning rather than a success:
        // somebody's till has just been stopped, and that is not good news even
        // when it is the intended news.
        return back()->with('warning', $result['said']);
    }

    /** Section 7: run a shop's backup, and download it. Logged. */
    public function backUp(Customer $customer, ShopControls $controls): RedirectResponse
    {
        try {
            $result = $controls->backUp($customer);
        } catch (Throwable $e) {
            return back()->with('warning', $e->getMessage());
        }

        return back()->with('success', sprintf(
            '%s is backed up: %s (%s). It is on the server; use Download beside it to keep a copy off it.',
            $customer->name,
            basename($result['path']),
            Bytes::human($result['bytes']),
        ));
    }

    /**
     * Hand over a backup this panel has a record of writing.
     *
     * ⚠️ **The action id, never a path.** This route sends a whole customer's
     * database to whoever asks, so nothing the operator types reaches the
     * filesystem: the row is looked up, it has to belong to THIS customer, and
     * the file is whatever that row says the panel wrote. A shop's backup
     * folder is not a fixed place either — the shop system reads
     * `setting('backup_path')` and then `BACKUP_PATH` from the shop's own
     * `.env` — so there is no folder to whitelist even if that were wise.
     */
    public function downloadBackup(Customer $customer, Action $action): BinaryFileResponse
    {
        abort_unless($action->customer_id === $customer->id, 404);

        // Taking one on, migrating one and removing one each keep a backup too,
        // under their own key. The one worth most is a migration's: it is the
        // copy from the minute before the schema changed.
        $path = $action->detail['path'] ?? $action->detail['backup'] ?? null;

        // Backups are pruned by the shop's own retention — 30 daily — so a row
        // from two months ago names a file that is legitimately gone.
        abort_unless(is_string($path) && is_file($path), 404);

        return response()->download($path);
    }

    /**
     * Section 7: hold to confirm, backup taken first.
     *
     * No typed shop name, unlike suspending and removing. Those two stop a
     * shop or end it; this one brings its database up to date with the code it
     * is already running, and the pending count is on the button before the
     * press. A rail that is everywhere is a rail nobody reads.
     */
    public function migrate(Customer $customer, ShopMigrator $migrator): RedirectResponse
    {
        try {
            $result = $migrator->run($customer);
        } catch (Throwable $e) {
            return back()->with('warning', $e->getMessage());
        }

        return back()->with('success', $result['said']);
    }

    public function resume(Customer $customer, ShopControls $controls): RedirectResponse
    {
        try {
            $result = $controls->resume($customer);
        } catch (Throwable $e) {
            return back()->with('warning', $e->getMessage());
        }

        // Green only when the shop itself says it is trading again. A resume
        // the shop did not accept looked like a success and was not.
        return back()->with($result['ok'] ? 'success' : 'warning', $result['said']);
    }
}
