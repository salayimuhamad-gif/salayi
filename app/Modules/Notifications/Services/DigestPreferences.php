<?php

declare(strict_types=1);

namespace App\Modules\Notifications\Services;

use Illuminate\Support\Facades\DB;

/**
 * Decides whether a notification waits for the daily digest (spec 22.2).
 *
 * The whole value of a digest is that it reduces the number of interruptions,
 * and the whole risk of one is that it delays the message that needed to
 * interrupt. So the deferral rules are deliberately narrow, and three classes
 * of message are never batched no matter what the recipient chose:
 *
 *   1. TRANSACTIONAL PURPOSES. A moderation outcome, a security notice or a
 *      legal notice. Holding "your password was changed" for up to a day is
 *      the difference between a person catching an account takeover and
 *      reading about it the following morning. `ConsentGate` already exempts
 *      these from consent; they are exempt from batching for the same reason.
 *
 *   2. HIGH AND URGENT PRIORITY. A rejection is high priority precisely
 *      because the seller needs to act on it.
 *
 *   3. ANY EVENT WITH NO IN-APP RECORD. Deferral only defers the EXTERNAL
 *      send; the in-app row is written immediately either way, so the
 *      information is never actually withheld — only the push is delayed.
 *
 * What is left is the case a digest is for: routine alerts, several a day,
 * none of which needs a response within the hour.
 */
final class DigestPreferences
{
    public const IMMEDIATE = 'immediate';

    public const DAILY = 'daily';

    /** Purposes that must never wait. Mirrors ConsentGate's transactional list. */
    private const NEVER_BATCHED_PURPOSES = ['account_security', 'moderation_outcome', 'legal_notice'];

    private const NEVER_BATCHED_PRIORITIES = ['high', 'urgent'];

    /**
     * The recipient's chosen frequency.
     *
     * Defaults to immediate. A user who has expressed no preference has not
     * asked to be batched, and defaulting the other way would silently delay
     * everyone's messages the moment this shipped.
     */
    public function frequencyFor(int $userId): string
    {
        $row = DB::table('notification_preferences')
            ->where('user_id', $userId)
            ->first();

        $frequency = $row->frequency ?? self::IMMEDIATE;

        return $frequency === self::DAILY ? self::DAILY : self::IMMEDIATE;
    }

    /**
     * The recipient's full settings, with defaults for a user who has none.
     *
     * @return array{frequency: string, digest_hour: int}
     */
    public function settingsFor(int $userId): array
    {
        $row = DB::table('notification_preferences')
            ->where('user_id', $userId)
            ->first();

        return [
            'frequency' => ($row->frequency ?? null) === self::DAILY ? self::DAILY : self::IMMEDIATE,
            'digest_hour' => (int) ($row->digest_hour ?? 8),
        ];
    }

    public function setFrequency(int $userId, string $frequency, int $digestHour = 8): void
    {
        $attributes = [
            'frequency' => $frequency === self::DAILY ? self::DAILY : self::IMMEDIATE,
            'digest_hour' => max(0, min(23, $digestHour)),
            'updated_at' => now(),
        ];

        $exists = DB::table('notification_preferences')->where('user_id', $userId)->exists();

        if ($exists) {
            // Not updateOrInsert with created_at in the payload: that form
            // rewrites created_at on every save, so "when did this user opt
            // into the digest" becomes unanswerable after their first edit.
            DB::table('notification_preferences')->where('user_id', $userId)->update($attributes);

            return;
        }

        DB::table('notification_preferences')->insert(
            $attributes + ['user_id' => $userId, 'created_at' => now()],
        );
    }

    /**
     * Whether this particular send waits for the digest.
     *
     * Pure decision, no I/O beyond the preference lookup, so the rules can be
     * exercised directly in the standalone suite.
     */
    public function shouldDefer(
        string $frequency,
        string $consentPurpose,
        string $priority,
    ): bool {
        if ($frequency !== self::DAILY) {
            return false;
        }

        if (in_array($consentPurpose, self::NEVER_BATCHED_PURPOSES, true)) {
            return false;
        }

        if (in_array($priority, self::NEVER_BATCHED_PRIORITIES, true)) {
            return false;
        }

        return true;
    }

    /** @return list<string> */
    public function neverBatchedPurposes(): array
    {
        return self::NEVER_BATCHED_PURPOSES;
    }

    /** @return list<string> */
    public function neverBatchedPriorities(): array
    {
        return self::NEVER_BATCHED_PRIORITIES;
    }
}
