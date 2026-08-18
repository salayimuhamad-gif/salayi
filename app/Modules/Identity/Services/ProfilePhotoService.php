<?php

declare(strict_types=1);

namespace App\Modules\Identity\Services;

use App\Modules\Identity\Models\User;
use GdImage;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use RuntimeException;
use Throwable;

/**
 * Profile photos (spec §4.1, correction §6.7), built on one principle:
 * NOTHING the uploader sent is ever served back — and now a second one:
 * nothing is DECODED unless the arithmetic says this process can afford it.
 *
 * The stored files are re-encodes: GD decodes the upload into pixels and
 * writes fresh JPEGs from those pixels, which strips every metadata block
 * and reduces any polyglot to its picture. Filenames are generated, the
 * extension is fixed, and the path is built from the user id and a random
 * segment — the original filename never touches the filesystem.
 *
 * Memory safety is decided BEFORE `imagecreatefromstring()`, from the
 * header alone: a hard pixel ceiling first, then an estimate of what the
 * decode and the two resize targets will cost against what this PHP
 * process actually has left under its memory_limit, with a conservative
 * margin. A 40 KB file can describe an image that needs a quarter of a
 * gigabyte to exist; on shared hosting the answer to that file is a
 * translated refusal, not an OOM.
 *
 * Replacement is ATOMIC in effect: the new files are fully written and
 * verified, then the database row moves to them in one save, and only then
 * does the old directory die. A failure at any point deletes whatever new
 * partials exist and leaves the previous photo — files and pointer —
 * exactly as it was. The profile can never point at a directory that does
 * not exist.
 */
final class ProfilePhotoService
{
    public const DISK = 'public';

    public const MAX_BYTES = 5 * 1024 * 1024;

    /**
     * Correction §6.7 asked for a safer production ceiling than 5000². 4096
     * on an edge is still far beyond any avatar's needs, and together with
     * the pixel cap below it bounds the decode arithmetic tightly.
     */
    public const MAX_DIMENSION = 4096;

    /** ~12.6 MP — a 4096×3072 photo fits; a decompression bomb does not. */
    public const MAX_PIXELS = 12_600_000;

    /**
     * Bytes per pixel GD actually spends on a truecolor decode plus working
     * slack (observed ≈5; 6 is the conservative side of measured reality).
     */
    private const BYTES_PER_PIXEL = 6;

    /** Fraction of the remaining memory headroom the decode may claim. */
    private const HEADROOM_FRACTION = 0.6;

    /**
     * Test seam: lets the suite shrink "available memory" to prove the
     * refusal path without allocating real gigabytes. Never set in
     * production code paths.
     */
    public static ?int $availableMemoryOverride = null;

    /** Profile display size and the chat thumbnail, per the spec. */
    private const SIZES = ['p256' => 256, 'p64' => 64];

    /**
     * Validate, re-encode, thumbnail and store; returns the directory path.
     *
     * Throws a ValidationException keyed to `photo` for every refusal, so
     * the form shows a translated message instead of a 500.
     */
    public function store(User $user, UploadedFile $upload): string
    {
        if ($upload->getSize() === false || $upload->getSize() > self::MAX_BYTES) {
            throw ValidationException::withMessages(['photo' => __('identity.profile.photo_too_large')]);
        }

        /*
         * The REAL type, read from content by finfo — never the extension and
         * never the client's Content-Type, both of which are claims the
         * uploader controls. An .exe renamed to .jpg fails here.
         */
        $mime = (string) $upload->getMimeType();

        if (! in_array($mime, ['image/jpeg', 'image/png', 'image/webp'], true)) {
            throw ValidationException::withMessages(['photo' => __('identity.profile.photo_type')]);
        }

        $raw = (string) file_get_contents($upload->getRealPath());

        /*
         * Dimensions from the HEADER, before any decoder runs. Every refusal
         * below is arithmetic on two integers.
         */
        $info = @getimagesizefromstring($raw);

        if ($info === false || $info[0] < 1 || $info[1] < 1) {
            throw ValidationException::withMessages(['photo' => __('identity.profile.photo_invalid')]);
        }

        [$width, $height] = [$info[0], $info[1]];

        if ($width > self::MAX_DIMENSION || $height > self::MAX_DIMENSION) {
            throw ValidationException::withMessages(['photo' => __('identity.profile.photo_dimensions')]);
        }

        if ($width * $height > self::MAX_PIXELS) {
            throw ValidationException::withMessages(['photo' => __('identity.profile.photo_pixels')]);
        }

        if (! self::decodeIsAffordable($width, $height, strlen($raw))) {
            throw ValidationException::withMessages(['photo' => __('identity.profile.photo_memory')]);
        }

        $image = @imagecreatefromstring($raw);

        if ($image === false) {
            // A file whose header lied about being decodable.
            throw ValidationException::withMessages(['photo' => __('identity.profile.photo_invalid')]);
        }

        // Phones store rotation as metadata; the re-encode drops metadata,
        // so the pixels must be turned first or every portrait lies down.
        $image = $this->applyOrientation($image, $mime, (string) $upload->getRealPath());

        $directory = sprintf('profile-photos/%d/%s', $user->id, Str::lower(Str::random(16)));
        $disk = Storage::disk(self::DISK);
        $previousPath = $user->profile_photo_path;
        $previousDisk = $user->profile_photo_disk;

        /*
         * ATOMIC-IN-EFFECT REPLACEMENT. Order is the whole point:
         *
         *   1. every new file fully written and read back;
         *   2. the row repointed in ONE save;
         *   3. only then the old directory removed.
         *
         * Any failure in (1) or (2) deletes the new partials and rethrows —
         * the previous photo, files and pointer alike, is untouched. A
         * failure in (3) leaves an orphan directory, never a broken profile.
         */
        try {
            foreach (self::SIZES as $name => $edge) {
                $square = $this->squareResize($image, $edge);

                ob_start();
                $encoded = imagejpeg($square, null, 82);
                $bytes = (string) ob_get_clean();
                imagedestroy($square);

                if (! $encoded || $bytes === '' || ! $disk->put("{$directory}/{$name}.jpg", $bytes)) {
                    throw new RuntimeException('profile photo write failed');
                }
            }

            foreach (array_keys(self::SIZES) as $name) {
                if (! $disk->exists("{$directory}/{$name}.jpg")) {
                    throw new RuntimeException('profile photo verification failed');
                }
            }

            $user->forceFill([
                'profile_photo_path' => $directory,
                'profile_photo_disk' => self::DISK,
            ])->save();
        } catch (ValidationException $exception) {
            $this->deleteQuietly(self::DISK, $directory);

            throw $exception;
        } catch (Throwable $exception) {
            $this->deleteQuietly(self::DISK, $directory);

            throw ValidationException::withMessages(['photo' => __('identity.profile.photo_failed')]);
        } finally {
            imagedestroy($image);
        }

        if ($previousPath !== null && $previousPath !== $directory) {
            $this->deleteQuietly($previousDisk ?? self::DISK, $previousPath);
        }

        return $directory;
    }

    /** Delete the stored photo, if any, and clear the columns. */
    public function remove(User $user): void
    {
        if ($user->profile_photo_path === null) {
            return;
        }

        $path = $user->profile_photo_path;
        $disk = $user->profile_photo_disk ?? self::DISK;

        // Pointer first, files second: the failure mode is an orphaned
        // directory, never a profile pointing at nothing.
        $user->forceFill([
            'profile_photo_path' => null,
            'profile_photo_disk' => null,
        ])->save();

        $this->deleteQuietly($disk, $path);
    }

    /**
     * Whether decoding W×H plus building both avatar sizes fits inside what
     * this process has left, with margin. The estimate is deliberately on
     * the expensive side; the cost of a false refusal is one translated
     * error message, the cost of a false accept is a dead worker.
     */
    public static function decodeIsAffordable(int $width, int $height, int $rawBytes = 0): bool
    {
        /*
         * Correction H7, the honest bill: TWO image-sized buffers, not one.
         * Every rotation (and GD keeps rotation for four of the eight EXIF
         * orientations) allocates a second full pixel buffer before the
         * first is freed — budgeting only one made the estimate a best-case
         * story. The raw upload bytes are counted too: they sit in memory
         * for the whole decode, which the old base64 EXIF read then DOUBLED
         * — that read is gone (the file path serves EXIF now), but the one
         * legitimate copy still deserves its line on the bill.
         */
        $needed = ($width * $height * 2 + (256 * 256) + (64 * 64)) * self::BYTES_PER_PIXEL
            + $rawBytes;

        $available = self::availableBudget();

        // No discernible limit means the platform did not constrain us;
        // the pixel cap above still bounds the worst case.
        if ($available === null) {
            return true;
        }

        return $needed <= (int) ($available * self::HEADROOM_FRACTION);
    }

    /**
     * The budget every image estimator shares — the test seam included, so
     * a suite that constrains the profile estimator constrains portfolio's
     * identically.
     */
    public static function availableBudget(): ?int
    {
        return self::$availableMemoryOverride ?? self::availableMemory();
    }

    /**
     * Public doorway to the orientation pipeline for OTHER image services
     * (portfolio media, correction H8): EXIF off the file path, the same
     * guards, the same proven table.
     */
    public function orientFromFile(GdImage $image, string $mime, string $path): GdImage
    {
        return $this->applyOrientation($image, $mime, $path);
    }

    private static function availableMemory(): ?int
    {
        $limit = self::memoryLimitBytes();

        if ($limit === null) {
            return null;
        }

        return max(0, $limit - memory_get_usage(true));
    }

    private static function memoryLimitBytes(): ?int
    {
        $raw = trim((string) ini_get('memory_limit'));

        if ($raw === '' || $raw === '-1') {
            return null;
        }

        $unit = strtolower(substr($raw, -1));
        $value = (float) $raw;

        return (int) match ($unit) {
            'g' => $value * 1024 ** 3,
            'm' => $value * 1024 ** 2,
            'k' => $value * 1024,
            default => $value,
        };
    }

    /**
     * Rotate/flip decoded pixels per the JPEG EXIF orientation tag, when the
     * platform can read it. Anything unreadable means "leave as decoded" —
     * a wrong guess would be worse than no correction.
     */
    private function applyOrientation(GdImage $image, string $mime, string $path): GdImage
    {
        if ($mime !== 'image/jpeg' || ! function_exists('exif_read_data') || ! is_file($path)) {
            return $image;
        }

        /*
         * Correction H7: EXIF comes off the FILE, streamed by the extension
         * itself. The old data:// read base64-encoded the entire upload — a
         * full extra in-memory copy, ~1.33× the raw size, that the
         * affordability sum never knew about. Malformed EXIF answers false
         * (silenced), which lands on orientation 1: a wrong guess would be
         * worse than no correction.
         */
        $exif = @exif_read_data($path);
        $orientation = is_array($exif) ? (int) ($exif['Orientation'] ?? 1) : 1;

        return $this->orientImage($image, $orientation);
    }

    /**
     * The pure transformation table for EXIF orientations 1–8 — separated so
     * the suite can prove each case pixel by pixel without authoring EXIF.
     *
     * Correction H7. The standard, in GD's vocabulary (imagerotate turns
     * COUNTER-clockwise for positive angles):
     *
     *   1  identity            2  flip horizontal
     *   3  rotate 180          4  flip VERTICAL — one flip, not two;
     *                             the old H-then-V pair was a 180 in disguise
     *   5  transpose           = rotate  -90 (CW),  then flip horizontal
     *   6  rotate -90 (CW)
     *   7  transverse          = rotate  -90 (CW),  then flip VERTICAL
     *   8  rotate  90 (CCW)
     *
     * 5 and 7 are order-sensitive: flip-then-rotate — the old sequence —
     * lands on the OTHER diagonal. The suite derives every expectation from
     * the coordinate definitions, so this table cannot quietly regress.
     */
    public function orientImage(GdImage $image, int $orientation): GdImage
    {
        switch ($orientation) {
            case 2:
                imageflip($image, IMG_FLIP_HORIZONTAL);

                return $image;
            case 4:
                imageflip($image, IMG_FLIP_VERTICAL);

                return $image;
            case 3:
            case 6:
            case 8:
            case 5:
            case 7:
                $angle = $orientation === 3 ? 180 : ($orientation === 8 ? 90 : -90);
                $rotated = imagerotate($image, $angle, 0);

                if (! $rotated instanceof GdImage) {
                    return $image;
                }

                imagedestroy($image);

                if ($orientation === 5) {
                    imageflip($rotated, IMG_FLIP_HORIZONTAL);
                }

                if ($orientation === 7) {
                    imageflip($rotated, IMG_FLIP_VERTICAL);
                }

                return $rotated;
            default:
                return $image;
        }
    }

    /**
     * Centre-crop to a square, then scale to the requested edge — the shape
     * every avatar slot in the interface renders.
     */
    private function squareResize(GdImage $source, int $edge): GdImage
    {
        $width = imagesx($source);
        $height = imagesy($source);
        $side = min($width, $height);
        $x = intdiv($width - $side, 2);
        $y = intdiv($height - $side, 2);

        $target = imagecreatetruecolor($edge, $edge);
        imagecopyresampled($target, $source, 0, 0, $x, $y, $edge, $edge, $side, $side);

        return $target;
    }

    private function deleteQuietly(string $disk, string $path): void
    {
        try {
            Storage::disk($disk)->deleteDirectory($path);
        } catch (Throwable) {
            // Cleanup best-effort: an orphan directory is the acceptable
            // failure mode everywhere in this service.
        }
    }
}
