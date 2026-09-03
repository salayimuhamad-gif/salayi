<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Modules\Geography\Enums\AreaType;
use App\Modules\Geography\Models\Area;
use App\Modules\Identity\Models\User;
use App\Modules\Market\Models\MarketIndex;
use App\Modules\Market\Models\MarketIndexValue;
use App\Modules\Projects\Enums\ConstructionStatus;
use App\Modules\Projects\Enums\DeliveryStatus;
use App\Modules\Projects\Enums\PublicationStatus;
use App\Modules\Projects\Models\Project;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Canonical lowercase area slugs against legacy mixed-case rows.
 *
 * Production stored `EBL-CITY` while the public routes constrain `{slug}` to
 * `[a-z0-9\-]+`. Every surface then linked the RAW column value, so clicking
 * the only published city opened a 404 — and the lowercase URL that DOES
 * match the route resolved the row only under MySQL's ci collation, never on
 * SQLite. The contract pinned here: rows are never rewritten, every public
 * payload emits `Area::publicSlug()`, and every slug-addressed public lookup
 * resolves case-insensitively on every engine.
 *
 * The zoom matrix rides along because the same legacy row exposed it: area
 * POINTS are served at every zoom while polygons are gated at the boundary
 * threshold, and the two payloads must carry one slug casing so the client
 * can pair them.
 */
final class AreaSlugCanonicalizationTest extends TestCase
{
    use RefreshDatabase;

    private const VIEWPORT = [
        'north' => 36.60, 'south' => 35.80, 'east' => 44.50, 'west' => 43.50,
    ];

    private const RING = 'POLYGON((44.000 36.180, 44.020 36.180, 44.020 36.200, 44.000 36.200, 44.000 36.180))';

    protected function setUp(): void
    {
        parent::setUp();

        $this->setFeatures([
            'geography.areas' => true,
            'map.explorer' => true,
            'map.investment' => true,
            'market.intelligence' => true,
            // The prices LAYER rides its own flag, separate from the
            // movement/heat product above.
            'market.indices' => true,
        ]);
    }

    /**
     * The production shape verbatim: an uppercase slug written before any
     * normalisation existed. Created through the model on purpose — nothing
     * in the write path may quietly rewrite a row that is not passing
     * through the admin form.
     */
    private function legacyUppercaseArea(): Area
    {
        $area = Area::query()->create([
            'type' => AreaType::City->value,
            'slug' => 'EBL-CITY',
            'name_ckb' => 'هەولێر',
            'name_en' => 'Erbil City',
            'latitude' => 36.1914610,
            'longitude' => 44.0107357,
            'publication_status' => PublicationStatus::Published->value,
        ]);

        $area->forceFill(['boundary_wkt' => self::RING])->save();

        return $area->refresh();
    }

    private function publishedProjectIn(Area $area): Project
    {
        return Project::query()->create([
            'slug' => 'p-'.uniqid(),
            'name_ckb' => 'پرۆژە',
            'area_id' => $area->id,
            'project_type' => 'residential',
            'construction_status' => ConstructionStatus::Planning->value,
            'delivery_status' => DeliveryStatus::cases()[0]->value,
            'publication_status' => PublicationStatus::Published->value,
        ]);
    }

    /** A published two-value index so movement/heat rows exist for the area. */
    private function risingIndexOn(Area $area): void
    {
        $index = MarketIndex::query()->create([
            'key' => 'canon-'.mb_strtolower($area->slug),
            'name_ckb' => 'پێوەر',
            'scope_type' => 'area',
            'scope_id' => $area->id,
            'property_type' => null,
            'price_type' => 'sale_asking',
            'basis' => 'median',
            'currency' => 'USD',
            'methodology_version' => 'v1',
            'minimum_sample' => 3,
            'publication_status' => 'published',
        ]);

        foreach ([['2026-06', '100000'], ['2026-07', '110000']] as [$period, $value]) {
            MarketIndexValue::query()->create([
                'market_index_id' => $index->id,
                'period' => $period,
                'effective_date' => $period.'-01',
                'value' => $value,
                'sample_size' => 12,
                'excluded_outliers' => 0,
                'confidence' => 'moderate',
                'is_limited' => false,
                'methodology_version' => 'v1',
                'revision_status' => 'initial',
                'revision_number' => 0,
                'publication_status' => 'published',
            ]);
        }
    }

    /* ------------------------------------------------- the stored premise */

    /** No migration, mutator or observer may rewrite the legacy row. */
    public function test_the_legacy_row_keeps_its_stored_casing(): void
    {
        $area = $this->legacyUppercaseArea();

        $this->assertSame('EBL-CITY', $area->slug);
        $this->assertSame('ebl-city', $area->publicSlug());
    }

    /* -------------------------------------------------- profile resolution */

    public function test_the_lowercase_profile_url_resolves_in_every_locale(): void
    {
        $this->legacyUppercaseArea();

        $this->get('/areas/ebl-city')->assertSuccessful();
        $this->get('/ar/areas/ebl-city')->assertSuccessful();
        $this->get('/en/areas/ebl-city')->assertSuccessful();
    }

    /** The route constraint is the contract; uppercase URLs stay refused. */
    public function test_the_uppercase_url_still_misses_the_route(): void
    {
        $this->legacyUppercaseArea();

        $this->get('/areas/EBL-CITY')->assertNotFound();
        $this->get('/ar/areas/EBL-CITY')->assertNotFound();
    }

    public function test_the_profile_identity_canonical_and_alternates_are_lowercase(): void
    {
        $this->legacyUppercaseArea();

        $this->get('/areas/ebl-city')->assertInertia(function ($page): void {
            $page->component('Public/Areas/Show')
                ->where('area.slug', 'ebl-city');

            $seo = $page->toArray()['props']['seo'];

            $this->assertStringEndsWith('/areas/ebl-city', $seo['canonical']);

            foreach ($seo['alternates'] as $alternate) {
                $this->assertStringEndsWith('/areas/ebl-city', $alternate['href']);
            }
        });
    }

    public function test_breadcrumbs_and_child_links_emit_the_canonical_slug(): void
    {
        $city = $this->legacyUppercaseArea();

        Area::query()->create([
            'type' => AreaType::District->value,
            'slug' => 'SUB-DISTRICT',
            'name_ckb' => 'گەڕەک',
            'parent_id' => $city->id,
            'publication_status' => PublicationStatus::Published->value,
        ]);

        $this->get('/areas/sub-district')->assertInertia(
            fn ($page) => $page
                ->where('area.slug', 'sub-district')
                ->where('breadcrumb.0.slug', 'ebl-city'),
        );

        $this->get('/areas/ebl-city')->assertInertia(
            fn ($page) => $page->where('children.0.slug', 'sub-district'),
        );
    }

    /* ------------------------------------------------ directory and home */

    public function test_the_directory_and_home_page_emit_the_canonical_slug(): void
    {
        $area = $this->legacyUppercaseArea();
        $this->publishedProjectIn($area);

        $this->get('/areas')->assertInertia(
            fn ($page) => $page->where('groups.0.areas.0.slug', 'ebl-city'),
        );

        $this->get('/')->assertInertia(
            fn ($page) => $page->where('areas.items.0.slug', 'ebl-city'),
        );
    }

    /* ------------------------------------------------------- map payloads */

    /**
     * Point rows and polygon properties are paired client-side by slug
     * equality — one canonical casing for both, at every zoom. The zoom legs
     * also pin the boundary-gate contract the explorer and invest surfaces
     * rely on: below the threshold the point STAYS while the polygon goes.
     */
    public function test_map_features_emit_one_canonical_slug_above_and_below_the_boundary_gate(): void
    {
        $this->legacyUppercaseArea();

        $this->getJson('/map/features?'.http_build_query(self::VIEWPORT + [
            'layers' => ['areas'],
            'zoom' => 13,
        ]))
            ->assertJsonCount(1, 'areas')
            ->assertJsonPath('areas.0.slug', 'ebl-city')
            ->assertJsonCount(1, 'boundaries.features')
            ->assertJsonPath('boundaries.features.0.properties.slug', 'ebl-city');

        $this->getJson('/map/features?'.http_build_query(self::VIEWPORT + [
            'layers' => ['areas'],
            'zoom' => 9,
        ]))
            ->assertJsonCount(1, 'areas')
            ->assertJsonPath('areas.0.slug', 'ebl-city')
            ->assertJsonPath('boundaries.features', []);
    }

    /** The invest surface consumes the same contract for its area context. */
    public function test_invest_features_keep_the_area_point_below_the_boundary_gate(): void
    {
        $this->legacyUppercaseArea();

        $this->getJson('/invest/features?'.http_build_query(self::VIEWPORT + [
            'layers' => ['projects', 'areas'],
            'zoom' => 10,
        ]))
            ->assertJsonCount(1, 'areas')
            ->assertJsonPath('areas.0.slug', 'ebl-city')
            ->assertJsonPath('boundaries.features', []);

        $this->getJson('/invest/features?'.http_build_query(self::VIEWPORT + [
            'layers' => ['projects', 'areas'],
            'zoom' => 12,
        ]))
            ->assertJsonCount(1, 'areas')
            ->assertJsonCount(1, 'boundaries.features');
    }

    public function test_price_and_heat_rows_pair_with_the_lowercase_polygon_identity(): void
    {
        $area = $this->legacyUppercaseArea();
        $this->risingIndexOn($area);

        $this->getJson('/map/features?'.http_build_query(self::VIEWPORT + [
            'layers' => ['prices'],
            'zoom' => 13,
        ]))->assertJsonPath('prices.0.area_slug', 'ebl-city');

        $this->getJson('/map/market?'.http_build_query(self::VIEWPORT))
            ->assertJsonPath('available', true)
            ->assertJsonPath('rows.0.area_slug', 'ebl-city');
    }

    /* -------------------------------------------- resolve, search, compare */

    public function test_location_resolve_accepts_and_returns_the_canonical_slug(): void
    {
        $this->legacyUppercaseArea();

        $this->getJson('/location/resolve?area=ebl-city')
            ->assertSuccessful()
            ->assertJsonPath('area.slug', 'ebl-city');

        // The validator's lowercase vocabulary is part of the contract the
        // emitters must satisfy — the raw stored casing was never valid here.
        $this->getJson('/location/resolve?area=EBL-CITY')->assertStatus(422);
    }

    public function test_search_rows_carry_the_canonical_slug(): void
    {
        $this->legacyUppercaseArea();

        $this->getJson('/map/search?q=erbil')
            ->assertSuccessful()
            ->assertJsonPath('groups.areas.0.slug', 'ebl-city');
    }

    public function test_compare_columns_and_movement_keys_align_on_the_canonical_slug(): void
    {
        $legacy = $this->legacyUppercaseArea();
        $this->risingIndexOn($legacy);

        Area::query()->create([
            'type' => AreaType::District->value,
            'slug' => 'plain-area',
            'name_ckb' => 'ناوچە',
            'latitude' => 36.20,
            'longitude' => 44.05,
            'publication_status' => PublicationStatus::Published->value,
        ]);

        $this->getJson('/map/compare?'.http_build_query([
            'areas' => ['ebl-city', 'plain-area'],
        ]))
            ->assertSuccessful()
            ->assertJsonPath('areas.0.slug', 'ebl-city')
            ->assertJsonPath('areas.1.slug', 'plain-area')
            // The movement row was keyed by the STORED casing before the fix,
            // so the legacy column silently lost its market claim.
            ->assertJsonPath('areas.0.movement.area_slug', 'ebl-city');
    }

    /* ------------------------------------------------------ admin writes */

    public function test_admin_writes_store_new_and_updated_slugs_in_canonical_form(): void
    {
        $admin = User::factory()->superAdmin()->create();

        $input = [
            'name_ckb' => 'ناوچەی نوێ',
            'slug' => 'NEW-Suburb',
            'type' => AreaType::District->value,
        ];

        $this->actingAs($admin)->post('/admin/areas', $input)->assertSessionHasNoErrors();

        $area = Area::query()->where('slug', 'new-suburb')->firstOrFail();

        $this->actingAs($admin)
            ->put('/admin/areas/'.$area->id, ['slug' => 'NEW-SUBURB'] + $input)
            ->assertSessionHasNoErrors();

        $this->assertSame('new-suburb', $area->refresh()->slug);
    }

    /* ------------------------------------------------------ draft absence */

    /** A draft area is absent from every public surface, whatever its casing. */
    public function test_a_draft_area_stays_out_of_public_data(): void
    {
        $this->legacyUppercaseArea();

        Area::query()->create([
            'type' => AreaType::District->value,
            'slug' => 'ZINCITY',
            'name_ckb' => 'زینسیتی',
            'latitude' => 36.21,
            'longitude' => 44.03,
            'publication_status' => PublicationStatus::Draft->value,
        ]);

        $this->getJson('/map/features?'.http_build_query(self::VIEWPORT + [
            'layers' => ['areas'],
            'zoom' => 9,
        ]))
            ->assertJsonCount(1, 'areas')
            ->assertJsonPath('areas.0.slug', 'ebl-city');

        $this->get('/areas')->assertDontSee('zincity')->assertDontSee('ZINCITY');
        $this->get('/areas/zincity')->assertNotFound();
        $this->getJson('/location/resolve?area=zincity')->assertNotFound();
    }
}
