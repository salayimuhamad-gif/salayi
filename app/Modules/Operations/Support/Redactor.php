<?php

declare(strict_types=1);

namespace App\Modules\Operations\Support;

/**
 * Strips credentials and personal identifiers from anything on its way to a
 * log, an audit row or an analytics payload (spec 32.2, 33.2).
 *
 * Two independent passes, because either alone leaks:
 *
 *   1. Key matching — a key named `password` or `telegram_id` is redacted
 *      whatever it contains, at any nesting depth.
 *   2. Value matching — a value that LOOKS like a secret is redacted even
 *      under an innocent key, which is how `['note' => 'the token is sk-...']`
 *      gets caught.
 */
final class Redactor
{
    public const MASK = '[redacted]';

    /**
     * @param  array<array-key, mixed>  $data
     * @param  list<string>|null  $extraKeys
     * @return array<array-key, mixed>
     */
    public static function scrub(array $data, ?array $extraKeys = null, int $depth = 0): array
    {
        if ($depth > 12) {
            return ['_truncated' => 'max depth reached'];
        }

        /** @var list<string> $keys */
        $keys = array_map(
            'mb_strtolower',
            array_merge((array) config('mulkihawler.audit.redact_keys', []), $extraKeys ?? []),
        );

        $out = [];

        foreach ($data as $key => $value) {
            $lower = mb_strtolower((string) $key);

            $keyIsSensitive = false;

            foreach ($keys as $needle) {
                if ($needle !== '' && str_contains($lower, $needle)) {
                    $keyIsSensitive = true;
                    break;
                }
            }

            if ($keyIsSensitive) {
                $out[$key] = self::MASK;

                continue;
            }

            if (is_array($value)) {
                $out[$key] = self::scrub($value, $extraKeys, $depth + 1);

                continue;
            }

            $out[$key] = is_string($value) ? self::scrubString($value) : $value;
        }

        return $out;
    }

    /** Redacts secret-shaped substrings inside free text. */
    public static function scrubString(string $value): string
    {
        $patterns = [
            // Bearer tokens and common API key prefixes.
            '/\b(?:Bearer\s+)[A-Za-z0-9._\-]{16,}/i',
            '/\bsk-[A-Za-z0-9_\-]{16,}/',
            '/\bAIza[0-9A-Za-z_\-]{30,}/',
            // Telegram bot token: <digits>:<35 chars>
            '/\b\d{6,12}:[A-Za-z0-9_\-]{30,}\b/',
            // base64: APP_KEY form
            '/\bbase64:[A-Za-z0-9+\/=]{40,}\b/',
            // Database URLs with inline credentials.
            '/\b[a-z]+:\/\/[^:\s]+:[^@\s]+@[^\s]+/i',
            // PEM blocks.
            '/-----BEGIN [A-Z ]*PRIVATE KEY-----[\s\S]*?-----END [A-Z ]*PRIVATE KEY-----/',
        ];

        foreach ($patterns as $pattern) {
            $value = preg_replace($pattern, self::MASK, $value) ?? $value;
        }

        // Iraqi mobile numbers, in any of the shapes users type them.
        $value = preg_replace('/(?<!\d)(?:\+?964|0)7[0-9]{8,9}(?!\d)/', self::MASK, $value) ?? $value;

        return $value;
    }

    /** One-way, salted hash for an IP that must be countable but not readable. */
    public static function hashIp(?string $ip): ?string
    {
        if ($ip === null || $ip === '') {
            return null;
        }

        $key = (string) config('app.key');

        return substr(hash_hmac('sha256', $ip, $key), 0, 32);
    }
}
