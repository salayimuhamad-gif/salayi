<?php

declare(strict_types=1);

namespace App\Modules\Projects\Providers;

use App\Modules\Core\Support\ModuleServiceProvider;
use App\Modules\Projects\Services\ProjectRatingService;
use App\Modules\Projects\Services\RatingAggregator;

/**
 * Projects domain (spec 12, 13).
 *
 * IMPLEMENTATION STATE, stated precisely because "Step 2" no longer describes
 * it and a stale label is worse than none:
 *
 *   - SOURCE IMPLEMENTED: projects, drafts, the Creation Wizard, draft and
 *     final media services, area assignment, association provenance,
 *     ratings and the publication workflow.
 *   - TESTS WRITTEN: see tests/Feature/ProjectWizardTest.php.
 *   - TESTS EXECUTED: none of the PHPUnit suite. The standalone PHP and
 *     TypeScript suites in tests/Standalone and tests/js do run.
 *   - RUNTIME VERIFICATION: BLOCKED. No composer.lock or vendor directory is
 *     present, so Laravel has never booted, migrations have never run, and
 *     nothing here has been exercised against a database.
 *
 * See docs/ROADMAP_STATUS.md for the same four-way distinction applied across
 * the project.
 */
final class ProjectsServiceProvider extends ModuleServiceProvider
{
    protected function moduleName(): string
    {
        return 'Projects';
    }

    protected function roadmapStep(): int
    {
        return 2;
    }

    protected function registerModule(): void
    {
        $this->app->singleton(RatingAggregator::class);
        $this->app->singleton(ProjectRatingService::class);
    }
}
