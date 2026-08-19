<?php

declare(strict_types=1);

namespace App\Modules\Companies\Providers;

use App\Modules\Companies\Support\CompanyScope;
use App\Modules\Core\Support\ModuleServiceProvider;

/**
 * Companies domain — roadmap Step 5 (spec 18, 19).
 *
 * See docs/ROADMAP_STATUS.md for what is implemented versus scaffolded.
 */
final class CompaniesServiceProvider extends ModuleServiceProvider
{
    protected function moduleName(): string
    {
        return 'Companies';
    }

    protected function roadmapStep(): int
    {
        return 5;
    }

    protected function registerModule(): void
    {
        $this->app->singleton(CompanyScope::class);
    }
}
