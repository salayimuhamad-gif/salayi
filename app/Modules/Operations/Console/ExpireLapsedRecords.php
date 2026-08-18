<?php

declare(strict_types=1);

namespace App\Modules\Operations\Console;

use App\Modules\Knowledge\Enums\KnowledgeStatus;
use App\Modules\Knowledge\Models\KnowledgeEvent;
use App\Modules\Marketplace\Enums\OfferStatus;
use App\Modules\Marketplace\Models\Offer;
use App\Modules\Notifications\Services\Notifier;
use App\Modules\Operations\Services\AuditLogger;
use Illuminate\Console\Command;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Move lapsed records to Expired (spec 19.3, 21.3).
 *
 * Two things were storing an expiry date and relying on a query filter to hide
 * the consequence. That is not the same as expiring:
 *
 *   - A lapsed OFFER was excluded from the public browse query, so a buyer
 *     never saw it — and its status stayed Published, so the admin list, the
 *     company's own view and every count reported it as live. The seller
 *     believes their listing is running.
 *   - A lapsed KNOWLEDGE EVENT stopped being AI-usable via the scope and stayed
 *     Published, so it remained on the public timeline indefinitely. A road
 *     closure that ended two years ago still reads as current.
 *
 * The second is the worse of the two: the first hides something that should be
 * visible, the second shows something that should not be.
 *
 * Batched, because on Hostinger the worker gets roughly fifty seconds per cron
 * tick. An unbounded update on a large table is killed mid-flight every minute
 * and never completes.
 */
final class ExpireLapsedRecords extends Command
{
    protected $signature = 'mulkihawler:expire
                            {--limit=200 : Records of each kind to expire this run}
                            {--dry-run : Report what would change without changing it}';

    protected $description = 'Move offers and knowledge events past their expiry to Expired';

    public function __construct(
        private readonly AuditLogger $audit,
        private readonly Notifier $notifier,
    ) {
        parent::__construct();
    }

    public function handle(): int
    {
        $limit = max(1, (int) $this->option('limit'));
        $dryRun = (bool) $this->option('dry-run');

        $offers = $this->lapsedOffers($limit);
        $events = $this->lapsedEvents($limit);

        if ($dryRun) {
            $this->line(sprintf(
                'Would expire %d offer(s) and %d knowledge event(s).',
                $offers->count(),
                $events->count(),
            ));

            return self::SUCCESS;
        }

        $expiredOffers = $this->expireOffers($offers);
        $expiredEvents = $this->expireEvents($events);

        if ($expiredOffers === 0 && $expiredEvents === 0) {
            $this->info('Nothing to expire.');

            return self::SUCCESS;
        }

        $this->info(sprintf('Expired %d offer(s) and %d knowledge event(s).', $expiredOffers, $expiredEvents));

        return self::SUCCESS;
    }

    /** @return Collection<int, Offer> */
    private function lapsedOffers(int $limit)
    {
        return Offer::query()
            ->whereNotNull('expires_at')
            ->where('expires_at', '<', now())
            // Only published listings expire. A draft with an old expiry date
            // was never live, and moving it to Expired would tell a seller
            // their listing ended when it never started.
            ->where('status', OfferStatus::Published->value)
            ->limit($limit)
            ->get();
    }

    /** @return Collection<int, KnowledgeEvent> */
    private function lapsedEvents(int $limit)
    {
        return KnowledgeEvent::query()
            ->whereNotNull('expires_on')
            ->whereDate('expires_on', '<', now()->toDateString())
            ->whereIn('status', [KnowledgeStatus::Published->value, KnowledgeStatus::Approved->value])
            ->limit($limit)
            ->get();
    }

    /** @param Collection<int, Offer> $offers */
    private function expireOffers($offers): int
    {
        $count = 0;

        foreach ($offers as $offer) {
            try {
                DB::transaction(function () use ($offer, &$count): void {
                    // Through the state machine, not a bulk update. The
                    // workflow is the thing that guarantees Published only ever
                    // reaches Expired legally, and a direct UPDATE would step
                    // around the guard Step 5 exists to provide.
                    $offer->transitionTo(OfferStatus::Expired, actorIsModerator: true);
                    $offer->save();
                    $count++;
                });

                $this->audit->record('offer.expired', $offer, [], [
                    'expires_at' => $offer->expires_at?->toIso8601String(),
                ]);

                /*
                 * The seller is told. Until now the sweep recorded the expiry
                 * in the audit log and moved on, so a listing stopped being
                 * visible and the person who posted it found out by noticing
                 * the enquiries had stopped.
                 *
                 * Sent AFTER the transaction commits, never inside it: a
                 * message announcing an expiry that then rolls back is worse
                 * than a late one. Notifier swallows its own failures, so a
                 * bad telegram id cannot abort the remaining records.
                 */
                $this->notifyExpiry($offer);
            } catch (RuntimeException $e) {
                // One illegal transition must not abort the sweep. The
                // remaining records still need expiring.
                $this->warn(sprintf('Offer %d could not expire: %s', $offer->id, $e->getMessage()));
            }
        }

        return $count;
    }

    /**
     * Tell the seller their listing lapsed.
     *
     * `alerts` rather than a transactional purpose, deliberately. An expiry is
     * the platform's own housekeeping, not a moderation decision about them,
     * and someone who has switched alerts off has said they do not want this
     * kind of message. The in-app record is written regardless — the database
     * channel is exempt from the gate — so the information is never lost, only
     * the unsolicited push is withheld.
     */
    private function notifyExpiry(Offer $offer): void
    {
        $recipientId = $offer->owner_user_id ?? $offer->agent_user_id;

        if ($recipientId === null) {
            return;
        }

        $this->notifier->send(
            event: 'offer_expired',
            recipient: (int) $recipientId,
            replacements: [
                'title' => (string) $offer->title_ckb,
                'date' => (string) ($offer->expires_at?->toDateString() ?? ''),
            ],
            consentPurpose: 'alerts',
        );
    }

    /** @param Collection<int, KnowledgeEvent> $events */
    private function expireEvents($events): int
    {
        $count = 0;

        foreach ($events as $event) {
            try {
                DB::transaction(function () use ($event, &$count): void {
                    $event->transitionTo(KnowledgeStatus::Expired);
                    $event->saveQuietly();
                    $count++;
                });

                $this->audit->record('knowledge_event.expired', $event, [], [
                    'expires_on' => $event->expires_on?->toDateString(),
                ]);
            } catch (RuntimeException $e) {
                $this->warn(sprintf('Event %d could not expire: %s', $event->id, $e->getMessage()));
            }
        }

        return $count;
    }
}
