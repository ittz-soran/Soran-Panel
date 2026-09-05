<?php

namespace App\Contracts;

/**
 * Pointing a domain at a shop's public folder.
 *
 * Behind an interface for the same reason as DatabaseMaker: the panel runs on
 * a laptop during development, where there is no cPanel and no domain to
 * point, and on the real account, where cPanel is the only thing that may
 * write a vhost.
 *
 * ⚠️ **This exists because of one afternoon on the live account.** Two domains
 * were created by hand and both 404'd from LiteSpeed for every request,
 * including static files, while the same folders served correctly through the
 * main domain. It read exactly like a missing vhost, and a support ticket had
 * been drafted before `uapi DomainInfo single_domain_data` gave the answer:
 *
 *     documentroot: /home/soransto/home/soransto/public_html/panel
 *     homedir:      /home/soransto
 *
 * cPanel's Document Root field is relative to the home folder. Given the
 * absolute path a person naturally reads off the panel, cPanel appends it to
 * home and serves the domain from a folder that does not exist. Nothing warns
 * you — the Domains list shows the path you typed.
 *
 * So the point of this interface is not to save clicks. It is that the panel
 * knows the absolute path and each implementation converts it correctly, once,
 * in code that is tested — instead of a person retyping it for every shop and
 * getting one shop in three wrong.
 */
interface DomainMaker
{
    /**
     * Point `$host` at `$documentRoot`, which is ABSOLUTE.
     *
     * Converting it to whatever shape the host wants is the implementation's
     * job, and is exactly the knowledge callers must not have to hold.
     *
     * @throws \RuntimeException with what the host said, if anything fails.
     */
    public function create(string $host, string $documentRoot): void;

    /**
     * Take it off again. Used only to roll back a creation that failed
     * part-way, and when a shop is removed.
     *
     * Never throws: it runs when something has already gone wrong, and a
     * rollback that explodes buries the error that caused it.
     *
     * @return list<string> what could not be removed
     */
    public function remove(string $host): array;

    /**
     * What `panel:check` should say about this — and, when the panel cannot
     * point domains itself, what the operator must do by hand instead.
     */
    public function describe(): string;

    /** Whether this one actually points domains, or only says it cannot. */
    public function isAutomatic(): bool;
}
