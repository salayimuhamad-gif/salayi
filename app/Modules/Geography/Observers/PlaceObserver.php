<?php

declare(strict_types=1);

namespace App\Modules\Geography\Observers;

use App\Modules\Geography\Jobs\RecalculateNearbyPlaces;
use App\Modules\Geography\Models\Place;
use App\Modules\Geography\Services\NearbyPlaceCalculator;
use App\Modules\Geography\Support\Geodesy;
use App\Modules\Geography\ValueObjects\BoundingBox;
use App\Modules\Projects\Enums\PublicationStatus;
use App\Modules\Projects\Models\Project;

/**
 * Place changes that invalidate a project's nearby-place snapshot
 * (spec 10.5 steps 7 and 8).
 *
 * Three events matter, and they are deliberately handled differently:
 *
 *   moved       -> mark existing snapshots STALE, then queue a refresh. The
 *                  published figures stay put until the job runs, so nobody
 *                  sees a distance change mid-read.
 *   published   -> queue a refresh for projects in range. A newly published
 *                  school genuinely changes what is near a project.
 *   unpublished -> queue a refresh, because a closed hospital must stop being
 *                  advertised as nearby.
 *
 * "Projects in range" is bounded by the widest category radius rather than
 * every project in the database. A new café cannot affect a project 30 km away
 * and queueing a job per project would make publishing a place O(projects).
 */
final class PlaceObserver
{
    /** The widest radius any category declares, in metres. */
    private const MAX_INFLUENCE_M = 25_000;

    public function __construct(private readonly NearbyPlaceCalculator $calculator) {}

    public function saved(Place $place): void
    {
        $moved = $place->wasChanged(['latitude', 'longitude']);
        $visibilityChanged = $place->wasChanged(['publication_status', 'operational_status']);

        if (! $moved && ! $visibilityChanged) {
            return;
        }

        if ($moved) {
            // Flag first, recalculate second. Between the two, a reader sees the
            // old figure marked as needing refresh rather than a silently
            // different number.
            $this->calculator->markStaleForPlace($place->id);
        }

        $this->queueAffectedProjects($place);
    }

    public function deleted(Place $place): void
    {
        $this->calculator->markStaleForPlace($place->id);
        $this->queueAffectedProjects($place);
    }

    private function queueAffectedProjects(Place $place): void
    {
        $point = $place->coordinates();

        if ($point === null) {
            return;
        }

        $box = BoundingBox::aroundRadius($point, (float) self::MAX_INFLUENCE_M);

        Project::query()
            ->whereNotNull('latitude')
            ->whereNotNull('longitude')
            ->whereBetween('latitude', [$box->minLatitude, $box->maxLatitude])
            ->whereBetween('longitude', [$box->minLongitude, $box->maxLongitude])
            ->where('publication_status', '!=', PublicationStatus::Archived->value)
            ->select(['id', 'latitude', 'longitude'])
            ->chunkById(200, function ($projects) use ($point): void {
                foreach ($projects as $project) {
                    $projectPoint = $project->coordinates();

                    // The box is a superset; discard the corners.
                    if ($projectPoint === null
                        || Geodesy::distanceMetres($point, $projectPoint) > self::MAX_INFLUENCE_M) {
                        continue;
                    }

                    RecalculateNearbyPlaces::dispatch($project->id);
                }
            });
    }
}
