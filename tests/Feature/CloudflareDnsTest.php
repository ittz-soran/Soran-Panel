<?php

namespace Tests\Feature;

use App\Services\CloudflareDns;
use App\Services\ManualDns;
use Illuminate\Support\Facades\Http;
use RuntimeException;
use Tests\TestCase;

/**
 * Publishing a shop's name through Cloudflare.
 *
 * The token this uses can rewrite where every one of Soran's domains points, so
 * the tests care as much about what it refuses to do quietly as about what it
 * does.
 */
class CloudflareDnsTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        config([
            'panel.dns.cloudflare.token' => 'a-scoped-token',
            'panel.dns.cloudflare.zone_id' => 'zone123456',
            'panel.dns.cloudflare.proxied' => false,
        ]);
    }

    /** @param array<string, mixed> $extra */
    private function answering(array $extra = []): void
    {
        Http::fake([
            'api.cloudflare.com/*' => Http::response([
                'success' => true,
                'errors' => [],
                'result' => [],
                ...$extra,
            ]),
        ]);
    }

    public function test_it_publishes_an_a_record_for_the_shop(): void
    {
        $this->answering();

        (new CloudflareDns)->create('bazaar.soranstore.com', '192.0.2.7');

        Http::assertSent(function ($request) {
            if ($request->method() !== 'POST') {
                return false;
            }

            return $request['type'] === 'A'
                && $request['name'] === 'bazaar.soranstore.com'
                && $request['content'] === '192.0.2.7'
                && $request['proxied'] === false
                && str_contains($request->url(), '/zones/zone123456/dns_records');
        });
    }

    public function test_it_uses_the_token_and_never_puts_it_in_the_url(): void
    {
        $this->answering();

        (new CloudflareDns)->create('bazaar.soranstore.com', '192.0.2.7');

        Http::assertSent(fn ($request) => $request->hasHeader('Authorization', 'Bearer a-scoped-token')
            && ! str_contains($request->url(), 'a-scoped-token'));
    }

    /**
     * Cloudflare will hold two A records for one name and answer with either,
     * which is a shop that works every other request.
     */
    public function test_a_record_already_there_is_replaced_and_not_added_beside(): void
    {
        Http::fake([
            'api.cloudflare.com/*dns_records?*' => Http::sequence()
                ->push(['success' => true, 'errors' => [], 'result' => [['id' => 'old-one']]]),
            'api.cloudflare.com/*' => Http::response(['success' => true, 'errors' => [], 'result' => []]),
        ]);

        (new CloudflareDns)->create('bazaar.soranstore.com', '192.0.2.7');

        Http::assertSent(fn ($request) => $request->method() === 'DELETE'
            && str_contains($request->url(), 'dns_records/old-one'));
    }

    /**
     * ⚠️ Cloudflare answers 200 with `success: false` for a request it
     * understood and refused. Checking the status code alone believes every
     * call worked.
     */
    public function test_a_refusal_that_arrives_as_a_200_is_still_a_refusal(): void
    {
        Http::fake([
            'api.cloudflare.com/*' => Http::response([
                'success' => false,
                'errors' => [['code' => 81057, 'message' => 'Record already exists.']],
                'result' => null,
            ], 200),
        ]);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Record already exists.');

        (new CloudflareDns)->create('bazaar.soranstore.com', '192.0.2.7');
    }

    public function test_a_missing_token_says_which_setting_and_which_kind(): void
    {
        config(['panel.dns.cloudflare.token' => '']);

        try {
            (new CloudflareDns)->create('bazaar.soranstore.com', '192.0.2.7');
            $this->fail('it tried to publish with no token');
        } catch (RuntimeException $e) {
            $this->assertStringContainsString('PANEL_CLOUDFLARE_TOKEN', $e->getMessage());
            $this->assertStringContainsString('not a Global API Key', $e->getMessage());
        }
    }

    public function test_a_missing_zone_says_where_to_find_it(): void
    {
        config(['panel.dns.cloudflare.zone_id' => '']);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('PANEL_CLOUDFLARE_ZONE_ID');

        (new CloudflareDns)->create('bazaar.soranstore.com', '192.0.2.7');
    }

    /** A rollback runs when something has already gone wrong. */
    public function test_a_withdrawal_that_fails_reports_rather_than_throws(): void
    {
        Http::fake(['api.cloudflare.com/*' => Http::response(['success' => false, 'errors' => []], 403)]);

        $left = (new CloudflareDns)->remove('bazaar.soranstore.com');

        $this->assertCount(1, $left);
        $this->assertStringContainsString('bazaar.soranstore.com', $left[0]);
    }

    // ---- Proving it before a customer needs it -----------------------------

    /**
     * One call proves the token works, the zone id is real, and the token may
     * touch that zone — three settings that each look fine alone and only fail
     * together, in the middle of making a customer.
     */
    public function test_verifying_names_the_zone_it_can_actually_reach(): void
    {
        Http::fake([
            'api.cloudflare.com/*' => Http::response([
                'success' => true,
                'errors' => [],
                'result' => ['id' => 'zone123456', 'name' => 'soranstore.com'],
            ]),
        ]);

        $this->assertStringContainsString('soranstore.com', (new CloudflareDns)->verify());

        Http::assertSent(fn ($request) => $request->method() === 'GET'
            && str_ends_with($request->url(), '/zones/zone123456'));
    }

    public function test_a_token_that_does_not_work_is_found_here_and_not_later(): void
    {
        Http::fake([
            'api.cloudflare.com/*' => Http::response([
                'success' => false,
                'errors' => [['code' => 1000, 'message' => 'Invalid API Token']],
            ], 403),
        ]);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Invalid API Token');

        (new CloudflareDns)->verify();
    }

    /** A token scoped to somebody else's zone answers, but not with a zone. */
    public function test_a_token_for_the_wrong_zone_is_not_taken_as_working(): void
    {
        Http::fake([
            'api.cloudflare.com/*' => Http::response(['success' => true, 'errors' => [], 'result' => []]),
        ]);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('not with a zone this token can read');

        (new CloudflareDns)->verify();
    }

    public function test_the_manual_one_does_nothing_and_admits_it(): void
    {
        $manual = new ManualDns;

        $manual->create('bazaar.soranstore.com', '192.0.2.7');

        $this->assertSame([], $manual->remove('bazaar.soranstore.com'));
        $this->assertFalse($manual->isAutomatic());
        $this->assertStringContainsString('not published by the panel', $manual->describe());
    }
}
