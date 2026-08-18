<?php

declare(strict_types=1);

namespace App\Modules\Marketplace\Providers;

use App\Modules\Core\Support\ModuleServiceProvider;
use App\Modules\Marketplace\Services\OfferRanker;
use App\Modules\Marketplace\Services\OfferScorer;

/**
 * Marketplace domain — roadmap Step 5 (spec 18, 19).
 *
 * See docs/ROADMAP_STATUS.md for what is implemented versus scaffolded.
 */
final class MarketplaceServiceProvider extends ModuleServiceProvider
{
    protected function moduleName(): string
    {
        return 'Marketplace';
    }

    protected function roadmapStep(): int
    {
        return 5;
    }

    protected function registerModule(): void
    {
        // Storage-free, so the paid/organic separation rules can be tested
        // without a database and cannot drift between the search page, the
        // project page and the offers page.
        $this->app->singleton(OfferRanker::class);

        // Deliberately separate from the ranker. The scorer must never see
        // sponsorship, and keeping them apart is what makes that inspectable.
        $this->app->singleton(OfferScorer::class);
    }
}
