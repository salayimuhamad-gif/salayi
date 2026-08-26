<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Modules\Geography\Enums\AreaType;
use App\Modules\Geography\Models\Area;
use App\Modules\Geography\Models\Place;
use App\Modules\Geography\Models\PlaceCategory;
use App\Modules\Projects\Enums\PublicationStatus;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The public Area page's "Services in this area" summary (Map Phase 2).
 *
 * The numbers must come from the MULK places database under the map layer's
 * own public gates — published, public, duplicate-primary, operating — and
 * from THIS area's direct assignment, so a visitor can reconcile the count
 * with the list rendered beneath it. Everything the gates exclude is pinned
 * here by a counter-example.
 */
final class AreaServicesSummaryTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->setFeatures([
            'geography.areas' => true,
            'places.database' => true,
        ]);
    }

    private function area(string $slug = 'mufti'): Area
    {
        return Area::query()->create([
            'type' => AreaType::Neighborhood->value,
            'slug' => $slug,
            'name_ckb' => $slug,
            'publication_status' => PublicationStatus::Published->value,
        ]);
    }

    private function category(string $key, string $group, int $sortOrder = 0): PlaceCategory
    {
        return PlaceCategory::query()->firstOrCreate(
            ['key' => $key],
            [
                'group' => $group, 'name_ckb' => 'ckb '.$key, 'name_en' => 'en '.$key,
                'is_active' => true, 'sort_order' => $sortOrder,
            ],
        );
    }

    /** @param  array<model-property<Place>, mixed>  $attributes */
    private function place(Area $area, PlaceCategory $category, array $attributes = []): Place
    {
        static $n = 0;

        return Place::query()->create(array_merge([
            'name_ckb' => 'place-'.(++$n),
            'place_category_id' => $category->id,
            'area_id' => $area->id,
            'latitude' => 36.19,
            'longitude' => 44.01,
            'publication_status' => PublicationStatus::Published,
            'is_public' => true,
            'is_duplicate_primary' => true,
            'operational_status' => 'operating',
        ], $attributes));
    }

    public function test_counts_come_from_qualifying_places_only(): void
    {
        $area = $this->area();
        $other = $this->area('other-area');
        $school = $this->category('school', 'education', 1);
        $kindergarten = $this->category('kindergarten', 'education', 2);
        $pharmacy = $this->category('pharmacy', 'health', 3);

        // Qualify: two schools, one kindergarten, one pharmacy.
        $this->place($area, $school);
        $this->place($area, $school);
        $this->place($area, $kindergarten);
        $this->place($area, $pharmacy);

        // Every exclusion the product rules demand, one by one.
        $this->place($area, $school, ['publication_status' => PublicationStatus::Draft]);
        $this->place($area, $school, ['is_public' => false]);
        $this->place($area, $school, ['is_duplicate_primary' => false]);
        $this->place($area, $school, ['operational_status' => 'permanently_closed']);
        $this->place($other, $school);

        $this->get('/areas/mufti')->assertInertia(fn ($page) => $page
            ->where('services.0.key', 'education')
            ->where('services.0.count', 3)
            ->where('services.0.categories.0.key', 'school')
            ->where('services.0.categories.0.count', 2)
            ->where('services.0.categories.1.key', 'kindergarten')
            ->where('services.0.categories.1.count', 1)
            ->where('services.1.key', 'health')
            ->where('services.1.count', 1)
            // Only groups that actually have places arrive at all.
            ->count('services', 2));
    }

    public function test_labels_resolve_in_the_request_locale(): void
    {
        $area = $this->area();
        $this->place($area, $this->category('school', 'education'));

        $this->get('/en/areas/mufti')->assertInertia(fn ($page) => $page
            ->where('services.0.label', 'Education')
            ->where('services.0.categories.0.label', 'en school'));
    }

    public function test_an_area_with_no_qualifying_places_has_an_empty_summary(): void
    {
        $this->area();

        $this->get('/areas/mufti')->assertInertia(fn ($page) => $page
            ->where('services', []));
    }
}
