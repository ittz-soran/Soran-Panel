<?php

namespace App\Http\Controllers;

use App\Services\BorrowedLook;
use App\Services\ShopAssets;
use App\Services\ShopControls;
use App\Services\Updater;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\View\View;
use Throwable;

/**
 * What version everything is on, and taking the next one.
 *
 * The screen opens WITHOUT asking GitHub. Reading two local checkouts is
 * instant; two network round trips on a shared host is not, and a page that
 * sometimes hangs for ten seconds is one nobody opens to answer the small
 * question it exists for — "what am I running?"
 */
class UpdateController extends Controller
{
    public function index(Request $request, Updater $updater, BorrowedLook $look, ShopAssets $assets): View
    {
        $asked = $request->boolean('check');

        return view('updates.index', [
            'checkouts' => $updater->look(askGithub: $asked),
            'asked' => $asked,

            // Section 10: the panel wears a copy of the shop system's compiled
            // build. When that copy is gone every screen serves unstyled HTML,
            // and this screen is where the way back has to be — a panel in that
            // state is exactly a panel whose owner cannot see what is wrong.
            'lookInPlace' => $look->inPlace(),
            'lookSource' => $look->source(),

            // And the silent half of it: the folder the domain serves holding
            // an older copy than the manifest names. Nothing throws, every
            // stylesheet 404s, and the panel looks broken for no stated reason.
            'lookStale' => $look->stale(),

            // And the same question asked of the SHOPS, which is a different
            // failure with a different symptom — see ShopAssets. A shop wearing
            // an old copy does not 404 and does not look broken; it looks fine
            // and quietly stops styling anything added since the copy was made.
            // Nothing could see it, which is why it is on a screen now.
            'shopsBehind' => $assets->behind(),
        ]);
    }

    public function store(Request $request, Updater $updater): RedirectResponse
    {
        $fields = $request->validate([
            'checkout' => ['required', Rule::in(['panel', 'shop_system'])],
        ]);

        try {
            $done = $updater->update($fields['checkout']);
        } catch (Throwable $e) {
            return back()->with('warning', $e->getMessage());
        }

        $said = sprintf(
            '%s updated: %s → %s, %s.',
            $fields['checkout'] === 'panel' ? 'The panel' : 'The shop system',
            $done['was'], $done['now'],
            trans_choice(':count commit|:count commits', $done['took']),
        );

        /*
         * Updating the shared codebase can bring migrations, and a shop does
         * not migrate itself. Saying which shops are now behind is the honest
         * end of this action — running `migrate` on customers' databases as a
         * side effect of a button labelled "update" is not.
         */
        if ($fields['checkout'] === 'shop_system') {
            $said .= ' Check Health: any shop whose schema is now behind needs migrating before it is right.';
        }

        return redirect()->route('updates')
            ->with($done['warnings'] === [] ? 'success' : 'warning', trim($said.' '.implode(' ', $done['warnings'])));
    }

    /**
     * Move a checkout off a branch GitHub no longer has.
     *
     * Separate from `store`, because it is a different decision: that one takes
     * commits written for this branch, this one changes which branch is
     * followed at all. Its guards are in `Checkout::moveToDefaultBranch`.
     */
    public function moveBranch(Request $request, Updater $updater): RedirectResponse
    {
        $fields = $request->validate([
            'checkout' => ['required', Rule::in(['panel', 'shop_system'])],
        ]);

        try {
            $done = $updater->moveToDefaultBranch($fields['checkout']);
        } catch (Throwable $e) {
            return back()->with('warning', $e->getMessage());
        }

        return redirect()->route('updates', ['check' => 1])->with('success', sprintf(
            '%s now follows [%s] instead of [%s]. Check for updates again — there may be some waiting.',
            $fields['checkout'] === 'panel' ? 'The panel' : 'The shop system',
            $done['now'], $done['was'],
        ));
    }

    /**
     * Take the shop system's compiled look again — Section 10.
     *
     * Its own action rather than part of `store`, because it fixes something
     * updating cannot: a panel whose borrowed stylesheet is missing is already
     * up to date, so the update button has nothing to offer it and says so.
     */
    public function refreshLook(BorrowedLook $look): RedirectResponse
    {
        try {
            $written = $look->refresh();
        } catch (Throwable $e) {
            return back()->with('warning', $e->getMessage());
        }

        return back()->with('success', sprintf(
            'The panel is wearing the shop system’s compiled look again — %s.',
            implode(' and ', $written),
        ));
    }

    /**
     * Hand every shop the stylesheet the shared codebase is holding.
     *
     * Its own action for the same reason `refreshLook` is: a shop wearing last
     * month's stylesheet is running perfectly current code, so the update
     * button looks at it and correctly says there is nothing waiting. This
     * happens automatically when the shop system is updated; this is for every
     * shop that fell behind before it did, and for a pull done in a terminal.
     */
    public function refreshShopAssets(ShopAssets $assets): RedirectResponse
    {
        try {
            $done = $assets->refreshEvery();
        } catch (Throwable $e) {
            return back()->with('warning', $e->getMessage());
        }

        if ($done['written'] === [] && $done['stubborn'] === []) {
            return back()->with('success', 'Every shop is already wearing this build.');
        }

        $said = trans_choice(':count shop|:count shops', count($done['written']))
            .' are wearing the shop system’s compiled look now.';

        return back()->with(
            $done['stubborn'] === [] ? 'success' : 'warning',
            $done['stubborn'] === [] ? $said : $said.' These could not be written and are still serving their '
                .'old stylesheet: '.implode('; ', $done['stubborn']).'.',
        );
    }

    /**
     * Clear every shop's compiled code, without updating anything.
     *
     * This already happens as part of updating the shop system. It is offered
     * on its own because the automatic run is not the only time it is needed —
     * an update that half-failed, a file changed on the server — and because
     * "do they all need it?" is how the question actually gets asked.
     */
    public function clearShops(ShopControls $controls): RedirectResponse
    {
        $done = $controls->clearEveryShop();

        if ($done['cleared'] === 0 && $done['stubborn'] === []) {
            return back()->with('warning', 'There are no shops to clear.');
        }

        $said = trans_choice(':count shop|:count shops', $done['cleared'])
            .' threw away what they had compiled.';

        return back()->with(
            $done['stubborn'] === [] ? 'success' : 'warning',
            $done['stubborn'] === [] ? $said : $said.' These could not be cleared and may still be serving the '
                .'old code: '.implode(', ', $done['stubborn']).'.',
        );
    }
}
