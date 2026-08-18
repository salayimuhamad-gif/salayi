<?php

declare(strict_types=1);

namespace App\Modules\Core\Support;

use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Throwable;

/**
 * Validated image storage (spec 30.1, 8).
 *
 * Three checks, and the order matters:
 *
 *   1. MIME must be in the allowlist.
 *   2. The extension must NOT be in the blocklist, whatever the MIME says. A
 *      browser reports whatever the client claims, so a `.phtml` announcing
 *      itself as `image/jpeg` passes check 1 and fails here.
 *   3. The bytes must actually parse as an image. `getimagesize()` reads the
 *      header, so a PHP script renamed to `.jpg` with a forged MIME fails even
 *      after clearing both lists.
 *
 * Check 3 is the one that matters on shared hosting, where the uploads
 * directory is frequently inside the document root and a stored script is a
 * remote-execution hole rather than a broken thumbnail.
 *
 * NOT DONE: resizing and thumbnail generation. That needs Intervention Image,
 * a Composer dependency this build has never been able to install. Originals
 * are stored at full size, which is correct but wasteful — a 6 MB phone photo
 * is served to a phone on an Erbil mobile connection.
 */
final class MediaUploader
{
    /**
     * @return array{
     *     ok: bool, reason: string|null,
     *     path: string|null, mime: string|null, size: int|null,
     *     width: int|null, height: int|null, checksum: string|null
     * }
     */
    public function storeImage(UploadedFile $file, string $directory, string $disk = 'public'): array
    {
        $fail = static fn (string $reason): array => [
            'ok' => false, 'reason' => $reason,
            'path' => null, 'mime' => null, 'size' => null,
            'width' => null, 'height' => null, 'checksum' => null,
        ];

        $mime = (string) $file->getMimeType();

        if (! in_array($mime, (array) config('filesystems.uploads.image_mimes'), true)) {
            return $fail('mime_not_allowed');
        }

        $extension = strtolower((string) $file->getClientOriginalExtension());

        if (in_array($extension, (array) config('filesystems.uploads.blocked_extensions'), true)) {
            return $fail('extension_blocked');
        }

        $maxKb = (int) config('filesystems.uploads.max_image_kb');

        if ($file->getSize() > $maxKb * 1024) {
            return $fail('too_large');
        }

        // The header check. A forged MIME and an innocent extension both
        // survive the lists above; neither survives this.
        $dimensions = @getimagesize($file->getRealPath());

        if ($dimensions === false) {
            return $fail('not_a_real_image');
        }

        $checksum = hash_file('sha256', $file->getRealPath());
        $path = $file->store($directory, $disk);

        if ($path === false) {
            return $fail('storage_failed');
        }

        return [
            'ok' => true,
            'reason' => null,
            'path' => $path,
            'mime' => $mime,
            'size' => (int) $file->getSize(),
            'width' => (int) $dimensions[0],
            'height' => (int) $dimensions[1],
            'checksum' => $checksum === false ? null : $checksum,
        ];
    }

    /** Remove a stored file, tolerating one that has already gone. */
    public function remove(string $disk, string $path): bool
    {
        /*
         * A REMOVAL THAT CANNOT HAPPEN IS A FAILED ATTEMPT, NOT A CRASH.
         *
         * Resolving the disk throws when it has no configured driver — a disk
         * renamed or dropped from filesystems.php, which is exactly the kind of
         * misconfiguration this cleanup path exists to survive. The exception
         * escaped `finaliseDeletion()`, so instead of counting an attempt and
         * eventually recording a durable orphan, the whole sweep aborted and
         * every later row in the batch went unprocessed.
         *
         * Returning false feeds the documented lifecycle: the attempt is
         * counted under the lock, the ceiling is reached, and the file becomes
         * an outbox job a human can act on. The reason is logged rather than
         * swallowed, because a vanished disk is an operational fault somebody
         * needs to see.
         */
        try {
            return Storage::disk($disk)->exists($path)
                ? Storage::disk($disk)->delete($path)
                : true;
        } catch (Throwable $e) {
            Log::warning('Media removal failed', [
                'disk' => $disk,
                'path' => $path,
                'error' => $e->getMessage(),
            ]);

            return false;
        }
    }
}
