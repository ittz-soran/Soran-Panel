<?php

namespace App\Services;

use App\Contracts\DomainMaker;
use App\Support\HomeFolder;
use App\Support\Uapi;
use RuntimeException;
use Throwable;

/**
 * A subdomain pointed at a shop's folder, through cPanel's UAPI.
 *
 * `SubDomain::addsubdomain` wants three things, and each is a place the manual
 * route goes wrong:
 *
 *   - `domain` is the LABEL only — `bazaar`, not `bazaar.soranstore.com`.
 *   - `rootdomain` is what it hangs off — `soranstore.com`.
 *   - `dir` is the document root, RELATIVE TO THE HOME FOLDER.
 *
 * That last one is the whole reason this class exists; see DomainMaker.
 */
class CpanelDomainMaker implements DomainMaker
{
    public function __construct(private readonly Uapi $uapi = new Uapi) {}

    public function create(string $host, string $documentRoot): void
    {
        [$label, $root] = $this->split($host);

        $this->uapi->call('SubDomain', 'addsubdomain', [
            'domain' => $label,
            'rootdomain' => $root,
            'dir' => $this->relativeToHome($documentRoot),

            // cPanel would otherwise turn `shop.two` into `shop_two`, quietly
            // making a domain nobody asked for and nothing points at.
            'disallowdot' => 1,
        ]);
    }

    /**
     * Take the subdomain off, and then CHECK.
     *
     * ⚠️ **cPanel accepting `delsubdomain` is not the same as the domain being
     * gone**, and the first version believed it was. A shop was removed — its
     * folders deleted, its database dropped — and `hawler.soranstore.com` was
     * still sitting in cPanel's Domains list pointing at a folder that no
     * longer existed. The panel had reported the removal as complete.
     *
     * That is the worst shape a report can have: everything irreversible
     * happened, and the one part that did not is the part it said had. So the
     * answer comes from asking cPanel what it still has, not from what it said
     * when asked to delete.
     *
     * `DomainInfo::single_domain_data` refuses to describe a domain the account
     * does not have, which is how "gone" is recognised. If that call itself
     * breaks for its own reasons this reads it as gone — the one case still not
     * covered, and it is strictly narrower than trusting the delete.
     */
    public function remove(string $host): array
    {
        $said = [];

        /*
         * ⚠️ **Two ways, because UAPI does not carry this one.** The first real
         * removal came back with cPanel's own words:
         *
         *     The system could not find the function “delsubdomain”
         *     in the module “SubDomain”.
         *
         * `addsubdomain` is in UAPI — creating shops works — and the delete was
         * never moved across from API2. So both are tried, newest first, and
         * cPanel is asked after each whether the domain has actually gone. It
         * is that answer that ends the loop, not either call saying yes: a
         * function that exists and reports success while leaving the domain in
         * place is exactly what this method was rewritten to catch.
         */
        foreach ([
            fn () => $this->uapi->call('SubDomain', 'delsubdomain', ['domain' => $host]),
            fn () => $this->uapi->api2('SubDomain', 'delsubdomain', ['domain' => $host]),
        ] as $try) {
            try {
                $try();
            } catch (Throwable $e) {
                $said[] = $e->getMessage();
            }

            if (! $this->stillListed($host)) {
                return [];
            }
        }

        return ['the domain ['.$host.'], which cPanel still lists'
            .($said === []
                ? ' even though it accepted the request to delete it'
                : ' ('.implode(' ', $said).')')
            .' — remove it in cPanel → Domains, or it points at a folder that is gone'];
    }

    /** Whether cPanel will still describe this domain, which means it has it. */
    private function stillListed(string $host): bool
    {
        try {
            $this->uapi->call('DomainInfo', 'single_domain_data', ['domain' => $host]);

            return true;
        } catch (Throwable) {
            return false;
        }
    }

    public function secure(string $host): ?string
    {
        try {
            /*
             * The account-wide run, because that is what cPanel offers: AutoSSL
             * looks at every domain that has none. It is asynchronous, so a
             * success here means "asked", not "issued".
             */
            $this->uapi->call('SSL', 'start_autossl_check', []);

            return null;
        } catch (Throwable $e) {
            return "A certificate for {$host} was not requested — {$e->getMessage()} cPanel runs AutoSSL on "
                .'its own schedule anyway, so this usually sorts itself out; SSL/TLS Status has a button if not.';
        }
    }

    public function describe(): string
    {
        return sprintf('uapi at [%s], domains pointed automatically', config('panel.cpanel.uapi'));
    }

    public function isAutomatic(): bool
    {
        return true;
    }

    /**
     * `bazaar.soranstore.com` → [`bazaar`, `soranstore.com`].
     *
     * Split at the FIRST dot, not the last: `till.bazaar.soranstore.com` is a
     * subdomain `till` of `bazaar.soranstore.com`, and that is how cPanel wants
     * it. It fails if the parent is not a domain on the account, which is
     * cPanel's error to give and not this one's to guess at.
     */
    private function split(string $host): array
    {
        $host = mb_strtolower(trim($host));

        if (substr_count($host, '.') < 2) {
            throw new RuntimeException(
                "[{$host}] is not a subdomain of a domain on this account — it has no part in front of the "
                .'domain itself. Give something like bazaar.soranstore.com.',
            );
        }

        [$label, $root] = explode('.', $host, 2);

        return [$label, $root];
    }

    /**
     * ⚠️ The trap this class exists for.
     *
     * cPanel's `dir` is relative to the home folder. Hand it
     * `/home/soransto/public_html/bazaar` and the domain is served from
     * `/home/soransto/home/soransto/public_html/bazaar`, which does not exist —
     * so every request 404s, including static files, and it reads like a
     * missing vhost rather than a wrong path.
     */
    private function relativeToHome(string $documentRoot): string
    {
        $home = HomeFolder::find();

        if ($home === '') {
            throw new RuntimeException(
                'The home folder is not known — neither $HOME nor the account this runs as would say — '
                .'and a document root has to be given relative to it. '
                .'Set PANEL_CPANEL_HOME in the panel’s .env: it is what `echo $HOME` prints on the server.',
            );
        }

        $documentRoot = rtrim($documentRoot, '/');

        if (! str_starts_with($documentRoot, $home.'/')) {
            throw new RuntimeException(
                "[{$documentRoot}] is not inside [{$home}], so cPanel cannot serve a domain from it. "
                .'PANEL_SHOPS_PUBLIC must be a folder in the account’s home directory.',
            );
        }

        return ltrim(mb_substr($documentRoot, mb_strlen($home)), '/');
    }
}
