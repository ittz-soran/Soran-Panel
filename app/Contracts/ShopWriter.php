<?php

namespace App\Contracts;

use App\Models\Customer;

/**
 * The few things the panel may change about a shop — PANEL_DOC Section 7.
 *
 * Its `.env` and its compiled caches, and nothing else. Section 7's last line
 * is the boundary: the panel may never write to a shop's business tables,
 * delete its database, or hold the private key. Its settings file is not its
 * data, and changing a storage limit or delivering a licence is exactly what
 * Soran bought this panel to do.
 *
 * Behind an interface for the same reason as ShopReader (Section 8): if a
 * customer is ever hosted elsewhere, this is the other half of what changes,
 * and it changes alone.
 */
interface ShopWriter
{
    /**
     * Change some keys in a shop's `.env`, and take out others.
     *
     * @param  array<string, string>  $set  keys to write or replace
     * @param  list<string>  $remove  keys to take out entirely
     * @param  list<string>  $removeIfBlank
     *                                       Keys to take out only when they are set to nothing. PANEL_DOC
     *                                       Section 6 asks the Renew flow to "remove the blank
     *                                       LICENCE_PUBLIC_KEY line" — blank is what switches a shop's
     *                                       licensing off, and a shop that has one set on purpose is saying
     *                                       something deliberate that the panel has no business deleting.
     *
     * @throws \RuntimeException if the file cannot be read or written
     */
    public function putEnv(Customer $customer, array $set, array $remove = [], array $removeIfBlank = []): void;

    /**
     * Make the shop notice — PANEL_DOC Section 6, step 6.
     *
     * A shop with a cached config reads its old `.env` for ever. Returns false
     * when the shop could not be asked at all.
     */
    public function clearCache(Customer $customer): bool;
}
