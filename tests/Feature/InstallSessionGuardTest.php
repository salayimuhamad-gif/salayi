<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Modules\Install\Providers\InstallServiceProvider;
use Illuminate\Support\Facades\Config;
use ReflectionMethod;
use Tests\TestCase;

/**
 * The uninstalled-site session guard must survive a cached configuration.
 *
 * `InstallServiceProvider` forces a file session driver while the site is not
 * yet installed, because the database it would otherwise write sessions to does
 * not exist. The guard that skips this on an INSTALLED site read `env()`, and
 * `config:cache` bypasses the .env file entirely — so on a cached production
 * deployment the call returned null, the guard never fired, and a live site had
 * its session driver silently downgraded. Hostinger deployments are expected to
 * cache config, so this was a live-affecting defect rather than a theoretical
 * one.
 */
final class InstallSessionGuardTest extends TestCase
{
    private function runGuard(): void
    {
        $provider = new InstallServiceProvider($this->app);

        $method = new ReflectionMethod($provider, 'forceFileSessionsWhileUninstalled');
        $method->invoke($provider);
    }

    /** Installed site: the configured driver is left alone. */
    public function test_an_installed_site_keeps_its_session_driver(): void
    {
        Config::set('installer.enabled', false);
        Config::set('installer.lock_file', '');
        Config::set('session.driver', 'database');

        $this->runGuard();

        $this->assertSame('database', config('session.driver'));
    }

    /** Uninstalled site: sessions fall back to files, as intended. */
    public function test_an_uninstalled_site_falls_back_to_file_sessions(): void
    {
        Config::set('installer.enabled', true);
        Config::set('installer.lock_file', '');
        Config::set('session.driver', 'database');

        $this->runGuard();

        $this->assertSame('file', config('session.driver'));
    }

    /**
     * The decision must not depend on .env being readable.
     *
     * With the environment variable absent — exactly the state after
     * `config:cache` — an installed site must still be recognised.
     */
    public function test_the_guard_does_not_depend_on_the_environment_file(): void
    {
        $previous = $_ENV['MULKIHAWLER_INSTALLED'] ?? null;
        unset($_ENV['MULKIHAWLER_INSTALLED'], $_SERVER['MULKIHAWLER_INSTALLED']);

        Config::set('installer.enabled', false);
        Config::set('installer.lock_file', '');
        Config::set('session.driver', 'database');

        $this->runGuard();

        $this->assertSame('database', config('session.driver'));

        if ($previous !== null) {
            $_ENV['MULKIHAWLER_INSTALLED'] = $previous;
        }
    }
}
