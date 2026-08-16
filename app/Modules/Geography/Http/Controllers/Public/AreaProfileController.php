<?php

declare(strict_types=1);

namespace App\Modules\Geography\Http\Controllers\Public;

use App\Modules\Core\Support\LocalizedRoutes;
use App\Modules\Geography\Enums\AreaType;
use App\Modules\Geography\Models\Area;
use App\Modules\Geography\Models\Place;
use App\Modules\Knowledge\Models\KnowledgeEvent;
use App\Modules\Projects\Enums\PublicationStatus;
use App\Modules\Projects\Models\Project;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Support\Collection;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Public Area profiles (spec 10.2, 12.2 — File one §4 "areas").
 *
 * These pages did not exist. `PublicLayout.vue` nevertheless rendered `/areas`
 * in the primary navigation unconditionally, and the homepage linked every
 * area chip to `/areas/{id}` — so the product advertised a surface that
 * returned 404 on every page, in all three languages. The packaging repair
 * disabled the links; this builds what they were pointing at.
 *
 * Two decisions worth stating, because both are load-bearing:
 *
 * PUBLICATION IS HIERARCHICAL. An area is publicly reachable only when it and
 * every ancestor is published. Without that, publishing a neighbourhood while
 * its district is still in review exposes the child through a direct URL and
 * leaks the unpublished parent's name into the breadcrumb. The materialised
 * path makes the check one query rather than a walk.
 *
 * COUNTS ARE DIRECT, LISTS ARE DIRECT. `Area::projects()` is deliberately
 * direct children only, and this controller does not quietly widen it to the
 * subtree. A district showing "48 projects" above a list of six is a number
 * nobody can reconcile; the subtree total is shown as its own labelled figure
 * instead, so both are checkable.
 */
final class AreaProfileController extends Controller
{
    /** Areas listed on the index, capped so a deep tree cannot produce an unbounded page. */
    private const INDEX_LIMIT = 200;

    /** Projects shown on a single area profile before paginating. */
    private const PROJECTS_PER_PAGE = 12;

    /** Notable places surfaced on a profile. */
    private const PLACES_LIMIT = 24;

    /** Same bound as the project profile's timeline (Phase 13). */
    private const TIMELINE_LIMIT = 25;

    /**
     * The area directory.
     *
     * Grouped by type rather than rendered as a tree: Erbil's hierarchy is
     * seven levels deep and a visitor looking for Ankawa wants to find it by
     * name, not to expand four districts to reach it.
     */
    public function index(Request $request): Response
    {
        $areas = Area::query()
            ->published()
            ->orderBy('depth')
            ->orderBy('name_ckb')
            ->limit(self::INDEX_LIMIT)
            ->get();

        // Ancestors are resolved in one pass rather than per-area, so the
        // directory costs two queries regardless of how many areas exist.
        $visible = $this->rejectAreasWithUnpublishedAncestors($areas);

        $projectCounts = $this->directProjectCounts($visible->pluck('id')->all());

        $grouped = $visible
            ->groupBy(static fn (Area $area): string => $area->type->value)
            ->map(static fn (Collection $group): array => $group
                ->map(static fn (Area $area): array => [
                    'slug' => $area->slug,
                    'name' => $area->name(),
                    'name_is_fallback' => $area->nameIsFallback(),
                    'project_count' => $projectCounts[$area->id] ?? 0,
                ])
                ->values()
                ->all())
            ->all();

        // Ordered by the enum, not by whatever the grouping produced, so the
        // directory reads city -> district -> ... every time.
        $ordered = [];

        foreach (AreaType::cases() as $type) {
            if (isset($grouped[$type->value])) {
                $ordered[] = [
                    'type' => $type->value,
                    'label' => __('geography.public.type.'.$type->value),
                    'areas' => $grouped[$type->value],
                ];
            }
        }

        return Inertia::render('Public/Areas/Index', [
            'groups' => $ordered,
            'total' => $visible->count(),
            'truncated' => $areas->count() >= self::INDEX_LIMIT,
            'seo' => [
                'title' => __('geography.public.index_title'),
                'canonical' => LocalizedRoutes::canonical('/areas'),
                'alternates' => LocalizedRoutes::alternates('/areas'),
            ],
        ]);
    }

    /**
     * A single area profile.
     *
     * Resolved by slug. An id would be stable but meaningless in a URL, and
     * the homepage's old `/areas/{id}` links are exactly the shape this
     * replaces.
     */
    public function show(Request $request, string $slug): Response
    {
        $area = Area::query()
            ->published()
            ->where('slug', $slug)
            ->first();

        // 404 rather than 403 for an unpublished area, matching
        // EnsureFeatureEnabled: "not found" and "exists but withheld" are
        // different disclosures and only one is safe to make to a visitor.
        abort_if($area === null, 404);

        $ancestors = $this->publishedAncestors($area);

        // A published area under an unpublished parent is not reachable. The
        // count comparison is the check: if any ancestor is missing from the
        // published set, the chain is broken.
        abort_if(count($ancestors) !== count($area->ancestorIds()), 404);

        $children = Area::query()
            ->published()
            ->where('parent_id', $area->id)
            ->orderBy('name_ckb')
            ->get();

        $childCounts = $this->directProjectCounts($children->pluck('id')->all());

        $projects = Project::query()
            ->where('area_id', $area->id)
            ->where('publication_status', PublicationStatus::Published->value)
            ->orderByDesc('published_at')
            ->paginate(self::PROJECTS_PER_PAGE)
            ->withQueryString();

        $places = Place::query()
            ->published()
            ->where('area_id', $area->id)
            ->where('is_public', true)
            ->with('category')
            ->orderBy('name_ckb')
            ->limit(self::PLACES_LIMIT)
            ->get();

        return Inertia::render('Public/Areas/Show', [
            'area' => [
                'slug' => $area->slug,
                'name' => $area->name(),
                'name_is_fallback' => $area->nameIsFallback(),
                'description' => $area->description(),
                'type' => $area->type->value,
                'type_label' => __('geography.public.type.'.$area->type->value),
                'lifestyle_classification' => $area->lifestyle_classification,
                // Present only when the area actually carries a point. A map
                // centred on a default coordinate is worse than no map.
                'coordinates' => $area->latitude !== null && $area->longitude !== null
                    ? ['latitude' => (float) $area->latitude, 'longitude' => (float) $area->longitude]
                    : null,
                'has_boundary' => filled($area->boundary_wkt),
            ],
            'breadcrumb' => array_map(static fn (Area $ancestor): array => [
                'slug' => $ancestor->slug,
                'name' => $ancestor->name(),
            ], $ancestors),
            'children' => $children->map(static fn (Area $child): array => [
                'slug' => $child->slug,
                'name' => $child->name(),
                'type_label' => __('geography.public.type.'.$child->type->value),
                'project_count' => $childCounts[$child->id] ?? 0,
            ])->all(),
            'projects' => [
                'items' => collect($projects->items())->map(static fn (Project $project): array => [
                    'slug' => $project->slug,
                    'name' => $project->name(),
                    'status' => $project->construction_status->value,
                ])->all(),
                'total' => $projects->total(),
                'current_page' => $projects->currentPage(),
                'last_page' => $projects->lastPage(),
            ],
            // The subtree figure is labelled separately rather than folded into
            // the direct count above it. See the class docblock.
            'subtree_project_count' => $this->subtreeProjectCount($area),
            'places' => $places->map(static fn (Place $place): array => [
                'name' => $place->name(),
                'category' => $place->category?->name(),
                'operational_status' => $place->operational_status,
            ])->all(),
            /*
             * The area's market timeline (Phase 13): the same public contract
             * the project profile uses — Published, unexpired, scoped to THIS
             * area's morph identity, one bounded query, the shared
             * publicTimelineEntry() shape. Not scopeAiUsable, and never
             * ai_usage_permitted: AI permission has never meant public
             * visibility (spec 21.3).
             */
            'timeline' => KnowledgeEvent::query()
                ->publiclyCurrent()
                ->where('entity_type', 'area')
                ->where('entity_id', $area->id)
                ->orderByDesc('effective_date')
                ->limit(self::TIMELINE_LIMIT)
                ->get()
                ->map(static fn (KnowledgeEvent $event): array => $event->publicTimelineEntry())
                ->all(),
            'seo' => [
                'title' => $area->name(),
                'canonical' => LocalizedRoutes::canonical('/areas/'.$area->slug),
                'alternates' => LocalizedRoutes::alternates('/areas/'.$area->slug),
            ],
        ]);
    }

    /**
     * Published ancestors of an area, outermost first.
     *
     * @return list<Area>
     */
    private function publishedAncestors(Area $area): array
    {
        $ids = $area->ancestorIds();

        if ($ids === []) {
            return [];
        }

        $byId = Area::query()
            ->published()
            ->whereIn('id', $ids)
            ->get()
            ->keyBy('id');

        $ordered = [];

        foreach ($ids as $id) {
            if ($byId->has($id)) {
                $ordered[] = $byId->get($id);
            }
        }

        return $ordered;
    }

    /**
     * Drop any area whose ancestor chain is not fully published.
     *
     * @param  Collection<int, Area>  $areas
     * @return Collection<int, Area>
     */
    private function rejectAreasWithUnpublishedAncestors(Collection $areas): Collection
    {
        $publishedIds = $areas->pluck('id')->flip();

        return $areas->filter(static function (Area $area) use ($publishedIds): bool {
            foreach ($area->ancestorIds() as $ancestorId) {
                if (! $publishedIds->has($ancestorId)) {
                    return false;
                }
            }

            return true;
        })->values();
    }

    /**
     * Published project counts keyed by area id, for direct children only.
     *
     * @param  list<int>  $areaIds
     * @return array<int, int>
     */
    private function directProjectCounts(array $areaIds): array
    {
        if ($areaIds === []) {
            return [];
        }

        return Project::query()
            ->whereIn('area_id', $areaIds)
            ->where('publication_status', PublicationStatus::Published->value)
            ->selectRaw('area_id, COUNT(*) as aggregate')
            ->groupBy('area_id')
            ->pluck('aggregate', 'area_id')
            ->map(static fn ($count): int => (int) $count)
            ->all();
    }

    /** Published projects anywhere beneath this area, via the materialised path. */
    private function subtreeProjectCount(Area $area): int
    {
        return Project::query()
            ->where('publication_status', PublicationStatus::Published->value)
            ->whereIn('area_id', Area::query()
                ->published()
                ->where('path', 'like', $area->path.'%')
                ->select('id'))
            ->count();
    }
}
