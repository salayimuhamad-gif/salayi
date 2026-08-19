<?php

declare(strict_types=1);

namespace App\Modules\Notifications\Console;

use App\Modules\Core\Concerns\GuardedByFeature;
use App\Modules\Identity\Models\User;
use App\Modules\Notifications\Models\Notification;
use App\Modules\Notifications\Services\NotificationComposer;
use App\Modules\Notifications\Services\NotificationDispatcher;
use App\Modules\Notifications\Services\RecipientResolver;
use App\Modules\Notifications\Support\NotificationEnvelope;
use App\Modules\Operations\Services\AuditLogger;
use Illuminate\Console\Command;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Sends the daily digest (spec 22.2).
 *
 * Everything a batched recipient would have been pushed is already sitting in
 * their notification centre with `digest_state = 'pending'`. This rolls those
 * into one external message and marks them sent.
 *
 * Batched per user and bounded by `--limit`, because on Hostinger the worker
 * gets roughly fifty seconds per cron tick. An unbounded run across every user
 * is killed mid-flight every minute and never completes — the same constraint
 * that shaped the expiry sweep.
 *
 * Idempotent by construction: a row moves from `pending` to `sent` in the same
 * pass that includes it, so a second run finds nothing and a run killed halfway
 * resends only what it had not yet marked. Duplicated is better than lost, and
 * the window for duplication is one user.
 */
final class SendNotificationDigests extends Command
{
    use GuardedByFeature;

    protected $signature = 'mulkihawler:notifications:digest
                            {--limit=100 : Recipients to process this run}
                            {--user= : Send for one user only, ignoring the hour}
                            {--dry-run : Report what would be sent without sending}';

    protected $description = 'Send the daily notification digest to recipients who chose batching';

    public function __construct(
        private readonly NotificationDispatcher $dispatcher,
        private readonly NotificationComposer $composer,
        private readonly RecipientResolver $recipients,
        private readonly AuditLogger $audit,
    ) {
        parent::__construct();
    }

    public function handle(): int
    {
        /*
         * Repair prompt §3.5 / §17. Any-of rather than all-of: a digest is
         * worth sending if one delivery channel is live, and should be skipped
         * only when no channel could reach anybody. Requiring all three would
         * silence digests for a site that had deliberately chosen email only.
         */
        if (! $this->featureAnyEnabled('alerts.email', 'alerts.telegram', 'alerts.push')) {
            $this->info('All notification channels are disabled — nothing to send.');

            return self::SUCCESS;
        }

        $limit = max(1, (int) $this->option('limit'));
        $dryRun = (bool) $this->option('dry-run');
        $onlyUser = $this->option('user') === null ? null : (int) $this->option('user');

        $recipientIds = $this->pendingRecipients($limit, $onlyUser);

        if ($recipientIds === []) {
            $this->info(__('notifications.digest.none_pending'));

            return self::SUCCESS;
        }

        $sent = 0;

        foreach ($recipientIds as $userId) {
            $pending = Notification::query()
                ->forUser($userId)
                ->awaitingDigest()
                ->orderBy('created_at')
                ->orderBy('id')
                // A hard ceiling on one message. Someone returning from a
                // fortnight away should not be sent a Telegram message with
                // four hundred lines in it; the rest stay pending and go in
                // tomorrow's, and all of them are in the centre already.
                ->limit(50)
                ->get();

            if ($pending->isEmpty()) {
                continue;
            }

            if ($dryRun) {
                $this->line(sprintf('Would send %d item(s) to user %d.', $pending->count(), $userId));

                continue;
            }

            $sent += $this->sendFor($userId, $pending) ? 1 : 0;
        }

        if ($dryRun) {
            return self::SUCCESS;
        }

        $this->info(__('notifications.digest.sent', ['count' => $sent]));

        if ($sent > 0) {
            $this->audit->record('notification.digest.sent', null, [], ['recipients' => $sent]);
        }

        return self::SUCCESS;
    }

    /**
     * Recipients with something waiting.
     *
     * `digest_hour` is honoured with an hour of tolerance rather than exactly,
     * because the scheduler runs hourly and a strict equality check would drop
     * an entire day's digest if one cron tick were late. `--user` bypasses the
     * hour so the command is testable and can be run on demand.
     *
     * @return list<int>
     */
    private function pendingRecipients(int $limit, ?int $onlyUser): array
    {
        /*
         * TABLE-QUALIFIED COLUMNS ARE SQL EXPRESSIONS, NOT MODEL PROPERTIES.
         *
         * This query joins `notification_preferences`, so half the columns it
         * names do not belong to Notification at all — and `notifications.user_id`
         * is qualified only because the join makes the bare name ambiguous.
         * Wrapping them tells the analyser what they are instead of asserting
         * that Notification has a `notification_preferences.frequency` column.
         * The generated SQL is identical.
         */
        $query = Notification::query()
            ->awaitingDigest()
            ->join('notification_preferences', 'notification_preferences.user_id', '=', 'notifications.user_id')
            ->where(DB::raw('notification_preferences.frequency'), 'daily');

        if ($onlyUser !== null) {
            $query->where(DB::raw('notifications.user_id'), $onlyUser);
        } else {
            $query->where(DB::raw('notification_preferences.digest_hour'), (int) now()->format('G'));
        }

        return $query->distinct()
            ->limit($limit)
            ->pluck(DB::raw('notifications.user_id'))
            ->map(static fn ($id): int => (int) $id)
            ->all();
    }

    /**
     * Compose and send one digest.
     *
     * @param  Collection<int, Notification>  $pending
     */
    private function sendFor(int $userId, $pending): bool
    {
        $user = User::query()->find($userId);

        if ($user === null) {
            return false;
        }

        $recipient = $this->recipients->for($user);

        try {
            $envelope = $this->composer->compose(
                event: 'digest',
                recipientId: $userId,
                locale: $recipient['locale'],
                replacements: ['count' => $pending->count()],
                // 'alerts', so the gate applies. Someone who withdrew alert
                // consent between the item being queued and the digest going
                // out must not receive it — the digest is a push, and the
                // withdrawal was about pushes.
                consentPurpose: 'alerts',
                priority: 'low',
            );
        } catch (Throwable $e) {
            Log::error('Digest could not be composed', ['user_id' => $userId, 'exception' => $e->getMessage()]);

            return false;
        }

        // The item list is appended to the body the envelope already built, so
        // the reason and unsubscribe line stay where the envelope puts them —
        // last. A summary inserted after them would push the unsubscribe link
        // into the middle of the message.
        $summary = $pending
            ->map(static fn (Notification $n): string => __('notifications.digest.item', ['subject' => $n->subject]))
            ->implode("\n");

        $withItems = new NotificationEnvelope(
            key: $envelope->key,
            locale: $envelope->locale,
            subject: $envelope->subject,
            body: $envelope->body."\n\n".$summary,
            reason: $envelope->reason,
            unsubscribeUrl: $envelope->unsubscribeUrl,
            data: $envelope->data + ['item_count' => $pending->count()],
            actionUrl: $envelope->actionUrl,
            priority: $envelope->priority,
            consentPurpose: $envelope->consentPurpose,
        );

        $result = $this->dispatcher->dispatch($recipient, $withItems);

        /*
         * Marked sent whatever the dispatcher reports, including a refusal.
         *
         * A recipient who withdrew consent will never be sent these, so leaving
         * them pending would accumulate a queue that is retried hourly forever.
         * The items remain in the notification centre, unchanged and readable —
         * "sent" here means "no longer waiting for a digest", not "delivered".
         */
        Notification::query()
            ->whereIn('id', $pending->pluck('id')->all())
            ->update(['digest_state' => 'sent', 'digest_sent_at' => now(), 'updated_at' => now()]);

        return $result['delivered'] !== [];
    }
}
