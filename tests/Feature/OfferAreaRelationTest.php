<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Modules\Geography\Enums\AreaType;
use App\Modules\Geography\Models\Area;
use App\Modules\Marketplace\Models\Offer;
use App\Modules\Projects\Enums\PublicationStatus;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * An offer must be able to name the area it sits in.
 *
 * `offers.area_id` has existed since the marketplace tables were created, and
 * `OfferBrowseController` renders `$offer->area?->name()` — but no `area()`
 * relation was ever declared. The nullsafe operator turned the missing relation
 * into null, so every public listing showed no area and nothing failed loudly.
 */
final class OfferAreaRelationTest extends TestCase
{
    use RefreshDatabase;

    private function offer(?int $areaId): Offer
    {
        $offer = new Offer;

        $offer->fill([
            'title_ckb' => 'ماڵ',
            'offer_type' => 'sale',
            'property_type' => 'apartment',
            'status' => 'draft',
        ]);

        $offer->forceFill([
            'public_id' => (string) Str::uuid(),
            'area_id' => $areaId,
        ])->save();

        return $offer->refresh();
    }

    public function test_an_offer_resolves_its_area(): void
    {
        $area = Area::query()->create([
            'type' => AreaType::District->value,
            'slug' => 'ankawa',
            'name_ckb' => 'ئانکاوا',
            'publication_status' => PublicationStatus::Published->value,
        ]);

        $offer = $this->offer($area->id);

        $this->assertNotNull($offer->area, 'The area relation should resolve.');
        $this->assertSame($area->id, $offer->area->id);
        $this->assertSame('ئانکاوا', $offer->area->name('ckb'));
    }

    /** An offer with no area is still valid; it simply has none. */
    public function test_an_offer_without_an_area_resolves_to_null(): void
    {
        $this->assertNull($this->offer(null)->area);
    }
}
