<?php

namespace App\Contracts;

/**
 * Publishing a shop's name, so the world can find it.
 *
 * The third and last thing a shop needs that is not a folder or a database —
 * and, until now, the one step of selling a shop that always meant leaving the
 * panel.
 *
 * ⚠️ **Not the same as pointing the domain.** DomainMaker tells the WEB SERVER
 * which folder answers for a name; this tells the INTERNET where that server
 * is. Doing only the first gives a correctly configured site nobody can reach,
 * which is exactly what happened on this account:
 *
 *     This site can’t be reached
 *     panel.soranstore.com’s server IP address could not be found.
 *
 * They are separate because the authority for each is separate. cPanel owns
 * the vhost; Cloudflare owns the zone, and nothing cPanel writes to its own DNS
 * is ever published while the nameservers point elsewhere.
 */
interface DnsMaker
{
    /**
     * Publish `$host`, pointing at `$address`.
     *
     * @throws \RuntimeException with what the provider said, if anything fails.
     */
    public function create(string $host, string $address): void;

    /**
     * Unpublish it. Used to roll back a creation that failed part-way, and
     * when a shop is removed.
     *
     * Never throws: it runs when something has already gone wrong, and a
     * rollback that explodes buries the error that caused it.
     *
     * @return list<string> what could not be removed
     */
    public function remove(string $host): array;

    /** What `panel:check` should say, and what to do by hand when it is manual. */
    public function describe(): string;

    /** Whether this one actually publishes names, or only says it cannot. */
    public function isAutomatic(): bool;
}
