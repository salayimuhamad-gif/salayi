<?php

declare(strict_types=1);

namespace App\Modules\Geography\Services;

use App\Modules\Geography\Enums\AreaType;
use App\Modules\Geography\Models\Area;
use App\Modules\Geography\Support\Polygon;
use App\Modules\Geography\Support\Wkt;
use App\Modules\Geography\ValueObjects\Coordinates;
use Illuminate\Support\Collection;
use Throwable;

/**
 * Resolves which area contains a coordinate (File two §4, §6.1).
 *
 * §4 requires that a project be linked to its area automatically from its
 * location, and §6.1 makes that the first step of the whole nearby-places
 * pipeline. Every primitive this needs already existed — `Polygon::polygonContains`,
 * `Wkt::parsePolygon`, the cached bounding boxes on `areas` — and nothing
 * called them. Areas were assigned by hand or not at all, which meant "every
 * project in Ronaki" depended on whoever typed the form remembering to pick it.
 *
 * THE RESULT IS THE MOST SPECIFIC MATCH. A point inside Erbil, inside a
 * district, inside a mahalla matches all three; returning the city would be
 * true and useless. Areas are therefore ordered by depth descending and the
 * first containing match wins.
 *
 * Two-stage matching, for the same reason NearbyPlaceCalculator uses it: the
 * cached bbox columns are four indexed range predicates that eliminate almost
 * every candidate, and ray-casting — which is O(vertices) in PHP — runs only on
 * what survives. A city boundary can carry thousands of vertices, and doing
 * that for every area on every project save would be felt on shared hosting.
 */
final class AreaResolver
{
    /**
     * The area whose boundary contains this point, most specific first.
     *
     * Returns null when nothing contains it — which is a real answer, not a
     * failure. A project outside every drawn boundary must not be silently
     * attached to the nearest area; that would fabricate a location claim.
     */
    public function resolve(Coordinates $point, bool $publishedOnly = true): ?Area
    {
        foreach ($this->candidates($point, $publishedOnly) as $area) {
            if (! $this->contains($area, $point)) {
                continue;
            }

            /*
             * ANCESTRY IS PART OF THE POLICY, and it belongs here rather than
             * in one caller. The observer had this check and the Wizard's
             * nearby suggestion did not, so the two disagreed: the suggestion
             * offered a published neighbourhood whose district was still in
             * review, and submission then refused it.
             *
             * Candidates arrive finest-first, so continuing the loop yields
             * the finest PUBLISHABLE ancestor — coarser but true beats precise
             * but undisclosable.
             */
            if (! $publishedOnly || $this->ancestryIsPublished($area)) {
                return $area;
            }
        }

        return null;
    }

    /**
     * Whether this area and every ancestor is published.
     *
     * A published neighbourhood under an unpublished district must not be
     * named publicly: the same rule public Area profiles apply to their
     * breadcrumbs. Geometry must not be a way around it.
     */
    public function ancestryIsPublished(Area $area): bool
    {
        if ($area->publication_status->value !== 'published') {
            return false;
        }

        $ancestorIds = $area->ancestorIds();

        if ($ancestorIds === []) {
            return true;
        }

        return Area::query()
            ->whereIn('id', $ancestorIds)
            ->where('publication_status', 'published')
            ->count() === count($ancestorIds);
    }

    /**
     * Every containing area, coarsest first.
     *
     * Useful for breadcrumbs ("Erbil › Ronaki") and for area-level rollups
     * where a project should count towards its district as well as its mahalla.
     *
     * @return list<Area>
     */
    public function resolveAll(Coordinates $point, bool $publishedOnly = true): array
    {
        $matches = [];

        foreach ($this->candidates($point, $publishedOnly) as $area) {
            if (! $this->contains($area, $point)) {
                continue;
            }

            // Same policy as resolve(). A breadcrumb built from resolveAll()
            // would otherwise name an ancestor still in review.
            if ($publishedOnly && ! $this->ancestryIsPublished($area)) {
                continue;
            }

            $matches[] = $area;
        }

        usort($matches, static fn (Area $a, Area $b): int => $a->depth <=> $b->depth);

        return $matches;
    }

    /**
     * Does this specific area contain the point?
     *
     * Public so a reviewer screen can ask about one area without scanning all
     * of them, and so the admin override UI can warn when a manually chosen
     * area does not actually contain the project.
     */
    public function contains(Area $area, Coordinates $point): bool
    {
        $wkt = $area->boundary_wkt;

        if (! is_string($wkt) || $wkt === '') {
            return false;
        }

        $type = Wkt::type($wkt);

        try {
            if ($type === 'MULTIPOLYGON') {
                foreach (Wkt::parseMultiPolygon($wkt) as $rings) {
                    if (Polygon::polygonContains($rings, $point)) {
                        return true;
                    }
                }

                return false;
            }

            if ($type === 'POLYGON') {
                return Polygon::polygonContains(Wkt::parsePolygon($wkt), $point);
            }
        } catch (Throwable) {
            // A malformed boundary must not take down a project save. It is a
            // data-quality problem for the geography admin to fix, and treating
            // it as "does not contain" is the honest reading.
            return false;
        }

        return false;
    }

    /**
     * Areas whose cached bounding box could contain the point, finest first.
     *
     * Ordering by depth descending is what makes `resolve()` return the most
     * specific match without loading the whole tree.
     *
     * @return Collection<int, Area>
     */
    private function candidates(Coordinates $point, bool $publishedOnly = true): Collection
    {
        /*
         * PUBLICATION POLICY, stated once and applied everywhere.
         *
         * The default is PUBLISHED ONLY. Resolving against unpublished areas
         * put a draft area's name onto a public project page — the same
         * disclosure the Area profile's hierarchical check exists to prevent,
         * arriving through geometry instead of through a link. An area in
         * review is not a place the product will name in public.
         *
         * `$publishedOnly = false` exists for administrative tools that
         * legitimately need to see where a point falls among draft areas. It
         * is opt-in, so no public path can reach it by omission.
         */
        return Area::query()
            ->when($publishedOnly, static fn ($query) => $query->where('publication_status', 'published'))
            ->whereNotNull('boundary_wkt')
            ->where('bbox_min_lat', '<=', $point->latitude)
            ->where('bbox_max_lat', '>=', $point->latitude)
            ->where('bbox_min_lng', '<=', $point->longitude)
            ->where('bbox_max_lng', '>=', $point->longitude)
            ->orderByDesc('depth')
            ->get();
    }

    /**
     * The finest area type that should own a project directly.
     *
     * Used by the admin UI to warn when a project has been attached to a city
     * while a mahalla containing it exists — technically correct, practically
     * a data-entry slip.
     */
    public function isSpecificEnough(?Area $area): bool
    {
        return $area !== null && $area->type !== AreaType::City;
    }
}
