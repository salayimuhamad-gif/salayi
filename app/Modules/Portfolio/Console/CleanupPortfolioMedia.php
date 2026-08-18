<?php

declare(strict_types=1);

namespace App\Modules\Portfolio\Console;

use App\Modules\Operations\Services\AuditLogger;
use App\Modules\Portfolio\Models\PortfolioMedia;
use App\Modules\Portfolio\Services\PortfolioMediaService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Throwable;

/**
 * The durable cleanup BLOCKER 9 required (correction v4).
 *
 * v3 marked a media row `deletion_pending_at` when its file would not
 * delete, which correctly avoided orphaning bytes — and then nothing ever
 * came back for it. No command, no queue, no retry counter, no cap. A
 * persistent disk fault meant pending rows accumulated forever while the
 * owner kept adding new files, because pending rows are excluded from the
 * twelve-file count.
 *
 * This command closes that loop, and it does three separate jobs because
 * the two-phase upload introduced in BLOCKER 8 creates two more ways for
 * bytes and rows to disagree:
 *
 *   1. PENDING DELETIONS — rows whose file removal failed and whose
 *      backoff has elapsed. Retried; the row goes only when the disk is
 *      provably clean.
 *   2. STALE STAGING ROWS — an upload that claimed a slot and then died
 *      before finalising. After a grace period the row and any staged
 *      bytes are removed, which is what stops a crashed request from
 *      permanently consuming one of the twelve slots.
 *   3. ORPHANED STAGING FILES — bytes under the staging prefix with no row
 *      at all, from a crash between writing the staged file and committing
 *      the row. Removed after the same grace period.
 *
 * Everything here is idempotent and safe to run concurrently with itself:
 * each row is re-read and re-checked under a lock inside
 * `retryPendingDeletion()`, and a second pass over a row another pass
 * already resolved is a no-op rather than an error. That matters on
 * Hostinger, where a single cron entry can overlap with a slow previous
 * run.
 *
 * Nothing in here logs a private path or an original filename. The private
 * portfolio path embeds the owner's user id and property id, and the log
 * channel is not the place for either.
 *
 * Hostinger cron (documented in DEPLOY_HOSTINGER.md):
 *
 *   *\/10 * * * * /opt/alt/php83/usr/bin/php \
 *     ~/domains/myhawler.com/application/artisan portfolio:media-cleanup >> /dev/null 2>&1
 */
final class CleanupPortfolioMedia extends Command
{
    protected $signature = 'portfolio:media-cleanup
                            {--limit=100 : Maximum rows to process in one pass}
                            {--stale-minutes=30 : Age before a staging row is considered abandoned}';

    protected $description = 'Retry failed portfolio media deletions and clear abandoned staging files';

    public function handle(PortfolioMediaService $media, AuditLogger $audit): int
    {
        $limit = max(1, (int) $this->option('limit'));
        $staleMinutes = max(5, (int) $this->option('stale-minutes'));

        $resolved = $this->drainPendingDeletions($media, $limit);
        $staleRows = $this->clearStaleStagingRows($media, $limit, $staleMinutes);
        $staleFiles = $this->clearOrphanStagingFiles($staleMinutes);
        $escalated = PortfolioMedia::query()->whereNotNull('deletion_escalated_at')->count();

        $this->info(sprintf(
            'deletions resolved: %d, stale staging rows: %d, orphan staged files: %d, escalated: %d',
            $resolved,
            $staleRows,
            $staleFiles,
            $escalated,
        ));

        /*
         * Escalations are the administrator-visible signal the review asked
         * for. Reported every pass rather than once, because a backlog that
         * nobody is told about repeatedly is a backlog nobody fixes.
         */
        if ($escalated > 0) {
            Log::channel('security')->error('portfolio.media_cleanup_escalations_outstanding', [
                'count' => $escalated,
            ]);

            $audit->security('portfolio.media_cleanup_escalations_outstanding', [
                'count' => $escalated,
            ]);

            $this->warn(sprintf('%d media row(s) need manual review.', $escalated));
        }

        return self::SUCCESS;
    }

    /**
     * Retry deletions whose backoff has elapsed.
     *
     * Escalated rows (`deletion_next_attempt_at` null after exhausting the
     * attempt budget) are deliberately NOT picked up: at that point
     * retrying is not going to help, and hammering a broken disk every ten
     * minutes forever obscures the alert rather than resolving it.
     */
    private function drainPendingDeletions(PortfolioMediaService $media, int $limit): int
    {
        $resolved = 0;

        PortfolioMedia::query()
            ->whereNotNull('deletion_pending_at')
            ->whereNull('deletion_escalated_at')
            ->where(function ($query): void {
                $query->whereNull('deletion_next_attempt_at')
                    ->orWhere('deletion_next_attempt_at', '<=', now());
            })
            ->orderBy('deletion_next_attempt_at')
            ->limit($limit)
            ->get()
            ->each(function (PortfolioMedia $row) use ($media, &$resolved): void {
                try {
                    if ($media->retryPendingDeletion($row)) {
                        $resolved++;
                    }
                } catch (Throwable $exception) {
                    // One bad row must not end the pass.
                    Log::channel('security')->warning('portfolio.media_cleanup_row_failed', [
                        'media_id' => $row->id,
                        'exception' => $exception::class,
                    ]);
                }
            });

        return $resolved;
    }

    /**
     * Remove rows that claimed a slot and never finalised.
     *
     * The grace period is what makes this safe to run alongside live
     * uploads: a legitimate staging row lives for the length of one
     * request, so anything older than thirty minutes belongs to a process
     * that is not coming back.
     */
    private function clearStaleStagingRows(PortfolioMediaService $media, int $limit, int $staleMinutes): int
    {
        $cleared = 0;

        PortfolioMedia::query()
            ->whereIn('status', [PortfolioMedia::STATUS_STAGING, PortfolioMedia::STATUS_ORPHANED])
            ->where('created_at', '<=', now()->subMinutes($staleMinutes))
            ->limit($limit)
            ->get()
            ->each(function (PortfolioMedia $row) use ($media, &$cleared): void {
                try {
                    /*
                     * Routed through the ordinary deletion path so a stale
                     * staging row that ALSO cannot have its bytes removed
                     * lands in the pending state with a retry schedule,
                     * rather than being force-deleted into an orphan.
                     */
                    $media->delete($row);

                    if ($row->exists === false) {
                        $cleared++;
                    }
                } catch (Throwable $exception) {
                    Log::channel('security')->warning('portfolio.media_cleanup_row_failed', [
                        'media_id' => $row->id,
                        'exception' => $exception::class,
                    ]);
                }
            });

        return $cleared;
    }

    /**
     * Remove staged bytes that never got a row.
     *
     * The window this covers: the staged file is written in phase 1, and
     * the row is inserted in phase 2. A crash between them leaves bytes
     * that nothing references. They are found by walking the staging
     * prefix, which is the one place such files can be — final paths are
     * only ever written by a `move()` from a row that exists.
     */
    private function clearOrphanStagingFiles(int $staleMinutes): int
    {
        $removed = 0;
        $cutoff = now()->subMinutes($staleMinutes)->getTimestamp();

        try {
            $disk = Storage::disk(PortfolioMediaService::DISK);
            $prefix = 'portfolio-media-staging';

            if (! $disk->exists($prefix)) {
                return 0;
            }

            $known = PortfolioMedia::query()
                ->whereNotNull('staged_path')
                ->pluck('staged_path')
                ->flip();

            foreach ($disk->allFiles($prefix) as $path) {
                if ($known->has($path)) {
                    continue;
                }

                if ($disk->lastModified($path) > $cutoff) {
                    continue;
                }

                if ($disk->delete($path)) {
                    $removed++;
                }
            }
        } catch (Throwable $exception) {
            Log::channel('security')->warning('portfolio.media_cleanup_sweep_failed', [
                'exception' => $exception::class,
            ]);
        }

        return $removed;
    }
}
