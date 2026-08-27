<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Modules\Geography\Models\Area;
use App\Modules\Geography\Models\Place;
use App\Modules\Geography\Models\PlaceCategory;
use App\Modules\Projects\Models\Project;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\JsonResponse;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

/**
 * GET /map/search — unified trilingual map search (Map Phase 5).
 *
 * The contract under proof: the incoming query folds through the SAME
 * SoraniText::searchKey() that built every stored search_key, so any
 * legitimately stored spelling — Sorani, Arabic, English, alias, or a
 * keyboard-variant Sorani — finds its entity; visibility is each surface's
 * own public rule (never a weaker search rule); results are hard-capped,
 * deterministically ranked, and carry only navigation-safe fields. LIKE
 * wildcards can never act as wildcards, and the behavior is identical on
 * SQLite and MariaDB (both CI lanes run this file).
 */
final class MapSearchTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->setFeatures([
            'map.explorer' => true,
            'places.database' => true,
        ]);
    }

    /** @param array<model-property<Area>, mixed> $overrides */
    private function area(string $slug, array $overrides = [], ?string $wkt = null): Area
    {
        $area = Area::query()->create($overrides + [
            'type' => 'district',
            'slug' => $slug,
            'name_ckb' => $slug,
            'latitude' => 36.19,
            'longitude' => 44.01,
            'publication_status' => 'published',
        ]);

        if ($wkt !== null) {
            $area->forceFill(['boundary_wkt' => $wkt])->save();
        }

        return $area->refresh();
    }

    /** @param array<model-property<Project>, mixed> $overrides */
    private function project(string $slug, array $overrides = []): Project
    {
        return Project::query()->create($overrides + [
            'slug' => $slug,
            'name_ckb' => $slug,
            'project_type' => 'residential',
            'construction_status' => 'under_construction',
            'delivery_status' => 'not_started',
            'publication_status' => 'published',
            'latitude' => 36.20,
            'longitude' => 44.02,
        ]);
    }

    private function category(string $key = 'school'): PlaceCategory
    {
        return PlaceCategory::query()->firstOrCreate(
            ['key' => $key],
            ['group' => 'education', 'name_ckb' => 'ckb '.$key, 'name_en' => 'en '.$key, 'is_active' => true, 'sort_order' => 1],
        );
    }

    /** @param array<model-property<Place>, mixed> $overrides */
    private function place(string $slug, array $overrides = []): Place
    {
        return Place::query()->create($overrides + [
            'slug' => $slug,
            'name_ckb' => $slug,
            'place_category_id' => $this->category()->id,
            'latitude' => 36.21,
            'longitude' => 44.03,
            'publication_status' => 'published',
            'is_public' => true,
            'is_duplicate_primary' => true,
            'operational_status' => 'operating',
        ]);
    }

    /** @return TestResponse<JsonResponse> */
    private function search(string $q): TestResponse
    {
        return $this->getJson('/map/search?q='.rawurlencode($q));
    }

    /* ------------------------------------------------------------ gates */

    public function test_the_endpoint_sits_behind_the_explorer_feature_flag(): void
    {
        $this->setFeatures(['map.explorer' => false]);

        $this->search('mufti')->assertNotFound();
    }

    public function test_query_length_bounds_are_enforced(): void
    {
        $this->search('m')->assertStatus(422);
        $this->search(str_repeat('a', 81))->assertStatus(422);
        $this->getJson('/map/search')->assertStatus(422);
    }

    public function test_a_query_that_folds_to_noise_answers_an_honest_empty(): void
    {
        $this->area('mufti', ['name_ckb' => 'موفتی']);

        $this->search('؟!')
            ->assertOk()
            ->assertJsonCount(0, 'groups.areas')
            ->assertJsonCount(0, 'groups.projects')
            ->assertJsonCount(0, 'groups.places');
    }

    /* ------------------------------------------------- trilingual matching */

    public function test_sorani_arabic_english_and_alias_spellings_all_find_the_entity(): void
    {
        $this->area('mufti', [
            'name_ckb' => 'موفتی',
            'name_ar' => 'المفتي',
            'name_en' => 'Mufti',
            'aliases' => ['Mufty Quarter'],
        ]);

        foreach (['موفتی', 'المفتي', 'Mufti', 'mufti', 'Mufty Quarter'] as $spelling) {
            $this->search($spelling)
                ->assertOk()
                ->assertJsonPath('groups.areas.0.slug', 'mufti');
        }
    }

    public function test_a_keyboard_variant_sorani_spelling_still_matches(): void
    {
        // Stored with Kurdish letters; queried the way an Arabic-layout
        // typist writes it: arabic yeh, heh for ae, plus a ZWNJ — exactly
        // the equivalences searchKey() folds.
        $this->area('hawler', ['name_ckb' => 'شاری هەولێر']);

        $this->search("شارى هه\u{200C}ولير")
            ->assertOk()
            ->assertJsonPath('groups.areas.0.slug', 'hawler');
    }

    public function test_like_wildcards_never_act_as_wildcards(): void
    {
        $this->place('ab-cd', ['name_en' => 'ab cd', 'name_ckb' => 'ab cd']);
        $this->place('abxcd', ['name_en' => 'abxcd', 'name_ckb' => 'abxcd']);

        // '_' folds to a separator, so it matches the spaced name and can
        // never wildcard-match the single-token one.
        $this->search('ab_cd')
            ->assertOk()
            ->assertJsonCount(1, 'groups.places')
            ->assertJsonPath('groups.places.0.slug', 'ab-cd');

        // '%' likewise folds away rather than matching everything.
        $this->search('%%')
            ->assertOk()
            ->assertJsonCount(0, 'groups.places');
    }

    /* ------------------------------------------------------ result shapes */

    public function test_an_area_result_carries_navigation_fields_and_cached_bounds_never_wkt(): void
    {
        $parent = $this->area('erbil-city', ['type' => 'city', 'name_ckb' => 'هەولێر']);
        $this->area(
            'mufti',
            ['name_ckb' => 'موفتی', 'parent_id' => $parent->id],
            'POLYGON((44.005 36.180, 44.025 36.180, 44.025 36.205, 44.005 36.205, 44.005 36.180))',
        );

        $response = $this->search('موفتی')
            ->assertOk()
            ->assertJsonPath('groups.areas.0.kind', 'area')
            ->assertJsonPath('groups.areas.0.slug', 'mufti')
            ->assertJsonPath('groups.areas.0.type', 'district')
            ->assertJsonPath('groups.areas.0.breadcrumb.0.name', 'هەولێر')
            ->assertJsonPath('groups.areas.0.bounds.north', 36.205)
            ->assertJsonPath('groups.areas.0.bounds.west', 44.005);

        $row = $response->json('groups.areas.0');
        $this->assertSame(
            ['kind', 'slug', 'name', 'type', 'type_label', 'breadcrumb', 'lat', 'lng', 'bounds'],
            array_keys($row),
        );
        $this->assertStringNotContainsString('POLYGON', json_encode($row) ?: '');
    }

    public function test_a_project_result_carries_its_area_and_real_coordinates(): void
    {
        $area = $this->area('ankawa', ['name_ckb' => 'ئەنکاوە']);
        $this->project('empire-world', [
            'name_en' => 'Empire World',
            'name_ckb' => 'ئیمپایەر وۆرڵد',
            'area_id' => $area->id,
            'latitude' => 36.2233333,
            'longitude' => 44.0091111,
        ]);

        $response = $this->search('empire')
            ->assertOk()
            ->assertJsonPath('groups.projects.0.kind', 'project')
            ->assertJsonPath('groups.projects.0.slug', 'empire-world')
            ->assertJsonPath('groups.projects.0.name', 'ئیمپایەر وۆرڵد')
            ->assertJsonPath('groups.projects.0.project_type', 'residential')
            ->assertJsonPath('groups.projects.0.area_name', 'ئەنکاوە')
            ->assertJsonPath('groups.projects.0.area_slug', 'ankawa');

        $row = $response->json('groups.projects.0');
        $this->assertSame(
            ['kind', 'slug', 'name', 'project_type', 'area_name', 'area_slug', 'lat', 'lng'],
            array_keys($row),
        );
        $this->assertEqualsWithDelta(36.2233333, $row['lat'], 0.0000001);
    }

    public function test_a_place_result_carries_category_and_area_but_never_private_fields(): void
    {
        $area = $this->area('mufti', ['name_ckb' => 'موفتی']);
        $place = $this->place('mufti-school', [
            'name_ckb' => 'قوتابخانەی موفتی',
            'area_id' => $area->id,
        ]);
        $place->setPhone('0750 000 0000');
        $place->save();

        $response = $this->search('قوتابخانەی')
            ->assertOk()
            ->assertJsonPath('groups.places.0.kind', 'place')
            ->assertJsonPath('groups.places.0.slug', 'mufti-school')
            ->assertJsonPath('groups.places.0.category', 'school')
            ->assertJsonPath('groups.places.0.category_name', 'ckb school')
            ->assertJsonPath('groups.places.0.area_name', 'موفتی');

        $row = $response->json('groups.places.0');
        $this->assertSame(
            ['kind', 'slug', 'name', 'category', 'category_name', 'area_name', 'lat', 'lng'],
            array_keys($row),
        );
        $this->assertStringNotContainsString('phone', json_encode($response->json()) ?: '');
    }

    /* --------------------------------------------------- visibility gates */

    public function test_unpublished_entities_are_never_disclosed(): void
    {
        $this->area('draft-area', ['name_ckb' => 'secretarea', 'publication_status' => 'draft']);
        $this->project('draft-project', ['name_ckb' => 'secretproject', 'publication_status' => 'draft']);
        $this->place('draft-place', ['name_ckb' => 'secretplace', 'publication_status' => 'draft']);

        foreach (['secretarea', 'secretproject', 'secretplace'] as $q) {
            $this->search($q)
                ->assertOk()
                ->assertJsonCount(0, 'groups.areas')
                ->assertJsonCount(0, 'groups.projects')
                ->assertJsonCount(0, 'groups.places');
        }
    }

    public function test_a_published_area_under_an_unpublished_parent_stays_hidden(): void
    {
        $draftParent = $this->area('draft-parent', ['publication_status' => 'draft']);
        $this->area('leaky-child', [
            'name_ckb' => 'leakychild',
            'parent_id' => $draftParent->id,
            // A parent must be strictly coarser than its child (Area::booted).
            'type' => 'neighborhood',
        ]);

        $this->search('leakychild')
            ->assertOk()
            ->assertJsonCount(0, 'groups.areas');
    }

    public function test_a_project_without_coordinates_never_appears(): void
    {
        $this->project('floating', [
            'name_ckb' => 'floatingproject',
            'latitude' => null,
            'longitude' => null,
        ]);

        $this->search('floatingproject')
            ->assertOk()
            ->assertJsonCount(0, 'groups.projects');
    }

    public function test_non_public_duplicate_and_closed_places_are_excluded(): void
    {
        $this->place('hidden', ['name_ckb' => 'gatedplace', 'is_public' => false]);
        $this->place('secondary', ['name_ckb' => 'gatedplace two', 'is_duplicate_primary' => false]);
        $this->place('closed', ['name_ckb' => 'gatedplace three', 'operational_status' => 'permanently_closed']);

        $this->search('gatedplace')
            ->assertOk()
            ->assertJsonCount(0, 'groups.places');
    }

    public function test_a_disabled_places_feature_removes_the_places_group_content(): void
    {
        $this->place('mufti-school', ['name_ckb' => 'قوتابخانەی موفتی']);
        $this->setFeatures(['map.explorer' => true, 'places.database' => false]);

        $this->search('قوتابخانەی')
            ->assertOk()
            ->assertJsonCount(0, 'groups.places');
    }

    /* ------------------------------------------------- ranking and caps */

    public function test_exact_beats_prefix_beats_contains_and_ties_are_deterministic(): void
    {
        $this->area('c-contains', ['name_en' => 'New Mufti Heights', 'name_ckb' => 'a']);
        $this->area('b-prefix', ['name_en' => 'Mufti Gardens', 'name_ckb' => 'b']);
        $this->area('a-exact', ['name_en' => 'Mufti', 'name_ckb' => 'c']);

        $this->search('mufti')
            ->assertOk()
            ->assertJsonPath('groups.areas.0.slug', 'a-exact')
            ->assertJsonPath('groups.areas.1.slug', 'b-prefix')
            ->assertJsonPath('groups.areas.2.slug', 'c-contains');
    }

    public function test_an_exact_alias_outranks_a_name_that_merely_contains(): void
    {
        $this->area('contains-it', ['name_en' => 'Greater English Village Zone', 'name_ckb' => 'a']);
        $this->area('alias-exact', ['name_en' => 'Gundi Inglizi', 'name_ckb' => 'b', 'aliases' => ['English Village']]);

        $this->search('english village')
            ->assertOk()
            ->assertJsonPath('groups.areas.0.slug', 'alias-exact');
    }

    public function test_result_caps_hold_per_group(): void
    {
        foreach (range(1, 7) as $i) {
            $this->area('cap-area-'.$i, ['name_en' => 'Capville '.$i, 'name_ckb' => 'z'.$i]);
        }

        foreach (range(1, 9) as $i) {
            $this->place('cap-place-'.$i, ['name_en' => 'Capville place '.$i, 'name_ckb' => 'y'.$i]);
        }

        $this->search('capville')
            ->assertOk()
            ->assertJsonCount(5, 'groups.areas')
            ->assertJsonCount(7, 'groups.places');
    }

    public function test_the_response_echoes_the_query_and_groups_every_type(): void
    {
        $this->area('mufti', ['name_en' => 'Mufti', 'name_ckb' => 'موفتی']);
        $this->project('mufti-towers', ['name_en' => 'Mufti Towers', 'name_ckb' => 'م تاوەرز']);
        $this->place('mufti-school', ['name_en' => 'Mufti Primary School', 'name_ckb' => 'قوتابخانە']);

        $this->search('Mufti')
            ->assertOk()
            ->assertJsonPath('query', 'Mufti')
            ->assertJsonPath('groups.areas.0.slug', 'mufti')
            ->assertJsonPath('groups.projects.0.slug', 'mufti-towers')
            ->assertJsonPath('groups.places.0.slug', 'mufti-school');
    }
}
