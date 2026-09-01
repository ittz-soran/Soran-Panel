<?php

namespace App\Contracts;

use App\Models\Customer;
use App\Support\ShopReading;

/**
 * How the panel looks at a shop — PANEL_DOC Section 8.
 *
 * One method, and an interface at all, for the reason Section 8 gives: "If a
 * customer is ever hosted elsewhere, this is the part that changes, and it
 * changes alone. Keep the reading behind one service so a second implementation
 * can slot in."
 *
 * Everything above this line — the health check, the screens, the Overview —
 * asks a shop what it is doing and does not know whether the answer came off
 * this server's disk or off somebody else's HTTP endpoint.
 */
interface ShopReader
{
    /**
     * Look at one shop, and never throw.
     *
     * A reading that failed is a reading: `reachable` false with the reason in
     * `problems`. The hourly check runs over every customer, and one shop with
     * a stopped database must not stop the other five being looked at.
     */
    public function read(Customer $customer): ShopReading;

    /**
     * Just what the shop makes of its own licence — PANEL_DOC Section 6, step 7.
     *
     * "The shop is asked what it now thinks, and the answer is shown back.
     * `delivered_at` is set from a confirmation, never from an assumption."
     *
     * Separate from read() because read() runs the data check over every row
     * the shop has, and a person is standing at the screen waiting for this
     * one. Returns the shop's own word — valid, expiring, missing, invalid,
     * wrong_host, unlicensed — or null if it could not be asked at all, which
     * is not the same as a licence that did not work.
     */
    public function licenceState(Customer $customer): ?string;
}
