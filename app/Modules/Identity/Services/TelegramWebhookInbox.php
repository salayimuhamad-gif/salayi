<?php

declare(strict_types=1);

namespace App\Modules\Identity\Services;

use App\Modules\Operations\Services\AuditLogger;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * The Telegram webhook INBOX (correction v3, CRITICAL 2).
 *
 * Telegram delivers updates at-least-once, so the same `update_id` can
 * arrive twice for two very different reasons: because we already finished
 * it, or because we failed and Telegram is retrying. The old ledger could
 * not tell these apart — the first insert won forever, and a failure after
 * that insert dropped the person's registration on the floor. This inbox
 * gives every event an explicit lifecycle:
 *
 *   processing  — claimed by exactly one request, work in flight
 *   completed   — business effects committed; redeliveries are refused
 *   failed      — work threw; the NEXT delivery of the same update
 *                 reclaims the row and tries again
 *
 * Three properties the design guarantees:
 *
 *   1. Only a real unique-key collision is ever treated as "seen before".
 *      Any OTHER database error — an outage, a lost connection — is
 *      rethrown, the webhook answers 5xx, and Telegram's retry finds a
 *      claimable inbox. Catching Throwable and calling it a duplicate is
 *      exactly the bug this class replaces.
 *   2. Claims are atomic under concurrency: the insert's unique key
 *      decides the winner between simultaneous deliveries, and the loser
 *      inspects the winner's row under `lockForUpdate()` — so it either
 *      sees `completed`/fresh-`processing` (refuse quietly) or a
 *      `failed`/stuck row (reclaim).
 *   3. Recovery is defined, not hoped for: a row stuck in `processing`
 *      longer than STUCK_AFTER_SECONDS is presumed orphaned by a crashed
 *      worker and becomes reclaimable by the next delivery.
 *
 * At-least-once boundary, stated honestly: completion is recorded after
 * the business transaction and the bot reply. If the process dies in the
 * gap between "effects committed" and "completed recorded", the retry
 * re-runs an idempotent redemption (a no-op for the winner) and may send
 * the confirmation message a second time. One duplicate message in a
 * crash window is the accepted cost of never losing a registration.
 */
final class TelegramWebhookInbox
{
    public const STATUS_PROCESSING = 'processing';

    public const STATUS_COMPLETED = 'completed';

    public const STATUS_FAILED = 'failed';

    /** Recovery policy: a `processing` claim older than this is orphaned. */
    public const STUCK_AFTER_SECONDS = 300;

    public function __construct(private readonly AuditLogger $audit) {}

    /**
     * Claim an update for processing.
     *
     * @return array{claimed: bool, event_id?: int, attempt?: int, state?: string}
     */
    public function claim(int $updateId, string $kind): array
    {
        try {
            $eventId = DB::table('telegram_webhook_events')->insertGetId([
                'update_id' => $updateId,
                'kind' => $kind,
                'status' => self::STATUS_PROCESSING,
                'attempts' => 1,
                'received_at' => now(),
                'processing_at' => now(),
            ]);

            return ['claimed' => true, 'event_id' => (int) $eventId, 'attempt' => 1];
        } catch (UniqueConstraintViolationException) {
            /*
             * Somebody holds this update_id. WHICH somebody — a finished
             * event, a live concurrent request, a corpse — is decided below,
             * under a row lock. Every other exception type deliberately
             * escapes this method: see property 1 in the class docblock.
             */
            return $this->resolveExistingClaim($updateId);
        }
    }

    /** The event's business effects are committed and its replies sent. */
    public function complete(int $eventId): void
    {
        DB::table('telegram_webhook_events')
            ->where('id', $eventId)
            ->update([
                'status' => self::STATUS_COMPLETED,
                'completed_at' => now(),
                'last_error' => null,
            ]);
    }

    /**
     * Processing threw. The event becomes reclaimable by the next delivery;
     * only safe metadata is stored — an exception's class name says what
     * broke without carrying whose registration it was.
     */
    public function fail(int $eventId, Throwable $exception): void
    {
        DB::table('telegram_webhook_events')
            ->where('id', $eventId)
            ->update([
                'status' => self::STATUS_FAILED,
                'last_error' => mb_substr(class_basename($exception), 0, 500),
            ]);
    }

    /** @return array{claimed: bool, event_id?: int, attempt?: int, state?: string} */
    private function resolveExistingClaim(int $updateId): array
    {
        return DB::transaction(function () use ($updateId): array {
            $row = DB::table('telegram_webhook_events')
                ->where('update_id', $updateId)
                ->lockForUpdate()
                ->first();

            if ($row === null) {
                // The insert collided with a row that vanished before our
                // lock — only possible through external cleanup. Treat as
                // in-flight; the next delivery starts clean.
                return ['claimed' => false, 'state' => 'unknown'];
            }

            if ($row->status === self::STATUS_COMPLETED) {
                // The genuine replay: refused, audited, no effects re-run,
                // and — deliberately — no second bot message.
                $this->audit->security('telegram.replay_refused', ['update_id' => $updateId], result: 'refused');

                return ['claimed' => false, 'state' => self::STATUS_COMPLETED];
            }

            $stuck = $row->processing_at !== null
                && now()->diffInSeconds($row->processing_at, true) > self::STUCK_AFTER_SECONDS;

            if ($row->status === self::STATUS_FAILED || $stuck) {
                $attempt = (int) $row->attempts + 1;

                DB::table('telegram_webhook_events')
                    ->where('id', $row->id)
                    ->update([
                        'status' => self::STATUS_PROCESSING,
                        'attempts' => $attempt,
                        'processing_at' => now(),
                    ]);

                return ['claimed' => true, 'event_id' => (int) $row->id, 'attempt' => $attempt];
            }

            // A fresh `processing` row: a concurrent duplicate delivery is
            // being handled RIGHT NOW by another request. Ack quietly and
            // let it finish.
            return ['claimed' => false, 'state' => self::STATUS_PROCESSING];
        });
    }
}
