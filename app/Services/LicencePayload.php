<?php

namespace App\Services;

use App\Models\Customer;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;

/**
 * The thing a licence is, before it is signed — PANEL_DOC Section 6.
 *
 * Built here, in PHP, and NOT in the browser that signs it. The browser's one
 * job is the RSA sum; everything about what a licence says stays on the side
 * that already knows the shop, and that already gets dates right.
 *
 * That division is not fussiness. This has to agree with `licence:issue` on
 * every detail, because the shop reads licences from both and Soran will
 * compare them. Re-implementing month arithmetic in JavaScript would be a
 * second version of the same rule, free to drift from the first — and a
 * licence that quietly expires on the wrong day is not found until a shop
 * stops taking money.
 *
 * ⚠️ `addMonths` OVERFLOWS, and that is deliberate here only because
 * `licence:issue` does the same: 31 January plus one month is 3 March, not
 * 28 February. Carbon clamps only with `addMonthsNoOverflow`. If that is ever
 * judged wrong it must be changed in the shop system's command first, and
 * followed here — never fixed on one side.
 */
class LicencePayload
{
    /**
     * @param  array{months?: ?int, until?: ?string, forever?: bool}  $wanted
     * @return array{id: string, shop: string, host: ?string, issued: string, expires: ?string}
     */
    public function for(Customer $customer, array $wanted): array
    {
        return [
            'id' => strtoupper(Str::random(4).'-'.Str::random(4)),
            'shop' => $customer->name,
            'host' => $customer->host ?: null,
            'issued' => now()->toDateString(),
            'expires' => $this->expires($wanted),
        ];
    }

    /**
     * The bytes that get signed, and that the shop will verify over.
     *
     * ⚠️ The same flags as `licence:issue`. They do not have to match for the
     * signature to check out — the shop verifies over whatever body the token
     * carries, not over a re-encoding of it — but a licence issued here and one
     * issued from a terminal should be the same string for the same facts, or
     * comparing two of them tells you nothing.
     */
    public function body(array $payload): string
    {
        return (string) json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    }

    /** base64url, no padding — the shop's own encoding. */
    public function encode(string $raw): string
    {
        return rtrim(strtr(base64_encode($raw), '+/', '-_'), '=');
    }

    /** @param array{months?: ?int, until?: ?string, forever?: bool} $wanted */
    private function expires(array $wanted): ?string
    {
        return match (true) {
            ($wanted['forever'] ?? false) => null,
            filled($wanted['until'] ?? null) => Carbon::parse((string) $wanted['until'])->toDateString(),
            default => now()->addMonths(max(1, (int) ($wanted['months'] ?? 1)))->toDateString(),
        };
    }
}
