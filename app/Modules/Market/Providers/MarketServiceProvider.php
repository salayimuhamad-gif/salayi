<?php

declare(strict_types=1);

namespace App\Modules\Market\Providers;

use App\Modules\Core\Support\ModuleServiceProvider;
use App\Modules\Market\Models\PriceRecord;
use App\Modules\Market\Observers\PriceRecordObserver;
use App\Modules\Market\Services\IndexBuilder;
use App\Modules\Market\Services\IndexCalculator;
use App\Modules\Market\Services\OutlierDetector;
use App\Modules\Market\Services\ProjectPriceHistory;
use App\Modules\Operations\Services\AuditLogger;

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
    }
}
