<?php

namespace App\Services;

use App\Contracts\ShopReader;
use App\Contracts\ShopWriter;
use App\Models\Action;
use App\Models\Customer;
use App\Models\Licence;
use App\Support\DeliveryResult;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * Delivering a licence — PANEL_DOC Section 6, steps 3 to 8.
 *
 * The order here is the whole design, and it is deliberately not the order that
 * would be convenient:
 *
 *   verify        before anything is written, so a bad paste is refused here
 *                 rather than on the customer's server, where refusing it means
 *                 their till is already locked and somebody has to drive there
 *   save the row  as NOT delivered, because at this point it is not
 *   write .env    keeping the old file as .env.bak
 *   clear cache   or the shop reads its old .env for ever
 *   ASK the shop  and only then set delivered_at
 *
 * Step 7 is the one that is easy to leave out and is worth the most. A licence
 * written into a file is not a licence that works: the shop system carries the
 * seller's public key as a committed default, and a trial blanks it in the
 * shop's own .env — so writing a LICENCE_KEY while that blank line is still
 * there leaves the licence completely unchecked, which is the same as no
 * licence. The panel removes the line, and asking the shop is what catches it
 * if that ever stops working.
 */
class LicenceDelivery
{
    public function __construct(
        private readonly LicenceVerifier $verifier,
        private readonly ShopWriter $writer,
        private readonly ShopReader $reader,
    ) {}

    /**
     * @param  array{amount: int, covers_from: string, covers_to: string, method: ?string}|null  $payment
     */
    public function deliver(Customer $customer, string $pasted, ?array $payment = null): DeliveryResult
    {
        // Step 3. Nothing has been written when this returns false.
        $checked = $this->verifier->verify($pasted, $customer->host);

        if (! $checked->ok) {
            Action::record('licence.refused', $customer, [
                'why' => $checked->problem,
                'host_wanted' => $customer->host,
                'host_on_licence' => $checked->host,
            ]);

            return DeliveryResult::refused($checked->because($customer->host));
        }

        $tidied = preg_replace('/\s+/', '', trim($pasted)) ?? '';

        // Step 4. Saved as NOT delivered: nothing has reached the shop yet, and
        // a row that claims otherwise would put a shop on the Customers screen
        // as licensed while it is locked out.
        $licence = DB::transaction(function () use ($customer, $checked, $tidied, $payment) {
            $licence = $customer->licences()->create([
                'licence_id' => $checked->id ?? 'UNKNOWN',
                'host' => $checked->host,
                'licence_key' => $tidied,
                'issued_on' => $checked->issued ?? now(),
                'expires_on' => $checked->expires,
                'issued_by' => auth()->id(),
            ]);

            // Step 8's payment, recorded in the same transaction as the licence
            // it paid for. Money taken and no licence recorded, or the reverse,
            // is a conversation nobody can reconstruct three months later.
            if ($payment !== null) {
                $customer->payments()->create([...$payment, 'paid_on' => now(), 'recorded_by' => auth()->id()]);
            }

            return $licence;
        });

        // Step 5 and the Section 6 warning. Both edits, or the licence is
        // written and never checked.
        try {
            $this->writer->putEnv(
                $customer,
                set: ['LICENCE_KEY' => $tidied],
                removeIfBlank: ['LICENCE_PUBLIC_KEY'],
            );
        } catch (Throwable $e) {
            Action::record('licence.delivery_failed', $customer, [
                'licence' => $licence->licence_id,
                'at' => 'writing the shop\'s .env',
                'said' => $e->getMessage(),
            ]);

            return DeliveryResult::unconfirmed($licence, null,
                'The licence is recorded here, and could not be written into the shop: '.$e->getMessage());
        }

        // Step 6.
        $cleared = $this->writer->clearCache($customer);

        // Step 7. The shop's own word, not ours.
        $says = $this->reader->licenceState($customer);

        if ($says === null) {
            Action::record('licence.delivered_unconfirmed', $customer, [
                'licence' => $licence->licence_id, 'shop_says' => null,
            ]);

            return DeliveryResult::unconfirmed($licence, null,
                'The licence is written into the shop, and the shop could not be asked whether it worked. '
                .'Nothing here says it is live until it can be.');
        }

        if (! in_array($says, ['valid', 'expiring'], true)) {
            Action::record('licence.delivered_unconfirmed', $customer, [
                'licence' => $licence->licence_id, 'shop_says' => $says,
            ]);

            return DeliveryResult::unconfirmed($licence, $says, $this->whatThatMeans($says, $customer));
        }

        // Only now.
        $licence->update(['delivered_at' => now()]);

        // A delivered licence replaces the one before it. Left standing, the
        // old row is a second undelivered-looking licence in the history for
        // ever; revoking it says which one this shop is actually running.
        $customer->licences()
            ->whereKeyNot($licence->id)
            ->whereNotNull('delivered_at')
            ->whereNull('revoked_at')
            ->update(['revoked_at' => now(), 'revoked_reason' => 'replaced by '.$licence->licence_id]);

        // A shop that was on trial is not on trial any more — it has a real,
        // signed licence, and the Overview must stop chasing a trial end date
        // that no longer means anything.
        if ($customer->status === Customer::TRIAL) {
            $customer->update(['status' => Customer::ACTIVE]);
        }

        Action::record('licence.delivered', $customer, [
            'licence' => $licence->licence_id,
            'until' => $licence->expires_on?->toDateString(),
            'shop_says' => $says,
            'cache_cleared' => $cleared,
            'payment' => $payment !== null ? $payment['amount'] : null,
        ]);

        return DeliveryResult::delivered($licence, $says);
    }

    /** The shop's own word, turned into what to do about it. */
    private function whatThatMeans(string $says, Customer $customer): string
    {
        return match ($says) {
            'unlicensed' => 'The shop still reports `unlicensed`, which means LICENCE_PUBLIC_KEY is blank in its '
                .'.env — so the licence is not being checked at all, and that is the same as no licence. '
                .'The blank line should have been removed. Its .env.bak has the file as it was.',
            'missing' => 'The shop reports `missing` — it cannot see a LICENCE_KEY at all. The write said it '
                .'worked, so the shop is probably reading a cached config. Its .env.bak has the file as it was.',
            'invalid' => 'The shop reports `invalid` — it will not accept this signature. That means the shop is '
                .'carrying a different public key from the one this panel verified against.',
            'wrong_host' => "The shop reports `wrong_host`. The panel checked this licence against {$customer->host}, "
                .'so the shop is running on a different domain from the one recorded here.',
            'expired', 'grace' => 'The shop reports the licence as already past its date, which should not be '
                .'possible for one just issued. Check the date on the machine that signed it.',
            default => "The shop reports `{$says}`, which is not a working licence.",
        };
    }
}
