<?php

declare(strict_types=1);

namespace App\Modules\Core\Support;

/**
 * String helpers that never fatal (spec 26.1).
 *
 * `ext-mbstring` is a production requirement and composer.json declares it.
 * But these functions are used on FAILURE PATHS — recording an orphaned file,
 * compensating a half-written upload — and a failure path that itself throws
 * loses the original error along with the record of it.
 *
 * A local audit environment without the extension is exactly where that
 * happens, so the fallback exists for the case where the requirement is not
 * met rather than in place of the requirement.
 */
final class SafeText
{
    /**
     * Truncate to at most $length characters, without ever throwing.
     *
     * Prefers mb_substr, because cutting UTF-8 mid-sequence produces a broken
     * character — and Sorani, Arabic and Kurdish text is multi-byte
     * throughout, so a naive substr would corrupt almost every message this
     * product records.
     */
    public static function truncate(?string $value, int $length): ?string
    {
        if ($value === null || $value === '') {
            return $value;
        }

        if (function_exists('mb_substr')) {
            return mb_substr($value, 0, $length);
        }

        /*
         * Byte-safe fallback: back off until the cut is not mid-sequence, so
         * the stored message is short rather than corrupt.
         */
        $cut = substr($value, 0, $length);

        while ($cut !== '' && ! self::isValidUtf8($cut)) {
            $cut = substr($cut, 0, -1);
        }

        return $cut;
    }

    private static function isValidUtf8(string $value): bool
    {
        // preg_match with /u fails on malformed UTF-8, which is exactly the
        // test wanted, and needs no extension beyond PCRE.
        return preg_match('//u', $value) === 1;
    }
}
