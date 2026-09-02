<?php

namespace App\Http\Controllers;

use App\Services\ShopProvisioner;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\View\View;
use Throwable;

/**
 * A shop that already exists — build order step 10, the Halabja half.
 *
 * PANEL_DOC Section 4 records Halabja-phone's install folder being deleted
 * after it was found serving its own `.env` to anyone, and Section 13 records
 * the decision that followed: "its database must be kept", "because that is
 * what a rebuilt install restores from".
 *
 * So this screen is New customer read backwards. It asks for the database
 * instead of naming one, and everything it refuses, it refuses before writing
 * anything — a customer with years of trading in their tables gets no second
 * chance at a mistake here.
 *
 * There is no separate "look first" step, because the refusals ARE the look:
 * every one of them reports what was found, says nothing has been changed, and
 * leaves the form filled in to try again. A preview screen would be a second
 * read of the same database and one more thing to keep honest.
 */
class TakeOnController extends Controller
{
    public function create(): View
    {
        return view('customers.take-on', [
            'defaultFee' => 50000,
            'defaultLimit' => 2048,
        ]);
    }

    public function store(Request $request, ShopProvisioner $provisioner): RedirectResponse
    {
        $fields = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'short_name' => ['required', 'string', 'min:2', 'max:20', 'regex:/^[a-z][a-z0-9]*$/'],

            'host' => ['required', 'string', 'max:255', 'regex:/^[a-z0-9.-]+\.[a-z]{2,}$/i',
                Rule::unique('customers', 'host')->withoutTrashed()],

            /*
             * Their database, exactly as it is. Not derived from the short name
             * the way New customer derives it: this one was named by whoever
             * made it, years ago, and guessing at it would point the shop at
             * nothing — or worse, at somebody else's.
             */
            'database' => ['required', 'string', 'max:64', 'regex:/^[A-Za-z0-9_]+$/'],
            'database_user' => ['required', 'string', 'max:64', 'regex:/^[A-Za-z0-9_]+$/'],
            'database_password' => ['required', 'string', 'max:255'],

            /*
             * Their original APP_KEY, when their staff have authenticators.
             * Only asked for, never required here — whether it is needed
             * depends on what is in their `users` table, which is not known
             * until the database is read, and the provisioner refuses with an
             * explanation rather than this form guessing.
             */
            'app_key' => ['nullable', 'string', 'max:255', 'regex:/^base64:[A-Za-z0-9+\/=]+$/'],

            'contact_name' => ['nullable', 'string', 'max:255'],
            'phone' => ['nullable', 'string', 'max:40'],
            'email' => ['nullable', 'email', 'max:255'],
            'monthly_fee' => ['required', 'integer', 'min:0', 'max:100000000'],
            'storage_limit_mb' => ['nullable', 'integer', 'min:64', 'max:1048576'],

            // `sometimes`, not `nullable`: an unticked checkbox is not
            // submitted at all, and `accepted` fails on a field that is absent
            // — which would make declining the backup impossible rather than
            // merely loud.
            'backup' => ['sometimes', 'accepted'],
            'standing' => ['required', Rule::in(['active', 'trial', 'licence'])],
            'licence' => ['nullable', 'string', 'max:8000', 'required_if:standing,licence'],
            'notes' => ['nullable', 'string', 'max:5000'],
        ], [
            'short_name.regex' => 'The short name must be lower-case letters and numbers, starting with a letter — '
                .'it becomes the folder this builds. Their database keeps its own name.',
            'database.regex' => 'A database name is letters, numbers and underscores. Copy it from cPanel exactly, '
                .'prefix and all.',
            'app_key.regex' => 'An APP_KEY looks like `base64:` and then a long string. Copy the whole line from '
                .'the old install’s .env, after the `=`.',
            'licence.required_if' => 'Paste the signed licence, or take them on as active and renew afterwards.',
        ]);

        try {
            $made = $provisioner->takeOn([
                ...$fields,
                'app_key' => $fields['app_key'] ?? null,
                'storage_limit_mb' => $fields['storage_limit_mb'] ?? null,

                // Ticked by default in the form, and unticking it is a decision
                // the provisioner says out loud in the warnings.
                'backup' => $request->boolean('backup'),
                'trial' => $fields['standing'] === 'trial',
                'licence' => $fields['licence'] ?? null,
            ]);
        } catch (Throwable $e) {
            /*
             * Everything that can fail here fails before their data is touched,
             * and every message says so. Straight back to the form with what
             * was typed still in it, because the usual next step is one field
             * changed — the password, or the APP_KEY it just asked for.
             */
            return back()->withInput()->with('warning', $e->getMessage());
        }

        $found = $made['found'];

        return redirect()->route('customers.show', $made['customer'])
            ->with($made['warnings'] === [] ? 'success' : 'warning', trim(sprintf(
                '%s is on the panel, with %s, %s and %s already in their database. %s %s',
                $made['customer']->name,
                trans_choice(':count user|:count users', $found['users']),
                trans_choice(':count product|:count products', $found['products']),
                trans_choice(':count sale|:count sales', $found['sales']),
                $made['migrations_run'] === 0
                    ? 'Their schema was already up to date.'
                    : trans_choice(':count migration was run.|:count migrations were run.', $made['migrations_run']),
                implode(' ', $made['warnings']),
            )));
    }
}
