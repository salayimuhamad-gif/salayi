<?php

declare(strict_types=1);

namespace App\Modules\Core\Concerns;

use Illuminate\Support\Facades\Storage;
use Throwable;

/**
 * A public URL for a stored media row, or null when there cannot be one.
 *
 * `ProjectMedia` had this; `OfferMedia` did not — yet the offer moderation
 * queue and the public offer browser both called `$media->url()`, so both pages
 * died with "Call to undefined method". Nothing caught it because neither page
 * had a test that reached the media list.
 *
 * Shared rather than copied, so the two media surfaces cannot drift apart
 * again: a disk that is not configured, or a disk with no `url` entry, must
 * yield null on both instead of throwing at a visitor.
 */
trait ResolvesMediaUrl
{
    public function url(): ?string
    {
        $path = (string) ($this->path ?? '');

        if ($path === '') {
            return null;
        }

        $disk = (string) ($this->disk ?: 'public');

        if (config("filesystems.disks.{$disk}") === null) {
            return null;
        }

        try {
            $storage = Storage::disk($disk);

            // providesTemporaryUrls() is not the question; a disk without a
            // configured `url` throws from url(), so the attempt is guarded
            // rather than predicted.
            return $storage->url($path);
        } catch (Throwable) {
            return null;
        }
    }
}
