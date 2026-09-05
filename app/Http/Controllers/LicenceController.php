<?php

namespace App\Http\Controllers;

use App\Models\Customer;
use App\Services\LicenceDelivery;
use App\Services\LicencePayload;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * Renew — PANEL_DOC Section 6.
 *
 * The screen's whole job is steps 1 and 2: show the exact command to run on
 * Soran's own machine, and take back what it printed. Everything after the
 * paste is LicenceDelivery's.
 *
 * The command is shown rather than run because of Section 6's first line: the
 * private key never reaches the server. A break-in on soranstore.com must never
 * be able to forge a licence for anybody, and the only way to promise that is
 * for the thing that signs to be somewhere else.
 */
class LicenceController extends Controller
{
    public function create(Customer $customer): View
    {
        return view('customers.renew', [
            'customer' => $customer->load('currentLicence'),
            'command' => $this->commandFor($customer),
            'paidUpTo' => $customer->paidUpTo(),
        ]);
    }

    /**
     * The unsigned licence, for the browser to sign.
     *
     * This is the whole of the panel's part in signing: it says what the
     * licence claims, and hands over the exact bytes. What comes back is a
     * signature made on a machine this server has no reach into.
     *
     * ⚠️ It returns the BODY, not a token. There is nothing secret in it — a
     * licence's contents are shown on the shop's own screen — and it is
     * worthless without the signature, which is the point: this endpoint
     * cannot be used to obtain a licence, only to be told what one would say.
     */
    public function payload(Request $request, Customer $customer, LicencePayload $payloads): JsonResponse
    {
        $fields = $request->validate([
            'months' => ['nullable', 'integer', 'min:1', 'max:120'],
            'until' => ['nullable', 'date', 'after:today'],
            'forever' => ['nullable', 'boolean'],
        ]);

        $payload = $payloads->for($customer, [
            'months' => $fields['months'] ?? null,
            'until' => $fields['until'] ?? null,
            'forever' => $request->boolean('forever'),
        ]);

        return response()->json([
            'body' => $payloads->encode($payloads->body($payload)),
            'shop' => $payload['shop'],
            'host' => $payload['host'],
            'expires' => $payload['expires'],
            'id' => $payload['id'],
        ]);
    }

    public function store(Request $request, Customer $customer, LicenceDelivery $delivery): RedirectResponse
    {
        $fields = $request->validate([
            'licence' => ['required', 'string', 'max:8000'],
            'record_payment' => ['nullable', 'boolean'],
            'amount' => ['nullable', 'integer', 'min:0', 'max:100000000'],
            'covers_from' => ['nullable', 'date'],
            'covers_to' => ['nullable', 'date', 'after_or_equal:covers_from'],
            'method' => ['nullable', 'string', 'max:255'],
        ]);

        $payment = null;

        if ($request->boolean('record_payment')) {
            $request->validate([
                'amount' => ['required', 'integer', 'min:1'],
                'covers_from' => ['required', 'date'],
                'covers_to' => ['required', 'date', 'after_or_equal:covers_from'],
            ], [], ['amount' => 'amount', 'covers_from' => 'period start', 'covers_to' => 'period end']);

            $payment = [
                'amount' => (int) $fields['amount'],
                'covers_from' => $fields['covers_from'],
                'covers_to' => $fields['covers_to'],
                'method' => $fields['method'] ?? null,
            ];
        }

        $result = $delivery->deliver($customer, $fields['licence'], $payment);

        // Nothing was written. Back to the form with the paste still in it, so
        // a 400-character string does not have to be fetched twice.
        if (! $result->written) {
            return back()->withInput()->with('warning', $result->problem);
        }

        if (! $result->confirmed) {
            return redirect()->route('customers.show', $customer)->with('warning', $result->problem);
        }

        return redirect()->route('customers.show', $customer)->with('success', sprintf(
            'Licence %s delivered. The shop was asked, and it reports `%s`%s.',
            $result->licence->licence_id,
            $result->shopSays,
            $result->licence->expires_on ? ' until '.$result->licence->expires_on->toDateString() : ' with no end date',
        ));
    }

    /**
     * The exact line to paste into a terminal on Soran's own machine.
     *
     * Built from what the panel knows, so the host is right and the shop name
     * is spelled the way it will appear on the customer's screen. The only part
     * the panel cannot know is where his private key is, and that is a setting.
     */
    private function commandFor(Customer $customer): string
    {
        return sprintf(
            'php artisan licence:issue %s --host=%s --months=1 --key=%s',
            escapeshellarg($customer->name),
            $customer->host,
            config('licence.private_key_path'),
        );
    }
}
