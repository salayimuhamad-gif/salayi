<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Modules\Companies\Models\Company;
use App\Modules\Companies\Models\CompanyBranch;
use App\Modules\Geography\Enums\AreaType;
use App\Modules\Geography\Models\Area;
use App\Modules\Geography\Models\Place;
use App\Modules\Geography\Models\PlaceCategory;
use App\Modules\Market\Models\MarketIndex;
use App\Modules\Market\Models\MarketIndexValue;
use App\Modules\Projects\Enums\PublicationStatus;
use App\Modules\Projects\Models\Project;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * The public Map Explorer (File one §5, File two §10).
 *
 * The assertions that matter most are about what does NOT come back. A map
 * that renders correctly while also emitting a portfolio coordinate is a
 * privacy incident that looks like a working feature, and only a negative
 * assertion catches it.
 */
final class MapExplorerTest extends TestCase
{
    use RefreshDatabase;

    /** Erbil city centre, used as the reference point for radius assertions. */
    private const CENTRE_LAT = 36.1901;

    private const CENTRE_LNG = 44.0091;

    private const VIEWPORT = [
        'north' => 36.60, 'south' => 35.80, 'east' => 44.50, 'west' => 43.50,
    ];

    protected function setUp(): void
    {
        parent::setUp();

        $this->setFeatures([
            'map.explorer' => true,
            'places.database' => true,
        ]);
    }

    /* ------------------------------------------------------ flag gating */

    public function test_the_explorer_is_unreachable_when_its_flag_is_off(): void
    {
        $this->setFeatures(['map.explorer' => false,
        ]);

        $this->get('/map')->assertNotFound();
        $this->getJson('/map/features?'.http_build_query(self::VIEWPORT))->assertNotFound();
    }

    public function test_the_index_resolves_in_every_enabled_locale(): void
    {
        $default = (string) config('localization.default', 'ckb');

        foreach (enabled_locales() as $locale) {
            $this->get($locale === $default ? '/map' : '/'.$locale.'/map')->assertSuccessful();
        }
    }

    /**
     * A layer whose module is off must not be offered. A legend that lists a
     * layer which always returns nothing is worse than a shorter legend.
     */
    public function test_layers_are_filtered_by_their_feature_flags(): void
    {
        $this->setFeatures([
            'places.database' => false,
            'marketplace.offers' => false,
            'companies.branches' => false,
            'market.indices' => false,
        ]);

        $this->get('/map')->assertInertia(fn ($page) => $page
            ->component('Public/Map/Explorer')
            ->where('layers', function ($layers): bool {
                /** @var list<array<string, mixed>> $layers */
                $rendered = collect($layers);

                return $rendered->pluck('key')->all() === ['projects', 'areas'];
            }));
    }

    public function test_requesting_a_disabled_layer_returns_empty_rather_than_erroring(): void
    {
        $this->setFeatures(['places.database' => false,
        ]);

        $this->getJson('/map/features?'.http_build_query(self::VIEWPORT + ['layers' => ['places']]))
            ->assertSuccessful()
            ->assertJsonPath('places', []);
    }

    /* ----------------------------------------------------- map provider */

    /** §10.1: MapLibre must work with nothing configured. */
    public function test_maplibre_is_the_default_provider_and_needs_no_key(): void
    {
        config(['services.maps.provider' => 'maplibre', 'services.maps.google_key' => null]);

        $this->get('/map')->assertInertia(fn ($page) => $page
            ->where('provider', 'maplibre')
            ->where('provider_fallback_reason', null));
    }

    public function test_google_is_used_only_when_a_key_is_configured(): void
    {
        config(['services.maps.provider' => 'google', 'services.maps.google_key' => 'AIza-test-key']);

        $this->get('/map')->assertInertia(fn ($page) => $page->where('provider', 'google'));
    }

    /**
     * A misconfigured deployment degrades to a working map AND says so. A
     * silent fallback is indistinguishable from a deliberate MapLibre setup,
     * which leaves an administrator no signal that the key never took effect.
     */
    public function test_google_without_a_key_falls_back_to_maplibre_and_reports_why(): void
    {
        config(['services.maps.provider' => 'google', 'services.maps.google_key' => '   ']);

        $this->get('/map')->assertInertia(fn ($page) => $page
            ->where('provider', 'maplibre')
            ->where('provider_requested', 'google')
            ->where('provider_fallback_reason', 'missing_key'));
    }

    /* --------------------------------------------------------- privacy */

    /**
     * The single most important assertion in this file. `PortfolioProperty`
     * has coordinates; a portfolio is a private record of what somebody owns.
     */
    public function test_no_response_key_can_carry_portfolio_or_private_data(): void
    {
        $response = $this->getJson('/map/features?'.http_build_query(
            self::VIEWPORT + ['layers' => ['projects', 'areas', 'places', 'offers', 'companies', 'prices']]
        ));

        $response->assertSuccessful();

        foreach (['portfolio', 'portfolio_properties', 'important_places', 'user_places'] as $forbidden) {
            $response->assertJsonMissingPath($forbidden);
        }
    }

    public function test_place_markers_never_carry_a_phone_number(): void
    {
        $place = $this->makePlace('clinic', self::CENTRE_LAT, self::CENTRE_LNG);
        $place->setPhone('+9647501234567');
        $place->save();

        $response = $this->getJson('/map/features?'.http_build_query(
            self::VIEWPORT + ['layers' => ['places']]
        ));

        $response->assertSuccessful();
        $response->assertDontSee('7501234567');
        $response->assertJsonMissingPath('places.0.phone');
    }

    public function test_unpublished_records_are_absent_from_every_layer(): void
    {
        $this->makeProject('draft', self::CENTRE_LAT, self::CENTRE_LNG, PublicationStatus::Draft);
        $this->makePlace('draft-place', self::CENTRE_LAT, self::CENTRE_LNG, ['publication_status' => PublicationStatus::Draft->value]);

        $response = $this->getJson('/map/features?'.http_build_query(
            self::VIEWPORT + ['layers' => ['projects', 'places']]
        ));

        $response->assertJsonPath('projects', [])->assertJsonPath('places', []);
    }

    /** A published place that is not marked public is amenity data, not a pin. */
    public function test_a_non_public_place_is_not_plotted(): void
    {
        $this->makePlace('depot', self::CENTRE_LAT, self::CENTRE_LNG, ['is_public' => false]);

        $this->getJson('/map/features?'.http_build_query(self::VIEWPORT + ['layers' => ['places']]))
            ->assertJsonPath('places', []);
    }

    /* ------------------------------------------------- viewport bounds */

    public function test_features_outside_the_viewport_are_excluded(): void
    {
        $this->makeProject('inside', self::CENTRE_LAT, self::CENTRE_LNG);
        $this->makeProject('far-away', 33.3152, 44.3661); // Baghdad

        $this->getJson('/map/features?'.http_build_query(self::VIEWPORT + ['layers' => ['projects']]))
            ->assertJsonCount(1, 'projects')
            ->assertJsonPath('projects.0.slug', 'inside');
    }

    public function test_a_project_without_coordinates_is_absent_rather_than_placed_at_a_centroid(): void
    {
        $this->makeProject('located', self::CENTRE_LAT, self::CENTRE_LNG);
        $this->makeProject('unlocated', null, null);

        $this->getJson('/map/features?'.http_build_query(self::VIEWPORT + ['layers' => ['projects']]))
            ->assertJsonCount(1, 'projects');
    }

    /* --------------------------------------------------- radius search */

    public function test_radius_search_excludes_features_beyond_the_radius(): void
    {
        // ~1.1 km north of centre.
        $this->makeProject('near', self::CENTRE_LAT + 0.010, self::CENTRE_LNG);
        // ~5.5 km north of centre.
        $this->makeProject('far', self::CENTRE_LAT + 0.050, self::CENTRE_LNG);

        $this->getJson('/map/features?'.http_build_query(self::VIEWPORT + [
            'layers' => ['projects'],
            'center_lat' => self::CENTRE_LAT,
            'center_lng' => self::CENTRE_LNG,
            'radius_km' => 2,
        ]))
            ->assertJsonCount(1, 'projects')
            ->assertJsonPath('projects.0.slug', 'near');
    }

    public function test_a_radius_without_a_centre_is_rejected(): void
    {
        $this->getJson('/map/features?'.http_build_query(self::VIEWPORT + ['radius_km' => 5]))
            ->assertStatus(422);
    }

    public function test_a_radius_beyond_the_ceiling_is_rejected(): void
    {
        $this->getJson('/map/features?'.http_build_query(self::VIEWPORT + [
            'center_lat' => self::CENTRE_LAT,
            'center_lng' => self::CENTRE_LNG,
            'radius_km' => 5000,
        ]))->assertStatus(422);
    }

    /* --------------------------------------------------- drawn polygon */

    public function test_a_drawn_polygon_excludes_features_outside_the_ring(): void
    {
        $this->makeProject('inside-ring', self::CENTRE_LAT, self::CENTRE_LNG);
        $this->makeProject('outside-ring', self::CENTRE_LAT + 0.30, self::CENTRE_LNG + 0.30);

        $ring = [
            ['lat' => self::CENTRE_LAT - 0.05, 'lng' => self::CENTRE_LNG - 0.05],
            ['lat' => self::CENTRE_LAT - 0.05, 'lng' => self::CENTRE_LNG + 0.05],
            ['lat' => self::CENTRE_LAT + 0.05, 'lng' => self::CENTRE_LNG + 0.05],
            ['lat' => self::CENTRE_LAT + 0.05, 'lng' => self::CENTRE_LNG - 0.05],
        ];

        $this->getJson('/map/features?'.http_build_query(self::VIEWPORT + [
            'layers' => ['projects'],
            'polygon' => $ring,
        ]))
            ->assertJsonCount(1, 'projects')
            ->assertJsonPath('projects.0.slug', 'inside-ring');
    }

    public function test_a_polygon_with_fewer_than_three_points_is_rejected(): void
    {
        $this->getJson('/map/features?'.http_build_query(self::VIEWPORT + [
            'polygon' => [
                ['lat' => 36.1, 'lng' => 44.0],
                ['lat' => 36.2, 'lng' => 44.1],
            ],
        ]))->assertStatus(422);
    }

    /* -------------------------------------------------------- distance */

    /**
     * §10.5. Kilometres, straight-line, and travel time explicitly absent —
     * never estimated from the distance beside it.
     */
    public function test_distances_are_kilometres_labelled_straight_line_with_no_travel_time(): void
    {
        $this->makeProject('near', self::CENTRE_LAT + 0.010, self::CENTRE_LNG);

        $response = $this->getJson('/map/features?'.http_build_query(self::VIEWPORT + [
            'layers' => ['projects'],
            'center_lat' => self::CENTRE_LAT,
            'center_lng' => self::CENTRE_LNG,
        ]));

        $response
            ->assertJsonPath('distance.unit', 'km')
            ->assertJsonPath('distance.method', 'straight_line')
            ->assertJsonPath('distance.travel_time_available', false)
            ->assertJsonPath('projects.0.distance_method', 'straight_line')
            ->assertJsonPath('projects.0.travel_time_minutes', null);

        $distance = $response->json('projects.0.distance_km');

        $this->assertIsNumeric($distance);
        $this->assertGreaterThan(1.0, (float) $distance);
        $this->assertLessThan(1.3, (float) $distance);
    }

    /**
     * Null, not zero. Zero reads as "at the centre"; null reads as "not
     * measured", which is what it is.
     */
    public function test_distance_is_null_when_no_centre_was_given(): void
    {
        $this->makeProject('somewhere', self::CENTRE_LAT, self::CENTRE_LNG);

        $this->getJson('/map/features?'.http_build_query(self::VIEWPORT + ['layers' => ['projects']]))
            ->assertJsonPath('projects.0.distance_km', null)
            ->assertJsonPath('projects.0.distance_method', null)
            ->assertJsonPath('distance.applied', false);
    }

    public function test_results_are_ordered_nearest_first_when_a_centre_is_given(): void
    {
        $this->makeProject('far', self::CENTRE_LAT + 0.040, self::CENTRE_LNG);
        $this->makeProject('near', self::CENTRE_LAT + 0.005, self::CENTRE_LNG);

        $this->getJson('/map/features?'.http_build_query(self::VIEWPORT + [
            'layers' => ['projects'],
            'center_lat' => self::CENTRE_LAT,
            'center_lng' => self::CENTRE_LNG,
        ]))
            ->assertJsonPath('projects.0.slug', 'near')
            ->assertJsonPath('projects.1.slug', 'far');
    }

    /* ---------------------------------------------------- input safety */

    public function test_a_request_without_bounds_is_rejected(): void
    {
        $this->getJson('/map/features')->assertStatus(422);
    }

    public function test_out_of_range_coordinates_are_rejected(): void
    {
        $this->getJson('/map/features?'.http_build_query([
            'north' => 999, 'south' => 0, 'east' => 0, 'west' => 0,
        ]))->assertStatus(422);
    }

    /* ------------------------------------------- corrections: provider */

    /**
     * The server must not claim Google while the client renders MapLibre. The
     * key is what the browser needs to build a Google map, so a `provider` of
     * "google" with no key in the payload is the original defect restated.
     */
    public function test_google_provider_ships_the_key_the_client_needs(): void
    {
        config(['services.maps.provider' => 'google', 'services.maps.google_key' => 'AIza-test-key']);

        $this->get('/map')->assertInertia(fn ($page) => $page
            ->where('provider', 'google')
            ->where('google_key', 'AIza-test-key'));
    }

    /** A MapLibre deployment must never ship a credential it does not use. */
    public function test_the_google_key_is_withheld_when_maplibre_is_the_provider(): void
    {
        config(['services.maps.provider' => 'maplibre', 'services.maps.google_key' => 'AIza-test-key']);

        $this->get('/map')->assertInertia(fn ($page) => $page
            ->where('provider', 'maplibre')
            ->where('google_key', null));
    }

    /* ------------------------------------------ corrections: boundaries */

    public function test_boundaries_are_withheld_below_the_zoom_threshold(): void
    {
        $this->makeAreaWithBoundary('ankawa');

        $this->getJson('/map/features?'.http_build_query(self::VIEWPORT + [
            'layers' => ['areas'],
            'zoom' => 9,
        ]))->assertJsonPath('boundaries.features', []);
    }

    public function test_boundaries_are_returned_as_geojson_at_sufficient_zoom(): void
    {
        $this->makeAreaWithBoundary('ankawa');

        $response = $this->getJson('/map/features?'.http_build_query(self::VIEWPORT + [
            'layers' => ['areas'],
            'zoom' => 13,
        ]));

        $response
            ->assertJsonPath('boundaries.type', 'FeatureCollection')
            ->assertJsonCount(1, 'boundaries.features')
            ->assertJsonPath('boundaries.features.0.geometry.type', 'Polygon')
            ->assertJsonPath('boundaries.features.0.properties.slug', 'ankawa');

        // GeoJSON is [lng, lat]. Reversed, Erbil lands in the Indian Ocean and
        // the map still renders, so the order is asserted explicitly.
        $first = $response->json('boundaries.features.0.geometry.coordinates.0.0');

        $this->assertGreaterThan(43.0, $first[0], 'First ordinate must be longitude.');
        $this->assertLessThan(37.0, $first[1], 'Second ordinate must be latitude.');
    }

    /** Points remain available alongside polygons — labels come from points. */
    public function test_representative_points_are_still_returned_with_boundaries(): void
    {
        $this->makeAreaWithBoundary('ankawa');

        $this->getJson('/map/features?'.http_build_query(self::VIEWPORT + [
            'layers' => ['areas'],
            'zoom' => 13,
        ]))
            ->assertJsonCount(1, 'areas')
            ->assertJsonPath('areas.0.slug', 'ankawa')
            ->assertJsonCount(1, 'boundaries.features');
    }

    public function test_an_unpublished_area_contributes_no_boundary(): void
    {
        $area = $this->makeAreaWithBoundary('draft-area');
        $area->forceFill(['publication_status' => PublicationStatus::Draft->value])->save();

        $this->getJson('/map/features?'.http_build_query(self::VIEWPORT + [
            'layers' => ['areas'],
            'zoom' => 13,
        ]))->assertJsonPath('boundaries.features', []);
    }

    /** One bad WKT string costs that area its outline, not the whole map. */
    public function test_a_malformed_boundary_is_skipped_without_failing_the_request(): void
    {
        $good = $this->makeAreaWithBoundary('good-area');
        $bad = $this->makeAreaWithBoundary('bad-area');
        $bad->forceFill(['boundary_wkt' => 'POLYGON(((((nonsense'])->save();

        $this->getJson('/map/features?'.http_build_query(self::VIEWPORT + [
            'layers' => ['areas'],
            'zoom' => 13,
        ]))
            ->assertSuccessful()
            ->assertJsonCount(1, 'boundaries.features')
            ->assertJsonPath('boundaries.features.0.properties.slug', $good->slug);
    }

    /* ------------------------------------ corrections: locale categories */

    /**
     * A category label pinned to Sorani left Arabic and English visitors
     * reading one word of the interface in a language they had not chosen.
     */
    public function test_category_names_resolve_in_the_request_locale(): void
    {
        PlaceCategory::query()->create([
            'key' => 'school',
            'group' => 'education',
            'name_ckb' => 'قوتابخانە',
            'name_ar' => 'مدرسة',
            'name_en' => 'School',
            'is_active' => true,
        ]);

        $expected = ['ckb' => 'قوتابخانە', 'ar' => 'مدرسة', 'en' => 'School'];
        $default = (string) config('localization.default', 'ckb');

        foreach (enabled_locales() as $locale) {
            $url = $locale === $default ? '/map' : '/'.$locale.'/map';

            $this->get($url)->assertInertia(fn ($page) => $page
                ->where('categories.0.name', $expected[$locale] ?? $expected['ckb']));
        }
    }

    /** Sorani is the FALLBACK, not the pin. */
    public function test_a_missing_translation_falls_back_to_sorani(): void
    {
        PlaceCategory::query()->create([
            'key' => 'nursery',
            'group' => 'education',
            'name_ckb' => 'باخچەی منداڵان',
            'name_ar' => null,
            'name_en' => null,
            'is_active' => true,
        ]);

        $this->get('/en/map')->assertInertia(fn ($page) => $page
            ->where('categories.0.name', 'باخچەی منداڵان'));
    }

    /* -------------------------------------- corrections: company branches */

    public function test_an_inactive_branch_is_not_plotted(): void
    {
        $this->setFeatures(['companies.branches' => true,
        ]);

        $company = $this->makeVerifiedCompany();

        $this->makeBranch($company->id, 'open-branch', true);
        $this->makeBranch($company->id, 'closed-branch', false);

        $this->getJson('/map/features?'.http_build_query(self::VIEWPORT + ['layers' => ['companies']]))
            ->assertJsonCount(1, 'companies')
            ->assertJsonPath('companies.0.name', 'open-branch');
    }

    public function test_a_branch_of_an_unverified_company_is_not_plotted(): void
    {
        $this->setFeatures(['companies.branches' => true,
        ]);

        $company = $this->makeVerifiedCompany(['verification_status' => 'pending']);
        $this->makeBranch($company->id, 'unverified-branch', true);

        $this->getJson('/map/features?'.http_build_query(self::VIEWPORT + ['layers' => ['companies']]))
            ->assertJsonPath('companies', []);
    }

    public function test_branch_markers_never_carry_private_contact_fields(): void
    {
        $this->setFeatures(['companies.branches' => true,
        ]);

        $company = $this->makeVerifiedCompany();
        $this->makeBranch($company->id, 'a-branch', true);

        $response = $this->getJson('/map/features?'.http_build_query(
            self::VIEWPORT + ['layers' => ['companies']]
        ));

        $response->assertJsonMissingPath('companies.0.phone');
        $response->assertJsonMissingPath('companies.0.phone_encrypted');
        $response->assertJsonMissingPath('companies.0.whatsapp_encrypted');
        $response->assertJsonMissingPath('companies.0.email');
    }

    /* ------------------------------------------ corrections: place rules */

    /**
     * A permanently closed hospital rendered like an open one sends somebody
     * to a locked door in an emergency.
     */
    public function test_a_non_operating_place_is_not_plotted(): void
    {
        $this->makePlace('open-clinic', self::CENTRE_LAT, self::CENTRE_LNG);
        $this->makePlace('closed-clinic', self::CENTRE_LAT, self::CENTRE_LNG, [
            'operational_status' => 'permanently_closed',
        ]);

        $this->getJson('/map/features?'.http_build_query(self::VIEWPORT + ['layers' => ['places']]))
            ->assertJsonCount(1, 'places')
            ->assertJsonPath('places.0.slug', 'open-clinic');
    }

    public function test_a_low_confidence_place_is_not_plotted(): void
    {
        $this->makePlace('unreliable', self::CENTRE_LAT, self::CENTRE_LNG, ['confidence' => 'low']);

        $this->getJson('/map/features?'.http_build_query(self::VIEWPORT + ['layers' => ['places']]))
            ->assertJsonPath('places', []);
    }

    public function test_a_duplicate_secondary_place_is_not_plotted(): void
    {
        $this->makePlace('duplicate', self::CENTRE_LAT, self::CENTRE_LNG, [
            'is_duplicate_primary' => false,
        ]);

        $this->getJson('/map/features?'.http_build_query(self::VIEWPORT + ['layers' => ['places']]))
            ->assertJsonPath('places', []);
    }

    /* ------------------------------------------- corrections: price N+1 */

    /**
     * The price layer issued one ordered query per index. With fifty area
     * indices in a viewport that was fifty-one round trips, each carrying
     * full shared-host connection latency — the layer got slower in direct
     * proportion to how much data the product held.
     *
     * The ceiling is generous on purpose: this asserts the absence of
     * per-index growth, not an exact plan.
     */
    public function test_the_price_layer_does_not_scale_its_query_count_with_index_count(): void
    {
        $this->setFeatures(['market.indices' => true,
        ]);

        for ($i = 0; $i < 12; $i++) {
            $area = $this->makeArea('priced-area-'.$i, self::CENTRE_LAT + ($i * 0.001), self::CENTRE_LNG);
            $this->makePublishedIndexWithValue($area->id, 'idx-'.$i);
        }

        $queries = 0;
        DB::listen(function () use (&$queries): void {
            $queries++;
        });

        $this->getJson('/map/features?'.http_build_query(self::VIEWPORT + ['layers' => ['prices']]))
            ->assertSuccessful()
            ->assertJsonCount(12, 'prices');

        $this->assertLessThan(
            12,
            $queries,
            'The price layer still issues at least one query per index.'
        );
    }

    /** Reliability rules survive the query rewrite. */
    public function test_a_limited_price_value_is_excluded_after_the_rewrite(): void
    {
        $this->setFeatures(['market.indices' => true,
        ]);

        $area = $this->makeArea('limited-area', self::CENTRE_LAT, self::CENTRE_LNG);
        $this->makePublishedIndexWithValue($area->id, 'limited-idx', ['is_limited' => true]);

        $this->getJson('/map/features?'.http_build_query(self::VIEWPORT + ['layers' => ['prices']]))
            ->assertJsonPath('prices', []);
    }

    public function test_price_rows_retain_sample_size_confidence_and_qualifier(): void
    {
        $this->setFeatures(['market.indices' => true,
        ]);

        $area = $this->makeArea('priced-area', self::CENTRE_LAT, self::CENTRE_LNG);
        $this->makePublishedIndexWithValue($area->id, 'idx', ['sample_size' => 42, 'confidence' => 'high']);

        $this->getJson('/map/features?'.http_build_query(self::VIEWPORT + ['layers' => ['prices']]))
            ->assertJsonPath('prices.0.sample_size', 42)
            ->assertJsonPath('prices.0.confidence', 'high')
            ->assertJsonStructure(['prices' => [['period', 'price_type', 'requires_qualifier']]]);
    }

    /* ----------------------------------- corrections round 2: regressions */

    /**
     * Regression: MapExplorerController::categories() built its list with a
     * STATIC arrow function that called $this->categoryName(). A static
     * closure has no $this, so this was a fatal Error — but only when at least
     * one ACTIVE category existed, because an empty result never invoked the
     * callback. Every earlier test happened to run with an empty table, so the
     * whole index page was broken and fully green.
     */
    public function test_the_index_renders_with_active_categories_present(): void
    {
        PlaceCategory::query()->create([
            'key' => 'hospital',
            'group' => 'health',
            'name_ckb' => 'نەخۆشخانە',
            'name_en' => 'Hospital',
            'is_active' => true,
        ]);

        $this->get('/map')
            ->assertSuccessful()
            ->assertInertia(fn ($page) => $page
                ->component('Public/Map/Explorer')
                ->has('categories', 1)
                ->where('categories.0.key', 'hospital'));
    }

    /**
     * Regression: Wkt::parsePolygon() returns a LIST OF RINGS, not one ring.
     * The old code tested count() on the ring list, which is 1 for every
     * ordinary polygon, so every normal boundary was skipped and the layer
     * rendered permanently empty.
     */
    public function test_an_ordinary_single_ring_polygon_produces_a_visible_boundary(): void
    {
        $this->makeAreaWithBoundary('ankawa');

        $response = $this->getJson('/map/features?'.http_build_query(self::VIEWPORT + [
            'layers' => ['areas'],
            'zoom' => 13,
        ]));

        $response
            ->assertJsonCount(1, 'boundaries.features')
            ->assertJsonPath('boundaries.features.0.geometry.type', 'Polygon');

        // One exterior ring, closed, with at least four positions.
        $rings = $response->json('boundaries.features.0.geometry.coordinates');

        $this->assertCount(1, $rings);
        $this->assertGreaterThanOrEqual(4, count($rings[0]));
        $this->assertSame($rings[0][0], $rings[0][count($rings[0]) - 1], 'The exterior ring must be closed.');
    }

    /** A hole is real geography: filling it claims territory the area lacks. */
    public function test_interior_holes_are_preserved_in_the_geojson(): void
    {
        $area = $this->makeArea('with-hole', self::CENTRE_LAT, self::CENTRE_LNG);
        $area->forceFill(['boundary_wkt' => 'POLYGON('
            .'(44.000 36.180, 44.040 36.180, 44.040 36.220, 44.000 36.220, 44.000 36.180),'
            .'(44.010 36.190, 44.020 36.190, 44.020 36.200, 44.010 36.200, 44.010 36.190)'
            .')',
        ])->save();

        $rings = $this->getJson('/map/features?'.http_build_query(self::VIEWPORT + [
            'layers' => ['areas'], 'zoom' => 13,
        ]))->json('boundaries.features.0.geometry.coordinates');

        $this->assertCount(2, $rings, 'Exterior ring plus one hole.');
    }

    /** An area split by a river is one unit with two pieces. */
    public function test_a_multipolygon_boundary_is_rendered_rather_than_skipped(): void
    {
        $area = $this->makeArea('split-area', self::CENTRE_LAT, self::CENTRE_LNG);
        $area->forceFill(['boundary_wkt' => 'MULTIPOLYGON('
            .'((44.000 36.180, 44.010 36.180, 44.010 36.190, 44.000 36.190, 44.000 36.180)),'
            .'((44.020 36.200, 44.030 36.200, 44.030 36.210, 44.020 36.210, 44.020 36.200))'
            .')',
        ])->save();

        $this->getJson('/map/features?'.http_build_query(self::VIEWPORT + [
            'layers' => ['areas'], 'zoom' => 13,
        ]))
            ->assertJsonPath('boundaries.features.0.geometry.type', 'MultiPolygon')
            ->assertJsonCount(2, 'boundaries.features.0.geometry.coordinates');
    }

    /**
     * The bounding-box selection defect. A district whose centroid sits just
     * outside the viewport can still cover most of the visible screen.
     * Selecting on the representative point dropped exactly the large areas a
     * visitor is most likely to be looking at, and left no outline where one
     * belonged — invisible unless tested with real geometry.
     */
    public function test_a_boundary_overlapping_the_viewport_is_returned_though_its_centre_is_outside(): void
    {
        $viewport = ['north' => 36.20, 'south' => 36.15, 'east' => 44.02, 'west' => 43.98];

        $area = $this->makeArea('overlapping', 36.30, 44.00); // centre well north
        $area->forceFill([
            'boundary_wkt' => 'POLYGON((43.99 36.10, 44.01 36.10, 44.01 36.40, 43.99 36.40, 43.99 36.10))',
        ])->save();

        // The representative point is outside, so the areas layer omits it...
        $response = $this->getJson('/map/features?'.http_build_query($viewport + [
            'layers' => ['areas'], 'zoom' => 13,
        ]));

        $response->assertJsonPath('areas', []);

        // ...but the polygon overlaps, so the boundary must still be drawn.
        $response->assertJsonCount(1, 'boundaries.features')
            ->assertJsonPath('boundaries.features.0.properties.slug', 'overlapping');
    }

    /** Truncation is reported, never inferred from a full page. */
    public function test_boundary_truncation_is_reported_honestly(): void
    {
        for ($i = 0; $i < 42; $i++) {
            $this->makeAreaWithBoundary('bounded-'.$i);
        }

        $this->getJson('/map/features?'.http_build_query(self::VIEWPORT + [
            'layers' => ['areas'], 'zoom' => 13,
        ]))
            ->assertJsonCount(40, 'boundaries.features')
            ->assertJsonPath('truncated', true);
    }

    /* ---------------------------------------------------------- helpers */

    private function makeProject(
        string $slug,
        ?float $lat,
        ?float $lng,
        PublicationStatus $status = PublicationStatus::Published,
    ): Project {
        // project_type, construction_status and delivery_status are NOT NULL
        // with no default (see create_projects). Omitting them meant every
        // fixture in this file would have failed on insert the moment a real
        // database existed — the tests were unrunnable, not merely unrun.
        return Project::query()->create([
            'slug' => $slug,
            'name_ckb' => $slug,
            'project_type' => 'residential',
            'construction_status' => 'under_construction',
            'delivery_status' => 'not_started',
            'latitude' => $lat,
            'longitude' => $lng,
            'publication_status' => $status->value,
        ]);
    }

    /** @param  array<model-property<Place>, mixed>  $attributes */
    private function makePlace(string $slug, float $lat, float $lng, array $attributes = []): Place
    {
        // places.place_category_id is a NOT NULL foreign key.
        return Place::query()->create(array_merge([
            'slug' => $slug,
            'name_ckb' => $slug,
            'place_category_id' => $this->defaultCategory()->id,
            'latitude' => $lat,
            'longitude' => $lng,
            'publication_status' => PublicationStatus::Published->value,
            'is_public' => true,
            'is_duplicate_primary' => true,
            'confidence' => 'high',
            'operational_status' => 'operating',
        ], $attributes));
    }

    /** places.place_category_id is required; one shared row is enough. */
    private function defaultCategory(): PlaceCategory
    {
        return PlaceCategory::query()->firstOrCreate(
            ['key' => 'fixture-category'],
            ['group' => 'other', 'name_ckb' => 'Fixture', 'is_active' => true],
        );
    }

    private function makeAreaWithBoundary(string $slug): Area
    {
        $area = $this->makeArea($slug, self::CENTRE_LAT, self::CENTRE_LNG);

        $area->forceFill([
            'boundary_wkt' => 'POLYGON((44.000 36.180, 44.020 36.180, 44.020 36.200, 44.000 36.200, 44.000 36.180))',
        ])->save();

        return $area->refresh();
    }

    /** @param  array<model-property<Company>, mixed>  $attributes */
    private function makeVerifiedCompany(array $attributes = []): Company
    {
        // companies.legal_name is NOT NULL.
        return Company::query()->create(array_merge([
            'slug' => 'a-company-'.uniqid(),
            'name_ckb' => 'A Company',
            'legal_name' => 'A Company LLC',
            'verification_status' => 'verified',
            'publication_status' => PublicationStatus::Published->value,
        ], $attributes));
    }

    private function makeBranch(int $companyId, string $name, bool $isActive): CompanyBranch
    {
        return CompanyBranch::query()->create([
            'company_id' => $companyId,
            'name_ckb' => $name,
            'latitude' => self::CENTRE_LAT,
            'longitude' => self::CENTRE_LNG,
            'is_active' => $isActive,
        ]);
    }

    /** @param  array<model-property<MarketIndexValue>, mixed>  $valueAttributes */
    private function makePublishedIndexWithValue(int $areaId, string $key, array $valueAttributes = []): void
    {
        $index = MarketIndex::query()->create([
            'key' => $key,
            'name_ckb' => $key,
            'methodology_version' => 'v1',
            'scope_type' => 'area',
            'scope_id' => $areaId,
            'price_type' => 'sale_asking',
            'currency' => 'USD',
            'publication_status' => 'published',
        ]);

        // effective_date and methodology_version are NOT NULL (spec 15.3
        // stores them as columns precisely so a missing one is a schema error
        // rather than a quietly absent key on a public page).
        MarketIndexValue::query()->create(array_merge([
            'market_index_id' => $index->id,
            'period' => '2026-06',
            'effective_date' => '2026-06-30',
            'methodology_version' => 'v1',
            'value' => '1250.00',
            'publication_status' => 'published',
            'is_limited' => false,
            'sample_size' => 30,
            'confidence' => 'medium',
        ], $valueAttributes));
    }

    private function makeArea(string $slug, float $lat, float $lng): Area
    {
        return Area::query()->create([
            'type' => AreaType::District->value,
            'slug' => $slug,
            'name_ckb' => $slug,
            'latitude' => $lat,
            'longitude' => $lng,
            'publication_status' => PublicationStatus::Published->value,
        ]);
    }
}
