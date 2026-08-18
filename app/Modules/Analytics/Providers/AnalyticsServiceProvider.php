<?php

declare(strict_types=1);

namespace App\Modules\Analytics\Providers;

use App\Modules\Analytics\Support\AnalyticsGuard;
use App\Modules\Core\Support\ModuleServiceProvider;

/**
 * Analytics domain — roadmap Step 7 (spec 21, 24.5, 25, 32).
 *
 * See docs/ROADMAP_STATUS.md for implemented versus scaffolded.
 */
final class AnalyticsServiceProvider extends ModuleServiceProvider
{
    protected function moduleName(): string
    {
        return 'Analytics';
    }

    protected function roadmapStep(): int
    {
        return 7;
    }

    protected function registerModule(): void
    {
        $this->app->singleton(AnalyticsGuard::class);
    }
}
