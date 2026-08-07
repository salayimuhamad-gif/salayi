<?php

declare(strict_types=1);

namespace App\Modules\Portfolio\Providers;

use App\Modules\Core\Support\ModuleServiceProvider;
use App\Modules\Portfolio\Services\ValuationEngine;

/**
 * Portfolio domain — roadmap Step 6 (spec 20, 22.3, 23).
 *
 * See docs/ROADMAP_STATUS.md for implemented versus scaffolded.
 */
final class PortfolioServiceProvider extends ModuleServiceProvider
{
    protected function moduleName(): string
    {
        return 'Portfolio';
    }

    protected function roadmapStep(): int
    {
        return 6;
    }

    protected function registerModule(): void
    {
        $this->app->singleton(ValuationEngine::class);
    }
}
