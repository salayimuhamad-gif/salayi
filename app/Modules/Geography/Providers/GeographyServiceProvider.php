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
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;

/**
 * Geography domain — roadmap Step 2 (spec 10, 11).
 *
 * Implemented in this build: areas with a materialised-path hierarchy, place
 * categories, places, coordinate and boundary handling, the nearby-place
 * ranking engine, and the public map explorer UI (MapExplorerController +
 * Pages/Public/Map/Explorer.vue, behind the map.explorer flag).
 *
 * NOT implemented: external geocoding, routing-provider travel times
 * (spec 10.5 step 3), and the AI import assistant (spec 11.3).
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

        /*
         * NAMED limiters with their own buckets, not inline `throttle:N,1`.
         *
         * Inline numeric throttles key every guest by sha1(domain|ip) — ONE
         * shared counter across every route that uses them. The map surfaces
         * proved why that is wrong: panning fires a features request per
         * moveend (correctly allowed 60/min), and each one also advanced the
         * search endpoint's counter, so after a minute of browsing the map
         * the SEARCH box answered 429 while features kept flowing. One
         * person's panning must never spend their searching budget; the
         * budgets below are unchanged, only separated.
         */
        RateLimiter::for('map-features', static fn (Request $request): Limit => Limit::perMinute(60)
            ->by('map-features|'.$request->ip()));

        RateLimiter::for('map-search', static fn (Request $request): Limit => Limit::perMinute(30)
            ->by('map-search|'.$request->ip()));

        // Map Phase 5: the explorer's unified autocomplete — its OWN bucket,
        // same interactive budget, so it neither spends nor is starved by
        // the investment search or the viewport fetches.
        RateLimiter::for('map-explorer-search', static fn (Request $request): Limit => Limit::perMinute(30)
            ->by('map-explorer-search|'.$request->ip()));

        // Map Phase 6: the area comparison — its OWN bucket for the same
        // reason. Interactive budget: a filter change or an area swap is one
        // request, and 30/min is several of those a minute, sustained.
        RateLimiter::for('map-compare', static fn (Request $request): Limit => Limit::perMinute(30)
            ->by('map-compare|'.$request->ip()));

        /*
         * Wave 3's location-resolve endpoint. The interactive-search budget
         * (30/min is several taps a second, sustained) in its OWN bucket, for
         * the reason stated above: a person resolving their location must
         * neither spend the map budgets nor be starved by them.
         */
        RateLimiter::for('location-resolve', static fn (Request $request): Limit => Limit::perMinute(30)
            ->by('location-resolve|'.$request->ip()));
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
