<?php

namespace App\Services;

use App\Support\LicenceCheck;
use Illuminate\Support\Carbon;

/**
 * Checking a pasted licence — PANEL_DOC Section 6, step 3.
 *
 * The panel refuses a bad licence here rather than letting the customer's shop
 * refuse it later. The difference matters: by the time the shop refuses it, its
 * .env has already been rewritten and the shopkeeper is locked out of their
 * till, at which point somebody has to drive there.
 *
 * This is deliberately the same arithmetic as the shop system's Licence
 * service — base64url body, a dot, base64url RSA-SHA256 signature over the raw
 * JSON — because the panel and the shop must agree exactly. A panel that
 * accepted a string the shop rejects would break a customer by renewing them.
 *
 * It VERIFIES and it cannot sign. There is no private key here and there never
 * will be: Section 6's first line, and the reason the paste exists at all.
 */
class LicenceVerifier
{
    public function verify(string $pasted, string $wantedHost): LicenceCheck
    {
        // An editor, a chat window or a terminal will wrap a 400-character
        // string, and what comes back on the clipboard has newlines and
        // spaces through it. Those are never part of the licence, so taking
        // them out is not being lenient about a bad paste — it is undoing
        // damage done in transit.
        $pasted = preg_replace('/\s+/', '', trim($pasted)) ?? '';

        $parts = explode('.', $pasted);

        if (count($parts) !== 2) {
            return LicenceCheck::refused(LicenceCheck::NOT_SIGNED);
        }

        $body = $this->decode($parts[0]);
        $signature = $this->decode($parts[1]);

        if ($body === false || $signature === false) {
            return LicenceCheck::refused(LicenceCheck::NOT_SIGNED);
        }

        $public = openssl_pkey_get_public($this->armour((string) config('licence.public_key')));

        if ($public === false || openssl_verify($body, $signature, $public, OPENSSL_ALGO_SHA256) !== 1) {
            return LicenceCheck::refused(LicenceCheck::NOT_SIGNED);
        }

        $payload = json_decode($body, true);

        if (! is_array($payload)) {
            return LicenceCheck::refused(LicenceCheck::UNREADABLE);
        }

        $host = $payload['host'] ?? null;

        // Kept at the end of the day: a licence good until the 29th is good
        // all of the 29th. The shop system counts it the same way, and an
        // off-by-one here would refuse a licence the shop would have accepted.
        $expires = isset($payload['expires']) ? Carbon::parse($payload['expires'])->endOfDay() : null;

        if (! $this->hostMatches($host, $wantedHost)) {
            return LicenceCheck::refused(LicenceCheck::WRONG_HOST, $host, $expires);
        }

        if ($expires !== null && $expires->isPast()) {
            return LicenceCheck::refused(LicenceCheck::EXPIRED, $host, $expires);
        }

        return LicenceCheck::good(
            $payload['id'] ?? null,
            $payload['shop'] ?? null,
            $host,
            isset($payload['issued']) ? Carbon::parse($payload['issued']) : null,
            $expires,
        );
    }

    /**
     * The same rule the shop system applies, and it must stay the same rule.
     *
     * A licence with no host named is portable on purpose — the seller's own
     * machine, or a shop that has not settled on a domain — and `www.` is the
     * same shop.
     */
    private function hostMatches(?string $licensed, string $wanted): bool
    {
        if ($licensed === null || $licensed === '') {
            return true;
        }

        $strip = fn (string $host) => preg_replace('/^www\./i', '', mb_strtolower(trim($host)));

        return $strip($licensed) === $strip($wanted);
    }

    private function decode(string $encoded): string|false
    {
        return base64_decode(strtr($encoded, '-_', '+/'), true);
    }

    private function armour(string $key): string
    {
        $key = trim($key);

        if ($key === '' || str_contains($key, 'BEGIN')) {
            return $key;
        }

        $body = chunk_split(preg_replace('/\s+/', '', $key), 64, "\n");

        return "-----BEGIN PUBLIC KEY-----\n".$body.'-----END PUBLIC KEY-----';
    }
}
