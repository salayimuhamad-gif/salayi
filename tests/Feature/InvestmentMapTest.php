<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Modules\Companies\Models\Company;
use App\Modules\Companies\Models\CompanyBranch;
use App\Modules\Geography\Enums\AreaType;
use App\Modules\Geography\Models\Area;
use App\Modules\Geography\Models\Place;
use App\Modules\Geography\Models\PlaceCategory;
use App\Modules\Projects\Enums\PublicationStatus;
use App\Modules\Projects\Models\Project;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The public Investment Map (/invest).
 *
 * The surface's one non-negotiable is what it does NOT show: generic places —
 * schools, hospitals, cafés, shops — must never appear as investment markers.
 * That promise has to hold on the SERVER, against a client that asks for the
 * other layers explicitly, because a page's own restraint is not a security
 * property. Most of the assertions here are therefore about absence.
 */
final class InvestmentMapTest extends TestCase
{
    use RefreshDatabase;

    private const VIEWPORT = [
        'north' => 36.60, 'south' => 35.80, 'east' => 44.50, 'west' => 43.50,
    ];

    protected function setUp(): void
    {
        parent::setUp();

        $this->setFeatures(['map.investment' => true]);
    }

    /* ------------------------------------------------------ flag gating */

    public function test_the_investment_map_is_unreachable_when_its_flag_is_off(): void
    {
        $this->setFeatures(['map.investment' => false]);

        $this->get('/invest')->assertNotFound();
        $this->getJson('/invest/features?'.http_build_query(self::VIEWPORT))->assertNotFound();
    }

    public function test_the_page_resolves_in_every_enabled_locale(): void
    {
        $default = (string) config('localization.default', 'ckb');

        foreach (enabled_locales() as $locale) {
            $this->get($locale === $default ? '/invest' : '/'.$locale.'/invest')->assertSuccessful();
        }
    }

    /**
     * Independence in both directions: the investment map works with the
     * full explorer off, and turning the investment map off leaves the
     * explorer alone. One operator decision each.
     */
    public function test_the_two_map_surfaces_are_flagged_independently(): void
    {
        $this->setFeatures(['map.investment' => true, 'map.explorer' => false]);

        $this->get('/invest')->assertSuccessful();
        $this->get('/map')->assertNotFound();

        $this->setFeatures(['map.investment' => false, 'map.explorer' => true]);

        $this->get('/invest')->assertNotFound();
        $this->get('/map')->assertSuccessful();
    }

    /* -------------------------------------------------- layer restriction */

    public function test_the_page_offers_only_investment_layers(): void
    {
        // Every other layer's module is ON — availability is not the fence.
        $this->setFeatures([
            'places.database' => true,
            'marketplace.offers' => true,
            'companies.branches' => true,
            'market.indices' => true,
        ]);

        $this->get('/invest')->assertInertia(fn ($page) => $page
            ->component('Public/Map/Invest')
            ->where('layers', [
                ['key' => 'projects', 'flag' => null],
                ['key' => 'areas', 'flag' => null],
            ]));
    }

    /**
     * The core promise. A place, an offer-less marketplace module, a company
     * branch — all flagged on, all inside the viewport, all requested BY NAME
     * — and none of them comes back from the investment endpoint.
     */
    public function test_generic_places_never_appear_even_when_requested_explicitly(): void
    {
        $this->setFeatures([
            'places.database' => true,
            'companies.branches' => true,
            'market.indices' => true,
        ]);

        $this->makePlace('a-school', 36.19, 44.01);
        $this->makeBranch();
        $project = $this->makeProject('a-tower', 36.20, 44.02);

        $response = $this->getJson('/invest/features?'.http_build_query([
            ...self::VIEWPORT,
            'layers' => ['projects', 'places', 'companies', 'prices', 'offers'],
        ]))->assertSuccessful();

        $data = $response->json();

        // The allowed layer flows.
        $this->assertSame($project->id, $data['projects'][0]['id'] ?? null);

        // The refused layers are EMPTY — not absent keys the client might
        // misread, but explicitly empty collections.
        $this->assertSame([], $data['places']);
        $this->assertSame([], $data['companies']);
        $this->assertSame([], $data['prices']);
        $this->assertSame([], $data['offers']);
    }

    public function test_only_published_projects_with_coordinates_appear(): void
    {
        $this->makeProject('published-no-coords', null, null);
        $this->makeProject('draft-with-coords', 36.19, 44.01, PublicationStatus::Draft);
        $published = $this->makeProject('published-with-coords', 36.21, 44.03);

        $rows = $this->getJson('/invest/features?'.http_build_query([
            ...self::VIEWPORT,
            'layers' => ['projects'],
        ]))->assertSuccessful()->json('projects');

        $this->assertCount(1, $rows);
        $this->assertSame($published->id, $rows[0]['id']);
    }

    public function test_area_boundaries_are_available_for_context(): void
    {
        $this->makeAreaWithBoundary('citadel-district');

        $features = $this->getJson('/invest/features?'.http_build_query([
            ...self::VIEWPORT,
            'layers' => ['projects', 'areas'],
            'zoom' => 13,
        ]))->assertSuccessful()->json('boundaries.features');

        $this->assertNotEmpty($features);
    }

    /* ------------------------------------------------------------ fixtures */

    private function makeProject(
        string $slug,
        ?float $lat,
        ?float $lng,
        PublicationStatus $status = PublicationStatus::Published,
    ): Project {
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

    private function makePlace(string $slug, float $lat, float $lng): Place
    {
        $category = PlaceCategory::query()->firstOrCreate(
            ['key' => 'fixture-category'],
            ['group' => 'other', 'name_ckb' => 'Fixture', 'is_active' => true],
        );

        return Place::query()->create([
            'slug' => $slug,
            'name_ckb' => $slug,
            'place_category_id' => $category->id,
            'latitude' => $lat,
            'longitude' => $lng,
            'publication_status' => PublicationStatus::Published->value,
            'is_public' => true,
            'is_duplicate_primary' => true,
            'confidence' => 'high',
            'operational_status' => 'operating',
        ]);
    }

    private function makeBranch(): CompanyBranch
    {
        $company = Company::query()->create([
            'slug' => 'fixture-company',
            'name_ckb' => 'Fixture Company',
            'legal_name' => 'Fixture Company LLC',
            'verification_status' => 'verified',
            'publication_status' => PublicationStatus::Published->value,
        ]);

        return CompanyBranch::query()->create([
            'company_id' => $company->id,
            'name_ckb' => 'HQ',
            'latitude' => 36.19,
            'longitude' => 44.01,
            'is_active' => true,
        ]);
    }

    private function makeAreaWithBoundary(string $slug): Area
    {
        return Area::query()->create([
            'type' => AreaType::District->value,
            'slug' => $slug,
            'name_ckb' => $slug,
            'latitude' => 36.19,
            'longitude' => 44.01,
            'boundary_wkt' => 'POLYGON((44.00 36.18, 44.02 36.18, 44.02 36.20, 44.00 36.20, 44.00 36.18))',
            'publication_status' => PublicationStatus::Published->value,
        ]);
    }
}
