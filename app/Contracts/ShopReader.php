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
}
