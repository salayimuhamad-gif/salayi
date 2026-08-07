<?php

declare(strict_types=1);

namespace App\Modules\Projects\Console;

use App\Modules\Projects\Models\ProjectDraft;
use App\Modules\Projects\Models\ProjectDraftMedia;
use App\Modules\Projects\Services\ProjectDraftMediaService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;

/**
 * Delete abandoned wizard drafts (spec 12.1, 26.1).
 *
 * A draft nobody has touched for the retention period is abandoned. Left
 * forever they accumulate personal working notes about projects that were
 * never entered, which is data held for no purpose — and on a shared host the
 * JSON payloads are not small.
 *
 * TWO SAFETY RULES, both non-negotiable:
 *
 *   1. A SUBMITTED draft is never deleted by age. It is the audit trail
 *      linking a project to who entered it and what they typed.
 *   2. Deleting a draft NEVER deletes its project. The foreign key is
 *      nullOnDelete in that direction; this command only ever removes rows
 *      from project_drafts.
 */
final class PruneProjectDrafts extends Command
{
    protected $signature = 'mulkihawler:prune-project-drafts
                            {--days= : Retention period in days; defaults to config}
                            {--dry-run : Report what would be deleted and stop}';

    protected $description = 'Delete abandoned Project Creation Wizard drafts.';

    private ProjectDraftMediaService $media;

    public function handle(ProjectDraftMediaService $mediaService): int
    {
        $this->media = $mediaService;

        $days = (int) ($this->option('days') ?? config('mulkihawler.wizard.draft_retention_days', 30));

        if ($days < 1) {
            $this->error('Retention must be at least one day.');

            return self::FAILURE;
        }

        $cutoff = now()->subDays($days);

        $query = ProjectDraft::query()
            // Submitted drafts are audit records, not working state.
            ->whereNull('submitted_at')
            ->where(function ($q) use ($cutoff): void {
                $q->where('last_touched_at', '<', $cutoff)
                    ->orWhere(function ($fallback) use ($cutoff): void {
                        // Rows written before last_touched_at existed.
                        $fallback->whereNull('last_touched_at')->where('updated_at', '<', $cutoff);
                    });
            });

        $count = (clone $query)->count();

        if ($this->option('dry-run')) {
            $this->info("Would delete {$count} abandoned draft(s) older than {$days} day(s).");

            return self::SUCCESS;
        }

        /*
         * Chunked, and FILES FIRST.
         *
         * The previous version issued one bulk DELETE. The rows vanished and
         * every uploaded image stayed on disk forever — on Hostinger that is
         * storage nobody is accounting for, growing with every abandoned
         * draft, and unreachable because the row that named it is gone.
         *
         * Order matters: bytes are removed before the row, so a failure leaves
         * a row pointing at a missing file (recoverable, and visible) rather
         * than a file nothing points at (invisible, and permanent).
         */
        $deletedDrafts = 0;
        $deletedFiles = 0;
        $failedFiles = 0;
        $pending = [];

        $query->select('id')->chunkById(100, function ($drafts) use (&$deletedDrafts, &$deletedFiles, &$failedFiles, &$pending): void {
            $ids = $drafts->pluck('id')->all();

            foreach ($ids as $draftId) {
                /*
                 * Per draft, through the same proof the controllers use. The
                 * bulk delete this replaced removed drafts and cascaded their
                 * media rows away whether or not the files had gone — which is
                 * exactly how bytes become unfindable.
                 */
                /*
                 * Files and drafts are counted separately, and files are
                 * counted BEFORE the purge so the figure means something.
                 * Incrementing a file counter once per draft reported "1 file"
                 * for a draft holding twelve, and zero for a draft holding
                 * none — a number that is wrong in both directions is worse
                 * than no number.
                 */
                $before = ProjectDraftMedia::query()
                    ->where('project_draft_id', $draftId)
                    ->count();

                $stuck = $this->media->purgeDraft((int) $draftId);

                /*
                 * MEASURED, not inferred. `before - stuck` assumed every row
                 * not reported stuck was removed — but purgeDraft returns
                 * early when the draft is gone, and throws for a submitted
                 * one, in both cases having removed nothing. Counting what
                 * actually remains is the only figure that is true in every
                 * path.
                 */
                $after = ProjectDraftMedia::query()
                    ->where('project_draft_id', $draftId)
                    ->count();

                $deletedFiles += max(0, $before - $after);

                if ($stuck !== []) {
                    $failedFiles += count($stuck);
                    $pending = array_merge($pending, $stuck);

                    continue;
                }

                if ($this->media->completePurge((int) $draftId)) {
                    $deletedDrafts++;
                }
            }
        });

        Log::info('Pruned abandoned project drafts', [
            'drafts' => $deletedDrafts,
            'files_deleted' => $deletedFiles,
            'files_failed' => $failedFiles,
            'retention_days' => $days,
        ]);

        $this->info(
            "Deleted {$deletedDrafts} abandoned draft(s) and {$deletedFiles} file(s); "
            ."{$failedFiles} file(s) still failing."
        );

        /*
         * Non-zero when work remains, so a monitor watching exit codes — the
         * only thing watching on a shared host — can see the backlog.
         */
        return $failedFiles > 0 ? self::FAILURE : self::SUCCESS;
    }
}
