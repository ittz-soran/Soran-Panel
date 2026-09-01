<?php

namespace App\Support;

/**
 * Six digits from a phone, and the arithmetic behind them.
 *
 * RFC 6238: the app and the server share one secret, both look at the clock,
 * and both do the same sum. Nothing travels between them — which is exactly why
 * it works for this shop. Email here is MAIL_MAILER=log, so Laravel's own
 * "forgotten password" link has never left the building and never will on the
 * hosting these installs run on. A phone that already has the code needs no
 * post office.
 *
 * Written out rather than pulled in as a package, because this has to install
 * on shared hosting where composer is somebody else's problem and every
 * dependency is a thing that can be missing. It is forty lines of hash_hmac,
 * and the RFC publishes the answers, so it is checked against them.
 */
final class Totp
{
    /** The step, in seconds. Thirty is what every authenticator app assumes. */
    public const PERIOD = 30;

    public const DIGITS = 6;

    /**
     * How far either side of now still counts.
     *
     * One step, so a code typed as it rolls over is still accepted and a phone
     * whose clock is half a minute out still works. Wider than that starts
     * lengthening the window an attacker has for no benefit anybody feels.
     */
    public const WINDOW = 1;

    private const ALPHABET = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ234567';

    /** A fresh secret, in the base32 an authenticator app expects. */
    public static function secret(int $bytes = 20): string
    {
        return self::base32Encode(random_bytes($bytes));
    }

    /** The code for a given moment. */
    public static function at(string $secret, ?int $timestamp = null): string
    {
        $counter = intdiv($timestamp ?? time(), self::PERIOD);

        $hash = hash_hmac(
            'sha1',
            pack('N*', 0, $counter),   // the counter, 64-bit big-endian
            self::base32Decode($secret),
            true,
        );

        // RFC 4226's dynamic truncation: the low nibble of the last byte says
        // where in the hash to read four bytes from.
        $offset = ord($hash[strlen($hash) - 1]) & 0x0F;

        $number = (
            ((ord($hash[$offset]) & 0x7F) << 24) |
            ((ord($hash[$offset + 1]) & 0xFF) << 16) |
            ((ord($hash[$offset + 2]) & 0xFF) << 8) |
            (ord($hash[$offset + 3]) & 0xFF)
        ) % (10 ** self::DIGITS);

        return str_pad((string) $number, self::DIGITS, '0', STR_PAD_LEFT);
    }

    /**
     * Whether a typed code is right, now or one step either side.
     *
     * Compared with hash_equals rather than ===, so the time the comparison
     * takes says nothing about how much of the code was correct.
     */
    public static function check(string $secret, string $code, ?int $timestamp = null): bool
    {
        // Eastern digits reach this from a phone keyboard, and spaces reach it
        // from an app that shows "123 456".
        $code = self::english(trim($code));

        if (! preg_match('/^\d{'.self::DIGITS.'}$/', $code)) {
            return false;
        }

        $now = $timestamp ?? time();

        for ($step = -self::WINDOW; $step <= self::WINDOW; $step++) {
            if (hash_equals(self::at($secret, $now + ($step * self::PERIOD)), $code)) {
                return true;
            }
        }

        return false;
    }

    /** The otpauth:// line an authenticator app reads out of a QR square. */
    public static function uri(string $secret, string $account, string $issuer): string
    {
        return 'otpauth://totp/'.rawurlencode($issuer).':'.rawurlencode($account).'?'.http_build_query([
            'secret' => $secret,
            'issuer' => $issuer,
            'algorithm' => 'SHA1',
            'digits' => self::DIGITS,
            'period' => self::PERIOD,
        ]);
    }

    /**
     * The secret as a person reads it off a screen, in fours.
     *
     * Typing thirty-two characters into a phone by hand is the fallback when a
     * camera will not focus, and it is the difference between "I could not set
     * it up" and a shop that has a way back in.
     */
    public static function readable(string $secret): string
    {
        return trim(chunk_split($secret, 4, ' '));
    }

    /**
     * Arabic-Indic and Persian digits, as English ones.
     *
     * The same reason every number box in this system does it: a phone keyboard
     * left in Kurdish types ٦ and the shop cannot see why the code is refused.
     */
    private static function english(string $value): string
    {
        return str_replace(
            ['٠', '١', '٢', '٣', '٤', '٥', '٦', '٧', '٨', '٩', '۰', '۱', '۲', '۳', '۴', '۵', '۶', '۷', '۸', '۹', ' ', '-'],
            ['0', '1', '2', '3', '4', '5', '6', '7', '8', '9', '0', '1', '2', '3', '4', '5', '6', '7', '8', '9', '', ''],
            $value,
        );
    }

    private static function base32Encode(string $bytes): string
    {
        $bits = '';

        foreach (str_split($bytes) as $byte) {
            $bits .= str_pad(decbin(ord($byte)), 8, '0', STR_PAD_LEFT);
        }

        $out = '';

        foreach (str_split($bits, 5) as $chunk) {
            $out .= self::ALPHABET[bindec(str_pad($chunk, 5, '0', STR_PAD_RIGHT))];
        }

        return $out;
    }

    private static function base32Decode(string $secret): string
    {
        $secret = strtoupper(str_replace([' ', '-', '='], '', $secret));
        $bits = '';

        foreach (str_split($secret) as $character) {
            $index = strpos(self::ALPHABET, $character);

            if ($index === false) {
                continue;
            }

            $bits .= str_pad(decbin($index), 5, '0', STR_PAD_LEFT);
        }

        $out = '';

        foreach (str_split($bits, 8) as $chunk) {
            if (strlen($chunk) === 8) {
                $out .= chr(bindec($chunk));
            }
        }

        return $out;
    }
}
