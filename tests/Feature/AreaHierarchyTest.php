<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Modules\Geography\Enums\AreaType;
use App\Modules\Geography\Http\Controllers\Admin\AreaController;
use App\Modules\Geography\Http\Requests\AreaRequest;
use App\Modules\Geography\Models\Area;
use App\Modules\Identity\Models\User;
use App\Modules\Projects\Enums\PublicationStatus;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use RuntimeException;
use Tests\TestCase;

/**
 * The materialised-path hierarchy must stay consistent and acyclic.
 *
 * Two defects are pinned here.
 *
 * CYCLES. The old check asked whether the proposed parent's path began with
 * '/{id}/', which is only ever true for a child of the root. Moving a nested
 * area under its own deep descendant passed the check and produced two rows
 * each inside the other's subtree.
 *
 * DEPTH. Moving a subtree rewrote descendant paths and left their `depth`
 * columns at the old level, so path and depth disagreed for every descendant.
 */
final class AreaHierarchyTest extends TestCase
{
    use RefreshDatabase;

    private function area(string $slug, AreaType $type, ?Area $parent = null): Area
    {
        return Area::query()->create([
            'parent_id' => $parent?->id,
            'type' => $type->value,
            'slug' => $slug,
            'name_ckb' => $slug,
            'publication_status' => PublicationStatus::Published->value,
        ]);
    }

    /**
     * city > district > zone > neighborhood, strictly coarse-to-fine
     *
     * @return array{0: Area, 1: Area, 2: Area, 3: Area} city, district, zone, neighborhood
     */
    private function chain(): array
    {
        $gov = $this->area('gov', AreaType::City);
        $city = $this->area('city', AreaType::District, $gov);
        $district = $this->area('district', AreaType::Zone, $city);
        $hood = $this->area('hood', AreaType::Neighborhood, $district);

        return [$gov, $city, $district, $hood];
    }

    /* ------------------------------------------------------------ cycles */

    public function test_a_root_area_cannot_be_its_own_parent(): void
    {
        $gov = $this->area('root', AreaType::City);

        $this->expectException(RuntimeException::class);

        $gov->parent_id = $gov->id;
        $gov->save();
    }

    public function test_a_nested_area_cannot_be_its_own_parent(): void
    {
        [, $city] = $this->chain();

        $this->expectException(RuntimeException::class);

        $city->parent_id = $city->id;
        $city->save();
    }

    public function test_an_area_cannot_move_under_its_direct_child(): void
    {
        [, $city, $district] = $this->chain();

        $this->expectException(RuntimeException::class);

        $city->parent_id = $district->id;
        $city->save();
    }

    /**
     * THE DEFECT. `/gov/city/` is not a prefix of `/city/`, so the old
     * fragment check never fired for a deeply nested descendant.
     */
    /**
     * THE DEFECT, reached directly.
     *
     * The coarse-to-fine type rule normally rejects an ancestor/descendant swap
     * before the cycle guard is consulted, so the cycle guard is defence in
     * depth for exactly the case where the type rule cannot help: a hierarchy
     * that already contains a coarse area beneath a fine one. That state is
     * reachable from legacy data or a direct SQL write, so the rows are
     * inserted directly here — the point is that the MOVE is refused, not that
     * the fixture is pretty.
     *
     * `fine` is a mahalla at '/fine/', `coarse` is a city nested beneath it at
     * '/fine/coarse/'. A city may contain a mahalla, so the type rule passes
     * and only the path comparison stands between this and a cycle.
     */
    public function test_an_area_cannot_move_under_a_deeply_nested_descendant(): void
    {
        // NESTED, not a root. The old check compared the proposed parent's
        // path against the fragment '/{id}/', which only ever matches a child
        // of the root — so a root-level fixture would pass against the broken
        // implementation too and prove nothing.
        $top = $this->area('top', AreaType::City);
        $mid = $this->area('mid', AreaType::District, $top);
        $fine = $this->area('fine', AreaType::Mahalla, $mid);

        $coarseId = DB::table('areas')->insertGetId([
            'parent_id' => $fine->id,
            'type' => AreaType::City->value,
            'slug' => 'coarse',
            'name_ckb' => 'coarse',
            'publication_status' => PublicationStatus::Published->value,
            'search_key' => 'coarse',
            'depth' => (int) $fine->depth + 1,
            'path' => $fine->path.'2/',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        DB::table('areas')
            ->where('id', $coarseId)
            ->update(['path' => $fine->path.$coarseId.'/']);

        $before = Area::query()->orderBy('id')->get()
            ->map(fn (Area $a): string => $a->id.':'.$a->path.':'.$a->depth)->all();

        try {
            $fine->parent_id = $coarseId;
            $fine->save();
            $this->fail('Moving an area under its own descendant must be refused.');
        } catch (RuntimeException $e) {
            $this->assertStringContainsString('cycle', $e->getMessage());
        }

        $after = Area::query()->orderBy('id')->get()
            ->map(fn (Area $a): string => $a->id.':'.$a->path.':'.$a->depth)->all();

        $this->assertSame($before, $after, 'A refused cycle altered the tree.');
    }

    /* ------------------------------------------------------- valid moves */

    public function test_a_valid_move_to_an_unrelated_branch_is_accepted(): void
    {
        [$gov, $city, $district] = $this->chain();
        $other = $this->area('city-two', AreaType::District, $gov);

        $district->parent_id = $other->id;
        $district->save();
        $district->refresh();

        $this->assertSame((int) $other->id, (int) $district->parent_id);
        $this->assertSame($other->path.$district->id.'/', $district->path);
        $this->assertSame((int) $other->depth + 1, (int) $district->depth);
    }

    /* --------------------------------------------------- depth and paths */

    /** Every descendant's path AND depth must follow the moved node. */
    public function test_moving_a_subtree_shallower_updates_descendant_depth(): void
    {
        [$gov, $city, $district, $hood] = $this->chain();

        $this->assertSame([0, 1, 2, 3], [
            (int) $gov->depth, (int) $city->depth, (int) $district->depth, (int) $hood->depth,
        ]);

        // district (depth 2) moves up under the governorate (depth 0).
        $district->parent_id = $gov->id;
        $district->save();

        $district->refresh();
        $hood->refresh();

        $this->assertSame(1, (int) $district->depth);
        $this->assertSame(2, (int) $hood->depth, 'The descendant depth did not follow the move.');
        $this->assertSame($gov->path.$district->id.'/', $district->path);
        $this->assertSame($district->path.$hood->id.'/', $hood->path);
    }

    public function test_moving_a_subtree_deeper_updates_descendant_depth(): void
    {
        [$gov, $city, $district, $hood] = $this->chain();
        $other = $this->area('city-two', AreaType::District, $gov);

        // Move district from city to city-two, then deeper is proven by depth.
        $district->parent_id = $other->id;
        $district->save();
        $district->refresh();
        $hood->refresh();

        $this->assertSame(2, (int) $district->depth);
        $this->assertSame(3, (int) $hood->depth);
        $this->assertSame($district->path.$hood->id.'/', $hood->path);
    }

    public function test_moving_a_subtree_to_the_root_updates_paths_and_depths(): void
    {
        [, , $district, $hood] = $this->chain();

        $district->parent_id = null;
        $district->save();
        $district->refresh();
        $hood->refresh();

        $this->assertSame('/'.$district->id.'/', $district->path);
        $this->assertSame(0, (int) $district->depth);
        $this->assertSame($district->path.$hood->id.'/', $hood->path);
        $this->assertSame(1, (int) $hood->depth);
    }

    /** An unrelated branch must not be touched by someone else's move. */
    public function test_an_unrelated_branch_is_untouched_by_a_move(): void
    {
        [$gov, , $district, $hood] = $this->chain();
        $other = $this->area('untouched', AreaType::District, $gov);
        $otherChild = $this->area('untouched-child', AreaType::Nahiya, $other);

        $beforePath = $otherChild->path;
        $beforeDepth = (int) $otherChild->depth;

        $district->parent_id = $gov->id;
        $district->save();

        $otherChild->refresh();

        $this->assertSame($beforePath, $otherChild->path);
        $this->assertSame($beforeDepth, (int) $otherChild->depth);
        $this->assertSame($hood->id, Area::query()->where('slug', 'hood')->value('id'));
    }

    /**
     * A refused move must leave BOTH path and depth exactly as they were.
     *
     * The cycle guard runs during `saving`, before any subtree rewrite, so a
     * rejection cannot leave a half-moved tree.
     */
    public function test_a_refused_move_changes_neither_path_nor_depth(): void
    {
        [, $city, , $hood] = $this->chain();

        $before = Area::query()->orderBy('id')->get()
            ->map(fn (Area $a): string => $a->id.':'.$a->path.':'.$a->depth)
            ->all();

        try {
            $city->parent_id = $hood->id;
            $city->save();
        } catch (RuntimeException) {
            // expected
        }

        $after = Area::query()->orderBy('id')->get()
            ->map(fn (Area $a): string => $a->id.':'.$a->path.':'.$a->depth)
            ->all();

        $this->assertSame($before, $after, 'A refused move altered the tree.');
    }

    /* ----------------------------------------- admin store error parity */

    /**
     * The model guard firing on CREATE must be a field error, not a 500.
     *
     * Request validation and the model's `saving` hook each read the parent
     * row independently, so the parent can be reclassified between them —
     * validation sees the old, compatible type and passes; the hook sees the
     * new, incompatible one and throws. update() already converted that
     * RuntimeException into a parent_id error; store() let it escape as a
     * 500. The race is reproduced literally: the request is validated first,
     * the parent is changed, then store() runs.
     */
    public function test_a_parent_reclassified_after_validation_is_a_field_error_not_a_500(): void
    {
        $admin = User::factory()->superAdmin()->create();
        $parent = $this->area('parent-city', AreaType::City);

        $request = AreaRequest::create('/admin/areas', 'POST', [
            'name_ckb' => 'گەڕەکی تاقیکردنەوە',
            'slug' => 'toctou-child',
            'type' => AreaType::Neighborhood->value,
            'parent_id' => $parent->id,
        ]);
        $request->setUserResolver(fn (): User => $admin);
        $request->setContainer(app())->setRedirector(app('redirect'));
        $this->withSession([]);
        $request->validateResolved(); // passes: a city may contain a neighborhood

        // The race: the parent becomes finer than the child before the save.
        $parent->type = AreaType::Mahalla;
        $parent->save();

        $response = app(AreaController::class)->store($request);

        $this->assertSame(302, $response->getStatusCode());
        $this->assertArrayHasKey(
            'parent_id',
            session('errors')?->getBag('default')->toArray() ?? [],
            'The hierarchy rejection must surface as a parent_id field error.',
        );
        $this->assertSame(
            0,
            Area::query()->where('slug', 'toctou-child')->count(),
            'A refused create must not persist the area.',
        );
    }

    /** The guard wrap must not disturb an ordinary successful create. */
    public function test_a_valid_create_through_the_admin_door_still_succeeds(): void
    {
        $admin = User::factory()->superAdmin()->create();
        $parent = $this->area('parent-district', AreaType::District);

        $response = $this->actingAs($admin)->post('/admin/areas', [
            'name_ckb' => 'گەڕەکی نوێ',
            'slug' => 'store-parity-child',
            'type' => AreaType::Neighborhood->value,
            'parent_id' => $parent->id,
        ]);

        $area = Area::query()->where('slug', 'store-parity-child')->firstOrFail();
        $response->assertRedirect("/admin/areas/{$area->id}/edit");
        $this->assertSame((int) $parent->id, (int) $area->parent_id);
    }

    /**
     * Prefix rewriting must not corrupt a path that repeats its own prefix.
     *
     * `REPLACE(path, '/1/', '/9/1/')` rewrites EVERY occurrence, so a
     * descendant whose path repeats the moved prefix was mangled. Only the
     * leading prefix may change.
     */
    public function test_a_path_repeating_its_prefix_is_rewritten_only_at_the_front(): void
    {
        [$gov, $city, $district, $hood] = $this->chain();
        $target = $this->area('target', AreaType::District, $gov);

        $oldHoodTail = substr((string) $hood->path, strlen((string) $district->path));

        // `city` is a district; `target` is a district too, so move the ZONE
        // beneath it instead — a legal coarse-to-fine move that still relocates
        // a subtree whose descendant path repeats the moved prefix.
        $district->parent_id = $target->id;
        $district->save();

        $district->refresh();
        $hood->refresh();

        $this->assertSame($target->path.$district->id.'/', $district->path);
        // The tail below the moved node is preserved byte for byte.
        $this->assertSame($district->path.$oldHoodTail, $hood->path);
        $this->assertSame($district->path.$hood->id.'/', $hood->path);
        $this->assertSame((int) $district->depth + 1, (int) $hood->depth);
    }
}
