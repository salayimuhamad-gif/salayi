<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Modules\Geography\Enums\AreaType;
use App\Modules\Geography\Jobs\RecalculateNearbyPlaces;
use App\Modules\Geography\Models\Area;
use App\Modules\Geography\Models\Place;
use App\Modules\Geography\Models\PlaceCategory;
use App\Modules\Geography\Services\Osm\OsmPlaceImporter;
use App\Modules\Identity\Enums\RoleKey;
use App\Modules\Identity\Models\Role;
use App\Modules\Identity\Models\User;
use App\Modules\Projects\Enums\PublicationStatus;
use App\Modules\Projects\Models\Project;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

/**
 * The OSM place import pipeline (Map Phase 2): the importer's write rules,
 * the admin preview/confirm flow, and the bulk review actions.
 *
 * The contracts that matter most are the protective ones. Idempotency by
 * external_id (the same OSM object can never become two rows), curator work
 * outranking refresh (a verified, reviewed, published, differently-sourced
 * or deleted row is untouchable), manual area assignment surviving, drafts
 * landing invisible, and the publish hand-off firing the EXISTING
 * nearby-place recalculation — not a second pipeline.
 */
final class OsmPlaceImportTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->setFeatures(['places.database' => true]);
    }

    /* ------------------------------------------------------------ helpers */

    private function importer(): OsmPlaceImporter
    {
        return app(OsmPlaceImporter::class);
    }

    private function category(string $key, string $group = 'other', bool $active = true): PlaceCategory
    {
        return PlaceCategory::query()->firstOrCreate(
            ['key' => $key],
            ['group' => $group, 'name_ckb' => $key, 'is_active' => $active],
        );
    }

    /** A published district whose boundary contains (36.19, 44.01). */
    private function publishedAreaWithBoundary(string $slug = 'test-district'): Area
    {
        $area = Area::query()->create([
            'type' => AreaType::District->value,
            'slug' => $slug,
            'name_ckb' => $slug,
            'latitude' => 36.19,
            'longitude' => 44.01,
            'publication_status' => PublicationStatus::Published->value,
        ]);

        $area->forceFill([
            'boundary_wkt' => 'POLYGON((44.000 36.180, 44.020 36.180, 44.020 36.200, 44.000 36.200, 44.000 36.180))',
        ])->save();

        return $area->refresh();
    }

    /**
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    private function candidate(array $overrides = []): array
    {
        return array_merge([
            'external_id' => 'osm:node:1001',
            'category_key' => 'school',
            'subcategory' => null,
            'name_ckb' => 'قوتابخانەی ئازادی',
            'name_ar' => 'مدرسة آزادي',
            'name_en' => 'Azadi School',
            'aliases' => ['Azadi Primary'],
            'lat' => 36.19,
            'lng' => 44.01,
            'website' => null,
            'tags' => ['amenity' => 'school'],
            'source_url' => 'https://www.openstreetmap.org/node/1001',
        ], $overrides);
    }

    /* ------------------------------------------------------ importer core */

    public function test_import_creates_a_safe_reviewable_draft(): void
    {
        $this->category('school', 'education');
        $area = $this->publishedAreaWithBoundary();

        $summary = $this->importer()->import([$this->candidate()], actingUserId: null);

        $this->assertSame(1, $summary['created']);
        $this->assertSame(1, $summary['area_assigned']);

        $place = Place::query()->where('external_id', 'osm:node:1001')->firstOrFail();

        // The safe landing state: invisible until a human decides otherwise.
        $this->assertSame('draft', $place->publication_status->value);
        $this->assertSame('unverified', $place->verification_status);
        $this->assertSame('medium', $place->confidence);
        $this->assertSame('openstreetmap', $place->source);
        $this->assertSame('https://www.openstreetmap.org/node/1001', $place->source_url);

        // Trilingual names arrived as themselves; search key was synced even
        // though the observer-silent path was used.
        $this->assertSame('قوتابخانەی ئازادی', $place->name_ckb);
        $this->assertSame('Azadi School', $place->name_en);
        $this->assertNotNull($place->search_key);
        $this->assertNotNull($place->slug);

        // Area assignment went through the real resolver, provenance intact.
        $this->assertSame($area->id, $place->area_id);
        $this->assertFalse($place->area_is_manual);
        $this->assertSame('boundary', $place->area_match_type);
        $this->assertNotNull($place->area_assigned_at);
    }

    public function test_import_is_idempotent_by_external_id(): void
    {
        $this->category('school', 'education');

        $first = $this->importer()->import([$this->candidate()], null);
        $second = $this->importer()->import([$this->candidate()], null);

        $this->assertSame(1, $first['created']);
        $this->assertSame(0, $second['created']);
        $this->assertSame(1, $second['unchanged']);
        $this->assertSame(1, Place::query()->count());

        // The same object arriving twice in ONE batch is also a single row.
        Place::query()->forceDelete();
        $third = $this->importer()->import([$this->candidate(), $this->candidate()], null);
        $this->assertSame(1, $third['created']);
        $this->assertSame(1, Place::query()->count());
    }

    public function test_an_unreviewed_osm_row_is_refreshed_from_the_source(): void
    {
        $this->category('school', 'education');

        $this->importer()->import([$this->candidate()], null);

        $summary = $this->importer()->import([
            $this->candidate(['name_en' => 'Azadi School (renamed)', 'lat' => 36.191]),
        ], null);

        $this->assertSame(1, $summary['refreshed']);

        $place = Place::query()->where('external_id', 'osm:node:1001')->firstOrFail();
        $this->assertSame('Azadi School (renamed)', $place->name_en);
        $this->assertSame('36.1910000', $place->latitude);
    }

    public function test_curator_touched_rows_are_never_overwritten(): void
    {
        $this->category('school', 'education');
        $this->importer()->import([$this->candidate()], null);

        $protectedStates = [
            ['verification_status' => 'verified'],
            ['reviewed_by' => User::factory()->create()->id],
            ['verified_at' => now()],
            ['publication_status' => PublicationStatus::Published],
        ];

        foreach ($protectedStates as $state) {
            $place = Place::query()->where('external_id', 'osm:node:1001')->firstOrFail();
            $place->forceFill($state + ['name_en' => 'Curated Name'])->save();

            $summary = $this->importer()->import([
                $this->candidate(['name_en' => 'External Overwrite Attempt']),
            ], null);

            $this->assertSame(1, $summary['protected'], json_encode($state));
            $this->assertSame(0, $summary['refreshed']);
            $this->assertSame(
                'Curated Name',
                Place::query()->where('external_id', 'osm:node:1001')->firstOrFail()->name_en,
                json_encode($state),
            );

            // Reset for the next protected state.
            $place = Place::query()->where('external_id', 'osm:node:1001')->firstOrFail();
            $place->forceFill([
                'verification_status' => 'unverified', 'reviewed_by' => null,
                'verified_at' => null, 'publication_status' => PublicationStatus::Draft,
            ])->save();
        }
    }

    public function test_a_deleted_row_stays_deleted_and_a_foreign_source_is_untouched(): void
    {
        $this->category('school', 'education');

        // An administrator deleted the imported row on purpose.
        $this->importer()->import([$this->candidate()], null);
        Place::query()->where('external_id', 'osm:node:1001')->firstOrFail()->delete();

        $summary = $this->importer()->import([$this->candidate()], null);

        $this->assertSame(1, $summary['deleted_protected']);
        $this->assertSame(0, $summary['created']);
        $this->assertSame(0, Place::query()->count());

        // A row that happens to hold the id but was authored elsewhere.
        Place::query()->withTrashed()->forceDelete();
        Place::query()->create([
            'external_id' => 'osm:node:1001',
            'name_ckb' => 'دەستی مرۆڤ',
            'place_category_id' => $this->category('school')->id,
            'latitude' => 36.19, 'longitude' => 44.01,
            'source' => 'survey-2025',
        ]);

        $foreign = $this->importer()->import([$this->candidate(['name_ckb' => 'Overwrite'])], null);

        $this->assertSame(1, $foreign['foreign_source']);
        $this->assertSame('دەستی مرۆڤ', Place::query()->firstOrFail()->name_ckb);
    }

    public function test_a_manual_area_assignment_survives_refresh(): void
    {
        $this->category('school', 'education');
        $this->publishedAreaWithBoundary();

        $this->importer()->import([$this->candidate()], null);

        $manualArea = Area::query()->create([
            'type' => AreaType::District->value, 'slug' => 'manual-choice',
            'name_ckb' => 'manual-choice',
            'publication_status' => PublicationStatus::Published->value,
        ]);

        $place = Place::query()->where('external_id', 'osm:node:1001')->firstOrFail();
        $place->forceFill([
            'area_id' => $manualArea->id,
            'area_is_manual' => true,
            'area_match_type' => 'manual',
        ])->save();

        $this->importer()->import([$this->candidate(['name_en' => 'Refreshed'])], null);

        $place->refresh();
        $this->assertSame('Refreshed', $place->name_en);
        $this->assertSame($manualArea->id, $place->area_id);
        $this->assertTrue($place->area_is_manual);
        $this->assertSame('manual', $place->area_match_type);
    }

    public function test_an_inactive_category_skips_its_candidates(): void
    {
        $this->category('school', 'education', active: false);

        $summary = $this->importer()->import([$this->candidate()], null);

        $this->assertSame(1, $summary['missing_category']);
        $this->assertSame(0, Place::query()->count());
    }

    /* --------------------------------------------------------- admin flow */

    /** @return array<string, mixed> Overpass body with one school node. */
    private function overpassBody(): array
    {
        return ['elements' => [[
            'type' => 'node', 'id' => 1001, 'lat' => 36.19, 'lon' => 44.01,
            'tags' => ['amenity' => 'school', 'name:ckb' => 'قوتابخانەی ئازادی', 'name:en' => 'Azadi School'],
        ]]];
    }

    /** An ordinary places administrator: full geography permissions, no super-admin bypass. */
    private function gisPlacesManager(): User
    {
        $user = User::factory()->create();

        $role = Role::query()->firstOrCreate(
            ['key' => RoleKey::GisPlacesManager->value],
            ['name' => RoleKey::GisPlacesManager->value, 'is_system' => true],
        );

        $user->roles()->syncWithoutDetaching([$role->id]);

        return $user;
    }

    public function test_the_import_screen_is_gated_by_flag_and_permission(): void
    {
        // Flag OFF, ordinary places manager: the launch switch holds even
        // for a user holding every geography permission. On an ADMIN surface
        // EnsureFeatureEnabled says what happened (403, audited) — the 404
        // shape is reserved for public surfaces, which must not confirm the
        // feature exists.
        $this->setFeatures(['places.database' => false]);
        $manager = $this->gisPlacesManager();
        $this->actingAs($manager)->get('/admin/places/import')->assertForbidden();

        // Flag OFF, Super Admin: the EXISTING EnsureFeatureEnabled contract
        // — a disabled ADMIN surface stays reachable for a Super Admin as an
        // audited preview, because a flag is a launch switch, not an
        // authorisation. The import screen must not be an exception.
        $this->actingAs(User::factory()->superAdmin()->create())
            ->get('/admin/places/import')
            ->assertSuccessful();

        // Flag ON, user without geography.places.create: forbidden.
        $this->setFeatures(['places.database' => true]);
        $this->actingAs(User::factory()->create());
        $this->get('/admin/places/import')->assertForbidden();
        $this->post('/admin/places/import/preview', [
            'scope' => 'operating_area', 'groups' => ['education'],
        ])->assertForbidden();

        // Flag ON, places manager: the intended everyday path.
        $this->actingAs($manager)->get('/admin/places/import')->assertSuccessful();
    }

    public function test_preview_writes_nothing_and_stores_the_plan_in_session(): void
    {
        Http::fake(['overpass-api.de/*' => Http::response($this->overpassBody())]);
        $this->category('school', 'education');
        $admin = User::factory()->superAdmin()->create();

        $response = $this->actingAs($admin)
            ->from('/admin/places/import')
            ->post('/admin/places/import/preview', [
                'scope' => 'operating_area',
                'groups' => ['education'],
            ]);

        $response->assertRedirect('/admin/places/import');
        $response->assertSessionHas('places.osm_import');

        // The whole point of a preview: nothing was written.
        $this->assertSame(0, Place::query()->count());

        $preview = session('places.osm_import');
        $this->assertSame(1, $preview['counts']['new']);
        $this->assertSame(0, $preview['counts']['refreshable']);
    }

    public function test_confirm_imports_from_the_cached_answer_and_reports(): void
    {
        Http::fake(['overpass-api.de/*' => Http::response($this->overpassBody())]);
        $this->category('school', 'education');
        $admin = User::factory()->superAdmin()->create();

        $this->actingAs($admin)->post('/admin/places/import/preview', [
            'scope' => 'operating_area', 'groups' => ['education'],
        ]);

        $run = $this->actingAs($admin)->post('/admin/places/import/run');

        $run->assertRedirect('/admin/places/import');
        $run->assertSessionHas('success');
        $run->assertSessionMissing('places.osm_import');

        $this->assertSame(1, Place::query()->count());

        // Preview + confirm asked Overpass exactly once: the confirm re-read
        // the 24h cache rather than re-fetching or trusting the session.
        Http::assertSentCount(1);
    }

    public function test_confirm_without_a_preview_is_refused(): void
    {
        $admin = User::factory()->superAdmin()->create();

        $this->actingAs($admin)
            ->from('/admin/places/import')
            ->post('/admin/places/import/run')
            ->assertRedirect('/admin/places/import')
            ->assertSessionHasErrors('preview');

        $this->assertSame(0, Place::query()->count());
    }

    public function test_an_overpass_failure_surfaces_as_a_named_admin_error(): void
    {
        Http::fake(['overpass-api.de/*' => Http::response('', 429, ['Retry-After' => '90'])]);
        $this->category('school', 'education');

        $this->actingAs(User::factory()->superAdmin()->create())
            ->from('/admin/places/import')
            ->post('/admin/places/import/preview', [
                'scope' => 'operating_area', 'groups' => ['education'],
            ])
            ->assertRedirect('/admin/places/import')
            ->assertSessionHasErrors('overpass');

        $this->assertSame(0, Place::query()->count());
    }

    public function test_an_area_scope_post_filters_through_the_real_polygon(): void
    {
        // Two schools in the bbox; only one inside the polygon.
        Http::fake(['overpass-api.de/*' => Http::response(['elements' => [
            [
                'type' => 'node', 'id' => 1, 'lat' => 36.19, 'lon' => 44.01,
                'tags' => ['amenity' => 'school', 'name' => 'Inside'],
            ],
            [
                'type' => 'node', 'id' => 2, 'lat' => 36.30, 'lon' => 44.30,
                'tags' => ['amenity' => 'school', 'name' => 'Outside'],
            ],
        ]])]);

        $this->category('school', 'education');
        $area = $this->publishedAreaWithBoundary();
        $admin = User::factory()->superAdmin()->create();

        $this->actingAs($admin)->post('/admin/places/import/preview', [
            'scope' => 'area', 'area_id' => $area->id, 'groups' => ['education'],
        ]);

        $preview = session('places.osm_import');
        $this->assertSame(1, $preview['counts']['new']);
        $this->assertSame(1, $preview['counts']['outside_area']);

        $this->actingAs($admin)->post('/admin/places/import/run');

        $this->assertSame(1, Place::query()->count());
        $this->assertSame('osm:node:1', Place::query()->firstOrFail()->external_id);
    }

    /* -------------------------------------------------------- bulk review */

    public function test_bulk_publish_walks_legal_transitions_and_hands_off_to_recalculation(): void
    {
        Queue::fake();

        $this->category('school', 'education');
        $this->importer()->import([$this->candidate()], null);

        // A published project near the place: the existing observer must
        // queue ITS recalculation when the place becomes visible.
        $project = Project::query()->create([
            'slug' => 'nearby-project', 'name_ckb' => 'nearby-project',
            'project_type' => 'residential',
            'construction_status' => 'under_construction',
            'delivery_status' => 'not_started',
            'latitude' => 36.191, 'longitude' => 44.012,
            'publication_status' => PublicationStatus::Published->value,
        ]);

        $place = Place::query()->where('external_id', 'osm:node:1001')->firstOrFail();
        $this->assertSame('draft', $place->publication_status->value);

        $this->actingAs(User::factory()->superAdmin()->create())
            ->post('/admin/places/bulk-transition', [
                'action' => 'publish',
                'ids' => [$place->id],
            ])
            ->assertRedirect();

        $place->refresh();
        $this->assertSame('published', $place->publication_status->value);

        Queue::assertPushed(
            RecalculateNearbyPlaces::class,
            fn (RecalculateNearbyPlaces $job): bool => $job->projectId === $project->id,
        );
    }

    public function test_bulk_publish_requires_the_verify_permission(): void
    {
        $this->category('school', 'education');
        $this->importer()->import([$this->candidate()], null);
        $place = Place::query()->firstOrFail();

        // The data-editor role holds update-level access but not verify.
        $this->actingAs(User::factory()->create())
            ->post('/admin/places/bulk-transition', ['action' => 'publish', 'ids' => [$place->id]])
            ->assertForbidden();

        $this->assertSame('draft', $place->refresh()->publication_status->value);
    }

    public function test_bulk_unpublish_and_the_archived_refusal(): void
    {
        $this->category('school', 'education');
        $admin = User::factory()->superAdmin()->create();

        $published = Place::query()->create([
            'external_id' => 'osm:node:2001', 'name_ckb' => 'A',
            'place_category_id' => $this->category('school')->id,
            'latitude' => 36.19, 'longitude' => 44.01,
            'source' => 'openstreetmap',
            'publication_status' => PublicationStatus::Published,
        ]);

        $archived = Place::query()->create([
            'external_id' => 'osm:node:2002', 'name_ckb' => 'B',
            'place_category_id' => $this->category('school')->id,
            'latitude' => 36.19, 'longitude' => 44.01,
            'source' => 'openstreetmap',
            'publication_status' => PublicationStatus::Archived,
        ]);

        $this->actingAs($admin)->post('/admin/places/bulk-transition', [
            'action' => 'unpublish', 'ids' => [$published->id, $archived->id],
        ])->assertRedirect();

        $this->assertSame('unpublished', $published->refresh()->publication_status->value);
        // No legal path — a bulk action must not resurrect archived rows.
        $this->assertSame('archived', $archived->refresh()->publication_status->value);

        // Publishing walks archived rows nowhere either.
        $this->actingAs($admin)->post('/admin/places/bulk-transition', [
            'action' => 'publish', 'ids' => [$archived->id],
        ])->assertRedirect();
        $this->assertSame('archived', $archived->refresh()->publication_status->value);
    }

    public function test_bulk_selection_is_bounded(): void
    {
        $this->actingAs(User::factory()->superAdmin()->create())
            ->from('/admin/places')
            ->post('/admin/places/bulk-transition', [
                'action' => 'publish',
                'ids' => range(1, 201),
            ])
            ->assertSessionHasErrors('ids');
    }
}
