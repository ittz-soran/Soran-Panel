<?php

namespace Tests\Feature;

use Tests\TestCase;

/**
 * The one value the panel and the shop system must agree on — PANEL_DOC
 * Section 10.
 *
 * The panel verifies a pasted licence before delivering it (Section 6). If its
 * copy of the public key ever drifts from the shop system's, the panel accepts
 * licences the shops reject, or refuses ones they would have taken — and it
 * does so silently, because a wrong key looks exactly like a forged licence.
 *
 * That is not a hypothetical. PROJECT_DOC's task log for 2026-08-30 records a
 * nine-line PEM copied out of a Windows console into a one-line .env, after
 * which "every licence then looks forged". The fingerprint below is what
 * catches the same accident here.
 */
class LicencePublicKeyTest extends TestCase
{
    /**
     * The SHA-256 of the DER form of the key in the shop system's
     * config/licence.php, taken from that file on 2026-09-01.
     *
     * If this test fails, do not update the constant to make it pass. Find out
     * which side changed and why: one of the two is now wrong.
     */
    private const FINGERPRINT = '25ae8f7e20733474e5f6e1a041be55e0c86cad37b96d38f0c33c5136c18b4040';

    public function test_the_configured_key_is_a_real_rsa_public_key(): void
    {
        $key = openssl_pkey_get_public($this->pem());

        $this->assertNotFalse($key, 'The licence public key does not parse: '.openssl_error_string());

        $details = openssl_pkey_get_details($key);

        $this->assertSame(OPENSSL_KEYTYPE_RSA, $details['type']);
        $this->assertSame(2048, $details['bits']);
    }

    public function test_it_is_the_same_key_the_shop_system_ships(): void
    {
        $details = openssl_pkey_get_details(openssl_pkey_get_public($this->pem()));

        $this->assertSame(
            self::FINGERPRINT,
            hash('sha256', $details['key']),
            'The panel and the shop system no longer hold the same licence public key.',
        );
    }

    /**
     * The stored value is the base64 body without the PEM header and footer,
     * exactly as the shop system stores it, so it goes in an .env on one line.
     */
    private function pem(): string
    {
        return "-----BEGIN PUBLIC KEY-----\n"
            .trim((string) config('licence.public_key'))
            ."\n-----END PUBLIC KEY-----\n";
    }
}
