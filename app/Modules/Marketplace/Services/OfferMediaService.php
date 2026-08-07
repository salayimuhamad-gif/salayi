<?php

declare(strict_types=1);

namespace App\Modules\Marketplace\Services;

use App\Modules\Core\Support\MediaUploader;
use App\Modules\Core\Support\SafeText;
use App\Modules\Marketplace\Models\Offer;
use App\Modules\Marketplace\Models\OfferMedia;
use App\Modules\Operations\Services\AuditLogger;
use App\Modules\Projects\Exceptions\DuplicateMediaException;
use App\Modules\Projects\Models\OrphanedFile;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use RuntimeException;
use Throwable;

/**
 * The single writer for offer media state (spec 19.1).
 *
 * The controller did all of this itself: storage write, duplicate check, sort
 * order, row creation, cover, moderation and audit — each unlocked, and the
 * bytes written before the row existed. Two concurrent uploads both saw "no
 * duplicate" and both took the same sort position, and any failure after the
 * write left a public file nothing referenced.
 *
 * MODERATION MATTERS HERE more than it does for project media. An offer image
 * is a photograph a stranger uploaded to a public marketplace, so an
 * unmoderated row is never the cover and never appears publicly. That rule
 * lives in the invariant below rather than in a caller's good intentions.
 *
 * LOCK ORDER, matching the project services:
 *   1. offers row
 *   2. offer_media rows
 */
final class OfferMediaService
{
    /** Retries before a file is handed to the orphan outbox. */
    public const CLEANUP_ATTEMPT_CEILING = 5;

    public function __construct(
        private readonly MediaUploader $uploader,
        private readonly AuditLogger $audit,
    ) {}

    /**
     * Store an uploaded file and attach it to an offer.
     *
     * @param  array<string, mixed>  $result  the uploader's outcome
     * @param  array<model-property<OfferMedia>, mixed>  $attributes
     *
     * @throws DuplicateMediaException when this exact file is already attached
     */
    public function store(Offer $offer, array $result, array $attributes): ?OfferMedia
    {
        $path = (string) ($result['path'] ?? '');

        try {
            $media = DB::transaction(function () use ($offer, $result, $attributes, $path): OfferMedia {
                /*
                 * The OFFER row first. An offer with no media yet has no
                 * media row to lock, so two first uploads would serialise on
                 * nothing at all — the offer row is the one thing that always
                 * exists.
                 */
                $locked = Offer::query()->lockForUpdate()->findOrFail($offer->id);

                $rows = $this->lockOfferMedia((int) $locked->id);

                $checksum = $result['checksum'] ?? null;

                if ($checksum !== null
                    && $rows->contains(static fn (OfferMedia $m): bool => $m->checksum === $checksum)) {
                    throw new DuplicateMediaException;
                }

                $media = OfferMedia::query()->create($attributes + [
                    'offer_id' => $locked->id,
                    'disk' => 'public',
                    'path' => $path,
                    'mime_type' => $result['mime'] ?? null,
                    'size_bytes' => $result['size'] ?? null,
                    'checksum' => $checksum,
                    'sort_order' => (int) $rows->max('sort_order') + 1,
                    /*
                     * NEVER the cover on upload, however empty the gallery is.
                     * An unreviewed photograph becoming the card image is
                     * exactly how unmoderated content reaches a buyer.
                     */
                    'is_cover' => false,
                    'moderation_status' => 'pending',
                ]);

                /*
                 * Audit INSIDE the transaction. Writing it afterwards meant a
                 * failing audit call reported a failed upload for media that
                 * had already committed — the person saw an error and the row
                 * existed anyway.
                 */
                $this->audit->record('offer_media.uploaded', $media, [], [
                    'offer_id' => $locked->id,
                    'bytes' => $result['size'] ?? null,
                ]);

                $this->reconcileWithin((int) $locked->id);

                return $media;
            });

            return $media->refresh();
        } catch (DuplicateMediaException $e) {
            // Ordinary outcome. The redundant bytes still go.
            $this->discard($path, 'duplicate_upload_cleanup_failed', ['project_id' => null]);

            throw $e;
        } catch (Throwable $e) {
            // The bytes exist and the row does not.
            $this->discard($path, 'upload_compensation_failed', [
                'last_error' => SafeText::truncate($e->getMessage(), 255),
            ]);

            Log::warning('Offer media upload failed after storage', [
                'offer_id' => $offer->id,
                'error' => $e->getMessage(),
            ]);

            return null;
        }
    }

    /**
     * Record a moderation decision.
     *
     * Approval is the only route by which an image becomes eligible to be a
     * cover, so the invariant is re-run inside the same transaction.
     */
    public function moderate(Offer $offer, int $mediaId, string $decision, ?string $reason = null): bool
    {
        return DB::transaction(function () use ($offer, $mediaId, $decision, $reason): bool {
            Offer::query()->lockForUpdate()->findOrFail($offer->id);

            $rows = $this->lockOfferMedia((int) $offer->id);
            $target = $rows->firstWhere('id', $mediaId);

            // A row awaiting deletion must not be moderated: approving it
            // would resurrect an image already on its way out.
            if ($target === null || (bool) $target->cleanup_pending) {
                return false;
            }

            $target->forceFill([
                'moderation_status' => $decision,
                'moderation_reason' => $reason,
                'moderated_at' => now(),
            ])->save();

            // A rejected image cannot remain the cover.
            if ($decision !== 'approved' && (bool) $target->is_cover) {
                $target->forceFill(['is_cover' => false])->save();
            }

            $this->audit->record('offer_media.moderated', $target, [], [
                'decision' => $decision,
                'reason' => $reason,
            ]);

            $this->reconcileWithin((int) $offer->id);

            return true;
        });
    }

    /** Make one approved image the cover. */
    public function setCover(Offer $offer, int $mediaId): bool
    {
        return DB::transaction(function () use ($offer, $mediaId): bool {
            Offer::query()->lockForUpdate()->findOrFail($offer->id);

            $rows = $this->lockOfferMedia((int) $offer->id);
            $target = $rows->firstWhere('id', $mediaId);

            // Only an approved, live image may represent the offer publicly.
            if ($target === null
                || $target->moderation_status !== 'approved'
                || (bool) $target->cleanup_pending) {
                return false;
            }

            OfferMedia::query()->where('offer_id', $offer->id)->update(['is_cover' => false]);
            OfferMedia::query()->whereKey($mediaId)->update(['is_cover' => true]);

            $this->reconcileWithin((int) $offer->id);

            return true;
        });
    }

    /**
     * @param  list<int>  $orderedIds
     */
    public function reorder(Offer $offer, array $orderedIds): void
    {
        DB::transaction(function () use ($offer, $orderedIds): void {
            Offer::query()->lockForUpdate()->findOrFail($offer->id);

            $owned = $this->lockOfferMedia((int) $offer->id)
                ->reject(static fn (OfferMedia $m): bool => (bool) $m->cleanup_pending)
                ->pluck('id')
                ->all();

            foreach ($orderedIds as $position => $id) {
                if (in_array((int) $id, $owned, true)) {
                    OfferMedia::query()->whereKey($id)->update(['sort_order' => $position]);
                }
            }

            $this->reconcileWithin((int) $offer->id);
        });
    }

    /**
     * Delete one image, bytes removed AFTER the commit.
     *
     * Same ordering as every other media path: a row restored by a rolled-back
     * transaction must never point at bytes that are already gone.
     */
    public function delete(Offer $offer, int $mediaId): bool
    {
        $item = DB::transaction(function () use ($offer, $mediaId): ?OfferMedia {
            Offer::query()->lockForUpdate()->findOrFail($offer->id);

            $rows = $this->lockOfferMedia((int) $offer->id);
            $found = $rows->firstWhere('id', $mediaId);

            if ($found === null) {
                return null;
            }

            $found->forceFill(['cleanup_pending' => true])->save();

            /*
             * REQUESTED, not deleted. Writing `offer_media.deleted` here
             * recorded a deletion that had not happened — the bytes go after
             * this transaction commits, and may fail. The completion event is
             * written when the row actually goes.
             */
            $this->audit->record('offer_media.deletion_requested', $found, [], [
                'offer_id' => $offer->id,
            ]);

            $this->reconcileWithin((int) $offer->id);

            return $found;
        });

        if ($item === null) {
            return false;
        }

        return $this->finaliseDeletion($offer, $item);
    }

    /** Remove the bytes for a staged row, then the row. */
    public function finaliseDeletion(Offer $offer, OfferMedia $item): bool
    {
        if (! $this->removeBytes($item)) {
            /*
             * ATTEMPTS COUNTED UNDER THE LOCK, from a freshly read row —
             * identical to the project services. Incrementing from the
             * in-memory model let two concurrent runs both read the same count
             * and both write count+1, losing an attempt each time, so the
             * ceiling arrived late or never and the handoff with it.
             */
            $attempts = (int) DB::transaction(function () use ($offer, $item): int {
                Offer::query()->lockForUpdate()->find($offer->id);

                $fresh = $this->lockOfferMedia((int) $offer->id)->firstWhere('id', $item->id);

                if ($fresh === null) {
                    return 0;   // deleted while we waited
                }

                $next = (int) $fresh->cleanup_attempts + 1;

                $fresh->forceFill([
                    'cleanup_pending' => true,
                    'cleanup_attempts' => $next,
                    'cleanup_last_error' => 'remove() returned false',
                ])->save();

                $this->audit->record('offer_media.deletion_failed', $fresh, [], [
                    'offer_id' => $offer->id,
                    'attempts' => $next,
                ]);

                return $next;
            });

            // Handoff after that transaction commits: it writes to a different
            // table, and holding the media locks across it would widen the
            // window every ordinary edit contends with.
            if ($attempts >= self::CLEANUP_ATTEMPT_CEILING) {
                $this->handOffToOutbox($item->refresh());
            }

            return false;
        }

        DB::transaction(function () use ($offer, $item): void {
            // Owner first, then its media set.
            Offer::query()->lockForUpdate()->find($offer->id);
            $this->lockOfferMedia((int) $offer->id);

            $this->audit->record('offer_media.deletion_completed', $item, [], [
                'offer_id' => $offer->id,
            ]);

            $item->delete();
            $this->reconcileWithin((int) $offer->id);
        });

        return true;
    }

    /**
     * Remove a source row whose file the sweep has confirmed absent.
     *
     * Mirrors the project services exactly, including the lock order, so one
     * mental model covers all three media domains. A structured result rather
     * than a log line: the sweep needs to know whether to mark its own work
     * finished.
     *
     * @return array{ok: bool, reason: string|null}
     */
    public function finaliseAbsentSource(int $mediaId, string $disk, string $path, int $outboxId): array
    {
        try {
            DB::transaction(function () use ($mediaId, $disk, $path, $outboxId): void {
                // Owner id read unlocked purely to know WHICH owner to lock.
                $offerId = (int) (OfferMedia::query()->whereKey($mediaId)->value('offer_id') ?? 0);

                if ($offerId === 0) {
                    return;   // already gone
                }

                Offer::query()->lockForUpdate()->find($offerId);

                $rows = $this->lockOfferMedia($offerId);
                $row = $rows->firstWhere('id', $mediaId);

                if ($row === null) {
                    return;   // removed while we waited
                }

                if (! (bool) $row->cleanup_pending) {
                    throw new RuntimeException('The source row is no longer pending cleanup.');
                }

                // The exact job. Disk and path are not identity: a later
                // upload can reuse both.
                /*
                 * MANDATORY. The parameter was optional and the check skipped
                 * when null — so any caller omitting it could delete a row on
                 * disk-and-path alone, and paths are reused. A diagnostic call
                 * must never be able to destroy a row by accident.
                 */
                if ((int) $row->cleanup_outbox_id !== $outboxId) {
                    throw new RuntimeException('The source row is linked to a different cleanup job.');
                }

                /*
                 * Disk and path must still match what the outbox recorded. A
                 * path can be reused by a later upload, and deleting a row
                 * that now names a DIFFERENT file would destroy live media.
                 */
                // The job must also name THIS row, in THIS domain: a numeric
                // id is unique only within its own table.
                $job = OrphanedFile::query()->find($outboxId);

                if ($job === null
                    || $job->source_type !== 'offer_media'
                    || (int) $job->source_id !== $mediaId
                    || (string) $job->disk !== $disk
                    || (string) $job->path !== $path) {
                    throw new RuntimeException('The cleanup job does not describe this source row.');
                }

                if ((string) ($row->disk ?: 'public') !== $disk || (string) $row->path !== $path) {
                    throw new RuntimeException('The source row no longer matches the recorded file.');
                }

                $this->audit->record('offer_media.deletion_completed', $row, [], [
                    'offer_id' => $offerId,
                    'via' => 'orphan_sweep',
                ]);

                $row->delete();

                // Approved-cover state is restored from what survives.
                $this->reconcileWithin($offerId);
            });

            return ['ok' => true, 'reason' => null];
        } catch (Throwable $e) {
            return ['ok' => false, 'reason' => $e->getMessage()];
        }
    }

    /**
     * At most one cover, and only ever an approved one.
     *
     * Deliberately NOT "exactly one": an offer whose images are all awaiting
     * review correctly has none, and inventing a cover from a pending image
     * would publish exactly what moderation exists to hold back.
     */
    private function reconcileWithin(int $offerId): void
    {
        $eligible = $this->lockOfferMedia($offerId)
            ->reject(static fn (OfferMedia $m): bool => (bool) $m->cleanup_pending)
            ->filter(static fn (OfferMedia $m): bool => $m->moderation_status === 'approved')
            ->sortBy('sort_order')
            ->values();

        $covers = $eligible->filter(static fn (OfferMedia $m): bool => (bool) $m->is_cover);

        if ($eligible->isEmpty()) {
            // Nothing may represent the offer; clear any stale flag.
            OfferMedia::query()->where('offer_id', $offerId)->update(['is_cover' => false]);

            return;
        }

        if ($covers->count() === 1) {
            return;
        }

        OfferMedia::query()->where('offer_id', $offerId)->update(['is_cover' => false]);
        OfferMedia::query()->whereKey($eligible->first()->id)->update(['is_cover' => true]);
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
                $ownerId = (int) $item->offer_id;

                Offer::query()->lockForUpdate()->find($ownerId);

                $fresh = $this->lockOfferMedia($ownerId)->firstWhere('id', $item->id);

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
                    'offer_media_cleanup_exhausted',
                    ['source_type' => 'offer_media', 'source_id' => $fresh->id],
                );

                $fresh->forceFill([
                    'cleanup_outbox_id' => $outbox->id,
                    'cleanup_handed_off_at' => now(),
                ])->save();

                return true;
            });
        } catch (Throwable $e) {
            Log::warning('Media handoff to the orphan outbox failed', [
                'source_type' => 'offer_media',
                'source_id' => $item->id,
                'error' => $e->getMessage(),
            ]);

            return false;
        }
    }

    /** @return Collection<int, OfferMedia> */
    private function lockOfferMedia(int $offerId): Collection
    {
        return OfferMedia::query()
            ->where('offer_id', $offerId)
            ->lockForUpdate()
            ->get();
    }

    /**
     * Remove bytes that have no row, recording them durably if that fails.
     *
     * @param  array<string, mixed>  $context
     */
    private function discard(string $path, string $reason, array $context = []): void
    {
        if ($path === '') {
            return;   // the uploader never reported a path; nothing was written
        }

        OrphanedFile::removeOrRecord('public', $path, $reason, $context);
    }

    private function removeBytes(OfferMedia $item): bool
    {
        $path = (string) $item->path;

        if ($path === '') {
            return true;
        }

        $disk = (string) ($item->disk ?: 'public');

        // Already missing counts as removed: an interrupted earlier run may
        // have taken the file while failing to clear the flag.
        try {
            if (! Storage::disk($disk)->exists($path)) {
                return true;
            }
        } catch (Throwable) {
            return false;
        }

        return $this->uploader->remove($disk, $path);
    }
}
