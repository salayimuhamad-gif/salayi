<?php

declare(strict_types=1);

namespace App\Modules\Core\Support;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\ServiceProvider;
use ReflectionClass;

/**
 * Base class for every domain module (spec 27.2).
 *
 * A module owns its own migrations, routes, translations and views. This base
 * discovers them by convention so a new module needs no bootstrap wiring
 * beyond one line in bootstrap/providers.php.
 *
 * Modules registered but not yet implemented (Step 2+) extend this and simply
 * have nothing on disk to discover — which is why every load is guarded by an
 * is_dir/is_file check rather than assumed.
 */
abstract class ModuleServiceProvider extends ServiceProvider
{
    /** Short module name, e.g. "Projects". */
    abstract protected function moduleName(): string;

    /** Roadmap step that implements this module. 1 means "live now". */
    protected function roadmapStep(): int
    {
        return 1;
    }

    public function register(): void
    {
        $config = $this->modulePath('Config/config.php');

        if (is_file($config)) {
            $this->mergeConfigFrom($config, 'modules.'.$this->configKey());
        }

        $this->registerModule();
    }

    public function boot(): void
    {
        $this->loadModuleMigrations();
        $this->loadModuleTranslations();
        $this->loadModuleRoutes();
        $this->registerModuleCommands();
        $this->bootModule();
    }

    /** Container bindings. Runs before any other module has booted. */
    protected function registerModule(): void {}

    /** Event listeners, policies, view composers, macros. */
    protected function bootModule(): void {}

    protected function modulePath(string $relative = ''): string
    {
        return app_path('Modules/'.$this->moduleName().($relative !== '' ? '/'.ltrim($relative, '/') : ''));
    }

    protected function configKey(): string
    {
        return strtolower($this->moduleName());
    }

    private function loadModuleMigrations(): void
    {
        $path = $this->modulePath('Database/Migrations');

        if (is_dir($path)) {
            $this->loadMigrationsFrom($path);
        }
    }

    private function loadModuleTranslations(): void
    {
        $path = $this->modulePath('Resources/lang');

        if (is_dir($path)) {
            $this->loadTranslationsFrom($path, $this->configKey());
        }
    }

    /**
     * Route files are loaded per surface so each gets the right middleware
     * stack. `admin.php` is never reachable without an authenticated,
     * MFA-confirmed session (spec 30.1).
     */
    /**
     * Register this module's console commands with artisan.
     *
     * Without this the classes exist and are unreachable: `artisan list` does
     * not show them, `Schedule::command(Foo::class)` cannot resolve a
     * signature, and an operator following the runbook gets "command not
     * defined". Discovered by directory rather than listed, so adding a
     * command does not also require editing a provider — the failure mode
     * being avoided is precisely the one where those two drift apart.
     */
    private function registerModuleCommands(): void
    {
        if (! $this->app->runningInConsole()) {
            return;
        }

        $directory = $this->modulePath('Console');

        if (! is_dir($directory)) {
            return;
        }

        $namespace = str_replace('\\Providers', '\\Console', (new ReflectionClass($this))->getNamespaceName());

        $commands = [];

        foreach ((array) glob($directory.'/*.php') as $file) {
            $class = $namespace.'\\'.basename((string) $file, '.php');

            // Abstract bases and helpers live here too; only real commands
            // are registered.
            if (class_exists($class) && is_subclass_of($class, Command::class)) {
                $commands[] = $class;
            }
        }

        if ($commands !== []) {
            $this->commands($commands);
        }
    }

    private function loadModuleRoutes(): void
    {
        $routes = $this->modulePath('Routes');

        if (! is_dir($routes)) {
            return;
        }

        // A module may expose a portal surface (company-facing), which is
        // deliberately neither the public site nor the admin workspace.
        if (is_file($portal = $routes.'/portal.php')) {
            Route::middleware('web')->group($portal);
        }

        if (is_file($web = $routes.'/web.php')) {
            // Public routes are registered once per enabled locale, per
            // config('localization.url_strategy'). See LocalizedRoutes.
            LocalizedRoutes::group(fn () => require $web);
        }

        if (is_file($api = $routes.'/api.php')) {
            Route::middleware('api')
                ->prefix('api/v1')
                ->name('api.v1.')
                ->group($api);
        }

        if (is_file($admin = $routes.'/admin.php')) {
            Route::middleware(['web', 'auth', 'account.active', 'mfa'])
                ->prefix('admin')
                ->name('admin.')
                ->group($admin);
        }

        /*
         * Console routes are NOT loaded with loadRoutesFrom().
         *
         * That helper skips the file entirely once `route:cache` has run —
         * which is standard in production — so every scheduled sweep declared
         * in a module silently stopped existing on exactly the deployments
         * that need them. It also runs during route registration, which is
         * too early for the scheduler.
         *
         * Deferred to `booted` instead, and only in console context: the
         * schedule has no meaning during an HTTP request, and evaluating it
         * there is wasted work on every page load.
         */
        if (is_file($console = $routes.'/console.php') && $this->app->runningInConsole()) {
            $this->app->booted(static function () use ($console): void {
                require $console;
            });
        }
    }
}
