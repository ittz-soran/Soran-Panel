<?php

namespace App\Support;

use Illuminate\Support\Carbon;

/**
 * What the panel made of a pasted licence — PANEL_DOC Section 6, step 3.
 *
 * "The panel verifies it against the public key before writing anything. A
 * licence that does not verify, or names a different host, or is already
 * expired, is refused there — not on the customer's server."
 */
final class LicenceCheck
{
    /** The string is not two base64 parts, or the signature does not check out. */
    public const NOT_SIGNED = 'not_signed';

    /** Signed perfectly, and for somebody else's shop. */
    public const WRONG_HOST = 'wrong_host';

    /** Signed perfectly, and already over. */
    public const EXPIRED = 'expired';

    /** Signed, but with nothing in it the panel can use. */
    public const UNREADABLE = 'unreadable';

    private function __construct(
        public readonly bool $ok,
        public readonly ?string $problem = null,
        public readonly ?string $id = null,
        public readonly ?string $shop = null,
        public readonly ?string $host = null,
        public readonly ?Carbon $issued = null,
        public readonly ?Carbon $expires = null,
    ) {}

    public static function good(?string $id, ?string $shop, ?string $host, ?Carbon $issued, ?Carbon $expires): self
    {
        return new self(true, null, $id, $shop, $host, $issued, $expires);
    }

    public static function refused(string $problem, ?string $host = null, ?Carbon $expires = null): self
    {
        return new self(false, $problem, host: $host, expires: $expires);
    }

    /** Why it was refused, in words meant for the person holding the paste. */
    public function because(string $wantedHost): string
    {
        return match ($this->problem) {
            self::NOT_SIGNED => 'This is not a licence signed by your key. Check you copied the whole string — '
                .'it is one long line with a single dot in it, and an editor that wrapped it may have added a space.',
            self::WRONG_HOST => sprintf(
                'This licence is for %s, and this shop is %s. Re-issue it with --host=%s.',
                $this->host ?: 'no domain at all', $wantedHost, $wantedHost,
            ),
            self::EXPIRED => sprintf(
                'This licence ran out on %s, so delivering it would lock the shop out. Issue a new one.',
                $this->expires?->toDateString() ?? 'a date already past',
            ),
            default => 'This licence verified, but there is nothing readable inside it.',
        };
    }
}
