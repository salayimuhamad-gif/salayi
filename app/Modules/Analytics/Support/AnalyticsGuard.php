<?php

declare(strict_types=1);

namespace App\Modules\Analytics\Support;

use App\Modules\Operations\Support\Redactor;

/**
 * The analytics privacy boundary (spec 32.2).
 *
 * Spec 32.2 is a list of eight things that must NEVER reach general analytics.
 * It is written as a prohibition, so this is a prohibition: a payload
 * containing any of them is REFUSED, not quietly cleaned and sent.
 *
 * Refusing rather than stripping is the deliberate choice. Silent stripping
 * makes the caller's bug invisible — a developer who accidentally includes a
 * phone number in an event payload sees the event arrive and assumes the code
 * is correct, and the next payload shape they add gets no scrutiny. A refusal
 * with the offending key named turns a privacy incident into a failing test.
 *
 * `sanitise()` exists for the one legitimate case: a caller who genuinely wants
 * a best-effort clean payload and will accept losing fields. It is separate and
 * named so that using it is a visible decision.
 */
final class AnalyticsGuard
{
    /**
     * Key fragments that must never appear in an analytics payload (spec 32.2).
     *
     * Matched as substrings so `user_phone`, `phone_e164` and `phoneNumber` are
     * all caught — an allowlist of exact names would miss the next variant
     * somebody invents.
     */
    private const FORBIDDEN_KEY_FRAGMENTS = [
        'phone', 'telegram_id', 'telegram_user', 'whatsapp',
        'conversation', 'transcript', 'message_body',
        'portfolio_lat', 'portfolio_lng', 'portfolio_coord',
        'ownership', 'title_deed', 'deed',
        'private_note', 'notes_encrypted', 'label_encrypted',
        'home_address', 'exact_address', 'street_address',
        'email', 'national_id', 'passport', 'password', 'token', 'secret',
    ];

    /**
     * Events permitted to carry coordinates at all.
     *
     * Map interaction legitimately reports a viewport. A portfolio event never
     * does — spec 32.2 names portfolio coordinates explicitly, because they are
     * someone's home.
     */
    private const COORDINATE_PERMITTED_EVENTS = [
        'map_layer_changed', 'area_viewed', 'project_viewed', 'place_viewed',
    ];

    /**
     * Validate an event before it is recorded.
     *
     * @param  array<string, mixed>  $payload
     * @return array{allowed: bool, violations: list<string>, reason: string|null}
     */
    public function check(string $eventName, array $payload): array
    {
        $violations = $this->findViolations($payload, $eventName);

        if ($violations !== []) {
            return [
                'allowed' => false,
                'violations' => $violations,
                'reason' => 'forbidden_personal_data_in_payload',
            ];
        }

        return ['allowed' => true, 'violations' => [], 'reason' => null];
    }

    /**
     * Record-or-refuse.
     *
     * @param  array<string, mixed>  $payload
     * @return array{recorded: bool, payload: array<string, mixed>|null, violations: list<string>}
     */
    public function admit(string $eventName, array $payload, bool $analyticsConsent): array
    {
        // Consent first. Spec 30.2 lists analytics as its own consent type, so
        // a payload that is technically clean is still not recordable without
        // the user having agreed to product analytics.
        if (! $analyticsConsent) {
            return ['recorded' => false, 'payload' => null, 'violations' => ['no_analytics_consent']];
        }

        $verdict = $this->check($eventName, $payload);

        if (! $verdict['allowed']) {
            return ['recorded' => false, 'payload' => null, 'violations' => $verdict['violations']];
        }

        return ['recorded' => true, 'payload' => $payload, 'violations' => []];
    }

    /**
     * Best-effort clean, for callers who will accept losing fields.
     *
     * Separate from admit() and named so that choosing it is visible in review.
     *
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    public function sanitise(string $eventName, array $payload): array
    {
        $clean = [];

        foreach ($payload as $key => $value) {
            if ($this->keyIsForbidden((string) $key)) {
                continue;
            }

            if ($this->isCoordinateKey((string) $key) && ! in_array($eventName, self::COORDINATE_PERMITTED_EVENTS, true)) {
                continue;
            }

            if (is_array($value)) {
                $clean[$key] = $this->sanitise($eventName, $value);

                continue;
            }

            // A value that looks like a secret or an Iraqi phone number is
            // dropped even under an innocent key, reusing the Step 1 redactor
            // rather than reimplementing the patterns.
            if (is_string($value)) {
                $scrubbed = Redactor::scrubString($value);

                if (str_contains($scrubbed, Redactor::MASK)) {
                    continue;
                }

                $clean[$key] = $value;

                continue;
            }

            $clean[$key] = $value;
        }

        return $clean;
    }

    /**
     * A stable pseudonymous id for cohort analysis.
     *
     * Keyed HMAC with the event date folded in, so the same user is consistent
     * within a day and NOT linkable across days. Spec 32.2 forbids unmasked
     * personal identifiers; a permanent pseudonym is a lightly masked one.
     */
    public static function pseudonym(int|string $userKey, string $date, string $salt): string
    {
        return substr(hash_hmac('sha256', $date.'|'.$userKey, $salt), 0, 32);
    }

    /**
     * @param  array<array-key, mixed>  $payload
     * @return list<string>
     */
    private function findViolations(array $payload, string $eventName, string $prefix = '', int $depth = 0): array
    {
        if ($depth > 8) {
            return [$prefix.'(max depth exceeded)'];
        }

        $violations = [];

        foreach ($payload as $key => $value) {
            $path = $prefix === '' ? (string) $key : $prefix.'.'.$key;

            if ($this->keyIsForbidden((string) $key)) {
                $violations[] = $path;

                continue;
            }

            if ($this->isCoordinateKey((string) $key) && ! in_array($eventName, self::COORDINATE_PERMITTED_EVENTS, true)) {
                $violations[] = $path.' (coordinates not permitted on this event)';

                continue;
            }

            if (is_array($value)) {
                $violations = array_merge($violations, $this->findViolations($value, $eventName, $path, $depth + 1));

                continue;
            }

            if (is_string($value) && str_contains(Redactor::scrubString($value), Redactor::MASK)) {
                $violations[] = $path.' (value matched a personal-data pattern)';
            }
        }

        return $violations;
    }

    private function keyIsForbidden(string $key): bool
    {
        $lower = mb_strtolower($key);

        foreach (self::FORBIDDEN_KEY_FRAGMENTS as $fragment) {
            if (str_contains($lower, $fragment)) {
                return true;
            }
        }

        return false;
    }

    private function isCoordinateKey(string $key): bool
    {
        $lower = mb_strtolower($key);

        return in_array($lower, ['lat', 'lng', 'latitude', 'longitude', 'coords', 'coordinates'], true);
    }
}
