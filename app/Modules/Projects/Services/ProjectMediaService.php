<?php

declare(strict_types=1);

namespace App\Modules\Projects\Services;

use App\Modules\Core\Support\MediaUploader;
use App\Modules\Core\Support\SafeText;
use App\Modules\Operations\Services\AuditLogger;
use App\Modules\Projects\Exceptions\DuplicateMediaException;
use App\Modules\Projects\Models\OrphanedFile;
use App\Modules\Projects\Models\Project;
use App\Modules\Projects\Models\ProjectDraft;
use App\Modules\Projects\Models\ProjectDraftMedia;
use App\Modules\Projects\Models\ProjectMedia;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use RuntimeException;
use Throwable;

/**
 * The single writer for project media state.
 *
 * Cover changes, ordering, deletion and promotion were spread across the media
 * controller, the wizard controller and two console commands — five places
 * each maintaining the same invariant slightly differently. The immediate
 * delete path reassigned a cover and the retry path did not; upload set one
 * and update could unset it. Any of those, run concurrently, produces a
 * project with zero covers or two.
 *
 * THE INVARIANT: a project with at least one non-cleanup-pending media row has
 * EXACTLY ONE cover.
 *
 * Zero is not a cosmetic fault — a project with no cover renders no card image
 * anywhere, and nobody notices until a listing page looks broken. Two is worse,
 * because which one wins depends on row order.
 *
 * Every method below opens a transaction and takes `lockForUpdate` on the
 * project's media before reading state it is about to change. Without the
 * lock, two concurrent requests both observe "there is a cover", both clear
 * it, and both set their own.
 *
 * PHYSICAL FILES ARE REMOVED AFTER THE DATABASE COMMIT.
 *
 * This docblock previously said the opposite, and the opposite was wrong: a
 * transaction that rolls back after the unlink restores the row while the
 * bytes stay gone, leaving a gallery entry pointing at nothing, permanently.
 *
 * The row is therefore flagged `cleanup_pending` and committed first; the
 * bytes go afterwards. A failure at that point leaves a retryable orphaned
 * FILE, which the sweep can find because the row still names it. An orphaned
 * file is a cost; a broken reference is a defect.
 */
final class ProjectMediaService
{
    /** Retries before a file is handed to the orphan outbox. */
    public const CLEANUP_ATTEMPT_CEILING = 5;

    public function __construct(
        private readonly AuditLogger $audit,
        private readonly MediaUploader $uploader,
    ) {}

    /**
     * Store an uploaded file and attach it to a project, atomically.
     *
     * The controller wrote the file, then created the row, then reconciled the
     * cover, then wrote an audit entry — four steps with three places to fail
     * after the bytes existed. Any of them left a public file with nothing
     * referencing it.
     *
     * Everything now happens here, and a failure after the write records a
     * durable cleanup row rather than a log line.
     *
     * @param  array<model-property<ProjectMedia>, mixed>  $attributes
     * @param  array{path?: string, checksum?: string, mime?: string, size?: int, width?: int|null, height?: int|null}  $result  the uploader's stored-file result
     * @return ProjectMedia|null null when the row could not be created; the
     *                           file is removed or recorded for the sweep
     */
    public function storeForProject(int $projectId, array $result, array $attributes): ?ProjectMedia
    {
        $path = (string) ($result['path'] ?? '');

        try {
            return DB::transaction(function () use ($projectId, $result, $attributes, $path): ProjectMedia {
                /*
                 * The PROJECT row first.
                 *
                 * An empty gallery has no media row to lock, so two first
                 * uploads serialised on nothing: both saw no duplicate, both
                 * computed sort_order 1, and both could claim the cover. The
                 * project row is the one thing that always exists.
                 *
                 * LOCK ORDER, matching every other media service:
                 *   1. projects row
                 *   2. project_media rows
                 */
                Project::query()->lockForUpdate()->findOrFail($projectId);

                $rows = $this->lockProjectMedia($projectId);

                /*
                 * The duplicate check and the next sort order are decided
                 * UNDER THE LOCK. Computing them in the controller meant two
                 * concurrent uploads both saw "no duplicate" and both took the
                 * same position — one file stored twice, at one index.
                 */
                $active = $rows->reject(static fn (ProjectMedia $m): bool => (bool) $m->cleanup_pending);

                $checksum = $result['checksum'] ?? null;

                if ($checksum !== null
                    && $active->contains(static fn (ProjectMedia $m): bool => $m->checksum === $checksum)) {
                    /*
                     * A dedicated exception. A generic RuntimeException here
                     * was caught by the compensation handler and reported as
                     * "upload failed after storage" — so an editor uploading
                     * the same render twice was told something had broken,
                     * and the log said the same.
                     */
                    throw new DuplicateMediaException;
                }

                $media = ProjectMedia::query()->create($attributes + [
                    'sort_order' => (int) $active->max('sort_order') + 1,
                    'project_id' => $projectId,
                    'disk' => 'public',
                    'path' => $path,
                    'mime_type' => $result['mime'] ?? null,
                    'size_bytes' => $result['size'] ?? null,
                    'width' => $result['width'] ?? null,
                    'height' => $result['height'] ?? null,
                    'checksum' => $result['checksum'] ?? null,
                    // Decided under the lock, never by an unlocked "is this
                    // the first?" read that races a concurrent upload.
                    'is_cover' => false,
                ]);

                /*
                 * AUDIT INSIDE the transaction.
                 *
                 * Written afterwards, a failing audit call reported a failed
                 * upload for media that had already committed — the person saw
                 * an error and the row existed anyway, which is the worst of
                 * both outcomes. In here it either commits with the media or
                 * rolls back with it.
                 */
                $this->audit->record('project_media.uploaded', $media, [], [
                    'project_id' => $projectId,
                    'bytes' => $result['size'] ?? null,
                ]);

                $this->reconcileWithin($projectId);

                return $media;
            });
        } catch (DuplicateMediaException $e) {
            /*
             * A duplicate is an ORDINARY outcome, not a fault. The redundant
             * bytes are still removed — they duplicate a file already stored —
             * but nothing is logged as an error and the caller can say
             * "you have already uploaded this".
             */
            OrphanedFile::removeOrRecord('public', $path, 'duplicate_upload_cleanup_failed', [
                'project_id' => $projectId,
            ]);

            throw $e;
        } catch (Throwable $e) {
            /*
             * COMPENSATE. The bytes exist and the row does not, so remove them
             * — and if that fails, record the file durably. There is no media
             * row to flag, which is precisely why the outbox exists.
             */
            /*
             * An EMPTY path means the uploader never reported one — nothing was
             * written, so there is nothing to remove and nothing to record.
             * Skipping silently was right; saying so is better, because the
             * next reader will otherwise wonder whether a case is missing.
             */
            if ($path !== '') {
                OrphanedFile::removeOrRecord('public', $path, 'upload_compensation_failed', [
                    'project_id' => $projectId,
                    'last_error' => SafeText::truncate($e->getMessage(), 255),
                ]);
            }

            Log::warning('Project media upload failed after storage', [
                'project_id' => $projectId,
                'error' => $e->getMessage(),
            ]);

            return null;
        }
    }

    /**
     * Make one media row the cover, atomically.
     *
     * Clearing then setting inside one transaction means a failure leaves
     * zero covers rather than two — and zero is the recoverable state, because
     * {@see reconcileCover} restores it.
     */
    public function setCover(int $projectId, int $mediaId): bool
    {
        return DB::transaction(function () use ($projectId, $mediaId): bool {
            // Project row first — same order everywhere, so two transactions
            // cannot take the same locks in opposite orders and deadlock.
            Project::query()->lockForUpdate()->findOrFail($projectId);

            $rows = $this->lockProjectMedia($projectId);

            $target = $rows->firstWhere('id', $mediaId);

            if ($target === null || (bool) $target->cleanup_pending) {
                // A row awaiting cleanup is on its way out; making it the
                // cover would leave the project coverless when it goes.
                return false;
            }

            ProjectMedia::query()->where('project_id', $projectId)->update(['is_cover' => false]);
            ProjectMedia::query()->whereKey($mediaId)->update(['is_cover' => true]);

            // Re-read and confirm. Clearing then setting is two statements;
            // if the second fails the project has zero covers, which this
            // restores before the transaction commits.
            $this->reconcileWithin($projectId);

            return true;
        });
    }

    /**
     * Refuse to unset the only cover.
     *
     * `is_cover = false` on the last cover is not a valid request: it asks for
     * a state the invariant forbids. Callers wanting a different cover should
     * call setCover, which is one operation rather than two that can be
     * interrupted between.
     */
    public function unsetCover(int $projectId, int $mediaId): bool
    {
        return DB::transaction(function () use ($projectId, $mediaId): bool {
            // Project row first — same order everywhere, so two transactions
            // cannot take the same locks in opposite orders and deadlock.
            Project::query()->lockForUpdate()->findOrFail($projectId);

            $rows = $this->lockProjectMedia($projectId);

            $active = $rows->reject(static fn (ProjectMedia $m): bool => (bool) $m->cleanup_pending);

            if ($active->count() <= 1) {
                return false;   // the only image must remain the cover
            }

            ProjectMedia::query()->whereKey($mediaId)->update(['is_cover' => false]);

            // Something must be the cover.
            $this->reconcileWithin($projectId);

            return true;
        });
    }

    /**
     * Reorder, keeping the cover intact.
     *
     * @param  list<int>  $orderedIds
     */
    /**
     * Update one media row's fields and, optionally, its cover state.
     *
     * ONE TRANSACTION. The controller saved the fields first and then asked
     * for the cover change — so a refused cover request returned an error
     * AFTER the alt text and credit had already been written. The person saw a
     * failure and half their edit had landed, which is the least explicable
     * outcome available.
     *
     * @param  array<string, mixed>  $fields
     * @return array{ok: bool, reason: string|null}
     */
    public function updateFields(int $projectId, int $mediaId, array $fields, ?bool $wantsCover = null): array
    {
        try {
            DB::transaction(function () use ($projectId, $mediaId, $fields, $wantsCover): void {
                Project::query()->lockForUpdate()->find($projectId);

                $rows = $this->lockProjectMedia($projectId);
                $target = $rows->firstWhere('id', $mediaId);

                if ($target === null) {
                    throw new RuntimeException(__('media.errors.not_found'));
                }

                // A row awaiting deletion accepts no ordinary edits: the text
                // would vanish with it and the person would never know.
                if ((bool) $target->cleanup_pending) {
                    throw new RuntimeException(__('media.errors.pending_cleanup'));
                }

                $target->forceFill(array_intersect_key($fields, array_flip([
                    'alt_ckb', 'alt_ar', 'alt_en', 'credit',
                ])))->save();

                if ($wantsCover === true) {
                    ProjectMedia::query()->where('project_id', $projectId)->update(['is_cover' => false]);
                    ProjectMedia::query()->whereKey($mediaId)->update(['is_cover' => true]);
                }

                if ($wantsCover === false && (bool) $target->is_cover) {
                    $active = $rows->reject(
                        static fn (ProjectMedia $m): bool => (bool) $m->cleanup_pending,
                    );

                    // Removing the only cover leaves a gallery with no card
                    // image; the whole edit is refused rather than half-applied.
                    if ($active->count() <= 1) {
                        throw new RuntimeException(__('projects.errors.cover_required'));
                    }

                    ProjectMedia::query()->whereKey($mediaId)->update(['is_cover' => false]);
                }

                // Audit commits with the change, not after it.
                $this->audit->record('project_media.updated', $target->refresh());

                $this->reconcileWithin($projectId);
            });

            return ['ok' => true, 'reason' => null];
        } catch (Throwable $e) {
            return ['ok' => false, 'reason' => $e->getMessage()];
        }
    }

    /**
     * @param  list<int>  $orderedIds  media ids in their new display order
     */
    public function reorder(int $projectId, array $orderedIds): void
    {
        DB::transaction(function () use ($projectId, $orderedIds): void {
            // Owner first, like every other mutation here.
            Project::query()->lockForUpdate()->find($projectId);

            /*
             * Cleanup-pending rows are excluded from the orderable set. Giving
             * one a position places a disappearing image among the survivors,
             * and the gap it leaves shifts everything after it.
             */
            $owned = $this->lockProjectMedia($projectId)
                ->reject(static fn (ProjectMedia $m): bool => (bool) $m->cleanup_pending)
                ->pluck('id')
                ->all();

            foreach ($orderedIds as $position => $id) {
                // Ids from another project silently affect nothing rather than
                // being rejected after the fact.
                if (in_array((int) $id, $owned, true)) {
                    ProjectMedia::query()->whereKey($id)->update(['sort_order' => $position]);
                }
            }

            $this->reconcileWithin($projectId);
        });
    }

    /**
     * Delete a media row, bytes first.
     *
     * Returns false when the file could not be removed: the row survives,
     * flagged, and the scheduled retry picks it up. Deleting it anyway would
     * destroy the last reference to bytes still on disk.
     */
    /**
     * Delete a media row, with the bytes removed AFTER the commit.
     *
     * Removing the file inside the transaction was the wrong order: if the
     * commit then failed, the row came back and the file did not — a gallery
     * entry pointing at nothing, permanently, with no record it had happened.
     *
     * So the database change commits first, marking the row `cleanup_pending`.
     * A failure at that point leaves an orphaned FILE, which is recoverable
     * because the row still names it and the retry command finds it. An
     * orphaned file is a cost; a broken reference is a defect.
     */
    public function delete(int $projectId, int $mediaId): bool
    {
        $item = DB::transaction(function () use ($projectId, $mediaId): ?ProjectMedia {
            Project::query()->lockForUpdate()->findOrFail($projectId);

            $rows = $this->lockProjectMedia($projectId);
            $found = $rows->firstWhere('id', $mediaId);

            if ($found === null) {
                return null;
            }

            // Staged, not deleted. If the commit fails this rolls back with
            // it and the row is untouched.
            $found->forceFill(['cleanup_pending' => true])->save();

            $this->reconcileWithin($projectId);

            return $found;
        });

        if ($item === null) {
            return false;
        }

        return $this->finaliseDeletion($projectId, $item);
    }

    /**
     * Remove the bytes for an already-staged row, then drop the row.
     *
     * Shared by the immediate path and the retry command so both behave
     * identically — they previously differed, and the difference was invisible
     * until a delete failed.
     */
    public function finaliseDeletion(int $projectId, ProjectMedia $item): bool
    {
        if (! $this->removeBytes($item)) {
            /*
             * ATTEMPTS COUNTED UNDER THE LOCK, from a freshly read row.
             *
             * Incrementing from the in-memory model let two concurrent runs
             * both read the same count and both write count+1 — one attempt
             * lost every time, so the ceiling arrived late or never, and the
             * handoff with it.
             */
            $attempts = (int) DB::transaction(function () use ($item): int {
                $ownerId = (int) $item->project_id;

                Project::query()->lockForUpdate()->find($ownerId);

                $fresh = $this->lockProjectMedia($ownerId)->firstWhere('id', $item->id);

                if ($fresh === null) {
                    return 0;   // deleted while we waited
                }

                $next = (int) $fresh->cleanup_attempts + 1;

                $fresh->forceFill([
                    'cleanup_pending' => true,
                    'cleanup_attempts' => $next,
                    'cleanup_last_error' => 'remove() returned false',
                ])->save();

                return $next;
            });

            /*
             * Handoff runs AFTER that transaction commits. It writes to a
             * different table, and holding the media locks across it would
             * widen the window every ordinary edit contends with.
             */
            if ($attempts >= self::CLEANUP_ATTEMPT_CEILING) {
                $this->handOffToOutbox($item->refresh());
            }

            return false;
        }

        DB::transaction(function () use ($projectId, $item): void {
            // Owner first: the same order the sweep and every mutation uses.
            Project::query()->lockForUpdate()->find($projectId);
            $this->lockProjectMedia($projectId);
            $item->delete();
            $this->reconcileWithin($projectId);
        });

        return true;
    }

    /**
     * Promote a draft's uploads into a project, atomically.
     *
     * Runs inside the submission transaction, so a failure here rolls the
     * project back with it rather than leaving half a gallery.
     */
    public function promoteDraftMedia(int $projectId, int $draftId): void
    {
        /*
         * LOCK ORDER, applied identically everywhere:
         *   1. project_drafts row
         *   2. project_draft_media rows
         *   3. project_media rows
         *
         * Taking them in one order in every path is what stops two
         * transactions deadlocking against each other.
         */
        $draft = ProjectDraft::query()->lockForUpdate()->find($draftId);

        if ($draft === null) {
            return;
        }

        /*
         * CLEANUP-PENDING ROWS ARE NOT PROMOTED.
         *
         * A row marked pending is a deleted item whose bytes are already gone
         * or going. Promoting it resurrected a photograph the person had
         * removed — and, when the file was genuinely absent, failed the whole
         * submission over an image nobody wanted.
         */
        $items = ProjectDraftMedia::query()
            ->where('project_draft_id', $draftId)
            ->where('cleanup_pending', false)
            ->orderBy('sort_order')
            ->lockForUpdate()
            ->get();

        if ($items->isEmpty()) {
            return;
        }

        /*
         * COMPENSATION LIST.
         *
         * Public files are written inside the submission transaction, so a
         * later failure or a rolled-back commit would leave them on disk with
         * no database row naming them — untracked bytes nothing can ever find.
         *
         * Every copied path is recorded and removed if the transaction does
         * not commit. Laravel fires the rollback callback for us, so this
         * works for a failure anywhere later in the submission, not only here.
         */
        /** @var list<string> $copied paths written during this transaction */
        $copied = [];

        DB::afterRollback(function () use (&$copied, $projectId): void {
            foreach ($copied as $path) {
                /*
                 * Handles BOTH shapes: delete() returning false, and delete()
                 * throwing. Recording only on an exception left every
                 * permissions failure untracked.
                 */
                // Non-throwing: this runs during rollback handling, where an
                // exception replaces the original error and loses both.
                OrphanedFile::recordSafely('public', $path, 'promotion_rollback', [
                    'project_id' => $projectId,
                ]);
            }
        });

        foreach ($items as $position => $item) {
            /*
             * COPY, don't move. The private original stays until the database
             * row exists and the transaction commits — a move would destroy it
             * the moment anything after this failed, and there would be no
             * second chance.
             */
            /*
             * Deterministic and unique per source file. Using only the
             * basename meant a resubmission — or two drafts holding
             * identically-named uploads — overwrote an unrelated project's
             * image. The checksum makes the target a function of the CONTENT,
             * so a retry writes the same path harmlessly and a different file
             * never collides.
             */
            $target = 'projects/'.$projectId.'/'
                .substr((string) ($item->checksum ?: (string) $item->id), 0, 16)
                .'-'.basename((string) $item->path);

            /*
             * Registered BEFORE the write, not after.
             *
             * writeStream can create a partial file and then return false or
             * throw — recording the path only on success left exactly those
             * bytes untracked. Registering first means the compensation list
             * is a superset of what exists, and deleting a path that was never
             * created is harmless.
             */
            $copied[] = $target;

            if (! $this->copyToPublic($item, $target)) {
                // A partial gallery is worse than none: throwing rolls the
                // whole submission back and leaves every private original
                // intact for a retry.
                throw new RuntimeException('Draft media could not be promoted.');
            }

            ProjectMedia::query()->create([
                'project_id' => $projectId,
                'kind' => $item->kind,
                'disk' => 'public',
                'path' => $target,
                'original_name' => $item->original_name,
                'mime_type' => $item->mime_type,
                'size_bytes' => $item->size_bytes,
                'width' => $item->width,
                'height' => $item->height,
                'checksum' => $item->checksum,
                'alt_ckb' => $item->alt_ckb,
                'alt_ar' => $item->alt_ar,
                'alt_en' => $item->alt_en,
                'sort_order' => $position,
                'is_cover' => false,   // reconciled below, in one place
                'uploaded_by' => $item->uploaded_by,
            ]);
        }

        /*
         * The draft rows are cleared, but the PRIVATE ORIGINALS are left on
         * disk deliberately. They are removed after the transaction commits,
         * by the caller or the retry sweep — deleting them here would destroy
         * the source if the commit then failed.
         */
        // Only the rows actually promoted. A row already pending keeps its own
        // recorded error rather than being relabelled.
        ProjectDraftMedia::query()
            ->whereIn('id', $items->pluck('id'))
            ->update([
                'cleanup_pending' => true,
                'cleanup_last_error' => 'promoted; original awaiting removal',
            ]);

        $this->reconcileWithin($projectId);
    }

    /**
     * Transfer an exhausted row's file to the orphan outbox, once.
     *
     * ONE LOCKED TRANSITION. The previous version read a stale model, decided
     * outside any lock, and wrote the link in a bare save — so two runs could
     * both decide a handoff was owed and create competing state, and the row
     * it inspected might already have been finalised.
     *
     * Owner, then media set, then the exact row reloaded from inside that
     * locked set. The outbox row is created BEFORE the link is written, so a
     * crash between them leaves the handoff owed rather than falsely claimed —
     * `cleanup_outbox_id` null on an exhausted row is the retry signal.
     */
    public function handOffToOutbox(mixed $item): bool
    {
        try {
            return (bool) DB::transaction(function () use ($item): bool {
                $ownerId = (int) $item->project_id;

                Project::query()->lockForUpdate()->find($ownerId);

                $fresh = $this->lockProjectMedia($ownerId)->firstWhere('id', $item->id);

                if ($fresh === null) {
                    return true;   // finalised while we waited; nothing owed
                }

                if ($fresh->cleanup_outbox_id !== null) {
                    return true;   // already transferred
                }

                // Only a pending, exhausted row is handed over. A row still
                // being retried belongs to the retry path, not the outbox.
                if (! (bool) $fresh->cleanup_pending
                    || (int) $fresh->cleanup_attempts < self::CLEANUP_ATTEMPT_CEILING) {
                    return false;
                }

                $outbox = OrphanedFile::record(
                    (string) ($fresh->disk ?: 'public'),
                    (string) $fresh->path,
                    'project_media_cleanup_exhausted',
                    ['source_type' => 'project_media', 'source_id' => $fresh->id],
                );

                $fresh->forceFill([
                    'cleanup_outbox_id' => $outbox->id,
                    'cleanup_handed_off_at' => now(),
                ])->save();

                return true;
            });
        } catch (Throwable $e) {
            Log::warning('Media handoff to the orphan outbox failed', [
                'source_type' => 'project_media',
                'source_id' => $item->id,
                'error' => $e->getMessage(),
            ]);

            return false;
        }
    }

    /**
     * Restore the invariant: exactly one cover, or none if there is no media.
     *
     * Called after every mutation rather than trusted to each caller — that
     * trust is what let the immediate and deferred delete paths disagree.
     */
    /**
     * Finalise a project-media row whose file the outbox confirmed absent.
     *
     * Same verification as the draft equivalent: the row must still be the one
     * the outbox entry names, or a re-upload to the same path would be deleted
     * in its place.
     *
     * @return array{ok: bool, reason: string|null}
     */
    /**
     * Remove a source row whose file the sweep has confirmed absent.
     *
     * `$outboxId` is checked against the row's own `cleanup_outbox_id`.
     * Without it, any job naming a matching disk and path could finalise a row
     * it never owned — and paths are reused.
     *
     * @return array{ok: bool, reason: string|null}
     */
    public function finaliseAbsentSource(int $mediaId, string $disk, string $path, int $outboxId): array
    {
        try {
            DB::transaction(function () use ($mediaId, $disk, $path, $outboxId): void {
                // Owner id read unlocked purely to know which owner to lock.
                $ownerId = (int) (ProjectMedia::query()
                    ->whereKey($mediaId)->value('project_id') ?? 0);

                if ($ownerId === 0) {
                    return;   // already gone
                }

                Project::query()->lockForUpdate()->find($ownerId);

                $rows = $this->lockProjectMedia($ownerId);
                $row = $rows->firstWhere('id', $mediaId);

                if ($row === null) {
                    return;   // removed while we waited for the lock
                }

                if (! (bool) $row->cleanup_pending) {
                    throw new RuntimeException('The source row is no longer pending cleanup.');
                }

                /*
                 * THE EXACT LINK. A stale or unrelated job must never finalise
                 * this row: disk and path alone are not identity, because a
                 * later upload can reuse both.
                 */
                /*
                 * MANDATORY. The parameter was optional and the check skipped
                 * when null — so any caller omitting it could delete a row on
                 * disk-and-path alone, and paths are reused. A diagnostic call
                 * must never be able to destroy a row by accident.
                 */
                if ((int) $row->cleanup_outbox_id !== $outboxId) {
                    throw new RuntimeException('The source row is linked to a different cleanup job.');
                }

                // The job must also name THIS row, in THIS domain: a numeric
                // id is unique only within its own table.
                $job = OrphanedFile::query()->find($outboxId);

                if ($job === null
                    || $job->source_type !== 'project_media'
                    || (int) $job->source_id !== $mediaId
                    || (string) $job->disk !== $disk
                    || (string) $job->path !== $path) {
                    throw new RuntimeException('The cleanup job does not describe this source row.');
                }

                if ((string) ($row->disk ?: 'public') !== $disk || (string) $row->path !== $path) {
                    throw new RuntimeException('The source row no longer matches the recorded file.');
                }

                $row->delete();
                $this->reconcileWithin($ownerId);
            });

            return ['ok' => true, 'reason' => null];
        } catch (Throwable $e) {
            return ['ok' => false, 'reason' => $e->getMessage()];
        }
    }

    public function reconcileCover(int $projectId): void
    {
        DB::transaction(function () use ($projectId): void {
            Project::query()->lockForUpdate()->find($projectId);

            $this->reconcileWithin($projectId);
        });
    }

    /**
     * Restore the invariant from FRESHLY READ state.
     *
     * This used to accept a collection the caller had already loaded — and
     * every caller loaded it BEFORE its own update. So `unsetCover` cleared
     * the flag in the database and then handed this method models that still
     * had `is_cover = true` in memory: it counted one cover, returned
     * satisfied, and left the database with zero. The project silently lost
     * its card image and nothing reported a problem.
     *
     * Reloading inside the same transaction is cheap — the rows are already
     * locked — and removes the entire class of stale-read bug.
     */
    private function reconcileWithin(int $projectId): void
    {
        $active = $this->lockProjectMedia($projectId)
            ->reject(static fn (ProjectMedia $m): bool => (bool) $m->cleanup_pending)
            ->sortBy('sort_order')
            ->values();

        if ($active->isEmpty()) {
            return;   // nothing to be the cover; not an error
        }

        $covers = $active->filter(static fn (ProjectMedia $m): bool => (bool) $m->is_cover);

        if ($covers->count() === 1) {
            return;
        }

        // Zero or several: the first by sort order wins, deterministically.
        ProjectMedia::query()->where('project_id', $projectId)->update(['is_cover' => false]);
        ProjectMedia::query()->whereKey($active->first()->id)->update(['is_cover' => true]);

        Log::info('Project cover reconciled', [
            'project_id' => $projectId,
            'previous_cover_count' => $covers->count(),
        ]);
    }

    /**
     * Lock this project's media rows for the rest of the transaction.
     *
     * `lockForUpdate` is the whole point: without it two concurrent requests
     * both read "there is a cover", both clear it, and both set their own.
     *
     * @return Collection<int, ProjectMedia>
     */
    private function lockProjectMedia(int $projectId): Collection
    {
        return ProjectMedia::query()
            ->where('project_id', $projectId)
            ->lockForUpdate()
            ->get();
    }

    /**
     * Copy a private draft file onto the public project disk.
     *
     * Streamed rather than read into memory: a phone photograph on a shared
     * host is easily large enough to matter.
     */
    private function copyToPublic(ProjectDraftMedia $item, string $target): bool
    {
        try {
            $source = Storage::disk((string) ($item->disk ?: 'draft-media'));

            if (! $source->exists((string) $item->path)) {
                return false;
            }

            $stream = $source->readStream((string) $item->path);

            if ($stream === null) {
                return false;
            }

            $written = Storage::disk('public')->writeStream($target, $stream);

            if (is_resource($stream)) {
                fclose($stream);
            }

            return $written;
        } catch (Throwable $e) {
            Log::warning('Draft media promotion failed', [
                'draft_media_id' => $item->id,
                'error' => $e->getMessage(),
            ]);

            return false;
        }
    }

    private function removeBytes(ProjectMedia $item): bool
    {
        $path = (string) $item->path;

        if ($path === '') {
            return true;
        }

        $disk = (string) ($item->disk ?: 'public');

        /*
         * ALREADY MISSING counts as removed. An interrupted earlier run may
         * have taken the file while failing to clear the flag, and treating
         * that as an error would keep the row forever — the exact backlog this
         * mechanism exists to drain.
         */
        try {
            if (! Storage::disk($disk)->exists($path)) {
                return true;
            }
        } catch (Throwable) {
            // An unreadable disk is a real failure; fall through and report it.
            return false;
        }

        return $this->uploader->remove($disk, $path);
    }
}
