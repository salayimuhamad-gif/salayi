<?php

declare(strict_types=1);

namespace App\Modules\Operations\Providers;

use App\Modules\Core\Support\ModuleServiceProvider;
use App\Modules\Operations\Release\ProductionChecklist;
use App\Modules\Operations\Services\AuditLogger;
use App\Modules\Operations\Services\BackupService;
use App\Modules\Operations\Services\EnvironmentSettings;

final class OperationsServiceProvider extends ModuleServiceProvider
{
    protected function moduleName(): string
    {
        return 'Operations';
    }

    protected function registerModule(): void
    {
        $this->app->singleton(AuditLogger::class);
        $this->app->singleton(EnvironmentSettings::class);
        $this->app->singleton(BackupService::class, fn ($app): BackupService => new BackupService(
            $app->make(AuditLogger::class),
        ));
        $this->app->singleton(ProductionChecklist::class);
    }
}
