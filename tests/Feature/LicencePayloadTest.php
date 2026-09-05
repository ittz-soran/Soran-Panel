<?php

namespace Tests\Feature;

use App\Models\Customer;
use App\Models\User;
use App\Services\LicencePayload;
use App\Services\LicenceVerifier;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/**
 * What a licence would say, for the browser to sign.
 *
 * The panel builds this and the browser only does the RSA sum. That division is
 * the point of these tests: everything about what a licence CLAIMS is checked
 * here, in PHP, so JavaScript never has to be trusted with a date.
 */
class LicencePayloadTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->actingAs(User::factory()->create());
    }

    private function customer(): Customer
    {
        return Customer::factory()->create([
            'name' => 'Hawler Computer',
            'host' => 'hawler.soranstore.com',
        ]);
    }

    public function test_it_says_what_the_licence_will_claim(): void
    {
        Carbon::setTestNow('2026-09-05');

        $payload = (new LicencePayload)->for($this->customer(), ['months' => 12]);

        $this->assertSame('Hawler Computer', $payload['shop']);
        $this->assertSame('hawler.soranstore.com', $payload['host']);
        $this->assertSame('2026-09-05', $payload['issued']);
        $this->assertSame('2027-09-05', $payload['expires']);
        $this->assertMatchesRegularExpression('/^[A-Z0-9]{4}-[A-Z0-9]{4}$/', $payload['id']);
    }

    /**
     * ⚠️ A month from the 31st overflows, and this pins it deliberately.
     *
     * `addMonths` is PHP's own arithmetic: 31 January plus one month is
     * 3 March, not 28 February. Carbon clamps only with `addMonthsNoOverflow`.
     *
     * This is not the behaviour anyone would choose, but it IS what
     * `licence:issue` does, and the two must agree — a licence issued from the
     * panel and one issued from a terminal on the same day should say the same
     * thing. Changing it means changing the shop system's command first and
     * following here, never fixing one side.
     *
     * (Written expecting 28 February, and corrected by running it.)
     */
    public function test_a_month_from_the_end_of_january_overflows_the_way_licence_issue_does(): void
    {
        Carbon::setTestNow('2026-01-31');

        $payload = (new LicencePayload)->for($this->customer(), ['months' => 1]);

        $this->assertSame('2026-03-03', $payload['expires']);
    }

    public function test_a_licence_sold_outright_has_no_end_date(): void
    {
        $payload = (new LicencePayload)->for($this->customer(), ['forever' => true]);

        $this->assertNull($payload['expires']);
    }

    public function test_an_exact_date_is_taken_as_given(): void
    {
        $payload = (new LicencePayload)->for($this->customer(), ['until' => '2028-03-01']);

        $this->assertSame('2028-03-01', $payload['expires']);
    }

    /** The shop reads both this and `licence:issue`, so the shape must match. */
    public function test_the_body_is_the_shape_the_shop_reads(): void
    {
        $payloads = new LicencePayload;
        $payload = $payloads->for($this->customer(), ['months' => 1]);

        $decoded = json_decode($payloads->body($payload), true);

        $this->assertSame(['id', 'shop', 'host', 'issued', 'expires'], array_keys($decoded));
    }

    /** base64url, no padding — the shop's own encoding, or it cannot decode it. */
    public function test_it_encodes_the_way_the_shop_decodes(): void
    {
        $payloads = new LicencePayload;

        $encoded = $payloads->encode('a body with / and + in it, and some padding');

        $this->assertStringNotContainsString('=', $encoded);
        $this->assertStringNotContainsString('/', $encoded);
        $this->assertStringNotContainsString('+', $encoded);
        $this->assertSame(
            'a body with / and + in it, and some padding',
            base64_decode(strtr($encoded, '-_', '+/')),
        );
    }

    // ---- The endpoint -----------------------------------------------------

    public function test_the_endpoint_hands_over_the_bytes_to_sign(): void
    {
        $customer = $this->customer();

        $said = $this->postJson(route('customers.renew.payload', $customer), ['months' => 6])
            ->assertOk()
            ->json();

        $body = base64_decode(strtr($said['body'], '-_', '+/'));
        $payload = json_decode($body, true);

        $this->assertSame('Hawler Computer', $payload['shop']);
        $this->assertSame('hawler.soranstore.com', $payload['host']);
        $this->assertSame($said['id'], $payload['id']);
    }

    public function test_the_endpoint_is_behind_the_sign_in(): void
    {
        $customer = $this->customer();
        auth()->logout();

        $this->postJson(route('customers.renew.payload', $customer), ['months' => 1])
            ->assertUnauthorized();
    }

    /**
     * It hands over a body, never a token. There is nothing secret in a
     * licence's contents — the shop shows them on its own screen — and without
     * a signature it is worthless, which is what makes this safe to expose.
     */
    public function test_it_never_hands_back_something_that_would_pass_as_a_licence(): void
    {
        $said = $this->postJson(route('customers.renew.payload', $this->customer()), ['months' => 1])
            ->assertOk()
            ->json();

        $this->assertStringNotContainsString('.', $said['body'], 'that is a whole token, not a body');
    }

    // ---- The contract with the browser ------------------------------------

    /**
     * ⚠️ The one that matters: a signature made the way the browser makes it
     * must satisfy the panel's own verifier, and then the shop's.
     *
     * `crypto.subtle.sign('RSASSA-PKCS1-v1_5', …, SHA-256)` is exactly
     * `openssl_sign(…, OPENSSL_ALGO_SHA256)` — PKCS#1 v1.5 is deterministic,
     * so the two produce the same bytes for the same key and body. That is
     * what this asserts, without needing a browser in the suite.
     *
     * It was also confirmed in real Chromium: a token signed there read `valid`
     * to the shop system's own Licence class, `wrong_host` on another domain,
     * and `invalid` with one character of the signature changed. That run is
     * not repeatable here, so this holds the format in its place.
     */
    public function test_a_signature_made_the_way_the_browser_makes_it_is_accepted(): void
    {
        $pair = openssl_pkey_new(['private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA]);
        openssl_pkey_export($pair, $private);
        $public = openssl_pkey_get_details($pair)['key'];

        config(['licence.public_key' => $public]);

        $customer = $this->customer();
        $payloads = new LicencePayload;

        // What the endpoint hands the browser.
        $body = $payloads->body($payloads->for($customer, ['months' => 12]));

        // What the browser does with it, byte for byte.
        openssl_sign($body, $signature, $private, OPENSSL_ALGO_SHA256);

        $token = $payloads->encode($body).'.'.$payloads->encode($signature);

        $checked = app(LicenceVerifier::class)->verify($token, $customer->host);

        $this->assertTrue($checked->ok, $checked->problem ?? '');
        $this->assertSame('Hawler Computer', $checked->shop);
        $this->assertSame('hawler.soranstore.com', $checked->host);
    }

    /** One character changed, and it is refused — which is the whole point. */
    public function test_a_tampered_signature_is_refused(): void
    {
        $pair = openssl_pkey_new(['private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA]);
        openssl_pkey_export($pair, $private);

        config(['licence.public_key' => openssl_pkey_get_details($pair)['key']]);

        $customer = $this->customer();
        $payloads = new LicencePayload;
        $body = $payloads->body($payloads->for($customer, ['months' => 12]));

        openssl_sign($body, $signature, $private, OPENSSL_ALGO_SHA256);

        $token = $payloads->encode($body).'.'.substr($payloads->encode($signature), 0, -4).'AAAA';

        $this->assertFalse(app(LicenceVerifier::class)->verify($token, $customer->host)->ok);
    }

    public function test_a_date_in_the_past_is_refused(): void
    {
        $this->postJson(route('customers.renew.payload', $this->customer()), ['until' => '2020-01-01'])
            ->assertJsonValidationErrors('until');
    }
}
