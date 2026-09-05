<?php

namespace App\Http\Controllers;

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
    public function index(Request $request, Updater $updater): View
    {
        $asked = $request->boolean('check');

        return view('updates.index', [
            'checkouts' => $updater->look(askGithub: $asked),
            'asked' => $asked,
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
}
