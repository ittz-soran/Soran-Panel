<?php

namespace Tests\Feature;

use App\Services\LicenceVerifier;
use App\Support\LicenceCheck;
use Illuminate\Support\Facades\File;
use Tests\TestCase;

/**
 * Refusing a bad licence here, not on the customer's server — PANEL_DOC
 * Section 6, step 3.
 *
 * The difference is the whole point: by the time the shop refuses a licence,
 * its .env has already been rewritten and the shopkeeper is locked out of their
 * till, at which point somebody has to drive there.
 *
 * The signing here is the shop system's own four lines, unchanged, because the
 * panel and the shop must agree exactly. A panel that accepted a string the
 * shop rejects would break a customer by the act of renewing them.
 */
class LicenceVerifierTest extends TestCase
{
    private static string $private = '';

    private static string $public = '';

    public static function setUpBeforeClass(): void
    {
        $resource = openssl_pkey_new([
            'private_key_bits' => 2048,
            'private_key_type' => OPENSSL_KEYTYPE_RSA,
        ]);

        openssl_pkey_export($resource, self::$private);
        self::$public = openssl_pkey_get_details($resource)['key'];
    }

    protected function setUp(): void
    {
        parent::setUp();

        config(['licence.public_key' => self::$public]);
    }

    /** Exactly what App\Services\Licence::sign does in the shop system. */
    private function sign(array $payload, ?string $key = null): string
    {
        $body = json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

        openssl_sign($body, $signature, openssl_pkey_get_private($key ?? self::$private), OPENSSL_ALGO_SHA256);

        $encode = fn (string $raw) => rtrim(strtr(base64_encode($raw), '+/', '-_'), '=');

        return $encode($body).'.'.$encode($signature);
    }

    private function licence(array $payload = []): string
    {
        return $this->sign([
            'id' => 'K7QP-3MZX',
            'shop' => 'Hawler Computer',
            'host' => 'hawler.soranstore.com',
            'issued' => now()->toDateString(),
            'expires' => now()->addMonth()->toDateString(),
            ...$payload,
        ]);
    }

    private function verify(string $pasted, string $host = 'hawler.soranstore.com'): LicenceCheck
    {
        return app(LicenceVerifier::class)->verify($pasted, $host);
    }

    public function test_a_real_licence_is_accepted_and_read(): void
    {
        $found = $this->verify($this->licence());

        $this->assertTrue($found->ok);
        $this->assertSame('K7QP-3MZX', $found->id);
        $this->assertSame('Hawler Computer', $found->shop);
        $this->assertSame('hawler.soranstore.com', $found->host);
        $this->assertSame(now()->addMonth()->toDateString(), $found->expires->toDateString());
    }

    public function test_a_licence_signed_by_somebody_else_is_refused(): void
    {
        $other = '';
        openssl_pkey_export(openssl_pkey_new([
            'private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA,
        ]), $other);

        $found = $this->verify($this->sign([
            'id' => 'FAKE-0001', 'shop' => 'Not Soran’s', 'host' => 'hawler.soranstore.com',
            'issued' => now()->toDateString(), 'expires' => now()->addYears(10)->toDateString(),
        ], $other));

        $this->assertFalse($found->ok);
        $this->assertSame(LicenceCheck::NOT_SIGNED, $found->problem);
    }

    /** One character changed anywhere breaks it — that is what the signature is for. */
    public function test_a_licence_with_one_character_changed_is_refused(): void
    {
        $licence = $this->licence();
        $tampered = substr($licence, 0, 20).(($licence[20] === 'a') ? 'b' : 'a').substr($licence, 21);

        $this->assertFalse($this->verify($tampered)->ok);
    }

    public function test_a_licence_for_another_shop_is_refused_before_anything_is_written(): void
    {
        $found = $this->verify($this->licence(['host' => 'halabja.soranstore.com']));

        $this->assertFalse($found->ok);
        $this->assertSame(LicenceCheck::WRONG_HOST, $found->problem);
        $this->assertStringContainsString('halabja.soranstore.com', $found->because('hawler.soranstore.com'));
        $this->assertStringContainsString('--host=hawler.soranstore.com', $found->because('hawler.soranstore.com'));
    }

    /**
     * Delivering an expired licence would lock the shop out — the exact
     * opposite of what renewing is for.
     */
    public function test_a_licence_that_has_already_run_out_is_refused(): void
    {
        $found = $this->verify($this->licence(['expires' => now()->subDay()->toDateString()]));

        $this->assertFalse($found->ok);
        $this->assertSame(LicenceCheck::EXPIRED, $found->problem);
    }

    /** A licence good until the 29th is good all of the 29th. */
    public function test_a_licence_ending_today_is_still_good_today(): void
    {
        $this->assertTrue($this->verify($this->licence(['expires' => now()->toDateString()]))->ok);
    }

    /** No host named is portable on purpose, and the shop system agrees. */
    public function test_a_licence_with_no_host_is_accepted_anywhere(): void
    {
        $this->assertTrue($this->verify($this->licence(['host' => null]))->ok);
    }

    public function test_www_is_the_same_shop(): void
    {
        $this->assertTrue($this->verify($this->licence(['host' => 'www.hawler.soranstore.com']))->ok);
    }

    /** Sold outright, with no end date. */
    public function test_a_licence_with_no_end_date_is_accepted(): void
    {
        $found = $this->verify($this->licence(['expires' => null]));

        $this->assertTrue($found->ok);
        $this->assertNull($found->expires);
    }

    /**
     * A 400-character string does not survive a chat window or a terminal
     * intact. The newlines are damage done in transit, not part of the paste.
     */
    public function test_a_paste_that_got_wrapped_on_the_way_still_verifies(): void
    {
        $this->assertTrue($this->verify(chunk_split($this->licence(), 64, "\n"))->ok);
        $this->assertTrue($this->verify("  \n".$this->licence()."  \n")->ok);
    }

    public function test_rubbish_is_refused_rather_than_crashing(): void
    {
        foreach (['', 'hello', 'one.two.three', 'not-base64.also-not', '.'] as $rubbish) {
            $found = $this->verify($rubbish);

            $this->assertFalse($found->ok, "[{$rubbish}] was accepted");
            $this->assertNotEmpty($found->because('hawler.soranstore.com'));
        }
    }

    /** There is no private key here, and nothing in the panel can sign. */
    public function test_the_panel_holds_no_private_key(): void
    {
        $key = (string) config('licence.public_key');

        $this->assertStringNotContainsString('PRIVATE', $key);
        $this->assertFalse(openssl_pkey_get_private($key), 'the panel is holding a private key');

        foreach (['app', 'config', 'routes'] as $where) {
            foreach (File::allFiles(base_path($where)) as $file) {
                $this->assertStringNotContainsString(
                    'openssl_sign', $file->getContents(), "{$file->getRelativePathname()} can sign a licence",
                );
            }
        }
    }
}
