<?php

declare(strict_types=1);

namespace App\Modules\Projects\Console;

use App\Modules\Marketplace\Models\Offer;
use App\Modules\Marketplace\Models\OfferMedia;
use App\Modules\Marketplace\Services\OfferMediaService;
use App\Modules\Projects\Models\ProjectDraftMedia;
use App\Modules\Projects\Models\ProjectMedia;
use App\Modules\Projects\Services\ProjectDraftMediaService;
use App\Modules\Projects\Services\ProjectMediaService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Retry every failed media cleanup, across all three domains (spec 26.1).
 *
 * `project_media` and `project_draft_media` each had their own retry command;
 * `offer_media` had none at all, so a failed offer deletion incremented its
 * attempt counter once and was then never looked at again — the file stayed on
 * disk and the row stayed in the gallery, permanently.
 *
 * One command, three domains, and the DELETION LOGIC ITSELF stays in the
 * services. This selects work and calls `finaliseDeletion`; it does not know
 * how to remove a file, reconcile a cover or complete a purge, because three
 * copies of those rules is precisely what went wrong before.
 */
final class RetryMediaCleanupAll extends Command
{
    protected $signature = 'mulkihawler:retry-media-cleanup-all
                            {--limit=200 : Rows to attempt per domain}
                            {--dry-run : Report the backlog and stop}';

    protected $description = 'Retry failed media cleanup for project, draft and offer media.';

    /**
     * How long a claim holds before another run may take it.
     *
     * Longer than any single pass, far shorter than the hourly schedule, so a
     * crashed run's rows return on the next tick rather than sticking.
     */
    private const CLAIM_LEASE_SECONDS = 900;

    public function handle(
        ProjectMediaService $projectMedia,
        ProjectDraftMediaService $draftMedia,
        OfferMediaService $offerMedia,
    ): int {
        $limit = max(1, (int) $this->option('limit'));
        $dryRun = (bool) $this->option('dry-run');

        $removed = 0;
        $failed = 0;
        $exhausted = 0;

        /* ---------------------------------------------- project media */

        [$done, $stuck, $spent] = $this->sweepDomain(
            ProjectMedia::query()
                ->where('cleanup_pending', true)
                ->where('cleanup_attempts', '<', ProjectMediaService::CLEANUP_ATTEMPT_CEILING)
                ->orderBy('cleanup_attempts')
                ->limit($limit),
            ProjectMedia::query()
                ->where('cleanup_pending', true)
                ->where('cleanup_attempts', '>=', ProjectMediaService::CLEANUP_ATTEMPT_CEILING),
            $dryRun,
            static fn (ProjectMedia $row): bool => $projectMedia->finaliseDeletion((int) $row->project_id, $row),
            static fn (ProjectMedia $row): bool => $projectMedia->handOffToOutbox($row),
            'project_media',
        );

        $removed += $done;
        $failed += $stuck;
        $exhausted += $spent;

        /* ------------------------------------------------ draft media */

        [$done, $stuck, $spent] = $this->sweepDomain(
            ProjectDraftMedia::query()
                ->where('cleanup_pending', true)
                ->where('cleanup_attempts', '<', ProjectDraftMediaService::CLEANUP_ATTEMPT_CEILING)
                ->orderBy('cleanup_attempts')
                ->limit($limit),
            ProjectDraftMedia::query()
                ->where('cleanup_pending', true)
                ->where('cleanup_attempts', '>=', ProjectDraftMediaService::CLEANUP_ATTEMPT_CEILING),
            $dryRun,
            static fn (ProjectDraftMedia $row): bool => $draftMedia->finaliseDeletion($row),
            static fn (ProjectDraftMedia $row): bool => $draftMedia->handOffToOutbox($row),
            'project_draft_media',
        );

        $removed += $done;
        $failed += $stuck;
        $exhausted += $spent;

        /* ------------------------------------------------ offer media */

        [$done, $stuck, $spent] = $this->sweepDomain(
            OfferMedia::query()
                ->where('cleanup_pending', true)
                ->where('cleanup_attempts', '<', OfferMediaService::CLEANUP_ATTEMPT_CEILING)
                ->orderBy('cleanup_attempts')
                ->limit($limit),
            OfferMedia::query()
                ->where('cleanup_pending', true)
                ->where('cleanup_attempts', '>=', OfferMediaService::CLEANUP_ATTEMPT_CEILING),
            $dryRun,
            static function (OfferMedia $row) use ($offerMedia): bool {
                $offer = Offer::query()->find($row->offer_id);

                // An offer deleted around its media leaves nothing to lock;
                // the row goes with the cascade and the orphan sweep owns the
                // file from that point.
                return $offer !== null && $offerMedia->finaliseDeletion($offer, $row);
            },
            static fn (OfferMedia $row): bool => $offerMedia->handOffToOutbox($row),
            'offer_media',
        );

        $removed += $done;
        $failed += $stuck;
        $exhausted += $spent;

        if ($dryRun) {
            return self::SUCCESS;
        }

        Log::info('Retried media cleanup across all domains', [
            'removed' => $removed,
            'failed' => $failed,
            'exhausted' => $exhausted,
        ]);

        $this->info("Removed {$removed}; {$failed} still failing; {$exhausted} exhausted.");

        /*
         * Non-zero while work remains. A scheduled command that always exits 0
         * is invisible to every monitor watching exit codes, which on a shared
         * host is the only thing watching.
         */
        return ($failed > 0 || $exhausted > 0) ? self::FAILURE : self::SUCCESS;
    }

    /**
     * @param  callable(mixed): bool  $finalise
     * @return array{0: int, 1: int, 2: int}
     */
    private function sweepDomain(
        mixed $pending,
        mixed $exhaustedQuery,
        bool $dryRun,
        callable $finalise,
        callable $handOff,
        string $label,
    ): array {
        /*
         * EXHAUSTED ROWS ARE INSPECTED, not merely counted.
         *
         * Handoff to the outbox previously ran only on the exact attempt that
         * reached the ceiling. A failure at that instant left the row
         * exhausted and unhanded-off — and since no query selects rows past
         * the ceiling, the row and its file became permanently ownerless.
         *
         * `cleanup_outbox_id` makes that state visible: null on an exhausted
         * row means the handoff still owes, and it is retried here.
         */
        $exhaustedRows = $exhaustedQuery->get();
        $exhausted = $exhaustedRows->count();

        $owed = $exhaustedRows->filter(
            static fn ($row): bool => $row->cleanup_outbox_id === null,
        );

        if ($dryRun) {
            $this->line(
                "  {$label}: ".$pending->count()." retryable, {$exhausted} exhausted"
                .' ('.$owed->count().' awaiting handoff)'
            );

            return [0, 0, $exhausted];
        }

        $failed = 0;

        foreach ($owed as $row) {
            // A failed handoff leaves cleanup_outbox_id null, so the row stays
            // eligible for another attempt on the next run.
            if (! $handOff($row)) {
                $failed++;
            }
        }

        /*
         * ROWS ARE CLAIMED, not merely selected.
         *
         * `withoutOverlapping()` protects the SCHEDULED invocation only. A
         * manual run, a deprecated single-domain command, a second worker or a
         * second host could all process the same source at once — producing
         * duplicate finalisation and duplicate audit evidence describing
         * events that happened once.
         *
         * `cleanup_last_error` carries the claim marker because it already
         * exists on all three tables; a dedicated column would be tidier but
         * needs a migration on each, and the marker is transient either way.
         */
        $claimed = [];

        foreach ($pending->get() as $row) {
            $won = DB::transaction(function () use ($row): bool {
                $fresh = $row->newQuery()->lockForUpdate()->find($row->id);

                if ($fresh === null || ! (bool) $fresh->cleanup_pending) {
                    return false;   // finished by somebody else
                }

                if (str_starts_with((string) $fresh->cleanup_last_error, 'claimed:')) {
                    $claimedAt = (int) substr((string) $fresh->cleanup_last_error, 8);

                    // A claim older than the lease belonged to a run that died.
                    if (time() - $claimedAt < self::CLAIM_LEASE_SECONDS) {
                        return false;
                    }
                }

                $fresh->forceFill(['cleanup_last_error' => 'claimed:'.time()])->save();

                return true;
            });

            if ($won) {
                $claimed[] = $row->refresh();
            }
        }

        $removed = 0;

        foreach ($claimed as $row) {
            $finalise($row) ? $removed++ : $failed++;
        }

        return [$removed, $failed, $exhausted];
    }
}
