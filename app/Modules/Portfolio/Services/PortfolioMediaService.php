<?php

declare(strict_types=1);

namespace App\Modules\Portfolio\Services;

use App\Modules\Identity\Services\ProfilePhotoService;
use App\Modules\Operations\Services\AuditLogger;
use App\Modules\Portfolio\Models\PortfolioMedia;
use App\Modules\Portfolio\Models\PortfolioProperty;
use GdImage;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use RuntimeException;
use Throwable;
use ZipArchive;

/**
 * Portfolio images and documents (correction §6.8).
 *
 * The rules, in the order an upload meets them:
 *
 *   WHO — the caller resolves the property through the owner-scoped query
 *   before this service is ever reached, so every path in here can assume
 *   the property is the caller's own. Media rows carry no user id; the
 *   property is the single source of ownership.
 *
 *   WHAT — the REAL type from finfo decides everything. Images are
 *   jpeg/png/webp and are RE-ENCODED (same discipline as profile photos:
 *   pixel ceiling, memory arithmetic before decode, metadata stripped,
 *   fresh JPEG out). Documents are pdf/docx/xlsx, and BOTH the sniffed
 *   mime and the claimed extension must independently land on the same
 *   allowlisted type — an .html, .svg, .php, .sh, .zip, or macro-enabled
 *   .docm/.xlsm fails here no matter which half of it lies.
 *
 *   WHERE — the PRIVATE local disk, under a generated name inside the
 *   property's own directory. Nothing under storage/app is web-served;
 *   the only way to a byte is the authorised streaming route.
 *
 *   HOW MUCH — per-file size caps and a per-property count cap, checked
 *   before any byte is stored.
 *
 * Deletion removes the file first and the row second, so a crash between
 * the two leaves a harmless dangling row rather than an orphaned file
 * pretending to be gone. Every add and remove is audited — names only,
 * never paths.
 */
final class PortfolioMediaService
{
    public const DISK = 'local';

    public const MAX_PER_PROPERTY = 12;

    public const MAX_IMAGE_BYTES = 5 * 1024 * 1024;

    public const MAX_DOCUMENT_BYTES = 10 * 1024 * 1024;

    /** Longest edge an attached image is stored at. */
    private const IMAGE_EDGE = 1600;

    private const IMAGE_MIMES = ['image/jpeg', 'image/png', 'image/webp'];

    /**
     * Documents: sniffed mime => the extensions allowed to claim it. Both
     * sides must agree; there is no "unknown but probably fine".
     */
    private const DOCUMENT_TYPES = [
        'application/pdf' => ['pdf'],
        'application/vnd.openxmlformats-officedocument.wordprocessingml.document' => ['docx'],
        'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet' => ['xlsx'],
        /*
         * Correction v4, BLOCKER 7. An OOXML file IS a zip, and on several
         * Linux/Fileinfo builds — Hostinger's among them — that is exactly
         * what `finfo` reports. The v3 allowlist accepted only the official
         * OOXML mime strings, so on those hosts a perfectly valid .docx was
         * rejected before it ever reached the structural check that would
         * have proved it valid. Users saw "wrong file type" for a file
         * Word had just written.
         *
         * `application/zip` is therefore accepted here, but ONLY for the
         * docx/xlsx extensions, and it buys nothing on its own: every such
         * upload must still pass `assertSafeOoxml()`, which requires the
         * content-type part, the format's root member, a declared content
         * type matching the claimed kind, no macros, no traversal, no
         * encryption, and the expansion limits below. A generic zip renamed
         * .docx fails that, which is why widening the sniff does not widen
         * the attack surface.
         */
        'application/zip' => ['docx', 'xlsx'],
    ];

    /**
     * Zip-expansion limits (v4, BLOCKER 7).
     *
     * The v3 structural check walked every entry but never looked at how
     * big those entries CLAIMED to become. A 40 KB docx can legitimately
     * declare gigabytes of uncompressed XML, and the cost is not paid here
     * — it is paid by whatever later opens the document. Four independent
     * ceilings, because a bomb only has to beat one of them:
     *
     *   ENTRIES        — a real docx has tens of members, not thousands.
     *   TOTAL_BYTES    — the whole archive expanded.
     *   ENTRY_BYTES    — one member expanded.
     *   RATIO          — compressed:uncompressed. The classic bomb is small
     *                    and absurdly compressible; 200:1 is far above any
     *                    honest office document and far below a zip bomb.
     */
    private const OOXML_MAX_ENTRIES = 512;

    private const OOXML_MAX_TOTAL_BYTES = 120 * 1024 * 1024;

    private const OOXML_MAX_ENTRY_BYTES = 60 * 1024 * 1024;

    private const OOXML_MAX_RATIO = 200;

    /** Where a processed upload waits before it is finalised (v4, BLOCKER 8). */
    private const STAGING_PREFIX = 'portfolio-media-staging';

    /**
     * The canonical mime for an OOXML kind, used when `finfo` reported the
     * zip wrapper. Storing `application/zip` on the row would make the
     * download response advertise the wrong type to the browser.
     */
    private const OOXML_MIME = [
        'docx' => 'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
        'xlsx' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
    ];

    /**
     * How many failed deletions one property may accumulate before it stops
     * accepting new media (v4, BLOCKER 9).
     *
     * Back-pressure, not punishment. A transient disk fault clears and the
     * sweeper drains the backlog within minutes; a persistent one is
     * exactly the situation where letting somebody keep adding files makes
     * the eventual cleanup worse.
     */
    public const MAX_PENDING_DELETIONS = 6;

    /**
     * How many automatic retries a failed deletion gets before it is
     * escalated to manual review (v4, BLOCKER 9).
     */
    public const MAX_DELETION_ATTEMPTS = 8;

    public function __construct(
        private readonly AuditLogger $audit,
        private readonly ProfilePhotoService $profilePhotos,
    ) {}

    /**
     * Accept one upload — validating and encoding OUTSIDE the database
     * lock, claiming the slot INSIDE a short transaction, and finalising
     * the bytes after it commits.
     *
     * Correction v4, BLOCKER 8. The v3 shape opened a transaction, locked
     * the property row, and then — still holding that lock — read the
     * upload, decoded the image, applied EXIF orientation, resampled,
     * re-encoded a JPEG and wrote it to disk. On a 5 MB photo over slow
     * shared-hosting I/O that is seconds of exclusive lock on a row the
     * owner's own portfolio pages also read. It also wrote the file before
     * commit, so a commit failure left bytes nothing referenced.
     *
     * Three phases now, and which phase owns which failure is the design:
     *
     *   PHASE 1 (no lock, no transaction, no row) — validate the type,
     *   enforce the size caps, decode, orient, resample, encode, and write
     *   to a STAGING path. Every expensive and every user-error-prone thing
     *   happens here. A failure leaves at most one staged file, which is
     *   deleted on the way out and swept later regardless.
     *
     *   PHASE 2 (short transaction, property row locked) — recount the
     *   active media, enforce the 12-file cap, and insert the row in
     *   `staging` status pointing at its FINAL path. Nothing but integer
     *   comparison and one insert happens under the lock, so its duration
     *   no longer depends on image size or disk speed. `staging` rows count
     *   toward the cap, which is what stops twelve concurrent uploads at
     *   eleven from all being admitted.
     *
     *   PHASE 3 (after commit) — move the staged bytes to the final path
     *   and flip the row to `ready`. Only now is the media servable.
     *
     * The failure matrix this produces:
     *   - Phase 1 fails            → staged file removed; no row. Nothing.
     *   - Phase 2 fails or the cap
     *     refuses                  → transaction rolls back; staged file
     *                                removed. No row, no final bytes.
     *   - Commit fails             → same as above: the row never existed.
     *   - Phase 3 (rename) fails   → the row EXISTS and is marked
     *                                `orphaned`, never `ready`. It is never
     *                                served (the serving path requires
     *                                `ready`) and the sweeper owns it.
     *   - Process dies mid-phase-3 → identical: a `staging` row and a
     *                                staged file, both swept.
     *
     * What can never happen, which is the property the review asked for: a
     * `ready` row pointing at bytes that are not there, or bytes on the
     * private disk that no row references.
     */
    public function store(PortfolioProperty $property, UploadedFile $upload): PortfolioMedia
    {
        // PHASE 1 — all the slow work, none of the locks.
        $staged = $this->processToStaging($property, $upload);

        try {
            // PHASE 2 — the short transaction.
            $media = DB::transaction(function () use ($property, $staged): PortfolioMedia {
                PortfolioProperty::query()->whereKey($property->id)->lockForUpdate()->firstOrFail();

                /*
                 * Rows on their way out do not count — a stuck deletion
                 * must not freeze somebody's ability to add. Rows in
                 * `staging` DO count: they are slots being claimed right
                 * now, and ignoring them is exactly how a concurrent burst
                 * overshoots the cap.
                 */
                $active = PortfolioMedia::query()
                    ->where('portfolio_property_id', $property->id)
                    ->whereNull('deletion_pending_at')
                    ->whereIn('status', [PortfolioMedia::STATUS_READY, PortfolioMedia::STATUS_STAGING])
                    ->count();

                if ($active >= self::MAX_PER_PROPERTY) {
                    throw ValidationException::withMessages(['file' => __('portfolio.media.too_many')]);
                }

                /*
                 * BLOCKER 9's back-pressure, enforced at the one place new
                 * bytes enter: a property whose failed deletions have piled
                 * up past the threshold cannot accept more until the
                 * sweeper drains them. Without this, a persistent disk
                 * fault lets a person add indefinitely while nothing can
                 * ever be removed.
                 */
                $pending = PortfolioMedia::query()
                    ->where('portfolio_property_id', $property->id)
                    ->whereNotNull('deletion_pending_at')
                    ->count();

                if ($pending >= self::MAX_PENDING_DELETIONS) {
                    throw ValidationException::withMessages(['file' => __('portfolio.media.cleanup_backlog')]);
                }

                $media = new PortfolioMedia([
                    'kind' => $staged['kind'],
                    'disk' => self::DISK,
                    'path' => $staged['final_path'],
                    'original_name' => $staged['name'],
                    'mime' => $staged['mime'],
                    'size_bytes' => $staged['size'],
                ]);
                $media->portfolio_property_id = $property->id;
                $media->forceFill([
                    'status' => PortfolioMedia::STATUS_STAGING,
                    'staged_path' => $staged['staged_path'],
                ])->save();

                return $media;
            });
        } catch (Throwable $exception) {
            // Nothing committed; the staged bytes have no owner.
            $this->deleteQuietly($staged['staged_path']);

            throw $exception;
        }

        // PHASE 3 — publish the bytes, then the row.
        $this->finalise($media);

        $this->audit->record('portfolio.media_added', $property, [], [
            'media_id' => $media->id,
            'kind' => $media->kind,
            'name' => $media->original_name,
        ]);

        return $media;
    }

    /**
     * PHASE 1: validate and encode into a staging path.
     *
     * @return array{kind: string, mime: string, size: int, name: string, staged_path: string, final_path: string}
     */
    private function processToStaging(PortfolioProperty $property, UploadedFile $upload): array
    {
        $mime = (string) $upload->getMimeType();

        return in_array($mime, self::IMAGE_MIMES, true)
            ? $this->stageImage($property, $upload)
            : $this->stageDocument($property, $upload, $mime);
    }

    /**
     * PHASE 3: move staged bytes into place and mark the row servable.
     *
     * The rename is the commit point for the FILE, the status flip is the
     * commit point for the ROW, and they are in that order on purpose: a
     * crash between them leaves a `staging` row whose final bytes exist,
     * which the sweeper resolves by completing it. The reverse order would
     * leave a `ready` row with no bytes, which is the one state that is
     * visible to a user as a broken download.
     */
    private function finalise(PortfolioMedia $media): void
    {
        try {
            $disk = Storage::disk($media->disk);
            $staged = (string) $media->staged_path;

            if ($staged !== '' && $disk->exists($staged) && ! $disk->exists($media->path)) {
                if (! $disk->move($staged, $media->path)) {
                    throw new RuntimeException('portfolio media finalisation failed');
                }
            }

            if (! $disk->exists($media->path)) {
                throw new RuntimeException('portfolio media missing after finalisation');
            }

            $media->forceFill([
                'status' => PortfolioMedia::STATUS_READY,
                'staged_path' => null,
            ])->save();
        } catch (Throwable) {
            /*
             * The slot is claimed but the bytes are not where the row says.
             * Marked `orphaned` rather than deleted: deleting the row here
             * could race the very failure that caused this and leave the
             * staged file untracked. The sweeper reconciles it, and until
             * then the row is invisible to every read path.
             */
            $media->forceFill(['status' => PortfolioMedia::STATUS_ORPHANED])->save();

            $this->audit->record('portfolio.media_finalisation_failed', $media->property, [], [
                'media_id' => $media->id,
            ], severity: 'warning');

            throw ValidationException::withMessages(['file' => __('portfolio.media.failed')]);
        }
    }

    /**
     * Delete one media item: the FILE first, the row only once the bytes
     * are provably gone.
     *
     * v3 established the ordering — deleting the row first and swallowing a
     * file-delete failure orphans bytes forever. v4 (BLOCKER 9) adds what
     * was missing: a failure is no longer a permanent, unbounded, silent
     * pending state. It records an attempt count, a backoff, a safe error
     * class and a next-attempt time, and once the attempts are exhausted it
     * escalates for human review instead of retrying forever.
     */
    public function delete(PortfolioMedia $media): void
    {
        // Explicit load — the model bans lazy relation access, and the audit
        // below genuinely needs the property as its subject.
        $media->loadMissing('property');
        $property = $media->property;

        $failure = $this->removeBytes($media);

        if ($failure !== null) {
            $this->markDeletionPending($media, $failure);

            $this->audit->record('portfolio.media_delete_pending', $property, [], [
                'media_id' => $media->id,
                'kind' => $media->kind,
                'name' => $media->original_name,
                'attempts' => $media->deletion_attempts,
            ], severity: 'warning');

            return;
        }

        $media->delete();

        $this->audit->record('portfolio.media_removed', $property, [], [
            'kind' => $media->kind,
            'name' => $media->original_name,
        ], severity: 'warning');
    }

    /**
     * Remove every file and row for a property about to be deleted — and
     * REFUSE to report success while any bytes remain.
     *
     * The media rows ride a cascade: if this returned quietly with a stuck
     * file, the property delete would remove the row and orphan the bytes
     * forever. Aborting here leaves the property (and its pending-marked
     * rows) in place for the sweeper, which is the only outcome where the
     * bytes stay reachable.
     */
    public function deleteAllFor(PortfolioProperty $property): void
    {
        foreach ($property->media()->get() as $media) {
            $this->delete($media);
        }

        if ($property->media()->count() > 0) {
            throw ValidationException::withMessages([
                'property' => __('portfolio.media.delete_pending'),
            ]);
        }
    }

    /**
     * One sweeper pass over a single pending row (v4, BLOCKER 9).
     *
     * Separated from {@see delete()} so the scheduled command has an entry
     * point that is idempotent and safe to call on a row somebody else may
     * be touching: the row is re-read under a lock, re-checked for still
     * being pending, and skipped if another pass already resolved it.
     *
     * Returns true when the row is gone — the only definition of success
     * that means the bytes are actually off the disk.
     */
    public function retryPendingDeletion(PortfolioMedia $media): bool
    {
        $resolved = false;

        DB::transaction(function () use ($media, &$resolved): void {
            $locked = PortfolioMedia::query()
                ->whereKey($media->getKey())
                ->lockForUpdate()
                ->first();

            if ($locked === null) {
                $resolved = true; // Already gone; nothing to do.

                return;
            }

            if ($locked->deletion_pending_at === null) {
                return; // No longer pending — another path resolved it.
            }

            $failure = $this->removeBytes($locked);

            if ($failure !== null) {
                $this->markDeletionPending($locked, $failure);

                return;
            }

            $locked->delete();
            $resolved = true;
        });

        return $resolved;
    }

    /**
     * Record that a deletion failed, and when to try again.
     *
     * Exponential backoff capped at an hour: a transient permissions blip
     * clears on the first retry, and a broken disk is not hammered every
     * minute for a week. After MAX_DELETION_ATTEMPTS the row is escalated —
     * it stops being retried automatically and starts being something an
     * administrator can see, because at that point the problem is not one
     * this code can solve by trying harder.
     *
     * The stored error is a CLASS NAME. An exception message from a storage
     * driver routinely contains the full path, and a private portfolio path
     * contains the owner's user id and property id.
     */
    private function markDeletionPending(PortfolioMedia $media, string $errorClass): void
    {
        $attempts = (int) $media->deletion_attempts + 1;
        $escalated = $attempts >= self::MAX_DELETION_ATTEMPTS;

        $media->forceFill([
            'deletion_pending_at' => $media->deletion_pending_at ?? now(),
            'deletion_attempts' => $attempts,
            'deletion_last_attempt_at' => now(),
            'deletion_next_attempt_at' => $escalated
                ? null
                : now()->addSeconds(min(3600, (int) (60 * (2 ** ($attempts - 1))))),
            'deletion_last_error' => Str::limit($errorClass, 180, ''),
            'deletion_escalated_at' => $escalated ? ($media->deletion_escalated_at ?? now()) : null,
        ])->save();

        if ($escalated) {
            Log::channel('security')->error('portfolio.media_deletion_escalated', [
                'media_id' => $media->id,
                'attempts' => $attempts,
                'error' => $errorClass,
            ]);
        }
    }

    /**
     * Try to take the bytes off the disk.
     *
     * Returns null on success — including the case where they were already
     * gone, because a retry after a partial failure has done its job — or
     * the exception class name on failure.
     *
     * Both the final path AND any staged twin are removed: a row that never
     * finished finalising has bytes under the staging prefix, and deleting
     * only the final path would leave them.
     */
    private function removeBytes(PortfolioMedia $media): ?string
    {
        try {
            $disk = Storage::disk($media->disk);

            foreach (array_filter([$media->path, $media->staged_path]) as $path) {
                if ($disk->exists($path) && ! $disk->delete($path)) {
                    return 'DeleteReturnedFalse';
                }
            }

            return null;
        } catch (Throwable $exception) {
            return $exception::class;
        }
    }

    /**
     * @return array{kind: string, mime: string, size: int, name: string, staged_path: string, final_path: string}
     */
    private function stageImage(PortfolioProperty $property, UploadedFile $upload): array
    {
        if ($upload->getSize() === false || $upload->getSize() > self::MAX_IMAGE_BYTES) {
            throw ValidationException::withMessages(['file' => __('portfolio.media.image_too_large')]);
        }

        $raw = (string) file_get_contents($upload->getRealPath());
        $info = @getimagesizefromstring($raw);

        if ($info === false || $info[0] < 1 || $info[1] < 1) {
            throw ValidationException::withMessages(['file' => __('portfolio.media.invalid')]);
        }

        [$width, $height] = [$info[0], $info[1]];

        if ($width > ProfilePhotoService::MAX_DIMENSION || $height > ProfilePhotoService::MAX_DIMENSION) {
            throw ValidationException::withMessages(['file' => __('portfolio.media.image_dimensions')]);
        }

        if ($width * $height > ProfilePhotoService::MAX_PIXELS) {
            throw ValidationException::withMessages(['file' => __('portfolio.media.image_pixels')]);
        }

        if (! self::imageDecodeIsAffordable($width, $height, strlen($raw))) {
            throw ValidationException::withMessages(['file' => __('portfolio.media.image_memory')]);
        }

        $image = @imagecreatefromstring($raw);

        if ($image === false) {
            throw ValidationException::withMessages(['file' => __('portfolio.media.invalid')]);
        }

        // Property photos come off the same phones profile photos do — the
        // proven orientation pipeline turns them upright before the
        // re-encode strips the metadata that said so.
        $image = $this->profilePhotos->orientFromFile($image, (string) $upload->getMimeType(), (string) $upload->getRealPath());

        $finalPath = $this->generatedPath($property, 'jpg');
        $stagedPath = $this->stagingPathFor($finalPath);

        try {
            $scaled = $this->scaleDown($image, self::IMAGE_EDGE);

            ob_start();
            $encoded = imagejpeg($scaled, null, 84);
            $bytes = (string) ob_get_clean();

            if ($scaled !== $image) {
                imagedestroy($scaled);
            }

            if (! $encoded || $bytes === '' || ! Storage::disk(self::DISK)->put($stagedPath, $bytes)) {
                throw new RuntimeException('portfolio image write failed');
            }
        } catch (ValidationException $exception) {
            $this->deleteQuietly($stagedPath);

            throw $exception;
        } catch (Throwable) {
            $this->deleteQuietly($stagedPath);

            throw ValidationException::withMessages(['file' => __('portfolio.media.failed')]);
        } finally {
            imagedestroy($image);
        }

        return [
            'kind' => 'image',
            'mime' => 'image/jpeg',
            'size' => strlen($bytes),
            'name' => $this->displayName($upload),
            'staged_path' => $stagedPath,
            'final_path' => $finalPath,
        ];
    }

    /**
     * @return array{kind: string, mime: string, size: int, name: string, staged_path: string, final_path: string}
     */
    private function stageDocument(PortfolioProperty $property, UploadedFile $upload, string $mime): array
    {
        if ($upload->getSize() === false || $upload->getSize() > self::MAX_DOCUMENT_BYTES) {
            throw ValidationException::withMessages(['file' => __('portfolio.media.document_too_large')]);
        }

        $allowedExtensions = self::DOCUMENT_TYPES[$mime] ?? null;
        $claimedExtension = Str::lower((string) $upload->getClientOriginalExtension());

        /*
         * BOTH halves must agree. A PHP script named report.pdf fails on the
         * sniff; a real PDF named report.php fails on the extension; the
         * docm/xlsm macro formats sniff as their own OOXML types, which are
         * simply absent from the table.
         *
         * v4 BLOCKER 7: `application/zip` reaches here for docx/xlsx only,
         * and buys nothing without passing `assertSafeOoxml()` below.
         */
        if ($allowedExtensions === null || ! in_array($claimedExtension, $allowedExtensions, true)) {
            throw ValidationException::withMessages(['file' => __('portfolio.media.type')]);
        }

        if (in_array($claimedExtension, ['docx', 'xlsx'], true)) {
            $this->assertSafeOoxml((string) $upload->getRealPath(), $claimedExtension);
        } elseif ($mime === 'application/zip') {
            // A zip claiming an extension that is not an OOXML kind never
            // reaches the structural check, so it must not reach storage.
            throw ValidationException::withMessages(['file' => __('portfolio.media.type')]);
        }

        $finalPath = $this->generatedPath($property, $claimedExtension);
        $stagedPath = $this->stagingPathFor($finalPath);

        $stored = Storage::disk(self::DISK)->putFileAs(
            dirname($stagedPath),
            $upload,
            basename($stagedPath),
        );

        if ($stored === false) {
            $this->deleteQuietly($stagedPath);

            throw ValidationException::withMessages(['file' => __('portfolio.media.failed')]);
        }

        return [
            'kind' => 'document',
            'mime' => $mime === 'application/zip'
                // Record the REAL format, not the wrapper the sniff saw.
                ? self::OOXML_MIME[$claimedExtension]
                : $mime,
            'size' => (int) $upload->getSize(),
            'name' => $this->displayName($upload),
            'staged_path' => $stagedPath,
            'final_path' => $finalPath,
        ];
    }

    /**
     * The staging twin of a final path.
     *
     * Derived from the final path rather than randomly generated, so a
     * sweeper looking at an abandoned staging file can tell what it was
     * going to become, and so the pair cannot drift apart.
     */
    private function stagingPathFor(string $finalPath): string
    {
        return self::STAGING_PREFIX.'/'.$finalPath;
    }

    /**
     * The uploader's filename as a LABEL only: basename'd, control characters
     * and separators stripped, bounded — and never used to address storage.
     */
    private function displayName(UploadedFile $upload): string
    {
        $name = basename((string) $upload->getClientOriginalName());
        $name = (string) preg_replace('/[\\x00-\\x1f\\/\\\\]+/u', '', $name);

        return Str::limit(trim($name) === '' ? 'file' : trim($name), 150, '…');
    }

    private function generatedPath(PortfolioProperty $property, string $extension): string
    {
        return sprintf(
            'portfolio-media/%d/%d/%s.%s',
            $property->user_id,
            $property->id,
            Str::lower(Str::random(24)),
            $extension,
        );
    }

    /** Same arithmetic-before-decode budget the profile pipeline uses. */
    /**
     * Correction H8: the PORTFOLIO pipeline's own bill, not the profile
     * service's. That estimator budgets two avatar thumbnails (256² + 64²);
     * this pipeline holds, at peak: the decode buffer, the orientation
     * working copy (rotation allocates the second before freeing the
     * first), the scaled target (bounded by the 1600-edge output, never
     * larger than the source), the JPEG encode output (≈ one byte per
     * scaled pixel at quality 84 is a generous ceiling), and the raw
     * upload bytes held throughout. Same 60% headroom rule, same shared
     * budget reader — so the profile suite's memory seam constrains this
     * estimator identically.
     */
    public static function imageDecodeIsAffordable(int $width, int $height, int $rawBytes = 0): bool
    {
        $scaledPixels = min($width * $height, self::IMAGE_EDGE * self::IMAGE_EDGE);

        $needed = ($width * $height * 2 + $scaledPixels) * 6
            + $scaledPixels
            + $rawBytes;

        $available = ProfilePhotoService::availableBudget();

        if ($available === null) {
            return true;
        }

        return $needed <= (int) ($available * 0.6);
    }

    private function scaleDown(GdImage $image, int $edge): GdImage
    {
        $width = imagesx($image);
        $height = imagesy($image);

        if (max($width, $height) <= $edge) {
            return $image;
        }

        $ratio = $edge / max($width, $height);
        $target = imagecreatetruecolor((int) round($width * $ratio), (int) round($height * $ratio));
        imagecopyresampled(
            $target,
            $image,
            0,
            0,
            0,
            0,
            imagesx($target),
            imagesy($target),
            $width,
            $height,
        );

        return $target;
    }

    /**
     * Prove an OOXML upload is what it claims to be, and that opening it
     * later cannot be turned into a denial of service.
     *
     * Correction v4, BLOCKER 7. v3 established the structural rules; this
     * adds the two things it was missing — a declared content type that
     * has to AGREE with the claimed extension, and hard ceilings on
     * expansion — and makes a missing `zip` extension fail with a
     * translated message rather than a fatal error.
     *
     * The rules, in the order an archive meets them:
     *
     *   1. `ZipArchive` must exist. On a host without ext-zip this is a
     *      configuration fault, not a bad upload, so it produces its own
     *      translated refusal and a security-log line — never a 500, and
     *      never a silent accept.
     *   2. The archive must open. `RDONLY` throughout; nothing is ever
     *      extracted, here or later, and the traversal check below exists
     *      to protect any future consumer that might.
     *   3. Encrypted entries are refused. An encrypted member cannot be
     *      inspected, and a file this service cannot read is a file it
     *      cannot vouch for.
     *   4. `[Content_Types].xml` must be present, must be small enough to
     *      read, and must DECLARE the content type of the claimed kind. A
     *      generic zip with a fabricated `word/document.xml` fails here.
     *   5. The format's root member must exist.
     *   6. No entry may be a macro container, traverse, or be absolute.
     *   7. The four expansion ceilings.
     */
    private function assertSafeOoxml(string $path, string $extension): void
    {
        $refuse = static function (): never {
            throw ValidationException::withMessages([
                'file' => __('portfolio.media.document_structure'),
            ]);
        };

        /*
         * 1. Fail SAFE, not fatally. `class_exists` rather than
         *    `extension_loaded('zip')` because that is the thing actually
         *    depended on two lines below.
         */
        if (! class_exists(ZipArchive::class)) {
            Log::channel('security')->error('portfolio.media_zip_extension_missing');

            throw ValidationException::withMessages([
                'file' => __('portfolio.media.zip_unavailable'),
            ]);
        }

        $zip = new ZipArchive;

        if ($zip->open($path, ZipArchive::RDONLY) !== true) {
            $refuse();
        }

        try {
            if ($zip->numFiles < 1 || $zip->numFiles > self::OOXML_MAX_ENTRIES) {
                $refuse();
            }

            $required = $extension === 'docx' ? 'word/document.xml' : 'xl/workbook.xml';

            if ($zip->locateName('[Content_Types].xml') === false || $zip->locateName($required) === false) {
                $refuse();
            }

            $totalUncompressed = 0;

            for ($i = 0; $i < $zip->numFiles; $i++) {
                $stat = $zip->statIndex($i);

                if (! is_array($stat)) {
                    $refuse();
                }

                $name = (string) $stat['name'];
                $normalised = str_replace('\\', '/', $name);
                $uncompressed = (int) $stat['size'];
                $compressed = (int) $stat['comp_size'];

                if ($normalised === ''
                    || str_starts_with($normalised, '/')
                    || preg_match('#^[a-zA-Z]:#', $normalised) === 1
                    || in_array('..', explode('/', $normalised), true)
                    || Str::lower(basename($normalised)) === 'vbaproject.bin') {
                    $refuse();
                }

                // 3. Encrypted members cannot be vouched for.
                if ($stat['encryption_method'] !== ZipArchive::EM_NONE) {
                    $refuse();
                }

                if ($uncompressed > self::OOXML_MAX_ENTRY_BYTES) {
                    $refuse();
                }

                $totalUncompressed += $uncompressed;

                if ($totalUncompressed > self::OOXML_MAX_TOTAL_BYTES) {
                    $refuse();
                }

                /*
                 * Ratio is judged per entry and only once an entry is big
                 * enough for the ratio to mean anything — a 12-byte stub
                 * compressing to 1 byte is a 12:1 "bomb" and is nothing at
                 * all. The 4 KB floor is what stops that false positive.
                 */
                if ($compressed > 0
                    && $uncompressed >= 4096
                    && ($uncompressed / max(1, $compressed)) > self::OOXML_MAX_RATIO) {
                    $refuse();
                }
            }

            /*
             * 4. The declared content type must AGREE with the extension.
             *    Read with an explicit length so a hostile
             *    [Content_Types].xml cannot itself be the expansion attack;
             *    the entry is a few kilobytes in every real document.
             */
            $declared = $zip->getFromName('[Content_Types].xml', 64 * 1024);

            if (! is_string($declared) || $declared === '') {
                $refuse();
            }

            $expected = $extension === 'docx'
                ? 'wordprocessingml.document.main+xml'
                : 'spreadsheetml.sheet.main+xml';

            if (! str_contains($declared, $expected)) {
                $refuse();
            }

            // Macro-enabled main parts, declared rather than smuggled.
            foreach (['ms-word.document.macroEnabled', 'ms-excel.sheet.macroEnabled'] as $macro) {
                if (str_contains($declared, $macro)) {
                    $refuse();
                }
            }
        } finally {
            $zip->close();
        }
    }

    private function deleteQuietly(string $path): void
    {
        try {
            Storage::disk(self::DISK)->delete($path);
        } catch (Throwable) {
            // Best-effort cleanup.
        }
    }
}
