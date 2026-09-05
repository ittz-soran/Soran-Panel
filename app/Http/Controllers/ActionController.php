<?php

namespace App\Http\Controllers;

use App\Models\Action;
use App\Models\Customer;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * What I changed — the `actions` log, PANEL_DOC Section 9.
 *
 * Section 1, rule 2: "anything that reaches into a customer's install leaves a
 * record with a name on it." A record nobody can read is not a record, so this
 * is the screen that makes that rule true rather than merely stored.
 *
 * Read-only, and there is no way to delete a row from here or anywhere else.
 * The table has no `updated_at` for the same reason — a log somebody can edit
 * is not a log.
 */
class ActionController extends Controller
{
    public function index(Request $request): View
    {
        $filters = $request->validate([
            'customer' => ['nullable', 'integer', 'exists:customers,id'],
            'user' => ['nullable', 'integer', 'exists:users,id'],
            'action' => ['nullable', 'string', 'max:255'],
        ]);

        return view('actions.index', [
            'actions' => Action::query()
                ->with(['customer', 'user'])
                ->when($filters['customer'] ?? null, fn ($query, $id) => $query->where('customer_id', $id))
                ->when($filters['user'] ?? null, fn ($query, $id) => $query->where('user_id', $id))
                ->when($filters['action'] ?? null, fn ($query, $action) => $query->where('action', $action))
                ->latest('created_at')->latest('id')
                ->paginate(50)
                ->withQueryString(),

            // withTrashed, like the operators below: a removed shop is one of
            // the things you most want to read the history of, and it is the
            // one the plain query drops.
            'customers' => Customer::withTrashed()->orderBy('name')->get(['id', 'name']),
            'operators' => User::withTrashed()->orderBy('name')->get(['id', 'name']),

            // What has actually happened, rather than a list of everything the
            // panel could ever do — a filter offering twenty things that have
            // never occurred is a filter nobody uses twice.
            'kinds' => Action::query()->distinct()->orderBy('action')->pluck('action'),

            'chosen' => $filters,
        ]);
    }
}
