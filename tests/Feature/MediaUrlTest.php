<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Modules\Marketplace\Models\OfferMedia;
use App\Modules\Projects\Models\ProjectMedia;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * Both media surfaces must be able to produce a URL.
 *
 * `OfferMedia::url()` did not exist, yet the offer moderation queue and the
 * public offer browser both called it — so both pages died with "Call to
 * undefined method". Nothing caught it because neither had a test that reached
 * the media list. The behaviour now lives in one trait so the two cannot drift
 * apart again.
 */
final class MediaUrlTest extends TestCase
{
    use RefreshDatabase;

    public function test_offer_media_resolves_a_url(): void
    {
        Storage::fake('public');

        $media = new OfferMedia;
        $media->forceFill(['disk' => 'public', 'path' => 'offers/1/a.jpg']);

        $this->assertIsString($media->url());
        $this->assertStringContainsString('offers/1/a.jpg', (string) $media->url());
    }

    public function test_project_media_resolves_a_url(): void
    {
        Storage::fake('public');

        $media = new ProjectMedia;
        $media->forceFill(['disk' => 'public', 'path' => 'projects/1/a.jpg']);

        $this->assertStringContainsString('projects/1/a.jpg', (string) $media->url());
    }

    /** A row with no path has no URL — not an exception. */
    public function test_media_without_a_path_has_no_url(): void
    {
        $media = new OfferMedia;
        $media->forceFill(['disk' => 'public', 'path' => '']);

        $this->assertNull($media->url());
    }

    /**
     * An unconfigured disk yields null rather than throwing at a visitor.
     *
     * A disk renamed or removed from filesystems.php must degrade the image,
     * not the page.
     */
    public function test_an_unconfigured_disk_yields_no_url(): void
    {
        $media = new OfferMedia;
        $media->forceFill(['disk' => 'nonexistent-disk', 'path' => 'offers/1/a.jpg']);

        $this->assertNull($media->url());
    }
}
