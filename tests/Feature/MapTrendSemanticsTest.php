<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Modules\Identity\Models\User;
use App\Modules\Projects\Models\Project;
use App\Modules\Projects\Models\ProjectPrice;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The investment map's price-trend contract, pinned from the data up.
 *
 * The rule: a trend is a claim about TWO comparable stored observations —
 * same price type, same currency, both figures present — and anything less
 * is `unknown`, which makes no claim, carries no percentage, and is never
 * dressed up as "flat". The old derivation matched the previous row on
 * price type alone, so a USD-latest against an IQD-previous compared raw
 * magnitudes, and a null price cast to 0.0 read as a 100% collapse; both
 * fabrications are now impossible and pinned here.
 */
final class MapTrendSemanticsTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->setFeatures(['map.investment' => true]);
    }

    private function project(string $slug, ?float $lat = 36.19, ?float $lng = 44.0): Project
    {
        $project = Project::query()->create([
            'slug' => $slug,
            'name_ckb' => 'پڕۆژەی '.$slug,
            'project_type' => 'residential',
            'construction_status' => 'under_construction',
            'delivery_status' => 'not_started',
            'latitude' => $lat,
            'longitude' => $lng,
            'publication_status' => 'published',
        ]);
        $project->forceFill(['published_at' => now()])->save();

        return $project;
    }

    private function price(
        Project $project,
        string $from,
        string $date,
        string $currency = 'USD',
        string $type = 'sale_asking',
        ?string $rawFrom = null,
    ): void {
        $row = ProjectPrice::query()->create([
            'project_id' => $project->id,
            'price_from' => $from,
            'price_to' => null,
            'currency' => $currency,
            'price_type' => $type,
            'period' => substr($date, 0, 7),
            'effective_date' => $date,
            'source' => 'fixture',
            'confidence' => 'medium',
        ]);

        // A row with a NULL figure — an observation that is not one.
        if ($rawFrom === 'null-out') {
            $row->forceFill(['price_from' => null])->save();
        }
    }

    /** @return array<string, mixed> */
    private function features(): array
    {
        return $this->getJson('/invest/features?west=43.7&south=35.9&east=44.4&north=36.5&zoom=12')
            ->assertOk()
            ->json();
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    private function row(array $payload, string $slug): array
    {
        foreach ($payload['projects'] as $row) {
            if ($row['slug'] === $slug) {
                return $row;
            }
        }

        $this->fail("project {$slug} missing from the invest payload");
    }

    /* ------------------------------------------------- the four semantics */

    public function test_two_comparable_observations_yield_up_down_or_flat(): void
    {
        $up = $this->project('trend-up');
        $this->price($up, '100000', '2026-06-01');
        $this->price($up, '110000', '2026-07-01');

        $down = $this->project('trend-down');
        $this->price($down, '100000', '2026-06-01');
        $this->price($down, '90000', '2026-07-01');

        $flat = $this->project('trend-flat');
        $this->price($flat, '100000', '2026-06-01');
        $this->price($flat, '100000', '2026-07-01');

        $payload = $this->features();

        $this->assertSame('up', $this->row($payload, 'trend-up')['trend']);
        $this->assertSame('10.0', $this->row($payload, 'trend-up')['trend_percent']);
        $this->assertSame('down', $this->row($payload, 'trend-down')['trend']);
        $this->assertSame('-10.0', $this->row($payload, 'trend-down')['trend_percent']);
        $this->assertSame('flat', $this->row($payload, 'trend-flat')['trend']);
    }

    public function test_a_single_observation_is_unknown_never_flat(): void
    {
        $one = $this->project('one-observation');
        $this->price($one, '100000', '2026-07-01');

        $none = $this->project('no-observations');

        $payload = $this->features();

        foreach (['one-observation', 'no-observations'] as $slug) {
            $row = $this->row($payload, $slug);
            $this->assertSame('unknown', $row['trend'], "{$slug} must claim nothing");
            $this->assertNull($row['trend_percent'], "{$slug} must carry no percentage");
        }
    }

    public function test_mixed_currencies_are_not_comparable_and_yield_unknown(): void
    {
        // 130,000,000 IQD "previous" against 100,000 USD "latest": the old
        // code compared the raw magnitudes and emitted a fabricated
        // -99.9% collapse. Currencies that differ are simply not history.
        $project = $this->project('mixed-currency');
        $this->price($project, '130000000', '2026-06-01', currency: 'IQD');
        $this->price($project, '100000', '2026-07-01', currency: 'USD');

        $row = $this->row($this->features(), 'mixed-currency');

        $this->assertSame('unknown', $row['trend']);
        $this->assertNull($row['trend_percent']);
        $this->assertSame('USD', $row['currency']);
    }

    public function test_mixed_price_types_are_not_comparable_and_yield_unknown(): void
    {
        $project = $this->project('mixed-type');
        $this->price($project, '90000', '2026-06-01', type: 'sale_verified');
        $this->price($project, '100000', '2026-07-01', type: 'sale_asking');

        $this->assertSame('unknown', $this->row($this->features(), 'mixed-type')['trend']);
    }

    public function test_a_null_previous_figure_is_not_an_observation(): void
    {
        // (float) null === 0.0 used to read as "price collapsed 100%".
        $project = $this->project('null-history');
        $this->price($project, '90000', '2026-06-01', rawFrom: 'null-out');
        $this->price($project, '100000', '2026-07-01');

        $row = $this->row($this->features(), 'null-history');

        $this->assertSame('unknown', $row['trend']);
        $this->assertNull($row['trend_percent']);
    }

    /* ------------------------------------------ selection gates unchanged */

    public function test_projects_without_coordinates_or_publication_never_appear(): void
    {
        $this->project('mapped-and-published');
        $this->project('no-coordinates', lat: null, lng: null);

        Project::query()->create([
            'slug' => 'still-draft',
            'name_ckb' => 'ڕەشنووس',
            'project_type' => 'residential',
            'construction_status' => 'under_construction',
            'delivery_status' => 'not_started',
            'latitude' => 36.2,
            'longitude' => 44.01,
            'publication_status' => 'draft',
        ]);

        $slugs = array_column($this->features()['projects'], 'slug');

        $this->assertContains('mapped-and-published', $slugs);
        $this->assertNotContains('no-coordinates', $slugs, 'a missing coordinate must mean absent, never invented');
        $this->assertNotContains('still-draft', $slugs, 'unpublished projects must never reach the public map');
    }

    public function test_the_invest_endpoint_serves_projects_and_areas_only(): void
    {
        $this->project('layer-restriction');

        $payload = $this->getJson(
            '/invest/features?west=43.7&south=35.9&east=44.4&north=36.5&zoom=12'
            .'&layers[]=projects&layers[]=places&layers[]=offers&layers[]=companies',
        )->assertOk()->json();

        // Whatever the request asks for, the curated surface answers with
        // its own restriction — no places, offers or companies keys carrying
        // data rows.
        $this->assertArrayHasKey('projects', $payload);
        $this->assertSame([], $payload['places'] ?? []);
        $this->assertSame([], $payload['offers'] ?? []);
        $this->assertSame([], $payload['companies'] ?? []);
    }

    /* ------------------------------------------------ admin map readiness */

    public function test_admin_projects_carry_and_filter_by_map_readiness(): void
    {
        $this->project('admin-ready');
        $this->project('admin-not-ready', lat: null, lng: null);

        $admin = User::factory()->superAdmin()->create();

        $rows = $this->actingAs($admin)->get('/admin/projects')
            ->assertOk()
            ->inertiaPage()['props']['projects']['data'];

        $bySlug = array_column($rows, null, 'slug');
        $this->assertTrue($bySlug['admin-ready']['map_ready']);
        $this->assertFalse($bySlug['admin-not-ready']['map_ready']);

        $ready = $this->actingAs($admin)->get('/admin/projects?map_ready=1')
            ->assertOk()->inertiaPage()['props']['projects']['data'];
        $this->assertSame(['admin-ready'], array_column($ready, 'slug'));

        $notReady = $this->actingAs($admin)->get('/admin/projects?map_ready=0')
            ->assertOk()->inertiaPage()['props']['projects']['data'];
        $this->assertSame(['admin-not-ready'], array_column($notReady, 'slug'));
    }
}
