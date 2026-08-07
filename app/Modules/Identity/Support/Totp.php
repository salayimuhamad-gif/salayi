<?php

declare(strict_types=1);

namespace App\Modules\Identity\Support;

use InvalidArgumentException;

/**
 * RFC 6238 time-based one-time passwords, for mandatory administrator MFA
 * (spec 30.1, 37.5).
 *
 * Written in-house rather than pulled from a package because it is ~80 lines
 * of well-specified arithmetic with published test vectors, and because MFA is
 * the one dependency that must keep working on a shared host with no ability
 * to run `composer update` in a hurry.
 *
 * Verified against the RFC 6238 Appendix B vectors — see the standalone suite.
 */
final class Totp
{
    private const PERIOD = 30;

    private const DIGITS = 6;

    private const BASE32_ALPHABET = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ234567';

    /** Cryptographically random base32 secret for a new enrolment. */
    public static function generateSecret(int $bytes = 20): string
    {
        return self::base32Encode(random_bytes($bytes));
    }

    /**
     * @param  string  $secret  base32-encoded shared secret
     */
    public static function code(
        string $secret,
        ?int $timestamp = null,
        int $digits = self::DIGITS,
        int $period = self::PERIOD,
        string $algorithm = 'sha1',
    ): string {
        $counter = intdiv($timestamp ?? time(), $period);

        return self::hotp(self::base32Decode($secret), $counter, $digits, $algorithm);
    }

    /**
     * Verify a submitted code.
     *
     * `$window` tolerates clock skew in BOTH directions. One step (±30s) is
     * the default: enough for a phone whose clock has drifted, tight enough
     * that a shoulder-surfed code expires quickly.
     *
     * Comparison is constant-time — a timing oracle on a 6-digit code is a
     * real attack, not a theoretical one.
     */
    public static function verify(
        string $secret,
        string $code,
        ?int $timestamp = null,
        int $window = 1,
        int $digits = self::DIGITS,
        int $period = self::PERIOD,
        string $algorithm = 'sha1',
    ): bool {
        $code = preg_replace('/\D/', '', $code) ?? '';

        if (strlen($code) !== $digits) {
            return false;
        }

        $now = $timestamp ?? time();
        $key = self::base32Decode($secret);
        $counter = intdiv($now, $period);
        $valid = false;

        // Loop the full window every time; do not short-circuit on a match.
        // Early return would leak which step matched through response time.
        for ($offset = -$window; $offset <= $window; $offset++) {
            $candidate = self::hotp($key, $counter + $offset, $digits, $algorithm);

            if (hash_equals($candidate, $code)) {
                $valid = true;
            }
        }

        return $valid;
    }

    /** otpauth:// URI for authenticator apps and QR codes. */
    public static function provisioningUri(
        string $secret,
        string $account,
        string $issuer,
        int $digits = self::DIGITS,
        int $period = self::PERIOD,
    ): string {
        return 'otpauth://totp/'.rawurlencode($issuer.':'.$account).'?'.http_build_query([
            'secret' => $secret,
            'issuer' => $issuer,
            'algorithm' => 'SHA1',
            'digits' => $digits,
            'period' => $period,
        ], '', '&', PHP_QUERY_RFC3986);
    }

    /** RFC 4226 HOTP. */
    private static function hotp(string $key, int $counter, int $digits, string $algorithm): string
    {
        if ($digits < 6 || $digits > 10) {
            throw new InvalidArgumentException('TOTP digit count must be between 6 and 10.');
        }

        // 8-byte big-endian counter.
        $binary = pack('J', $counter);

        $hash = hash_hmac($algorithm, $binary, $key, true);

        // Dynamic truncation, RFC 4226 section 5.3.
        $offset = ord($hash[strlen($hash) - 1]) & 0x0F;

        $truncated = ((ord($hash[$offset]) & 0x7F) << 24)
            | ((ord($hash[$offset + 1]) & 0xFF) << 16)
            | ((ord($hash[$offset + 2]) & 0xFF) << 8)
            | (ord($hash[$offset + 3]) & 0xFF);

        return str_pad((string) ($truncated % (10 ** $digits)), $digits, '0', STR_PAD_LEFT);
    }

    public static function base32Encode(string $binary): string
    {
        if ($binary === '') {
            return '';
        }

        $bits = '';

        foreach (str_split($binary) as $char) {
            $bits .= str_pad(decbin(ord($char)), 8, '0', STR_PAD_LEFT);
        }

        $out = '';

        foreach (str_split($bits, 5) as $chunk) {
            $out .= self::BASE32_ALPHABET[bindec(str_pad($chunk, 5, '0', STR_PAD_RIGHT))];
        }

        return $out;
    }

    public static function base32Decode(string $secret): string
    {
        $secret = strtoupper(preg_replace('/[^A-Za-z2-7]/', '', $secret) ?? '');

        if ($secret === '') {
            throw new InvalidArgumentException('TOTP secret is empty or contains no valid base32 characters.');
        }

        $bits = '';

        foreach (str_split($secret) as $char) {
            $index = strpos(self::BASE32_ALPHABET, $char);

            if ($index === false) {
                throw new InvalidArgumentException('Invalid base32 character in TOTP secret.');
            }

            $bits .= str_pad(decbin($index), 5, '0', STR_PAD_LEFT);
        }

        $binary = '';

        foreach (str_split($bits, 8) as $byte) {
            if (strlen($byte) === 8) {
                $binary .= chr(bindec($byte));
            }
        }

        return $binary;
    }

    /**
     * Single-use recovery codes issued at enrolment.
     *
     * @return list<string>
     */
    public static function recoveryCodes(int $count = 8): array
    {
        $codes = [];

        for ($i = 0; $i < $count; $i++) {
            $codes[] = strtoupper(bin2hex(random_bytes(3)).'-'.bin2hex(random_bytes(3)));
        }

        return $codes;
    }
}
