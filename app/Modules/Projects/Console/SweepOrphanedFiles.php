<?php

declare(strict_types=1);

namespace App\Modules\Projects\Console;

use App\Modules\Core\Support\SafeText;
use App\Modules\Marketplace\Services\OfferMediaService;
use App\Modules\Projects\Models\OrphanedFile;
use App\Modules\Projects\Services\ProjectDraftMediaService;
use App\Modules\Projects\Services\ProjectMediaService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use RuntimeException;
use Throwable;

/**
 * Drain the orphaned-file outbox (spec 26.1).
 *
 * Every path that writes bytes before creating its row can fail in between.
 * Compensation removes the file; when compensation itself fails, an
 * `orphaned_files` row IS the only remaining reference. This is what turns
 * that row back into free disk space.
 *
 * Idempotent by construction: a file already absent counts as resolved, so
 * running twice is harmless and a partially-completed run resumes cleanly.
 */
final class SweepOrphanedFiles extends Command
{
    protected $signature = 'mulkihawler:sweep-orphaned-files
                            {--limit=200 : Rows to attempt in one run}
                            {--max-attempts=10 : Report rather than retry past this}
                            {--dry-run : Report the backlog and stop}';

    protected $description = 'Remove files recorded as orphaned by a failed compensation.';

    /**
     * A barrier fired after the claim transaction commits and before the CAS.
     *
     * @var (callable(int, int): void)|null
     */
    private static $casBarrier = null;

    /** Install a barrier for a test. Pass null to remove it. */
    public static function setCasBarrier(?callable $barrier): void
    {
        self::$casBarrier = $barrier;
    }

    public function handle(
        ProjectDraftMediaService $draftMedia,
        ProjectMediaService $projectMedia,
        OfferMediaService $offerMedia,
    ): int {
        $limit = max(1, (int) $this->option('limit'));
        $maxAttempts = max(1, (int) $this->option('max-attempts'));

        /*
         * CLAIMED under a lock, so two concurrent sweeps do not both try the
         * same file — on a shared host the scheduler can overlap, and two
         * processes deleting the same path means one of them always "fails".
         */
        /*
         * A DRY RUN CLAIMS NOTHING. Claiming before checking the flag meant
         * the first dry run stamped every row and a second reported an empty
         * backlog — a report that changes what it reports is worse than none.
         */
        $dryRun = (bool) $this->option('dry-run');

        $outstanding = DB::transaction(function () use ($limit, $maxAttempts, $dryRun) {
            $rows = OrphanedFile::query()
                ->outstanding()
                ->where('attempts', '<', $maxAttempts)
                ->where(function ($claimable): void {
                    /*
                     * Never claimed, or claimed by a run that has since died.
                     * Fifteen minutes is longer than any single sweep and far
                     * shorter than the hourly schedule, so a crashed run's
                     * rows return on the next tick rather than sticking.
                     */
                    $claimable->whereNull('last_attempted_at')
                        ->orWhere('last_attempted_at', '<', now()->subMinutes(15));
                })
                ->orderBy('attempts')
                ->limit($limit)
                ->lockForUpdate()
                ->get();

            if (! $dryRun) {
                OrphanedFile::query()
                    ->whereIn('id', $rows->pluck('id'))
                    ->update(['last_attempted_at' => now()]);
            }

            return $rows;
        });

        $exhausted = OrphanedFile::query()
            ->outstanding()
            ->where('attempts', '>=', $maxAttempts)
            ->count();

        if ($dryRun) {
            $this->info("Outstanding: {$outstanding->count()} retryable, {$exhausted} exhausted.");

            foreach ($outstanding as $file) {
                $this->line("  {$file->disk}:{$file->path} ({$file->reason}, {$file->attempts} attempts)");
            }

            return self::SUCCESS;
        }

        $resolved = 0;
        $failed = 0;

        foreach ($outstanding as $file) {
            try {
                $disk = Storage::disk((string) $file->disk);

                /*
                 * ALREADY MISSING is resolved, not an error. An earlier
                 * interrupted run may have removed it, and treating that as a
                 * failure would keep the row forever — the exact backlog this
                 * exists to drain.
                 */
                $gone = ! $disk->exists((string) $file->path) || $disk->delete((string) $file->path);

                if (! $gone) {
                    throw new RuntimeException('delete returned false');
                }

                // Kept briefly as evidence rather than deleted outright: a
                // vanished backlog tells nobody it was ever there.
                /*
                 * TWO PHASES, and resolution needs BOTH.
                 *
                 * Marking resolved before finalising hid every failure of the
                 * second phase behind a row nothing would look at again — a
                 * media row that could not be deleted, a cover left
                 * unreconciled, a purge that never completed.
                 */
                /*
                 * COMPARE-AND-SWAP, not a stale write.
                 *
                 * `attempts = $file->attempts + 1` was computed from a model
                 * read BEFORE the byte removal. A concurrent record() could
                 * increment the row in between, and this save overwrote that
                 * increment — losing the newer failure and then letting the
                 * equality check below close an incident that was still
                 * outstanding.
                 *
                 * The observed count becomes a version token: the update binds
                 * to it, so a row that moved underneath us matches nothing.
                 */
                $observedAttempts = (int) $file->attempts;

                /*
                 * THE BARRIER, if a test installed one.
                 *
                 * The claim transaction has committed and the bytes are gone;
                 * the CAS below has not run. That is the only window in which
                 * a competing failure can be recorded, and it cannot be
                 * reached from outside this command — a DB::listen hook fires
                 * while this connection still holds its claim lock, which on
                 * SQLite simply gives the other writer "database is locked".
                 *
                 * Null in production.
                 */
                if (self::$casBarrier !== null) {
                    (self::$casBarrier)((int) $file->id, $observedAttempts);
                }

                $claimed = OrphanedFile::query()
                    ->whereKey($file->id)
                    ->where('attempts', $observedAttempts)
                    ->update([
                        'file_resolved_at' => now(),
                        'last_attempted_at' => now(),
                        'attempts' => $observedAttempts + 1,
                    ]);

                if ($claimed === 0) {
                    // Somebody recorded a newer failure; this pass stands down
                    // rather than overwriting it.
                    $this->warn("Job {$file->id} changed while being swept; left for the next run.");

                    $failed++;

                    continue;
                }

                // The swap succeeded, so this is the authoritative count now.
                $observedAttempts++;
                $file->refresh();

                $outcome = $this->finaliseSource($file, $draftMedia, $projectMedia, $offerMedia);

                if (! $outcome['ok']) {
                    // Retryable, with its reason kept. The FILE is gone, which
                    // is progress; the row still needs finishing.
                    $file->forceFill([
                        'last_error' => Str::limit((string) $outcome['reason'], 240, ''),
                    ])->save();

                    $failed++;

                    continue;
                }

                /*
                 * THE TRANSITION HAPPENS UNDER A LOCK, from a reloaded row.
                 *
                 * Resolving from the stale model read before the byte removal
                 * could close a job that had recorded a NEW failure in the
                 * meantime — the newer attempt silently discarded and the file
                 * marked resolved while still outstanding.
                 */
                $closed = DB::transaction(function () use ($file, $observedAttempts): bool {
                    $fresh = OrphanedFile::query()->lockForUpdate()->find($file->id);

                    if ($fresh === null) {
                        return true;   // already gone
                    }

                    /*
                     * The SAME token this pass swapped in. A different count
                     * means a fresh failure was recorded while the source was
                     * being finalised, and closing now would discard it.
                     */
                    if ((int) $fresh->attempts !== $observedAttempts) {
                        return false;
                    }

                    $fresh->forceFill(['source_finalised_at' => now()])->save();

                    /*
                     * Resolution RELEASES the identity: `active_key` goes to
                     * null, so this row becomes immutable evidence of one past
                     * incident and the next incident gets its own row.
                     */
                    $fresh->markResolved();

                    return true;
                });

                if (! $closed) {
                    $this->warn("Job {$file->id} recorded a newer failure while being swept; left open.");

                    $failed++;

                    continue;
                }

                $resolved++;

                /*
                 * FINISH THE JOB. The bytes are gone, but the cleanup-pending
                 * media row that named them survives — and the retry commands
                 * no longer select it, being past the ceiling. Left alone it
                 * blocks completePurge() forever and shows in the gallery as a
                 * reference to nothing.
                 */

            } catch (Throwable $e) {
                $failed++;

                /*
                 * The error path increments IN THE DATABASE too. Writing
                 * `$file->attempts + 1` from the model here would overwrite a
                 * concurrent increment just as surely as the success path did.
                 */
                OrphanedFile::query()->whereKey($file->id)->update([
                    'attempts' => DB::raw(
                        DB::connection()->getDriverName() === 'mysql'
                            ? '`attempts` + 1'
                            : '"orphaned_files"."attempts" + 1'
                    ),
                    'last_attempted_at' => now(),
                    'last_error' => SafeText::truncate($e->getMessage(), 255),
                ]);
            }
        }

        Log::info('Swept orphaned files', [
            'resolved' => $resolved,
            'failed' => $failed,
            'exhausted' => $exhausted,
        ]);

        if ($exhausted > 0) {
            // Surfaced: a file that will not delete after ten attempts is a
            // permissions or mount problem, and needs a person.
            $this->warn("{$exhausted} orphaned file(s) have exhausted their retries and need attention.");
        }

        $this->info("Resolved {$resolved}; {$failed} still failing.");

        /*
         * A NON-ZERO exit when work remains. A scheduled command that always
         * reports success is invisible to every monitor watching exit codes —
         * which is the only thing watching on a shared host.
         */
        return ($failed > 0 || $exhausted > 0) ? self::FAILURE : self::SUCCESS;
    }

    /**
     * Ask the owning service to finalise the row this file came from.
     *
     * The command SELECTS work; the service performs the transition. This
     * previously deleted rows itself — a second implementation of locking,
     * cover reconciliation and purge completion, which is exactly the
     * duplication the services exist to prevent.
     *
     * @return array{ok: bool, reason: string|null}
     */
    private function finaliseSource(
        OrphanedFile $file,
        ProjectDraftMediaService $draftMedia,
        ProjectMediaService $projectMedia,
        OfferMediaService $offerMedia,
    ): array {
        /*
         * The job must name a source before anything is deleted. A job with no
         * source describes a file that never had a surviving row — there is
         * nothing to finalise, and treating it as finalisable would look for a
         * row by path alone.
         */
        if ($file->source_type === null || $file->source_id === null) {
            // Nothing to finalise: the file had no surviving row to begin with.
            return ['ok' => true, 'reason' => null];
        }

        return match ($file->source_type) {
            'project_draft_media' => $draftMedia->finaliseAbsentSource(
                (int) $file->source_id,
                (string) $file->disk,
                (string) $file->path,
                // The exact job: a source linked to a DIFFERENT job must not
                // be finalised by this one.
                (int) $file->id,
            ),
            'project_media' => $projectMedia->finaliseAbsentSource(
                (int) $file->source_id,
                (string) $file->disk,
                (string) $file->path,
                // The exact job: a source linked to a DIFFERENT job must not
                // be finalised by this one.
                (int) $file->id,
            ),
            /*
             * Offer media fell through to `default` and was reported as an
             * unknown source type — so an exhausted offer image had its file
             * removed and its row left behind permanently, since no command
             * selects rows past the ceiling.
             */
            'offer_media' => $offerMedia->finaliseAbsentSource(
                (int) $file->source_id,
                (string) $file->disk,
                (string) $file->path,
                // The exact job: a source linked to a DIFFERENT job must not
                // be finalised by this one.
                (int) $file->id,
            ),
            default => ['ok' => false, 'reason' => 'Unknown source type: '.$file->source_type],
        };
    }
}
