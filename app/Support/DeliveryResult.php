<?php

namespace App\Support;

use App\Models\Licence;

/**
 * What happened when a licence was delivered — PANEL_DOC Section 6.
 *
 * Three outcomes, not two, and the middle one is the point of step 7. A licence
 * can be written into a shop's `.env` perfectly and still not work: the shop is
 * asked what it now thinks, and if the answer is not "valid" then the delivery
 * is not finished, whatever the file says.
 */
final class DeliveryResult
{
    private function __construct(
        public readonly bool $written,
        public readonly bool $confirmed,
        public readonly ?Licence $licence = null,
        public readonly ?string $shopSays = null,
        public readonly ?string $problem = null,
    ) {}

    /** Written, and the shop says it is working. */
    public static function delivered(Licence $licence, string $shopSays): self
    {
        return new self(true, true, $licence, $shopSays);
    }

    /** Written, and the shop does not agree it is working. */
    public static function unconfirmed(Licence $licence, ?string $shopSays, string $problem): self
    {
        return new self(true, false, $licence, $shopSays, $problem);
    }

    /** Nothing was written at all. */
    public static function refused(string $problem): self
    {
        return new self(false, false, problem: $problem);
    }
}
