<?php

declare(strict_types=1);

namespace App\Modules\Leads\Providers;

use App\Modules\Core\Support\ModuleServiceProvider;
use App\Modules\Leads\Services\LeadScorer;
use App\Modules\Leads\Services\PhoneRevealService;
use App\Modules\Leads\Support\ConsentGate;

/**
 * Leads domain — roadmap Step 6 (spec 20, 22.3, 23).
 *
 * See docs/ROADMAP_STATUS.md for implemented versus scaffolded.
 */
final class LeadsServiceProvider extends ModuleServiceProvider
{
    protected function moduleName(): string
    {
        return 'Leads';
    }

    protected function roadmapStep(): int
    {
        return 6;
    }

    protected function registerModule(): void
    {
        $this->app->singleton(PhoneRevealService::class);

        $this->app->singleton(LeadScorer::class);
        // Deliberately a separate binding from LeadScorer: a lead's value and
        // a lead's contactability must never share a code path.
        $this->app->singleton(ConsentGate::class);
    }
}
