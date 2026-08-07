<?php

declare(strict_types=1);

namespace App\Modules\Branding\Providers;

use App\Modules\Branding\Services\DatabaseFeatureFlagRepository;
use App\Modules\Branding\Services\DatabaseSettingsRepository;
use App\Modules\Core\Contracts\FeatureFlagRepository;
use App\Modules\Core\Contracts\SettingsRepository;
use App\Modules\Core\Support\FeatureFlagResolver;
use App\Modules\Core\Support\ModuleServiceProvider;
use Illuminate\Contracts\Cache\Repository as Cache;

final class BrandingServiceProvider extends ModuleServiceProvider
{
    protected function moduleName(): string
    {
        return 'Branding';
    }

    protected function registerModule(): void
    {
        $this->app->singleton(
            SettingsRepository::class,
            fn ($app): DatabaseSettingsRepository => new DatabaseSettingsRepository($app->make(Cache::class)),
        );

        $this->app->singleton(
            FeatureFlagRepository::class,
            fn ($app): DatabaseFeatureFlagRepository => new DatabaseFeatureFlagRepository(
                $app->make(FeatureFlagResolver::class),
                $app->make(Cache::class),
            ),
        );
    }
}
