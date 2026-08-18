<?php

declare(strict_types=1);

namespace App\Modules\Imports\Providers;

use App\Modules\Core\Support\ModuleServiceProvider;
use App\Modules\Imports\Services\PriceImportService;
use App\Modules\Imports\Support\CsvReader;
use App\Modules\Imports\Support\PriceRowValidator;
use App\Modules\Operations\Services\AuditLogger;

/**
 * Imports domain — roadmap Step 7 (spec 21, 24.5, 25, 32).
 *
 * See docs/ROADMAP_STATUS.md for implemented versus scaffolded.
 */
final class ImportsServiceProvider extends ModuleServiceProvider
{
    protected function moduleName(): string
    {
        return 'Imports';
    }

    protected function roadmapStep(): int
    {
        return 7;
    }

    protected function registerModule(): void
    {
        // Storage-free, so preview and accept validate identically. A preview
        // that disagrees with the commit is how an operator accepts rows that
        // then fail.
        $this->app->singleton(PriceRowValidator::class);
        $this->app->singleton(CsvReader::class);

        $this->app->singleton(PriceImportService::class, fn ($app): PriceImportService => new PriceImportService(
            $app->make(PriceRowValidator::class),
            $app->make(AuditLogger::class),
        ));
    }
}
