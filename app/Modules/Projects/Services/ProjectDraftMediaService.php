<?php

declare(strict_types=1);

namespace App\Modules\Projects\Services;

use App\Modules\Core\Support\MediaUploader;
use App\Modules\Projects\Exceptions\DuplicateMediaException;
use App\Modules\Projects\Models\OrphanedFile;
use App\Modules\Projects\Models\ProjectDraft;
use App\Modules\Projects\Models\ProjectDraftMedia;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use RuntimeException;
use Throwable;

/**
 * The single writer for DRAFT media state (spec 12.1).
 *
 * Draft media had the same problems the project gallery had, and none of the
 * fixes: ordering, cover selection and deletion were raw updates spread across
 * the wizard controller, with no lock and no invariant. Two concurrent uploads
 * could both decide they were first and both become the cover; deleting the
 * cover left none; and the bytes were removed inside a transaction that could
 * roll back around them.
 *
 * THE INVARIANT: a draft with at least one non-cleanup-pending upload has
 * EXACTLY ONE cover. It matters before submission because the cover is
 * promoted as-is — a draft with two covers produces a project whose card image
 * depends on row order.
 *
 * Ownership is enforced by every query: `ownedBy(draft, user)` rather than a
 * check applied after loading. An id belonging to another draft matches
 * nothing rather than being rejected late.
 */
final class ProjectDraftMediaService
{
    /**
     * Retries before a file is handed to the orphan outbox.
     *
     * Matched to PruneDraftMedia's default so the two cannot disagree about
     * when a row stops being retried — a mismatch would leave rows that
     * neither mechanism owns.
     */
    public const CLEANUP_ATTEMPT_CEILING = 5;

    public function __construct(private readonly MediaUploader $uploader) {}

    /**
     * Attach an uploaded file to a draft.
     *
     * The cover is decided by {@see reconcileWithin} under a lock, not by an
     * unlocked "is this the first?" read — that read races a concurrent upload
     * and produces two covers.
     *
     * @param  array<model-property<ProjectDraftMedia>, mixed>  $attributes
     */
    public function attach(int $draftId, array $attributes): ProjectDraftMedia
    {
        return DB::transaction(function () use ($draftId, $attributes): ProjectDraftMedia {
            $this->lockEditableDraft($draftId);
            $this->lockDraftMedia($draftId);

            /*
             * DUPLICATE DETECTION UNDER THE LOCK. The controller checked the
             * checksum before attach() took the draft lock, so two identical
             * simultaneous uploads both passed and both inserted.
             */
            $checksum = $attributes['checksum'] ?? null;

            if ($checksum !== null) {
                $clash = $this->lockDraftMedia($draftId)
                    ->reject(static fn (ProjectDraftMedia $m): bool => (bool) $m->cleanup_pending)
                    ->contains(static fn (ProjectDraftMedia $m): bool => $m->checksum === $checksum);

                if ($clash) {
                    // Typed, so the controller can compensate the redundant
                    // stored file rather than reporting a generic failure.
                    throw new DuplicateMediaException;
                }
            }

            $item = ProjectDraftMedia::query()->create($attributes + [
                'project_draft_id' => $draftId,
                'is_cover' => false,
                'sort_order' => (int) ProjectDraftMedia::query()
                    ->where('project_draft_id', $draftId)
                    ->max('sort_order') + 1,
            ]);

            $this->reconcileWithin($draftId);

            return $item->refresh();
        });
    }

    /** Make one upload the cover. Returns false for an id this draft does not own. */
    public function setCover(int $draftId, int $userId, int $mediaId): bool
    {
        return DB::transaction(function () use ($draftId, $userId, $mediaId): bool {
            $this->lockEditableDraft($draftId);

            /*
             * THE LOCKED ROWS ARE THE ONES WE THEN SEARCH.
             *
             * The return value was discarded and `$rows` read undefined, so
             * `$target` was always null and setting a draft cover ALWAYS
             * returned false — silently, because an undefined variable is a
             * warning rather than an error once display_errors is off. The
             * feature simply did not work in production, and the test that
             * covers it asserted `false` for a refusal it was getting for
             * entirely the wrong reason.
             */
            $rows = $this->lockDraftMedia($draftId);
            $target = $rows->firstWhere('id', $mediaId);

            // A row awaiting cleanup is on its way out; making it the cover
            // would leave the draft coverless when it goes.
            if ($target === null
                || (int) $target->uploaded_by !== $userId
                || (bool) $target->cleanup_pending) {
                return false;
            }

            ProjectDraftMedia::query()->where('project_draft_id', $draftId)->update(['is_cover' => false]);
            ProjectDraftMedia::query()->whereKey($mediaId)->update(['is_cover' => true]);

            // Clearing then setting is two statements; if the second fails the
            // draft has zero covers, which this restores before commit.
            $this->reconcileWithin($draftId);

            return true;
        });
    }

    /**
     * Reorder, keeping the cover intact.
     *
     * @param  list<int>  $orderedIds
     */
    public function reorder(int $draftId, int $userId, array $orderedIds): void
    {
        DB::transaction(function () use ($draftId, $userId, $orderedIds): void {
            $this->lockEditableDraft($draftId);

            /*
             * Pending rows are excluded from the owned set: giving one a sort
             * position places a disappearing item among the survivors, and the
             * gap it leaves shifts everything after it.
             */
            $owned = $this->lockDraftMedia($draftId)
                ->filter(static fn (ProjectDraftMedia $m): bool => (int) $m->uploaded_by === $userId
                    && ! (bool) $m->cleanup_pending)
                ->pluck('id')
                ->all();

            foreach ($orderedIds as $position => $id) {
                if (in_array((int) $id, $owned, true)) {
                    ProjectDraftMedia::query()->whereKey($id)->update(['sort_order' => $position]);
                }
            }

            $this->reconcileWithin($draftId);
        });
    }

    /**
     * Update trilingual alt text.
     *
     * @param  array<string, string|null>  $alt
     */
    public function updateAlt(int $draftId, int $userId, int $mediaId, array $alt): bool
    {
        return DB::transaction(function () use ($draftId, $userId, $mediaId, $alt): bool {
            $this->lockEditableDraft($draftId);

            /*
             * The media set is locked too, and a cleanup-pending row is
             * excluded. Locking only the draft let alt text be written to a
             * row that was already on its way out — the text vanished with it,
             * and the person had no idea their edit had gone nowhere.
             */
            $target = $this->lockDraftMedia($draftId)
                ->first(static fn (ProjectDraftMedia $item): bool => (int) $item->id === $mediaId
                    && (int) $item->uploaded_by === $userId
                    && ! (bool) $item->cleanup_pending);

            if ($target === null) {
                return false;
            }

            $target->forceFill([
                'alt_ckb' => $alt['ckb'] ?? null,
                'alt_ar' => $alt['ar'] ?? null,
                'alt_en' => $alt['en'] ?? null,
            ])->save();

            return true;
        });
    }

    /**
     * Delete a draft upload, bytes removed AFTER the commit.
     *
     * Same order as the project gallery, for the same reason: a row restored
     * by a rolled-back transaction must never point at bytes that are already
     * gone.
     */
    public function delete(int $draftId, int $userId, int $mediaId): bool
    {
        $item = DB::transaction(function () use ($draftId, $userId, $mediaId): ?ProjectDraftMedia {
            $this->lockEditableDraft($draftId);
            $this->lockDraftMedia($draftId);

            $found = ProjectDraftMedia::query()
                ->ownedBy($draftId, $userId)
                ->whereKey($mediaId)
                ->first();

            if ($found === null) {
                return null;
            }

            $found->forceFill(['cleanup_pending' => true])->save();

            $this->reconcileWithin($draftId);

            return $found;
        });

        if ($item === null) {
            return false;
        }

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
                $ownerId = (int) $item->project_draft_id;

                ProjectDraft::query()->lockForUpdate()->find($ownerId);

                $fresh = $this->lockDraftMedia($ownerId)->firstWhere('id', $item->id);

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

        DB::transaction(function () use ($draftId, $item): void {
            $this->lockEditableDraft($draftId);
            $this->lockDraftMedia($draftId);
            $item->delete();
            $this->reconcileWithin($draftId);
        });

        return true;
    }

    /**
     * Remove every upload for a draft, for purge and abandoned-draft cleanup.
     *
     * Returns the ids whose bytes could NOT be removed. A non-empty result
     * means the caller must not delete the draft: doing so cascades these rows
     * away and loses the only record of files still on disk.
     *
     * @return list<int>
     */
    /**
     * Stage and remove every upload for a draft.
     *
     * ATOMIC STAGING, then retryable removal. Enumerating rows without a lock
     * let a concurrent upload land AFTER the list was taken — its row then
     * vanished with the draft's cascade while its file stayed on disk, with
     * nothing left naming it.
     *
     * The draft is locked first (so no new media can be attached), the whole
     * set is marked pending and COMMITTED, and only then are bytes removed.
     * A failure after that point leaves a flagged row the sweep retries.
     *
     * @return list<int> ids whose bytes could not be removed
     */
    public function purgeDraft(int $draftId): array
    {
        /*
         * PHASE ONE — declare the intent, under lock, and commit it.
         *
         * Marking the draft `purging` is what closes the window: every
         * mutation checks that flag, so nothing new can attach once this
         * transaction commits.
         */
        $ids = DB::transaction(function () use ($draftId): array {
            $draft = ProjectDraft::query()->lockForUpdate()->find($draftId);

            if ($draft === null) {
                return [];
            }

            /*
             * A SUBMITTED draft is refused.
             *
             * Its media has already been promoted into project_media, and the
             * remaining draft rows point at the SAME files. Purging would
             * delete the bytes out from under a live project gallery. The
             * controllers check this too, but a service that can be called
             * from a command must not depend on its callers for a rule this
             * destructive.
             */
            if ($draft->submitted_at !== null || $draft->project_id !== null) {
                throw new RuntimeException('A submitted draft cannot be purged.');
            }

            $draft->forceFill([
                'purge_status' => 'purging',
                'purging_at' => now(),
            ])->save();

            $rows = $this->lockDraftMedia($draftId);

            ProjectDraftMedia::query()
                ->where('project_draft_id', $draftId)
                ->update(['cleanup_pending' => true]);

            return $rows->pluck('id')->map(static fn ($id): int => (int) $id)->all();
        });

        if ($ids === []) {
            return [];
        }

        /*
         * PHASE TWO — after commit, remove bytes one row at a time. Anything
         * that fails keeps its row, so the state stays recoverable and a retry
         * is idempotent.
         */
        $stuck = [];

        foreach (ProjectDraftMedia::query()->whereIn('id', $ids)->get() as $item) {
            if (! $this->finaliseDeletion($item)) {
                $stuck[] = (int) $item->id;
            }
        }

        if ($stuck !== []) {
            Log::warning('Draft media purge incomplete', ['draft_id' => $draftId, 'stuck' => $stuck]);
        }

        return $stuck;
    }

    /**
     * Remove one staged row's bytes, then the row.
     *
     * THE authoritative finaliser: storage deletion, missing-file handling,
     * attempt counting, error recording, row deletion and cover reconciliation
     * all live here. The commands and controllers select work and call this,
     * rather than each keeping its own copy of the rules.
     */
    public function finaliseDeletion(ProjectDraftMedia $item): bool
    {
        $draftId = (int) $item->project_draft_id;

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
                $draftId = (int) $item->project_draft_id;

                ProjectDraft::query()->lockForUpdate()->find($draftId);

                $fresh = $this->lockDraftMedia($draftId)->firstWhere('id', $item->id);

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
             * Handoff commits separately: it writes another table, and holding
             * the media locks across it would widen the window every ordinary
             * edit contends with. It re-checks exhaustion under its own lock,
             * so a repeat is harmless.
             */
            if ($attempts >= self::CLEANUP_ATTEMPT_CEILING) {
                $this->handOffToOutbox($item->refresh());
            }

            return false;
        }

        DB::transaction(function () use ($draftId, $item): void {
            // Owner first, matching every other path.
            ProjectDraft::query()->lockForUpdate()->find($draftId);
            $this->lockDraftMedia($draftId);
            $item->delete();
            $this->reconcileWithin($draftId);
        });

        return true;
    }

    /**
     * Finish a purge: verify no media remains, then delete the draft.
     *
     * Re-locked and re-checked. The draft is only removed once it is still
     * marked `purging` AND holds no media rows — so an interrupted purge is
     * resumable, and a draft is never deleted around files that survive.
     */
    /**
     * Finish a purge from INSIDE an already-open, already-locked transaction.
     *
     * `completePurge()` opens its own transaction and takes the locks again.
     * Calling it from within `finaliseAbsentSource` would nest — harmless on
     * some drivers, a re-entrant lock wait on others — so the actual decision
     * lives here and both entry points share it.
     */
    public function completePurgeLocked(int $draftId): bool
    {
        $draft = ProjectDraft::query()->lockForUpdate()->find($draftId);

        if ($draft === null) {
            return true;   // already gone; nothing to finish
        }

        if ($draft->purge_status !== 'purging') {
            return false;  // never staged, or already resumed
        }

        $remaining = ProjectDraftMedia::query()
            ->where('project_draft_id', $draftId)
            ->lockForUpdate()
            ->count();

        if ($remaining > 0) {
            // Files survive; deleting now would cascade those rows away and
            // orphan the bytes.
            return false;
        }

        $draft->delete();

        return true;
    }

    public function completePurge(int $draftId): bool
    {
        // The decision lives in completePurgeLocked(); this is the entry
        // point that opens the transaction for callers outside one.
        return DB::transaction(fn (): bool => $this->completePurgeLocked($draftId));
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
                $ownerId = (int) $item->project_draft_id;

                ProjectDraft::query()->lockForUpdate()->find($ownerId);

                $fresh = $this->lockDraftMedia($ownerId)->firstWhere('id', $item->id);

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
                    (string) ($fresh->disk ?: 'draft-media'),
                    (string) $fresh->path,
                    'project_draft_media_cleanup_exhausted',
                    ['source_type' => 'project_draft_media', 'source_id' => $fresh->id],
                );

                $fresh->forceFill([
                    'cleanup_outbox_id' => $outbox->id,
                    'cleanup_handed_off_at' => now(),
                ])->save();

                return true;
            });
        } catch (Throwable $e) {
            Log::warning('Media handoff to the orphan outbox failed', [
                'source_type' => 'project_draft_media',
                'source_id' => $item->id,
                'error' => $e->getMessage(),
            ]);

            return false;
        }
    }

    /**
     * Restore the invariant from freshly read state.
     *
     * Always re-reads. Handing this a collection loaded before the update is
     * how a cleared flag was counted as still set.
     */
    /**
     * Finalise a draft-media row whose file the outbox has confirmed absent.
     *
     * NOT a public wrapper around reconcileWithin(). The sweep was calling a
     * method that did not exist — its own catch swallowed the error, so the
     * failure was invisible and `completePurge()` never ran. Adding a bare
     * wrapper would have hidden the same problem more quietly.
     *
     * This verifies that the row it is about to delete is genuinely the one
     * the outbox entry names — same id, same disk, same path, and still marked
     * `cleanup_pending`. Deleting on an id alone would remove a row that had
     * since been re-uploaded to the same path.
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
                $ownerId = (int) (ProjectDraftMedia::query()
                    ->whereKey($mediaId)->value('project_draft_id') ?? 0);

                if ($ownerId === 0) {
                    return;   // already gone
                }

                ProjectDraft::query()->lockForUpdate()->find($ownerId);

                $rows = $this->lockDraftMedia($ownerId);
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
                    || $job->source_type !== 'project_draft_media'
                    || (int) $job->source_id !== $mediaId
                    || (string) $job->disk !== $disk
                    || (string) $job->path !== $path) {
                    throw new RuntimeException('The cleanup job does not describe this source row.');
                }

                if ((string) ($row->disk ?: 'draft-media') !== $disk || (string) $row->path !== $path) {
                    throw new RuntimeException('The source row no longer matches the recorded file.');
                }

                $row->delete();
                $this->reconcileWithin($ownerId);

                /*
                 * A PURGING DRAFT MUST BE FINISHED HERE.
                 *
                 * Removing the last media row and stopping left the draft
                 * stuck in `purging` forever: nothing else revisits it, and
                 * every mutation is refused while that flag is set. The outbox
                 * would report its source finalised while the draft it
                 * belonged to was permanently unusable.
                 */
                $draft = ProjectDraft::query()->find($ownerId);

                if ($draft?->purge_status === 'purging') {
                    $remaining = ProjectDraftMedia::query()
                        ->where('project_draft_id', $ownerId)
                        ->lockForUpdate()
                        ->count();

                    if ($remaining === 0 && ! $this->completePurgeLocked($ownerId)) {
                        // Reported, not swallowed: the caller must not mark
                        // this job finalised while the draft is still stuck.
                        throw new RuntimeException('The draft purge could not be completed.');
                    }
                }
            });

            return ['ok' => true, 'reason' => null];
        } catch (Throwable $e) {
            return ['ok' => false, 'reason' => $e->getMessage()];
        }
    }

    private function reconcileWithin(int $draftId): void
    {
        $active = $this->lockDraftMedia($draftId)
            ->reject(static fn (ProjectDraftMedia $m): bool => (bool) $m->cleanup_pending)
            ->sortBy('sort_order')
            ->values();

        if ($active->isEmpty()) {
            return;
        }

        $covers = $active->filter(static fn (ProjectDraftMedia $m): bool => (bool) $m->is_cover);

        if ($covers->count() === 1) {
            return;
        }

        ProjectDraftMedia::query()->where('project_draft_id', $draftId)->update(['is_cover' => false]);
        ProjectDraftMedia::query()->whereKey($active->first()->id)->update(['is_cover' => true]);
    }

    /**
     * Lock the draft and prove it is still editable.
     *
     * ORDER: the draft row first, then its media. Every path uses this, so two
     * transactions cannot take the same two locks in opposite orders and
     * deadlock.
     *
     * Revalidating INSIDE the lock is the point. Without it an upload that
     * began before submission could commit after it — attaching media to a
     * draft that is now an audit record, and to a project that has already
     * been built from a different set.
     *
     * @throws RuntimeException when the draft can no longer be edited
     */
    private function lockEditableDraft(int $draftId): ProjectDraft
    {
        $draft = ProjectDraft::query()->lockForUpdate()->find($draftId);

        if ($draft === null) {
            throw new RuntimeException('The draft no longer exists.');
        }

        if ($draft->submitted_at !== null || $draft->project_id !== null) {
            throw new RuntimeException('The draft has been submitted and can no longer be edited.');
        }

        /*
         * A draft being purged accepts nothing.
         *
         * Without this, an upload could land between the purge staging
         * committing and the draft being deleted — the row then went with the
         * cascade and its file stayed on disk, referenced by nothing.
         */
        if ($draft->purge_status !== null) {
            throw new RuntimeException('The draft is being removed and can no longer be edited.');
        }

        return $draft;
    }

    /** @return Collection<int, ProjectDraftMedia> */
    private function lockDraftMedia(int $draftId): Collection
    {
        return ProjectDraftMedia::query()
            ->where('project_draft_id', $draftId)
            ->lockForUpdate()
            ->get();
    }

    private function removeBytes(ProjectDraftMedia $item): bool
    {
        $path = (string) $item->path;

        if ($path === '') {
            return true;
        }

        return $this->uploader->remove((string) ($item->disk ?: 'public'), $path);
    }
}
