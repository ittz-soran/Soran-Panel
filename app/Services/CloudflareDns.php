<?php

namespace App\Services;

use App\Contracts\DnsMaker;
use Illuminate\Support\Facades\Http;
use RuntimeException;
use Throwable;

/**
 * A name published through Cloudflare's API.
 *
 * ⚠️ **This one holds a real key to Soran's business, and it is worth being
 * plain about that.** The token here can rewrite where his domains point. A
 * break-in on this server could send soranstore.com, and every shop on it,
 * anywhere at all — which is a different and larger thing than reading the
 * panel's database.
 *
 * So it is off by default, it is documented as a decision rather than a
 * default, and the token should be a Cloudflare **scoped** token with
 * `Zone:DNS:Edit` on one zone and nothing else. Not a Global API Key, which
 * carries the whole account.
 *
 * The trade it buys: thirty seconds per shop, and one fewer thing to get wrong
 * at the moment of selling.
 */
class CloudflareDns implements DnsMaker
{
    private const API = 'https://api.cloudflare.com/client/v4';

    public function create(string $host, string $address): void
    {
        $host = mb_strtolower(trim($host));

        // Cloudflare is happy to hold two A records for one name and answer
        // with either, which is a shop that works every other request. Take
        // any that are already there first.
        foreach ($this->recordsFor($host) as $existing) {
            $this->delete($existing);
        }

        $this->ask('post', "/zones/{$this->zone()}/dns_records", [
            'type' => 'A',
            'name' => $host,
            'content' => $address,
            'ttl' => 1,   // Cloudflare's own word for "automatic"
            'proxied' => (bool) config('panel.dns.cloudflare.proxied', true),
            'comment' => 'Added by Soran Panel',
        ]);
    }

    public function remove(string $host): array
    {
        $host = mb_strtolower(trim($host));

        try {
            $found = $this->recordsFor($host);

            foreach ($found as $record) {
                $this->delete($record);
            }

            return [];
        } catch (Throwable $e) {
            return ["the DNS record for [{$host}] ({$e->getMessage()})"];
        }
    }

    public function describe(): string
    {
        $zone = (string) config('panel.dns.cloudflare.zone_id');

        return sprintf(
            'Cloudflare, zone %s, records %s',
            $zone === '' ? '(not set)' : '…'.mb_substr($zone, -6),
            config('panel.dns.cloudflare.proxied', true) ? 'proxied' : 'DNS only',
        );
    }

    /**
     * One call proves all three at once: that the token works, that the zone
     * id is real, and that this token is allowed to touch that zone. Three
     * settings that each look fine on their own and only fail together.
     */
    public function verify(): string
    {
        $zone = $this->ask('get', "/zones/{$this->zone()}", []);

        $name = data_get($zone, 'result.name');

        if (! is_string($name) || $name === '') {
            throw new RuntimeException('Cloudflare answered, but not with a zone this token can read.');
        }

        return sprintf(
            'Cloudflare, zone [%s], records %s',
            $name,
            config('panel.dns.cloudflare.proxied', true) ? 'proxied' : 'DNS only',
        );
    }

    public function isAutomatic(): bool
    {
        return true;
    }

    /** @return list<string> the ids of any A records already answering for this name */
    private function recordsFor(string $host): array
    {
        $found = $this->ask('get', "/zones/{$this->zone()}/dns_records", ['type' => 'A', 'name' => $host]);

        return array_values(array_filter(array_map(
            fn (array $record) => $record['id'] ?? null,
            (array) ($found['result'] ?? []),
        )));
    }

    private function delete(string $id): void
    {
        $this->ask('delete', "/zones/{$this->zone()}/dns_records/{$id}", []);
    }

    private function zone(): string
    {
        $zone = (string) config('panel.dns.cloudflare.zone_id');

        if ($zone === '') {
            throw new RuntimeException(
                'PANEL_CLOUDFLARE_ZONE_ID is not set. It is on the zone’s Overview page in Cloudflare, '
                .'in the API panel on the right.',
            );
        }

        return $zone;
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    private function ask(string $method, string $path, array $data): array
    {
        $token = (string) config('panel.dns.cloudflare.token');

        if ($token === '') {
            throw new RuntimeException(
                'PANEL_CLOUDFLARE_TOKEN is not set. Make a token in Cloudflare with Zone:DNS:Edit on this '
                .'one zone — not a Global API Key, which carries the whole account.',
            );
        }

        $response = Http::withToken($token)
            ->acceptJson()
            ->timeout(30)
            ->{$method}(self::API.$path, $data);

        $body = $response->json();

        /*
         * Cloudflare answers 200 with `success: false` for a request it
         * understood and refused, so the status code alone believes every call
         * worked. Its own `errors` are what to show: they name the field.
         */
        if (! is_array($body) || ($body['success'] ?? false) !== true) {
            $said = collect((array) ($body['errors'] ?? []))
                ->map(fn ($error) => is_array($error) ? ($error['message'] ?? '') : (string) $error)
                ->filter()
                ->implode('; ');

            throw new RuntimeException(sprintf(
                'Cloudflare refused this (%d)%s',
                $response->status(),
                $said === '' ? '.' : ' — '.$said,
            ));
        }

        return $body;
    }
}
