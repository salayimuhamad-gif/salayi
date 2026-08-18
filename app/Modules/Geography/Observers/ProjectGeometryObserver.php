<?php

declare(strict_types=1);

namespace App\Modules\Geography\Observers;

use App\Modules\Geography\Jobs\RecalculateNearbyPlaces;
use App\Modules\Geography\Models\Area;
use App\Modules\Geography\Services\AreaResolver;
use App\Modules\Geography\ValueObjects\Coordinates;
use App\Modules\Projects\Models\Project;

/**
 * A project that moves needs its area re-resolved and its nearby places
 * recalculated (File two §4, §6.1; spec 10.5).
 *
 * Only geometry changes trigger this. Renaming a project or correcting its
 * unit count does not move it, and queueing a recalculation on every save would
 * make routine editing expensive on a host with one cron-driven worker.
 *
 * Area assignment happens INLINE while nearby places are queued. They look
 * similar and are not: resolving an area is a handful of indexed rows and a
 * ray-cast, and the answer is needed immediately because the admin screen shows
 * it on the very next render. Nearby places is a scan over the whole places
 * table per category and belongs in the queue.
 */
final class ProjectGeometryObserver
{
    public function __construct(private readonly AreaResolver $areas) {}

    public function saved(Project $project): void
    {
        if (! $project->wasChanged(['latitude', 'longitude', 'boundary_wkt'])) {
            return;
        }

        $coordinates = $project->coordinates();

        /*
         * Coordinates REMOVED. This used to return early, which left the old
         * area attached: a project stripped of its location kept claiming to
         * be in Ankawa, and every listing, filter and index built on area_id
         * carried on counting it there. A stale assignment is worse than none,
         * because nothing about it looks wrong.
         */
        if ($coordinates === null) {
            $this->clearAutomaticArea($project);

            return;
        }

        $this->assignArea($project, $coordinates);

        RecalculateNearbyPlaces::dispatch($project->id);
    }

    /**
     * Resolve and store the containing area (File two §4).
     *
     * A manual assignment is never overwritten. §4 requires both automatic
     * linking and administrator correction, and the only way both can hold is
     * if the automatic pass yields to the human one — otherwise the next time
     * anybody nudges a coordinate the correction silently reverts, and the
     * administrator finds out weeks later from a wrong listing.
     *
     * ALL FIVE FIELDS MOVE TOGETHER. They describe one fact — where this
     * project is and how confidently we know it — and updating a subset leaves
     * a record that contradicts itself: an area_id with area_unresolved true,
     * or a match_type of 'boundary' beside a null area.
     */
    private function assignArea(Project $project, Coordinates $coordinates): void
    {
        if ((bool) $project->area_is_manual === true) {
            return;
        }

        /*
         * PUBLISHED ONLY, and hierarchically so.
         *
         * `AreaResolver::resolve()` already filters to published areas, but a
         * published neighbourhood can sit under an unpublished district — and
         * attaching a project to it exposes the child's name on a public page
         * while its parent is still in review. Public Area profiles apply the
         * same ancestry check; geometry must not be a way around it.
         *
         * When the finest match fails that test, the search continues outward:
         * a published parent is a correct, coarser answer, and coarser-but-true
         * beats precise-but-undisclosable.
         */
        // ONE policy, owned by the resolver. Duplicating the ancestry walk
        // here is how the observer and the Wizard's suggestion came to
        // disagree about the same point.
        $area = $this->areas->resolve($coordinates);

        $project->forceFill([
            'area_id' => $area?->id,
            'area_is_manual' => false,
            // Null when nothing resolved: an "assigned at" timestamp beside a
            // null area describes an assignment that never happened.
            'area_assigned_at' => $area === null ? null : now(),
            'area_match_type' => $area === null ? 'none' : 'boundary',
            // The whole point of the flag: a project whose coordinates fall in
            // no publishable area needs review, and saying nothing would make
            // it indistinguishable from one nobody has located yet.
            'area_unresolved' => $area === null,
        ])->saveQuietly();
    }

    /**
     * Drop an automatic assignment when the location is gone.
     *
     * A manual assignment survives: an administrator who deliberately filed a
     * project under an area did not withdraw that decision by clearing a
     * coordinate, and silently discarding it would destroy the correction §4
     * exists to allow.
     */
    private function clearAutomaticArea(Project $project): void
    {
        if ((bool) $project->area_is_manual === true) {
            return;
        }

        if ($project->area_id === null && (bool) $project->area_unresolved === false) {
            return;   // already clean; no write needed
        }

        $project->forceFill([
            'area_id' => null,
            'area_is_manual' => false,
            'area_assigned_at' => null,
            'area_match_type' => 'none',
            // Not "unresolved" in the sense of a failed lookup — there is
            // nothing to look up. Flagged anyway, because a project with no
            // location is exactly as unusable and needs the same attention.
            'area_unresolved' => true,
        ])->saveQuietly();
    }
}
