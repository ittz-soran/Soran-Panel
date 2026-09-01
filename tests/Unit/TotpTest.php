<?php

namespace Tests\Unit;

use App\Support\Totp;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * The six digits, checked against the numbers the RFC prints.
 *
 * The shop system's own test, copied here beside the class it checks —
 * app/Support/Totp.php is the same file, reused as PANEL_DOC Section 5 says.
 * The class travelled and the test did not, which is the wrong half to bring:
 * a copied implementation with no copied test is an implementation nobody is
 * checking any more.
 *
 * It matters more here than it does in a shop. An authenticator that is subtly
 * wrong locks a shopkeeper out of their own records; this one locks Soran out
 * of the panel, and behind the panel is every customer's install. There is no
 * forgotten-password email to fall back on — that is the whole design.
 *
 * RFC 6238 publishes its answers, so the arithmetic is checked against them
 * rather than against another copy of itself.
 */
class TotpTest extends TestCase
{
    /**
     * RFC 6238, Appendix B — the SHA-1 rows.
     *
     * The RFC's secret is the ASCII "12345678901234567890", which is
     * GEZDGNBVGY3TQOJQGEZDGNBVGY3TQOJQ in base32. The published codes are eight
     * digits; every authenticator app uses six, so these are the last six of
     * each, which is what truncating to six produces.
     */
    public static function rfcVectors(): array
    {
        return [
            '1970-01-01 00:00:59' => [59, '287082'],
            '2005-03-18 01:58:29' => [1111111109, '081804'],
            '2005-03-18 01:58:31' => [1111111111, '050471'],
            '2009-02-13 23:31:30' => [1234567890, '005924'],
            '2033-05-18 03:33:20' => [2000000000, '279037'],
            '2603-10-11 11:33:20' => [20000000000, '353130'],
        ];
    }

    #[DataProvider('rfcVectors')]
    public function test_it_produces_the_codes_the_rfc_publishes(int $at, string $expected): void
    {
        $secret = 'GEZDGNBVGY3TQOJQGEZDGNBVGY3TQOJQ';

        $this->assertSame($expected, Totp::at($secret, $at));
    }

    public function test_a_code_is_accepted_at_the_moment_it_is_shown(): void
    {
        $secret = Totp::secret();
        $now = time();

        $this->assertTrue(Totp::check($secret, Totp::at($secret, $now), $now));
    }

    /** A phone whose clock is half a minute out still works. */
    public function test_one_step_either_side_is_still_accepted(): void
    {
        $secret = Totp::secret();
        $now = time();

        $this->assertTrue(Totp::check($secret, Totp::at($secret, $now - 30), $now));
        $this->assertTrue(Totp::check($secret, Totp::at($secret, $now + 30), $now));
    }

    /** Two steps is not. A window wide enough to be convenient is wide enough to be a hole. */
    public function test_further_out_than_that_is_refused(): void
    {
        $secret = Totp::secret();
        $now = time();

        $this->assertFalse(Totp::check($secret, Totp::at($secret, $now - 90), $now));
        $this->assertFalse(Totp::check($secret, Totp::at($secret, $now + 90), $now));
    }

    public function test_a_wrong_code_is_refused(): void
    {
        $secret = Totp::secret();

        $this->assertFalse(Totp::check($secret, '000000'));
        $this->assertFalse(Totp::check($secret, '12345'));
        $this->assertFalse(Totp::check($secret, 'abcdef'));
        $this->assertFalse(Totp::check($secret, ''));
    }

    /**
     * A phone keyboard left in Kurdish types ٦, and the shop cannot see why the
     * code is refused. Every other number box in this system does the same.
     */
    public function test_eastern_digits_and_spaces_are_read_as_the_code_they_are(): void
    {
        $secret = Totp::secret();
        $now = time();
        $code = Totp::at($secret, $now);

        $eastern = str_replace(
            ['0', '1', '2', '3', '4', '5', '6', '7', '8', '9'],
            ['٠', '١', '٢', '٣', '٤', '٥', '٦', '٧', '٨', '٩'],
            $code,
        );

        $this->assertTrue(Totp::check($secret, $eastern, $now), 'Arabic-Indic digits');

        // What an app that shows "123 456" puts on the clipboard.
        $spaced = substr($code, 0, 3).' '.substr($code, 3);

        $this->assertTrue(Totp::check($secret, $spaced, $now), 'a space in the middle');
    }

    public function test_a_secret_is_base32_and_long_enough_to_be_worth_having(): void
    {
        $secret = Totp::secret();

        $this->assertSame(32, strlen($secret), '160 bits, as every app expects');
        $this->assertMatchesRegularExpression('/^[A-Z2-7]+$/', $secret);
        $this->assertNotSame($secret, Totp::secret(), 'and a different one each time');
    }

    /** The line inside the QR square, as the apps parse it. */
    public function test_the_setup_uri_names_the_panel_and_the_person(): void
    {
        $uri = Totp::uri('GEZDGNBVGY3TQOJQ', 'soran@soranstore.com', 'Soran Panel');

        $this->assertStringStartsWith('otpauth://totp/Soran%20Panel:soran%40soranstore.com?', $uri);
        $this->assertStringContainsString('secret=GEZDGNBVGY3TQOJQ', $uri);
        $this->assertStringContainsString('issuer=Soran+Panel', $uri);
        $this->assertStringContainsString('digits=6', $uri);
        $this->assertStringContainsString('period=30', $uri);
    }

    /** Thirty-two characters typed by hand off a screen, in fours. */
    public function test_the_secret_is_shown_in_readable_groups(): void
    {
        $this->assertSame('GEZD GNBV GY3T QOJQ', Totp::readable('GEZDGNBVGY3TQOJQ'));
    }
}
