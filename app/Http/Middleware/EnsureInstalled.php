<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Front gate for a not-yet-installed deployment (spec 33).
 *
 * Runs before every other web middleware because on a fresh Hostinger upload
 * there is no schema, no APP_KEY and no session table — anything that touches
 * the database first would fatal with a driver error instead of showing the
 * installer.
 *
 * Installation state is a FILE, not a database row or an env value alone.
 * A lock file is the only signal available before a database exists, and it
 * survives an .env edit, which is what makes "installer disables itself"
 * (spec 37.6) actually hold.
 */
final class EnsureInstalled
{
    /** Paths reachable while uninstalled. */
    private const INSTALLER_PREFIX = 'install';

    /** Paths reachable in both states. */
    private const ALWAYS_ALLOWED = ['up', 'build/*', 'storage/*', 'favicon.ico', 'robots.txt'];

    public function handle(Request $request, Closure $next): Response
    {
        $installed = $this->isInstalled();
        $onInstaller = $request->is(self::INSTALLER_PREFIX)
            || $request->is(self::INSTALLER_PREFIX.'/*');

        foreach (self::ALWAYS_ALLOWED as $pattern) {
            if ($request->is($pattern)) {
                return $next($request);
            }
        }

        if (! $installed && ! $onInstaller) {
            return redirect('/install');
        }

        /*
         * Installed but the installer route was requested. Spec 33.2 requires
         * the installer to "remove or disable" itself and 33.2 again to
         * "require explicit reset token to reopen". A 404 rather than a
         * redirect: an installed site should not advertise that an installer
         * ever existed.
         */
        if ($installed && $onInstaller && ! $this->hasValidResetToken($request)) {
            abort(404);
        }

        return $next($request);
    }

    private function isInstalled(): bool
    {
        /*
         * `config()`, NOT `env()` — the same defect as InstallServiceProvider.
         *
         * `config:cache` bypasses the .env file, so on a cached production
         * deployment this returned null and an INSTALLED site was treated as
         * uninstalled: every request redirected into the installer. Hostinger
         * deployments are expected to cache config, so this was live.
         *
         * `installer.enabled` is derived from the same variable in
         * config/installer.php, where reading env() is correct and cacheable.
         */
        if (! (bool) config('installer.enabled', true)) {
            return true;
        }

        $lock = (string) config('installer.lock_file', '');

        return $lock !== '' && is_file($lock);
    }

    /**
     * Reopening the installer requires a token that exists only in .env, so it
     * cannot be triggered by anyone without filesystem access. Compared in
     * constant time.
     */
    private function hasValidResetToken(Request $request): bool
    {
        $expected = (string) config('installer.reset_token', '');

        if ($expected === '' || mb_strlen($expected) < 32) {
            return false;
        }

        $provided = (string) $request->query('reset_token', '');

        return $provided !== '' && hash_equals($expected, $provided);
    }
}
