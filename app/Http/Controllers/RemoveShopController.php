<?php

namespace App\Http\Controllers;

use App\Models\Customer;
use App\Services\ShopRemover;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Throwable;

/**
 * Removing a shop — PANEL_DOC Section 7.
 *
 * Its own controller rather than another method on CustomerController, because
 * this is the only thing the panel does that nothing can undo, and it should be
 * possible to read all of it — the rule, the rails and the wording — without
 * reading anything else.
 */
class RemoveShopController extends Controller
{
    public function destroy(Request $request, Customer $customer, ShopRemover $remover): RedirectResponse
    {
        $request->validate(['why' => ['nullable', 'string', 'max:255']]);

        try {
            $result = $remover->remove($customer, $request->input('why'));
        } catch (Throwable $e) {
            // Every refusal inside ShopRemover says what it did not do, and it
            // is always "nothing". Back to the shop's own page, which is still
            // there, rather than to a list where it would look gone.
            return back()->with('warning', $e->getMessage());
        }

        $said = "{$customer->name} has been removed. Their last backup is at {$result['backup']}.";

        if ($result['left'] !== []) {
            return redirect()->route('customers.show', $customer)->with('warning', $said
                .' These were left behind and need doing by hand: '.implode('; ', $result['left']).'.');
        }

        // The list, not the shop's page: the shop is gone, and landing on a
        // page that says so is a better answer than one that still looks live.
        return redirect()->route('customers.index')->with('success', $said);
    }
}
