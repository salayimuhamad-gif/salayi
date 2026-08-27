<?php

declare(strict_types=1);

namespace App\Modules\Market\Providers;

use App\Modules\Core\Support\ModuleServiceProvider;
use App\Modules\Market\Models\PriceRecord;
use App\Modules\Market\Observers\PriceRecordObserver;
use App\Modules\Market\Services\IndexBuilder;
use App\Modules\Market\Services\IndexCalculator;
use App\Modules\Market\Services\MarketMovementService;
use App\Modules\Market\Services\OutlierDetector;
use App\Modules\Market\Services\ProjectPriceHistory;
use App\Modules\Operations\Services\AuditLogger;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;

/**
 * Market domain — roadmap Step 3 (spec 14, 15).
 *
 * Implemented: price records with structural asking/verified/official
 * separation, the index engine with explainability, MAD outlier detection,
 * unrealistic-jump detection, and the import batch/row schema.
 *
 * NOT implemented: the market dashboard UI, charts, Excel template generation
 * and download, the AI-assisted import path, and the recomputation job.
 * See docs/ROADMAP_STATUS.md.
 */
final class MarketServiceProvider extends ModuleServiceProvider
{
    protected function moduleName(): string
    {
        return 'Market';
    }

    protected function roadmapStep(): int
    {
        return 3;
    }

    protected function bootModule(): void
    {
        // Publishing a price changes what every matching index sees.
        PriceRecord::observe(PriceRecordObserver::class);

        /*
         * Wave 4's movement endpoint, in its OWN bucket — the same lesson
         * the map limiters carry: two surfaces spending one counter is how
         * one person's browsing starves another feature (and how the Wave 3
         * E2E suite went flaky). 60/min matches the read-heavy map-features
         * budget; the key prefix keeps the counter private to this route.
         */
        RateLimiter::for('market-movement', static fn (Request $request): Limit => Limit::perMinute(60)
            ->by('market-movement|'.$request->ip()));

        // Map Phase 4: the heatmap's viewport refetches spend their own
        // counter — panning in Market mode must never starve the pulse
        // panel, and the pulse panel must never starve the map.
        RateLimiter::for('map-market', static fn (Request $request): Limit => Limit::perMinute(60)
            ->by('map-market|'.$request->ip()));
    }

    protected function registerModule(): void
    {
        $this->app->singleton(OutlierDetector::class);
        $this->app->singleton(ProjectPriceHistory::class);
        $this->app->singleton(IndexCalculator::class, fn ($app): IndexCalculator => new IndexCalculator(
            $app->make(OutlierDetector::class),
        ));

        $this->app->singleton(IndexBuilder::class, fn ($app): IndexBuilder => new IndexBuilder(
            $app->make(IndexCalculator::class),
            $app->make(AuditLogger::class),
        ));

        // Movement DERIVES from the published series through the calculator;
        // it stores nothing, so there is nothing else to wire.
        $this->app->singleton(MarketMovementService::class, fn ($app): MarketMovementService => new MarketMovementService(
            $app->make(IndexCalculator::class),
        ));
    }
}
