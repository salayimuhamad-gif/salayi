<?php

declare(strict_types=1);

namespace App\Modules\Install\Providers;

use App\Modules\Core\Contracts\SettingsRepository;
use App\Modules\Core\Support\ModuleServiceProvider;
use App\Modules\Install\Services\EnvWriter;
use App\Modules\Install\Services\InstallConfigurator;
use App\Modules\Install\Services\InstallRunner;
use App\Modules\Install\Services\InstallState;
use App\Modules\Install\Services\RequirementChecker;
use App\Modules\Install\Services\StepValidator;
use App\Modules\Operations\Services\BackupService;
use Illuminate\Support\Facades\Config;

final class InstallServiceProvider extends ModuleServiceProvider
{
    protected function moduleName(): string
    {
        return 'Install';
    }

    protected function registerModule(): void
    {
        $this->forceFileSessionsWhileUninstalled();

        $this->app->singleton(InstallState::class);
        $this->app->singleton(RequirementChecker::class);
        $this->app->singleton(StepValidator::class);
        $this->app->singleton(EnvWriter::class, fn ($app): EnvWriter => new EnvWriter($app->make(InstallState::class)));

        $this->app->singleton(InstallConfigurator::class, fn ($app): InstallConfigurator => new InstallConfigurator(
            $app->make(InstallState::class),
            $app->make(EnvWriter::class),
            $app->make(StepValidator::class),
            $app->make(SettingsRepository::class),
        ));

        $this->app->singleton(InstallRunner::class, fn ($app): InstallRunner => new InstallRunner(
            $app->make(InstallState::class),
            $app->make(BackupService::class),
            $app->make(InstallConfigurator::class),
        ));
    }

    /**
     * Use file-backed sessions and cache until the lock file exists.
     *
     * SESSION_DRIVER defaults to `database` — correct for Hostinger, which has
     * no Redis — but the `sessions` table is not created until step 18. Before
     * that the installer cannot hold a session at all, which takes out CSRF
     * tokens, validation error bags and every flash message the wizard uses to
     * report a failed step. The operator would see a redirect loop with no
     * explanation, on the one screen where an explanation matters most.
     *
     * The same applies to the database cache store, which the rate limiter on
     * the installer routes depends on.
     *
     * Registered rather than booted, because the session manager resolves its
     * driver before boot. Reverts to the configured drivers the moment the
     * installation is locked, since the swap is conditional on that file.
     */
    private function forceFileSessionsWhileUninstalled(): void
    {
        $lock = (string) config('installer.lock_file', '');

        if ($lock !== '' && is_file($lock)) {
            return;
        }

        /*
         * `config()`, NOT `env()`.
         *
         * `env()` reads the .env file, which `config:cache` bypasses entirely —
         * on a cached production deployment this call returns null, the guard
         * never fires, and an INSTALLED site has its session driver quietly
         * forced back to `file`. Hostinger deployments are expected to cache
         * config, so this was live-affecting rather than theoretical.
         *
         * `config('installer.enabled')` is derived from the same variable in
         * config/installer.php, where reading env() is correct and cached
         * safely.
         */
        if (! (bool) config('installer.enabled', true)) {
            return;
        }

        if (config('session.driver') === 'database') {
            Config::set('session.driver', 'file');
        }

        if (config('cache.default') === 'database') {
            Config::set('cache.default', 'file');
        }
    }
}
