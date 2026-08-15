<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Modules\Geography\Enums\AreaType;
use App\Modules\Geography\Models\Area;
use App\Modules\Geography\Models\Place;
use App\Modules\Geography\Models\PlaceCategory;
use App\Modules\Identity\Models\User;
use App\Modules\Projects\Models\Project;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The geometry round trip the map depends on, proven over HTTP.
 *
 * Create → Save → Reload → Edit → Update → Reload for the two records whose
 * boundaries feed the public map. Unit coverage of the WKT helpers already
 * exists; what nothing proved before is that the ADMIN FORM PATH preserves a
 * location verbatim across the full lifecycle — the exact workflow an editor
 * performs, and the one a silent parsing or serialisation drift would corrupt
 * without anyone noticing until markers moved.
 *
 * Also pins the door-parity rule stated in ProjectRequest: every entry path
 * into a boundary column enforces the same strict topology validation. The
 * area door accepted self-intersecting rings until it was aligned; these
 * tests keep both doors honest.
 */
final class GeometryWorkflowTest extends TestCase
{
    use RefreshDatabase;

    /** A closed square west of the citadel, inside the operating area. */
    private const SQUARE = 'POLYGON((44.0000000 36.1800000, 44.0200000 36.1800000, '
        .'44.0200000 36.2000000, 44.0000000 36.2000000, 44.0000000 36.1800000))';

    /** The same four corners reordered so the edges cross: a bow tie. */
    private const BOWTIE = 'POLYGON((44.0000000 36.1800000, 44.0200000 36.2000000, '
        .'44.0000000 36.2000000, 44.0200000 36.1800000, 44.0000000 36.1800000))';

    /** A second, shifted square for the update leg. */
    private const SQUARE_MOVED = 'POLYGON((44.0300000 36.1800000, 44.0500000 36.1800000, '
        .'44.0500000 36.2000000, 44.0300000 36.2000000, 44.0300000 36.1800000))';

    private function admin(): User
    {
        return User::factory()->superAdmin()->create();
    }

    /** @return array<string, mixed> */
    private function projectInput(): array
    {
        return [
            'name_ckb' => 'پرۆژەی جیۆمەتری',
            'slug' => 'geometry-roundtrip',
            'project_type' => 'residential',
            'construction_status' => 'under_construction',
            'delivery_status' => 'scheduled',
            'latitude' => '36.1900000',
            'longitude' => '44.0100000',
            'boundary_wkt' => self::SQUARE,
        ];
    }

    public function test_project_location_survives_the_full_admin_form_round_trip(): void
    {
        $admin = $this->admin();

        // CREATE → SAVE
        $response = $this->actingAs($admin)->post('/admin/projects', $this->projectInput());

        $project = Project::query()->firstOrFail();
        $response->assertRedirect("/admin/projects/{$project->id}/edit");

        $this->assertSame('36.1900000', (string) $project->latitude);
        $this->assertSame('44.0100000', (string) $project->longitude);
        $this->assertSame(self::SQUARE, $project->boundary_wkt);

        // RELOAD: the edit form must hand back exactly what was stored.
        $this->actingAs($admin)
            ->get("/admin/projects/{$project->id}/edit")
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->where('project.latitude', '36.1900000')
                ->where('project.longitude', '44.0100000')
                ->where('project.boundary_wkt', self::SQUARE));

        // EDIT → UPDATE: move the point and the outline.
        $this->actingAs($admin)
            ->put("/admin/projects/{$project->id}", [
                'latitude' => '36.1950000',
                'longitude' => '44.0400000',
                'boundary_wkt' => self::SQUARE_MOVED,
            ] + $this->projectInput())
            ->assertSessionHasNoErrors();

        // RELOAD again: the update, not the original, is what persists.
        $project->refresh();
        $this->assertSame('36.1950000', (string) $project->latitude);
        $this->assertSame('44.0400000', (string) $project->longitude);
        $this->assertSame(self::SQUARE_MOVED, $project->boundary_wkt);

        $this->actingAs($admin)
            ->get("/admin/projects/{$project->id}/edit")
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->where('project.latitude', '36.1950000')
                ->where('project.boundary_wkt', self::SQUARE_MOVED));
    }

    public function test_area_boundary_survives_the_full_admin_form_round_trip(): void
    {
        $admin = $this->admin();

        $input = [
            'name_ckb' => 'ناوچەی جیۆمەتری',
            'slug' => 'geometry-area',
            'type' => AreaType::District->value,
            'latitude' => '36.1900000',
            'longitude' => '44.0100000',
            'boundary_wkt' => self::SQUARE,
        ];

        $response = $this->actingAs($admin)->post('/admin/areas', $input);
        $response->assertSessionHasNoErrors();

        $area = Area::query()->firstOrFail();
        $response->assertRedirect("/admin/areas/{$area->id}/edit");
        $this->assertSame(self::SQUARE, $area->boundary_wkt);

        $this->actingAs($admin)
            ->put("/admin/areas/{$area->id}", ['boundary_wkt' => self::SQUARE_MOVED] + $input)
            ->assertSessionHasNoErrors();

        $area->refresh();
        $this->assertSame(self::SQUARE_MOVED, $area->boundary_wkt);

        $this->actingAs($admin)
            ->get("/admin/areas/{$area->id}/edit")
            ->assertOk();
    }

    /**
     * The area door now runs the same strict topology rule as the project
     * door. A bow tie was accepted here before the alignment — it parsed, so
     * the weaker check passed it, and the map layer later skipped it in
     * silence.
     */
    public function test_a_self_intersecting_area_boundary_is_rejected_at_entry(): void
    {
        $admin = $this->admin();

        $this->actingAs($admin)
            ->post('/admin/areas', [
                'name_ckb' => 'ناوچەی گرێی پاپیۆن',
                'slug' => 'bowtie-area',
                'type' => AreaType::District->value,
                'boundary_wkt' => self::BOWTIE,
            ])
            ->assertSessionHasErrors('boundary_wkt');

        $this->assertSame(0, Area::query()->count());
    }

    public function test_a_self_intersecting_boundary_cannot_replace_a_valid_one(): void
    {
        $admin = $this->admin();

        $area = Area::query()->create([
            'name_ckb' => 'ناوچەی دروست',
            'slug' => 'valid-area',
            'type' => AreaType::District->value,
            'boundary_wkt' => self::SQUARE,
        ]);

        $this->actingAs($admin)
            ->put("/admin/areas/{$area->id}", [
                'name_ckb' => 'ناوچەی دروست',
                'slug' => 'valid-area',
                'type' => AreaType::District->value,
                'boundary_wkt' => self::BOWTIE,
            ])
            ->assertSessionHasErrors('boundary_wkt');

        $this->assertSame(self::SQUARE, $area->refresh()->boundary_wkt);
    }

    /**
     * The place door, proven the same way. This POST is what surfaced the
     * `created_by` mass-assignment defect on Area and Place: the controllers
     * attribute the row to its author, neither model allowed the column, and
     * no test had ever driven either admin create form.
     */
    public function test_place_location_survives_the_admin_form_round_trip(): void
    {
        $this->setFeatures(['places.database' => true]);
        $admin = $this->admin();

        $category = PlaceCategory::query()->create([
            'key' => 'fixture-category', 'group' => 'other', 'name_ckb' => 'Fixture', 'is_active' => true,
        ]);

        $this->actingAs($admin)
            ->post('/admin/places', [
                'name_ckb' => 'شوێنی جیۆمەتری',
                'slug' => 'geometry-place',
                'place_category_id' => $category->id,
                'operational_status' => 'operating',
                'latitude' => '36.1900000',
                'longitude' => '44.0100000',
            ])
            ->assertSessionHasNoErrors();

        $place = Place::query()->firstOrFail();
        $this->assertSame('36.1900000', (string) $place->latitude);

        $this->actingAs($admin)
            ->put("/admin/places/{$place->id}", [
                'name_ckb' => 'شوێنی جیۆمەتری',
                'slug' => 'geometry-place',
                'place_category_id' => $category->id,
                'operational_status' => 'operating',
                'latitude' => '36.1950000',
                'longitude' => '44.0150000',
            ])
            ->assertSessionHasNoErrors();

        $this->assertSame('36.1950000', (string) $place->refresh()->latitude);
    }

    public function test_the_project_door_still_rejects_the_same_bow_tie(): void
    {
        $admin = $this->admin();

        $this->actingAs($admin)
            ->post('/admin/projects', ['boundary_wkt' => self::BOWTIE] + $this->projectInput())
            ->assertSessionHasErrors('boundary_wkt');

        $this->assertSame(0, Project::query()->count());
    }

    /** A square with a fully interior square hole — the citadel courtyard. */
    private const HOLED = 'POLYGON((44.0000000 36.1800000, 44.0200000 36.1800000, '
        .'44.0200000 36.2000000, 44.0000000 36.2000000, 44.0000000 36.1800000), '
        .'(44.0050000 36.1850000, 44.0100000 36.1850000, 44.0100000 36.1900000, '
        .'44.0050000 36.1900000, 44.0050000 36.1850000))';

    /** Two disjoint squares, the second carrying a hole of its own. */
    private const MULTI = 'MULTIPOLYGON(((44.0000000 36.1800000, 44.0200000 36.1800000, '
        .'44.0200000 36.2000000, 44.0000000 36.2000000, 44.0000000 36.1800000)), '
        .'((44.0300000 36.2100000, 44.0500000 36.2100000, 44.0500000 36.2300000, '
        .'44.0300000 36.2300000, 44.0300000 36.2100000), '
        .'(44.0350000 36.2150000, 44.0400000 36.2150000, 44.0400000 36.2200000, '
        .'44.0350000 36.2200000, 44.0350000 36.2150000)))';

    /**
     * Canonical L1: a boundary RICHER than the simple picker's single-ring
     * editing model must survive an untouched admin-form save byte for byte.
     *
     * The form binds boundary_wkt two-way; when the editor never touches the
     * boundary, the update request carries the stored WKT back verbatim —
     * exactly what these requests simulate for a Polygon-with-hole and a
     * MultiPolygon. The picker may only DISPLAY such geometry (read-only,
     * with a stated limitation); nothing on the untouched path may rewrite,
     * simplify or drop it.
     */
    public function test_a_holed_polygon_survives_an_untouched_form_save_verbatim(): void
    {
        $admin = $this->admin();

        $input = [
            'name_ckb' => 'ناوچەی کون',
            'slug' => 'geometry-holed',
            'type' => AreaType::District->value,
            'latitude' => '36.1900000',
            'longitude' => '44.0100000',
            'boundary_wkt' => self::HOLED,
        ];

        $this->actingAs($admin)->post('/admin/areas', $input)->assertSessionHasNoErrors();

        $area = Area::query()->firstOrFail();
        $this->assertSame(self::HOLED, $area->boundary_wkt);

        // The edit form hands the stored WKT back in full fidelity…
        $this->actingAs($admin)
            ->get("/admin/areas/{$area->id}/edit")
            ->assertOk()
            ->assertInertia(fn ($page) => $page->where('area.boundary_wkt', self::HOLED));

        // …and an untouched save (same boundary, unrelated field changed)
        // must not cost a single byte of it.
        $this->actingAs($admin)
            ->put("/admin/areas/{$area->id}", ['name_ckb' => 'ناوچەی کون نوێ'] + $input)
            ->assertSessionHasNoErrors();

        $area->refresh();
        $this->assertSame(self::HOLED, $area->boundary_wkt);
    }

    public function test_a_multipolygon_survives_an_untouched_form_save_verbatim(): void
    {
        $admin = $this->admin();

        $input = [
            'name_ckb' => 'ناوچەی فرەپارچە',
            'slug' => 'geometry-multi',
            'type' => AreaType::District->value,
            'latitude' => '36.1900000',
            'longitude' => '44.0100000',
            'boundary_wkt' => self::MULTI,
        ];

        $this->actingAs($admin)->post('/admin/areas', $input)->assertSessionHasNoErrors();

        $area = Area::query()->firstOrFail();
        $this->assertSame(self::MULTI, $area->boundary_wkt);

        $this->actingAs($admin)
            ->get("/admin/areas/{$area->id}/edit")
            ->assertOk()
            ->assertInertia(fn ($page) => $page->where('area.boundary_wkt', self::MULTI));

        $this->actingAs($admin)
            ->put("/admin/areas/{$area->id}", ['name_ckb' => 'ناوچەی فرەپارچە نوێ'] + $input)
            ->assertSessionHasNoErrors();

        $area->refresh();
        $this->assertSame(self::MULTI, $area->boundary_wkt);
    }

    /**
     * The picker's complex-boundary limitation is STATED, in every shipped
     * locale — a key that falls back to its own name would surface raw
     * `geography.map.…` text in the admin.
     */
    public function test_the_complex_boundary_strings_resolve_in_every_locale(): void
    {
        foreach (['ckb', 'ar', 'en'] as $locale) {
            foreach (['complex_notice', 'replace_boundary', 'replace_confirm', 'clear_confirm'] as $key) {
                $line = trans("geography.map.{$key}", [], $locale);

                $this->assertIsString($line);
                $this->assertNotSame("geography.map.{$key}", $line, "missing {$locale} translation for {$key}");
                $this->assertNotSame('', trim($line));
            }
        }
    }
}
