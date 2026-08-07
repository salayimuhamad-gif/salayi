<?php

declare(strict_types=1);

namespace App\Modules\Geography\Providers;

use App\Modules\Core\Support\ModuleServiceProvider;
use App\Modules\Geography\Models\Place;
use App\Modules\Geography\Observers\PlaceObserver;
use App\Modules\Geography\Observers\ProjectGeometryObserver;
use App\Modules\Geography\Services\NearbyPlaceCalculator;
use App\Modules\Geography\Services\NearbyPlaceRanker;
use App\Modules\Projects\Models\Project;

/**
 * Geography domain — roadmap Step 2 (spec 10, 11).
 *
 * Implemented in this build: areas with a materialised-path hierarchy, place
 * categories, places, coordinate and boundary handling, and the nearby-place
 * ranking engine.
 *
 * NOT implemented: the map explorer UI, external geocoding, routing-provider
 * travel times (spec 10.5 step 3), and the AI import assistant (spec 11.3).
 * See docs/ROADMAP_STATUS.md.
 */
final class GeographyServiceProvider extends ModuleServiceProvider
{
    protected function moduleName(): string
    {
        return 'Geography';
    }

    protected function roadmapStep(): int
    {
        return 2;
    }

    protected function bootModule(): void
    {
        /*
         * Spec 10.5 steps 7 and 8. Registered here rather than on the models so
         * the coupling is visible in one place: Geography watches Projects, and
         * Projects does not know Geography exists.
         */
        Place::observe(PlaceObserver::class);
        Project::observe(ProjectGeometryObserver::class);
    }

    protected function registerModule(): void
    {
        // Storage-free, so a singleton with no database dependency.
        $this->app->singleton(NearbyPlaceRanker::class);
        $this->app->singleton(NearbyPlaceCalculator::class, fn ($app): NearbyPlaceCalculator => new NearbyPlaceCalculator(
            $app->make(NearbyPlaceRanker::class),
        ));
    }
}
