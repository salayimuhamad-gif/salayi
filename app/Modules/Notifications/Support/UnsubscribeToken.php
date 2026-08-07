<?php

declare(strict_types=1);

namespace App\Modules\Notifications\Support;

use InvalidArgumentException;
use RuntimeException;

/**
 * The token behind every unsubscribe link (spec 22.3, 30.2).
 *
 * `NotificationEnvelope` already refuses to exist without an unsubscribe URL.
 * That guarantee was hollow while no route honoured the URL: a message saying
 * "unsubscribe here" that links to a 404 is worse than one that never offered,
 * because it converts a promise into evidence the promise is not kept.
 *
 * Stateless and signed rather than a stored random string. Three reasons:
 *
 *   1. No table, so an unsubscribe link cannot stop working because a cleanup
 *      job pruned its row. A link printed into a Telegram message six months
 *      ago must still work — the recipient did not agree to a deadline.
 *   2. No lookup, so the endpoint answers under load without touching the
 *      database until the recipient actually confirms.
 *   3. The signature is the authorisation. Nothing else identifies the user,
 *      so a guessed or edited token is rejected rather than unsubscribing a
 *      stranger.
 *
 * DELIBERATELY NEVER EXPIRES. `verify()` accepts a maximum age so a caller can
 * impose one, and no caller does. An expired unsubscribe link is a broken
 * promise, and "the link is too old" is not an answer anyone should receive
 * when asking to be left alone.
 *
 * The token identifies a user and a purpose; it is not a session. It authorises
 * exactly one thing — stopping contact — and confers no read access, which is
 * why it is safe to put in a message that may be forwarded.
 */
final class UnsubscribeToken
{
    /** Version prefix, so a future signing change can be rolled out without invalidating live links. */
    private const VERSION = 'v1';

    /**
     * Purposes a recipient may switch off for themselves.
     *
     * `ConsentGate::TRANSACTIONAL_PURPOSES` are absent on purpose: a moderation
     * outcome, a security notice and a legal notice are not unsubscribable, and
     * offering a link that silently does nothing would be worse than saying so.
     * The confirmation page says so instead.
     */
    public const UNSUBSCRIBABLE = ['alerts', 'marketing', 'company_contact', 'portfolio_contact', 'telegram_message'];

    /** The consent type each purpose withdraws (mirrors ConsentGate::REQUIRED_CONSENT). */
    public const CONSENT_FOR_PURPOSE = [
        'alerts' => 'alerts',
        'marketing' => 'marketing',
        'company_contact' => 'company_contact',
        'portfolio_contact' => 'portfolio_contact',
        // "Stop messaging me on Telegram" is the most likely thing someone
        // clicking a link inside a Telegram message actually wants, and
        // ConsentGate has always had the purpose. Without this entry the
        // composer had no unsubscribe destination for it.
        'telegram_message' => 'telegram_contact_sharing',
    ];

    /**
     * Mint a token for one recipient and one purpose.
     *
     * `all` is accepted as a purpose meaning "every optional contact". A single
     * link that stops everything is what someone annoyed enough to click is
     * actually looking for, and making them unsubscribe four times is a dark
     * pattern.
     */
    public static function issue(
        int $userId,
        string $purpose = 'alerts',
        ?int $issuedAt = null,
        ?string $secret = null,
    ): string {
        if ($userId <= 0) {
            throw new InvalidArgumentException('An unsubscribe token needs a real recipient.');
        }

        if ($purpose !== 'all' && ! in_array($purpose, self::UNSUBSCRIBABLE, true)) {
            throw new InvalidArgumentException(sprintf(
                'Purpose "%s" is transactional or unknown and cannot be unsubscribed (spec 30.2).',
                $purpose,
            ));
        }

        $payload = implode(':', [$userId, $purpose, $issuedAt ?? time()]);

        return self::VERSION.'.'.self::b64($payload).'.'.self::b64(self::sign($payload, $secret));
    }

    /**
     * Check a token and return what it authorises, or null.
     *
     * Returns null for anything malformed, truncated, re-signed or edited. The
     * caller gets one bit of information — valid or not — and never a reason,
     * so the endpoint cannot be used to probe which user ids exist.
     *
     * @return array{user_id: int, purpose: string, issued_at: int}|null
     */
    public static function verify(string $token, ?int $maxAgeSeconds = null, ?string $secret = null): ?array
    {
        $parts = explode('.', trim($token));

        if (count($parts) !== 3 || $parts[0] !== self::VERSION) {
            return null;
        }

        $payload = self::unb64($parts[1]);
        $signature = self::unb64($parts[2]);

        if ($payload === null || $signature === null) {
            return null;
        }

        // hash_equals, not ===. A timing-variable comparison on a signature is
        // forgeable given enough attempts, and this endpoint is public.
        if (! hash_equals(self::sign($payload, $secret), $signature)) {
            return null;
        }

        $fields = explode(':', $payload);

        if (count($fields) !== 3) {
            return null;
        }

        [$userId, $purpose, $issuedAt] = $fields;

        if (! ctype_digit($userId) || ! ctype_digit($issuedAt)) {
            return null;
        }

        if ($purpose !== 'all' && ! in_array($purpose, self::UNSUBSCRIBABLE, true)) {
            return null;
        }

        if ($maxAgeSeconds !== null && (time() - (int) $issuedAt) > $maxAgeSeconds) {
            return null;
        }

        return [
            'user_id' => (int) $userId,
            'purpose' => $purpose,
            'issued_at' => (int) $issuedAt,
        ];
    }

    /**
     * The consent types a token switches off.
     *
     * @return list<string>
     */
    public static function consentTypesFor(string $purpose): array
    {
        if ($purpose === 'all') {
            return array_values(self::CONSENT_FOR_PURPOSE);
        }

        return isset(self::CONSENT_FOR_PURPOSE[$purpose])
            ? [self::CONSENT_FOR_PURPOSE[$purpose]]
            : [];
    }

    private static function sign(string $payload, ?string $secret = null): string
    {
        $key = $secret ?? (string) config('app.key');

        // A missing key must fail loudly rather than sign with an empty string.
        // An unkeyed HMAC is forgeable by anyone who reads this file, and it
        // would look like it works.
        if (trim($key) === '') {
            throw new RuntimeException(
                'APP_KEY is not configured; refusing to sign an unsubscribe token with an empty key.',
            );
        }

        return hash_hmac('sha256', $payload, $key, true);
    }

    /** URL-safe base64, so a token survives being pasted out of a chat message. */
    private static function b64(string $raw): string
    {
        return rtrim(strtr(base64_encode($raw), '+/', '-_'), '=');
    }

    private static function unb64(string $encoded): ?string
    {
        $decoded = base64_decode(strtr($encoded, '-_', '+/'), true);

        return $decoded === false ? null : $decoded;
    }
}
