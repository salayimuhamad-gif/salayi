<?php

declare(strict_types=1);

namespace App\Modules\Advertising\Providers;

use App\Modules\Advertising\Services\AdServer;
use App\Modules\Core\Support\ModuleServiceProvider;

/**
 * Advertising domain — roadmap Step 5 (spec 18, 19).
 *
 * Phase 9 gave this module its schema: campaigns, creatives, placements and
 * per-day counters, with the §8.9 separation enforced structurally — nothing
 * here is reachable from a ranking service.
 *
 * See docs/ROADMAP_STATUS.md for what is implemented versus scaffolded.
 */
final class AdvertisingServiceProvider extends ModuleServiceProvider
{
    protected function moduleName(): string
    {
        return 'Advertising';
    }

    protected function roadmapStep(): int
    {
        return 5;
    }

    protected function registerModule(): void
    {
        $this->app->singleton(AdServer::class);
    }
}
