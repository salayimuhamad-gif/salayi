<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Modules\Identity\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Collection;
use Tests\TestCase;

/**
 * A zero-project database must NOT mean a blank page.
 *
 * The rule under test: the basemap is independent of the data. Both public
 * map surfaces render their page and answer their feature endpoints with a
 * VALID, empty collection when nothing exists yet — the map stays open and
 * pannable, and the client shows an empty-state notice over it instead of
 * nothing at all. This is the exact defect class where "no projects" quietly
 * became "no map".
 */
final class MapZeroStateTest extends TestCase
{
    use RefreshDatabase;

    public function test_the_investment_map_page_renders_with_zero_projects(): void
    {
        $this->setFeatures(['map.investment' => true]);

        $this->get('/invest')
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('Public/Map/Invest')
                // The provider block is what the adapter boots the basemap
                // from; it must be present and usable with no data at all.
                ->has('map.provider')
                ->has('types'));
    }

    public function test_the_investment_features_endpoint_answers_an_empty_collection(): void
    {
        $this->setFeatures(['map.investment' => true]);

        $this->getJson('/invest/features?west=43.7&south=35.9&east=44.4&north=36.5&zoom=12')
            ->assertOk()
            // Empty LAYERS, valid COLLECTIONS: the client boots the basemap
            // and its sources from this shape whether or not rows exist.
            ->assertJsonPath('projects', [])
            ->assertJsonPath('boundaries.type', 'FeatureCollection')
            ->assertJsonPath('project_boundaries.type', 'FeatureCollection');
    }

    public function test_the_explorer_page_and_features_render_with_zero_data(): void
    {
        $this->setFeatures(['map.explorer' => true]);

        $this->get('/map')
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('Public/Map/Explorer')
                ->has('map.provider'));

        $this->getJson('/map/features?west=43.7&south=35.9&east=44.4&north=36.5&zoom=12')
            ->assertOk()
            ->assertJsonPath('projects', [])
            ->assertJsonPath('boundaries.type', 'FeatureCollection');
    }

    public function test_both_map_flags_ship_disabled_and_gate_only_the_public_surface(): void
    {
        // The documented root cause of "I cannot see the map": the launch
        // flags default OFF. That is a configuration state, not a defect —
        // pinned here so a changed default is a conscious decision.
        $this->assertFalse((bool) config('features.defaults')['map.explorer']);
        $this->assertFalse((bool) config('features.defaults')['map.investment']);

        $this->get('/invest')->assertNotFound();
        $this->get('/map')->assertNotFound();
    }

    public function test_the_features_screen_offers_both_map_flags_to_the_operator(): void
    {
        // The operator's switch lives on the existing feature-flags surface;
        // both map flags must be present there, ordinary (no super-admin
        // gate), so enabling the maps is a deliberate admin action.
        $admin = User::factory()->superAdmin()->create();

        $this->actingAs($admin)->get('/admin/features')
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->where('flags', fn (Collection $flags): bool => $flags->contains(
                    fn (array $flag): bool => $flag['flag'] === 'map.explorer' && $flag['requires_super_admin'] === false,
                ) && $flags->contains(
                    fn (array $flag): bool => $flag['flag'] === 'map.investment' && $flag['requires_super_admin'] === false,
                )));
    }

    public function test_enabling_the_flag_through_the_admin_surface_opens_the_public_map(): void
    {
        $admin = User::factory()->superAdmin()->create();

        $this->get('/invest')->assertNotFound();

        $this->actingAs($admin)
            ->put('/admin/features', ['flag' => 'map.investment', 'enabled' => true])
            ->assertRedirect();

        $this->post('/logout');

        // The whole journey the operator will actually take: toggle on the
        // admin screen, and the public surface answers guests.
        $this->get('/invest')->assertOk();
    }
}
