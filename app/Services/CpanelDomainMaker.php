<?php

namespace App\Services;

use App\Contracts\DomainMaker;
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

    public function remove(string $host): array
    {
        try {
            $this->uapi->call('SubDomain', 'delsubdomain', ['domain' => $host]);

            return [];
        } catch (Throwable $e) {
            return ["the domain [{$host}] ({$e->getMessage()})"];
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
        $home = rtrim((string) (getenv('HOME') ?: config('panel.cpanel.home')), '/');

        if ($home === '') {
            throw new RuntimeException(
                'The home folder is not known, and a document root has to be given relative to it. '
                .'Set PANEL_CPANEL_HOME in the panel’s .env — it is what `echo $HOME` prints on the server.',
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
