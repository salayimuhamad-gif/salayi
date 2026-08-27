<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Http\Middleware\HandleInertiaRequests;
use App\Modules\Geography\Enums\AreaType;
use App\Modules\Geography\Models\Area;
use App\Modules\Geography\Models\Place;
use App\Modules\Geography\Models\PlaceCategory;
use App\Modules\Market\Models\MarketIndex;
use App\Modules\Market\Models\MarketIndexValue;
use App\Modules\Projects\Enums\PublicationStatus;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Wave 3: GET /location/resolve — "Know the Price Where You Are".
 *
 * The contract under proof, in one paragraph: coordinates go through the
 * EXISTING Coordinates rules, the point goes through the EXISTING
 * AreaResolver (published areas only, most specific match, published
 * ancestry, and — non-negotiably — NO nearest fallback), and the price
 * figures come from the map price layer's own latest-reliable MarketIndex
 * selection. An unresolvable point is an honest `outside_coverage`; a real
 * area with no compatible published price is an honest `no_data` that keeps
 * the area identity and never renders a zero; and nothing on this path
 * persists a coordinate anywhere.
 */
final class LocationResolveTest extends TestCase
{
    use RefreshDatabase;

    /** Inside every fixture polygon below. */
    private const LAT = 36.19;

    private const LNG = 44.01;

    /* -------------------------------------------------- resolution ------ */

    public function test_a_point_inside_a_published_polygon_resolves_to_that_area(): void
    {
        $this->makeBoundedArea('ankawa');

        $this->getJson('/location/resolve?lat='.self::LAT.'&lng='.self::LNG)
            ->assertOk()
            ->assertJsonPath('state', 'no_data')
            ->assertJsonPath('area.slug', 'ankawa')
            ->assertJsonPath('area.name', 'ankawa')
            ->assertHeader('Cache-Control', 'no-store, private');
    }

    public function test_the_most_specific_published_polygon_wins(): void
    {
        $city = $this->makeBoundedArea('erbil-city', AreaType::City, self::WIDE_POLYGON);
        $this->makeBoundedArea('inner-district', AreaType::District, self::TIGHT_POLYGON, $city->id);

        $this->getJson('/location/resolve?lat='.self::LAT.'&lng='.self::LNG)
            ->assertOk()
            ->assertJsonPath('area.slug', 'inner-district')
            // The published city forms the hierarchy context, coarsest first.
            ->assertJsonPath('area.breadcrumb.0.name', 'erbil-city');
    }

    public function test_an_unpublished_area_is_never_exposed(): void
    {
        $this->makeBoundedArea('draft-area', AreaType::District, self::TIGHT_POLYGON, null, PublicationStatus::Draft);

        $this->getJson('/location/resolve?lat='.self::LAT.'&lng='.self::LNG)
            ->assertOk()
            ->assertJsonPath('state', 'outside_coverage')
            ->assertJsonPath('area', null)
            ->assertJsonMissing(['slug' => 'draft-area']);
    }

    public function test_unpublished_ancestry_yields_the_finest_publishable_ancestor_not_the_precise_match(): void
    {
        $city = $this->makeBoundedArea('published-city', AreaType::City, self::WIDE_POLYGON);
        $draftDistrict = $this->makeBoundedArea('draft-district', AreaType::District, self::TIGHT_POLYGON, $city->id, PublicationStatus::Draft);
        $this->makeBoundedArea('published-neighborhood', AreaType::Neighborhood, self::TIGHT_POLYGON, $draftDistrict->id);

        // The published neighbourhood contains the point, but naming it would
        // disclose its unpublished district through the breadcrumb. Coarser
        // but true beats precise but undisclosable: the city answers.
        $this->getJson('/location/resolve?lat='.self::LAT.'&lng='.self::LNG)
            ->assertOk()
            ->assertJsonPath('area.slug', 'published-city')
            ->assertJsonMissing(['slug' => 'published-neighborhood'])
            ->assertJsonMissing(['name' => 'draft-district']);
    }

    public function test_a_point_outside_every_polygon_is_an_honest_outside_state(): void
    {
        $this->makeBoundedArea('ankawa');

        // Inside the operating area, outside the polygon.
        $this->getJson('/location/resolve?lat=36.30&lng=44.30')
            ->assertOk()
            ->assertJsonPath('state', 'outside_coverage')
            ->assertJsonPath('area', null)
            ->assertJsonPath('prices', null);
    }

    public function test_no_nearest_area_fallback_exists_even_metres_from_a_boundary(): void
    {
        // Polygon spans lng 44.000–44.020; this point sits ~90 m east of it.
        $this->makeBoundedArea('ankawa');

        $this->getJson('/location/resolve?lat=36.19&lng=44.021')
            ->assertOk()
            ->assertJsonPath('state', 'outside_coverage')
            ->assertJsonMissing(['slug' => 'ankawa']);
    }

    /* -------------------------------------------------- validation ------ */

    public function test_missing_or_malformed_coordinates_are_rejected(): void
    {
        $this->getJson('/location/resolve')->assertStatus(422);
        $this->getJson('/location/resolve?lat=36.19')->assertStatus(422);
        $this->getJson('/location/resolve?lat=abc&lng=44.01')->assertStatus(422);
        $this->getJson('/location/resolve?lat=95&lng=44.01')->assertStatus(422);
        $this->getJson('/location/resolve?lat=36.19&lng=181')->assertStatus(422);
    }

    public function test_swapped_coordinates_are_rejected_by_the_existing_heuristic(): void
    {
        // Erbil's pair reversed: outside the operating area, but the swap
        // lands inside it — the exact signature Coordinates::looksSwapped()
        // exists to catch.
        $this->getJson('/location/resolve?lat=44.01&lng=36.19')
            ->assertStatus(422)
            ->assertJsonValidationErrors(['lat']);
    }

    public function test_null_island_is_rejected(): void
    {
        $this->getJson('/location/resolve?lat=0&lng=0')
            ->assertStatus(422)
            ->assertJsonValidationErrors(['lat']);
    }

    public function test_a_point_outside_the_operating_area_is_outside_coverage_not_an_error(): void
    {
        $this->makeBoundedArea('ankawa');

        // London: a true statement about the visitor, not a typing mistake.
        $this->getJson('/location/resolve?lat=51.5&lng=-0.12')
            ->assertOk()
            ->assertJsonPath('state', 'outside_coverage');
    }

    /* -------------------------------------------- price intelligence ---- */

    public function test_a_resolved_area_with_a_published_index_returns_the_real_figure(): void
    {
        $this->setFeatures(['market.indices' => true]);

        $area = $this->makeBoundedArea('ankawa');
        $this->makeIndexWithValue($area->id, 'ankawa-sale');

        $this->getJson('/location/resolve?lat='.self::LAT.'&lng='.self::LNG)
            ->assertOk()
            ->assertJsonPath('state', 'resolved')
            ->assertJsonPath('prices.area_name', 'ankawa')
            ->assertJsonPath('prices.indices.0.key', 'ankawa-sale')
            ->assertJsonPath('prices.indices.0.value', '1250.0000')
            ->assertJsonPath('prices.indices.0.currency', 'USD')
            ->assertJsonPath('prices.indices.0.price_type', 'sale_asking')
            ->assertJsonPath('prices.indices.0.requires_qualifier', true)
            ->assertJsonPath('prices.indices.0.sample_size', 30)
            ->assertJsonPath('prices.indices.0.confidence', 'moderate');
    }

    public function test_a_resolved_area_with_no_compatible_price_returns_no_data_not_zero(): void
    {
        $this->setFeatures(['market.indices' => true]);

        $this->makeBoundedArea('ankawa');

        $response = $this->getJson('/location/resolve?lat='.self::LAT.'&lng='.self::LNG)
            ->assertOk()
            ->assertJsonPath('state', 'no_data')
            ->assertJsonPath('area.slug', 'ankawa')
            ->assertJsonPath('prices', null)
            ->assertJsonPath('prices_reason', 'no_published_values');

        // Absence must never surface as a figure of 0.
        $this->assertStringNotContainsString('"value":"0"', $response->getContent());
    }

    public function test_a_disabled_market_indices_flag_reads_as_feature_disabled_not_no_data(): void
    {
        // The flag ships OFF; state it explicitly so this test keeps meaning
        // if the default ever changes.
        $this->setFeatures(['market.indices' => false]);

        $area = $this->makeBoundedArea('ankawa');
        $this->makeIndexWithValue($area->id, 'ankawa-sale');

        $this->getJson('/location/resolve?lat='.self::LAT.'&lng='.self::LNG)
            ->assertOk()
            ->assertJsonPath('state', 'no_data')
            ->assertJsonPath('prices_reason', 'feature_disabled');
    }

    public function test_unpublished_and_limited_price_evidence_is_never_exposed(): void
    {
        $this->setFeatures(['market.indices' => true]);

        $area = $this->makeBoundedArea('ankawa');

        // A draft index with a perfectly published value: invisible.
        $this->makeIndexWithValue($area->id, 'draft-index', indexAttributes: ['publication_status' => 'draft']);

        $this->getJson('/location/resolve?lat='.self::LAT.'&lng='.self::LNG)
            ->assertOk()
            ->assertJsonPath('state', 'no_data')
            ->assertJsonMissing(['key' => 'draft-index']);

        // A published index whose NEWER value is draft, and newer still is
        // limited: the latest RELIABLE period answers — never the draft, never
        // the limited row, never a fabricated blank.
        $index = $this->makeIndexWithValue($area->id, 'ankawa-sale', ['period' => '2026-05', 'effective_date' => '2026-05-31', 'value' => '1100.0000']);
        $this->makeValue($index->id, ['period' => '2026-06', 'effective_date' => '2026-06-30', 'value' => '9999.0000', 'publication_status' => 'draft']);
        $this->makeValue($index->id, ['period' => '2026-07', 'effective_date' => '2026-07-31', 'value' => '8888.0000', 'is_limited' => true]);

        $this->getJson('/location/resolve?lat='.self::LAT.'&lng='.self::LNG)
            ->assertOk()
            ->assertJsonPath('state', 'resolved')
            ->assertJsonPath('prices.indices.0.period', '2026-05')
            ->assertJsonPath('prices.indices.0.value', '1100.0000')
            ->assertJsonMissing(['value' => '9999.0000'])
            ->assertJsonMissing(['value' => '8888.0000']);
    }

    public function test_incompatible_indices_stay_separate_rows_and_are_never_mixed(): void
    {
        $this->setFeatures(['market.indices' => true]);

        $area = $this->makeBoundedArea('ankawa');
        $this->makeIndexWithValue($area->id, 'ankawa-sale-usd', valueAttributes: ['value' => '1250.0000']);
        $this->makeIndexWithValue($area->id, 'ankawa-rent-iqd', indexAttributes: ['price_type' => 'rent_asking', 'currency' => 'IQD'], valueAttributes: ['value' => '650000.0000']);

        // Each figure travels with its own provenance, transaction basis and
        // currency; the two originals survive verbatim (ordered by key) and
        // no third, blended figure exists anywhere in the payload.
        $this->getJson('/location/resolve?lat='.self::LAT.'&lng='.self::LNG)
            ->assertOk()
            ->assertJsonCount(2, 'prices.indices')
            ->assertJsonPath('prices.indices.0.key', 'ankawa-rent-iqd')
            ->assertJsonPath('prices.indices.0.price_type', 'rent_asking')
            ->assertJsonPath('prices.indices.0.currency', 'IQD')
            ->assertJsonPath('prices.indices.0.value', '650000.0000')
            ->assertJsonPath('prices.indices.1.key', 'ankawa-sale-usd')
            ->assertJsonPath('prices.indices.1.price_type', 'sale_asking')
            ->assertJsonPath('prices.indices.1.currency', 'USD')
            ->assertJsonPath('prices.indices.1.value', '1250.0000');
    }

    public function test_price_intelligence_falls_back_only_to_a_genuinely_containing_published_ancestor(): void
    {
        $this->setFeatures(['market.indices' => true]);

        $city = $this->makeBoundedArea('erbil-city', AreaType::City, self::WIDE_POLYGON);
        $this->makeBoundedArea('inner-district', AreaType::District, self::TIGHT_POLYGON, $city->id);
        $this->makeIndexWithValue($city->id, 'city-index');

        // Identity stays the precise match; the figures say which containing
        // area they describe. Containment is real (hierarchy), never nearest.
        $this->getJson('/location/resolve?lat='.self::LAT.'&lng='.self::LNG)
            ->assertOk()
            ->assertJsonPath('state', 'resolved')
            ->assertJsonPath('area.slug', 'inner-district')
            ->assertJsonPath('prices.area_name', 'erbil-city')
            ->assertJsonPath('prices.indices.0.key', 'city-index');
    }

    /* ------------------------------------------------- localization ----- */

    public function test_area_names_localize_through_the_existing_trilingual_fields(): void
    {
        $area = $this->makeBoundedArea('ankawa');
        $area->forceFill(['name_ckb' => 'ئەنکاوە', 'name_ar' => 'عنكاوة', 'name_en' => 'Ankawa'])->save();

        $this->getJson('/location/resolve?lat='.self::LAT.'&lng='.self::LNG)
            ->assertJsonPath('area.name', 'ئەنکاوە');

        $this->getJson('/ar/location/resolve?lat='.self::LAT.'&lng='.self::LNG)
            ->assertJsonPath('area.name', 'عنكاوة');

        $this->getJson('/en/location/resolve?lat='.self::LAT.'&lng='.self::LNG)
            ->assertJsonPath('area.name', 'Ankawa');
    }

    public function test_a_missing_translation_falls_back_to_sorani_never_to_a_blank(): void
    {
        $area = $this->makeBoundedArea('ankawa');
        $area->forceFill(['name_ckb' => 'ئەنکاوە', 'name_ar' => null, 'name_en' => null])->save();

        $this->getJson('/ar/location/resolve?lat='.self::LAT.'&lng='.self::LNG)
            ->assertJsonPath('area.name', 'ئەنکاوە');
    }

    /* ----------------------------------------------- manual fallback ---- */

    public function test_a_published_area_can_be_chosen_manually_under_the_same_rules(): void
    {
        $this->setFeatures(['market.indices' => true]);

        $area = $this->makeBoundedArea('ankawa');
        $this->makeIndexWithValue($area->id, 'ankawa-sale');

        $this->getJson('/location/resolve?area=ankawa')
            ->assertOk()
            ->assertJsonPath('state', 'resolved')
            ->assertJsonPath('area.slug', 'ankawa')
            ->assertJsonPath('prices.indices.0.key', 'ankawa-sale');
    }

    public function test_manual_selection_discloses_nothing_unpublished(): void
    {
        $this->makeBoundedArea('draft-area', AreaType::District, self::TIGHT_POLYGON, null, PublicationStatus::Draft);
        $this->getJson('/location/resolve?area=draft-area')->assertNotFound();

        $this->getJson('/location/resolve?area=does-not-exist')->assertNotFound();

        // Published child under an unpublished parent: reachable by slug
        // guessing only, and the answer must not differ from "no such area".
        $draftParent = $this->makeBoundedArea('draft-parent', AreaType::District, self::WIDE_POLYGON, null, PublicationStatus::Draft);
        $this->makeBoundedArea('published-child', AreaType::Neighborhood, self::TIGHT_POLYGON, $draftParent->id);
        $this->getJson('/location/resolve?area=published-child')->assertNotFound();
    }

    public function test_coordinates_and_manual_selection_are_mutually_exclusive(): void
    {
        $this->makeBoundedArea('ankawa');

        $this->getJson('/location/resolve?area=ankawa&lat='.self::LAT.'&lng='.self::LNG)
            ->assertStatus(422);
    }

    /* ------------------------------------------- services (Map Phase 3) - */

    public function test_the_area_payload_carries_the_service_summary_in_both_modes(): void
    {
        $area = $this->makeBoundedArea('ankawa');

        $school = PlaceCategory::query()->firstOrCreate(
            ['key' => 'school'],
            ['group' => 'education', 'name_ckb' => 'قوتابخانە', 'is_active' => true, 'sort_order' => 1],
        );

        $this->makePlace($area, $school, 'school-open');

        // One gated-out counter-example. The full gate matrix is proven in
        // AreaServicesSummaryTest; this pins that the resolve payload
        // answers from THAT implementation, not a second counting path.
        $this->makePlace($area, $school, 'school-draft', [
            'publication_status' => PublicationStatus::Draft,
        ]);

        foreach ([
            '/location/resolve?lat='.self::LAT.'&lng='.self::LNG,
            '/location/resolve?area=ankawa',
        ] as $url) {
            $this->getJson($url)
                ->assertOk()
                ->assertJsonPath('area.services.0.key', 'education')
                ->assertJsonPath('area.services.0.count', 1)
                ->assertJsonPath('area.services.0.categories.0.key', 'school')
                ->assertJsonPath('area.services.0.categories.0.count', 1)
                ->assertJsonCount(1, 'area.services');
        }
    }

    public function test_an_area_with_no_qualifying_places_carries_an_empty_services_list(): void
    {
        $this->makeBoundedArea('ankawa');

        $this->getJson('/location/resolve?area=ankawa')
            ->assertOk()
            ->assertJsonPath('state', 'no_data')
            ->assertJsonPath('area.services', []);
    }

    /* -------------------------------------------------- rate limiting --- */

    public function test_the_named_rate_limiter_answers_429_beyond_the_budget(): void
    {
        $this->makeBoundedArea('ankawa');

        foreach (range(1, 30) as $i) {
            $this->getJson('/location/resolve?lat='.self::LAT.'&lng='.self::LNG)->assertOk();
        }

        $this->getJson('/location/resolve?lat='.self::LAT.'&lng='.self::LNG)
            ->assertStatus(429);
    }

    public function test_the_location_limiter_is_its_own_bucket_not_the_map_ones(): void
    {
        $this->setFeatures(['map.explorer' => true]);
        $this->makeBoundedArea('ankawa');

        foreach (range(1, 30) as $i) {
            $this->getJson('/location/resolve?lat='.self::LAT.'&lng='.self::LNG)->assertOk();
        }

        // The location budget is spent; the map budgets must be untouched.
        $this->getJson('/map/features?west=43.7&south=35.9&east=44.4&north=36.5&zoom=12')
            ->assertOk();
    }

    /* ------------------------------------------------------ privacy ----- */

    public function test_resolving_persists_nothing(): void
    {
        $this->setFeatures(['market.indices' => true]);

        $area = $this->makeBoundedArea('ankawa');
        $this->makeIndexWithValue($area->id, 'ankawa-sale');

        $writes = [];

        DB::listen(function ($query) use (&$writes): void {
            if (preg_match('/^\s*(insert|update|delete|replace)\b/i', $query->sql) === 1) {
                $writes[] = $query->sql;
            }
        });

        $this->getJson('/location/resolve?lat='.self::LAT.'&lng='.self::LNG)->assertOk();
        $this->getJson('/location/resolve?lat=36.30&lng=44.30')->assertOk();
        $this->getJson('/location/resolve?area=ankawa')->assertOk();

        $this->assertSame([], $writes, 'The resolve endpoint must never write — coordinates are resolved and discarded.');
    }

    /* -------------------------------------- homepage manual-area prop ---- */

    public function test_the_homepage_defers_the_manual_area_list_and_serves_published_areas_only(): void
    {
        $city = $this->makeBoundedArea('erbil-city', AreaType::City, self::WIDE_POLYGON);
        $this->makeBoundedArea('inner-district', AreaType::District, self::TIGHT_POLYGON, $city->id);
        $draft = $this->makeBoundedArea('draft-parent', AreaType::District, self::TIGHT_POLYGON, null, PublicationStatus::Draft);
        $this->makeBoundedArea('child-of-draft', AreaType::Neighborhood, self::TIGHT_POLYGON, $draft->id);

        // Absent from the initial load — it travels only on request.
        $this->get('/')
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('Public/Home')
                ->missing('location_areas'));

        $partial = $this->get('/', [
            'X-Inertia' => 'true',
            'X-Inertia-Version' => (string) (new HandleInertiaRequests)->version(request()),
            'X-Inertia-Partial-Component' => 'Public/Home',
            'X-Inertia-Partial-Data' => 'location_areas',
        ])->assertOk();

        $slugs = $partial->json('props.location_areas.*.slug');

        $this->assertIsArray($slugs);
        $this->assertContains('erbil-city', $slugs);
        $this->assertContains('inner-district', $slugs);
        $this->assertNotContains('draft-parent', $slugs);
        // Published, but its parent is not: the directory rule applies.
        $this->assertNotContains('child-of-draft', $slugs);
    }

    /* ------------------------------------------------------ fixtures ---- */

    /** lng 43.98–44.06 × lat 36.15–36.23 — comfortably around the test point. */
    private const WIDE_POLYGON = 'POLYGON((43.980 36.150, 44.060 36.150, 44.060 36.230, 43.980 36.230, 43.980 36.150))';

    /** lng 44.000–44.020 × lat 36.180–36.200 — the MapExplorerTest fixture ring. */
    private const TIGHT_POLYGON = 'POLYGON((44.000 36.180, 44.020 36.180, 44.020 36.200, 44.000 36.200, 44.000 36.180))';

    private function makeBoundedArea(
        string $slug,
        AreaType $type = AreaType::District,
        string $wkt = self::TIGHT_POLYGON,
        ?int $parentId = null,
        PublicationStatus $status = PublicationStatus::Published,
    ): Area {
        $area = Area::query()->create([
            'type' => $type->value,
            'slug' => $slug,
            'name_ckb' => $slug,
            'parent_id' => $parentId,
            'latitude' => self::LAT,
            'longitude' => self::LNG,
            'publication_status' => $status->value,
        ]);

        $area->forceFill(['boundary_wkt' => $wkt])->save();

        return $area->refresh();
    }

    /**
     * @param  array<model-property<MarketIndexValue>, mixed>  $valueAttributes
     * @param  array<model-property<MarketIndex>, mixed>  $indexAttributes
     */
    private function makeIndexWithValue(int $areaId, string $key, array $valueAttributes = [], array $indexAttributes = []): MarketIndex
    {
        $index = MarketIndex::query()->create(array_merge([
            'key' => $key,
            'name_ckb' => $key,
            'methodology_version' => 'v1',
            'scope_type' => 'area',
            'scope_id' => $areaId,
            'price_type' => 'sale_asking',
            'currency' => 'USD',
            'publication_status' => 'published',
        ], $indexAttributes));

        $this->makeValue($index->id, $valueAttributes);

        return $index;
    }

    /** @param  array<model-property<MarketIndexValue>, mixed>  $attributes */
    private function makeValue(int $indexId, array $attributes = []): void
    {
        MarketIndexValue::query()->create(array_merge([
            'market_index_id' => $indexId,
            'period' => '2026-06',
            'effective_date' => '2026-06-30',
            'methodology_version' => 'v1',
            'value' => '1250.0000',
            'publication_status' => 'published',
            'is_limited' => false,
            'sample_size' => 30,
            // A REAL confidence level (IndexCalculator's vocabulary), because
            // the public card translates it — an invented level renders as a
            // raw market.confidence.* key.
            'confidence' => 'moderate',
        ], $attributes));
    }

    /**
     * A place that passes every public-pin gate unless an override says
     * otherwise — the same defaults AreaServicesSummaryTest builds on.
     *
     * @param  array<model-property<Place>, mixed>  $attributes
     */
    private function makePlace(Area $area, PlaceCategory $category, string $name, array $attributes = []): Place
    {
        return Place::query()->create(array_merge([
            'name_ckb' => $name,
            'place_category_id' => $category->id,
            'area_id' => $area->id,
            'latitude' => self::LAT,
            'longitude' => self::LNG,
            'publication_status' => PublicationStatus::Published,
            'is_public' => true,
            'is_duplicate_primary' => true,
            'operational_status' => 'operating',
        ], $attributes));
    }
}
