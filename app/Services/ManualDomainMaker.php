<?php

namespace App\Services;

use App\Contracts\DomainMaker;

/**
 * The panel does not point domains here — a person does.
 *
 * What runs on a laptop, and on any host that is not cPanel. It deliberately
 * does nothing rather than pretending: a shop still gets its database, its
 * folder and its tables, and the operator is told plainly that the domain is
 * theirs to point.
 *
 * Saying so out loud matters more than it looks. A shop whose domain was never
 * pointed is not broken — it is finished except for one step somewhere else —
 * and a panel that stayed silent about it would leave that looking like a
 * fault in the shop.
 */
class ManualDomainMaker implements DomainMaker
{
    public function create(string $host, string $documentRoot): void
    {
        // Nothing to do, and nothing to pretend.
    }

    public function remove(string $host): array
    {
        return [];
    }

    public function secure(string $host): ?string
    {
        return null;
    }

    public function describe(): string
    {
        return 'not pointed by the panel — point each shop’s domain by hand';
    }

    public function isAutomatic(): bool
    {
        return false;
    }
}
