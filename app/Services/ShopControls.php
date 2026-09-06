<?php

namespace App\Services;

use App\Contracts\ShopReader;
use App\Contracts\ShopWriter;
use App\Models\Action;
use App\Models\Customer;
use App\Support\ShopBackup;
use RuntimeException;

/**
 * The things Soran does to a shop that is already running — PANEL_DOC Section 7.
 *
 * Most of them work the same way: change the shop's `.env`, make the shop
 * notice, ask it what it now thinks, and write down what was done and by whom.
 * None of them CHANGE a shop's data. Section 7's last line is the boundary and
 * ReadOnlyConnection is what enforces it — `backUp()` reads all of it, through
 * the shop's own tooling, and writes none of it.
 */
class ShopControls
{
    public function __construct(
        private readonly ShopWriter $writer,
        private readonly ShopReader $reader,
    ) {}

    /**
     * A new ceiling on how much disk a shop may use.
     *
     * Written into the shop's own `.env`, because that is where the shop reads
     * it — the number on the customer row is the panel's copy, and a copy that
     * disagrees with the shop is worse than no copy. Both are set here, in that
     * order, so a failure leaves the panel's record matching the shop's.
     */
    public function setStorageLimit(Customer $customer, ?int $megabytes): void
    {
        $was = $customer->storage_limit_mb;

        if ($was === $megabytes) {
            return;
        }

        $this->writer->putEnv($customer, ['STORAGE_LIMIT_MB' => (string) ($megabytes ?? '')]);
        $this->writer->clearCache($customer);

        $customer->update(['storage_limit_mb' => $megabytes]);

        Action::record('storage_limit.changed', $customer, ['from' => $was, 'to' => $megabytes]);
    }

    /**
     * Stop a shop, without locking anybody out of their own records.
     *
     * The lever is the licence, and that is deliberate. Taking the LICENCE_KEY
     * away puts the shop in the state the shop system already has a considered
     * answer for: read-only. PROJECT_DOC is explicit that reading, printing,
     * deleting and signing in never stop whatever the licence says, "because a
     * shop locked out of its own records is a shop that will never pay another
     * invoice" — which is exactly right for a customer who is behind on
     * payment, since the point is to be paid rather than to punish.
     *
     * The licence row is kept and not revoked. Resuming puts the same string
     * back, and until then the shop can still be looked at.
     */
    /**
     * @return array{ok: bool, said: string}
     *                                       `ok` is whether the SHOP agreed, not whether the file was
     *                                       written. The two come apart, and a green message for a shop that
     *                                       carried on trading is the one outcome worth catching.
     */
    public function suspend(Customer $customer, ?string $why = null): array
    {
        if ($customer->status === Customer::SUSPENDED) {
            return ['ok' => true, 'said' => 'That shop is already suspended.'];
        }

        $this->writer->putEnv($customer, ['LICENCE_KEY' => '']);
        $this->writer->clearCache($customer);

        $says = $this->reader->licenceState($customer);

        $customer->update(['status' => Customer::SUSPENDED]);

        Action::record('shop.suspended', $customer, ['why' => $why, 'shop_says' => $says]);

        // `missing` is the shop agreeing it has been stopped. Anything else
        // means the file was written and the shop did not notice, which for a
        // suspension is the failure that matters: the customer carries on
        // trading and nobody finds out until the next hourly check.
        return $says === 'missing'
            ? ['ok' => true, 'said' => "{$customer->name} is suspended. The shop is read-only — "
                .'they can still read and print their own records.']
            : ['ok' => false, 'said' => "The licence was taken out of {$customer->name}'s .env, and the shop "
                .'reports `'.($says ?? 'nothing at all').'` rather than `missing`. It may still be trading. Check it.'];
    }

    /**
     * A backup of one shop, now, because somebody asked — Section 7.
     *
     * The panel already takes one before migrating a shop and before removing
     * one. This is the same dump with no second half: the answer to "send me
     * their data" and to "I am about to do something by hand and want a copy
     * first", neither of which had anything to press.
     *
     * The path is recorded rather than returned into a session, because that is
     * what the download route reads. **The panel only ever hands over a file it
     * has a record of writing itself** — no path from the operator reaches the
     * filesystem, which matters when the file is a whole customer's database.
     *
     * @return array{path: string, bytes: int, action: Action}
     */
    public function backUp(Customer $customer): array
    {
        $path = ShopBackup::take((string) $customer->shop_home, 'so there is no backup to hand you');

        $bytes = (int) filesize($path);

        return [
            'path' => $path,
            'bytes' => $bytes,
            'action' => Action::record('shop.backed_up', $customer, ['path' => $path, 'bytes' => $bytes]),
        ];
    }

    /**
     * Give a shop back the licence it already had.
     *
     * Not a renewal: no new row, no paste, nothing signed. The string is the
     * one already on record, so a shop suspended for a late payment comes back
     * on exactly the licence it was running before.
     *
     * @return array{ok: bool, said: string}
     */
    public function resume(Customer $customer): array
    {
        $licence = $customer->licences()
            ->whereNotNull('delivered_at')->whereNull('revoked_at')
            ->orderByDesc('issued_on')->orderByDesc('id')
            ->first();

        if ($licence === null) {
            throw new RuntimeException(
                'This shop has no licence on record to give back. Renew it instead — that is the flow that '
                .'checks a licence before it is written.',
            );
        }

        if ($licence->expires_on !== null && $licence->expires_on->endOfDay()->isPast()) {
            throw new RuntimeException(sprintf(
                'The licence on record ran out on %s, so putting it back would leave the shop read-only anyway. '
                .'Renew it instead.',
                $licence->expires_on->toDateString(),
            ));
        }

        $this->writer->putEnv(
            $customer,
            set: ['LICENCE_KEY' => $licence->licence_key],
            removeIfBlank: ['LICENCE_PUBLIC_KEY'],
        );
        $this->writer->clearCache($customer);

        $says = $this->reader->licenceState($customer);

        $customer->update(['status' => Customer::ACTIVE]);

        Action::record('shop.resumed', $customer, ['licence' => $licence->licence_id, 'shop_says' => $says]);

        return in_array($says, ['valid', 'expiring'], true)
            ? ['ok' => true, 'said' => "{$customer->name} is trading again, on licence {$licence->licence_id}. "
                ."The shop reports `{$says}`."]
            : ['ok' => false, 'said' => "Licence {$licence->licence_id} was put back, and the shop reports `"
                .($says ?? 'nothing at all').'` rather than a working licence. It is still read-only. Check it.'];
    }
}
