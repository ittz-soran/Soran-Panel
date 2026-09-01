<?php

namespace App\Http\Controllers;

use App\Models\Customer;
use App\Services\ShopProvisioner;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\View\View;
use Throwable;

/**
 * A new customer, end to end — PANEL_DOC Section 7, build order step 7.
 *
 * Hold to confirm, and the shop's short name typed, because this is the one
 * action that makes something rather than changing it: a database that counts
 * against the account's limit, and a folder somebody will point a domain at.
 * Section 13 records that database count as the only real ceiling left on how
 * many customers fit on this hosting.
 */
class NewCustomerController extends Controller
{
    public function create(): View
    {
        return view('customers.create', [
            'defaultFee' => 50000,
            'defaultLimit' => 2048,
        ]);
    }

    public function store(Request $request, ShopProvisioner $provisioner): RedirectResponse
    {
        $fields = $request->validate([
            'name' => ['required', 'string', 'max:255'],

            // The folder and database name. Deliberately narrow: it becomes a
            // directory, a database, a database user and part of a cPanel
            // prefix, and every one of those has its own opinion about what a
            // name may contain.
            'short_name' => ['required', 'string', 'min:2', 'max:20', 'regex:/^[a-z][a-z0-9]*$/'],

            'host' => ['required', 'string', 'max:255', 'regex:/^[a-z0-9.-]+\.[a-z]{2,}$/i',
                Rule::unique('customers', 'host')->withoutTrashed()],

            'contact_name' => ['nullable', 'string', 'max:255'],
            'phone' => ['nullable', 'string', 'max:40'],
            'email' => ['nullable', 'email', 'max:255'],
            'monthly_fee' => ['required', 'integer', 'min:0', 'max:100000000'],
            'storage_limit_mb' => ['nullable', 'integer', 'min:64', 'max:1048576'],
            'start' => ['required', Rule::in(['trial', 'licence'])],
            'licence' => ['nullable', 'string', 'max:8000', 'required_if:start,licence'],
            'notes' => ['nullable', 'string', 'max:5000'],
        ], [
            'short_name.regex' => 'The short name must be lower-case letters and numbers, starting with a letter — '
                .'it becomes a folder, a database and a database user.',
            'licence.required_if' => 'Paste the signed licence, or start them on a trial instead.',
        ]);

        try {
            $made = $provisioner->create([
                ...$fields,
                'trial' => $fields['start'] === 'trial',
                'licence' => $fields['licence'] ?? null,
                'storage_limit_mb' => $fields['storage_limit_mb'] ?? null,
            ]);
        } catch (Throwable $e) {
            return back()->withInput()->with('warning', $e->getMessage());
        }

        /*
         * The administrator's password, once.
         *
         * The seeder prints it and nothing stores it — it is a hash in the
         * shop's own users table by the time this returns. Flashed to the next
         * screen rather than shown here, so a refresh does not repeat it, and
         * never written to `actions`: a log that carries a password is a log
         * that hands over every shop it describes.
         */
        return redirect()->route('customers.show', $made['customer'])
            ->with('made', [
                'email' => $made['admin_email'],
                'password' => $made['admin_password'],
                'host' => $made['customer']->host,
            ])
            ->with($made['warnings'] === [] ? 'success' : 'warning', $made['warnings'] === []
                ? "{$made['customer']->name} is set up and ready."
                : implode(' ', $made['warnings']));
    }
}
