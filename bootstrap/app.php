<?php

declare(strict_types=1);

use App\Http\Middleware\EnsureInstalled;
use App\Http\Middleware\HandleInertiaRequests;
use App\Http\Middleware\SetLocale;
use App\Modules\Companies\Http\Middleware\ResolveActingCompany;
use App\Modules\Core\Http\Middleware\EnsureFeatureEnabled;
use App\Modules\Identity\Http\Middleware\EnsureAccountActive;
use App\Modules\Identity\Http\Middleware\EnsureAnyPermission;
use App\Modules\Identity\Http\Middleware\EnsureMfaConfirmed;
use App\Modules\Identity\Http\Middleware\EnsurePermission;
use App\Modules\Identity\Http\Middleware\EnsureTelegramLinked;
use App\Modules\Identity\Http\Middleware\EnsureVerifiedAccount;
use App\Modules\Identity\Http\Middleware\TouchLastSeen;
use App\Modules\Operations\Http\Middleware\RecordAuditContext;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Middleware\AddLinkHeadersForPreloadedAssets;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        /*
         * routes/auth.php MUST be registered inside the `web` group.
         *
         * ApplicationBuilder::buildRoutingCallback() invokes `then` AFTER — and
         * outside — the `Route::middleware('web')->group(...)` it wraps around
         * the `web:` file. A bare `require` here therefore registered login,
         * logout, password reset and the MFA challenge with only their own
         * `guest` / `auth` middleware and no web stack at all: no cookie
         * encryption, no session, no CSRF verification, no shared validation
         * errors, and no HandleInertiaRequests, so every one of those pages
         * rendered an Inertia component with none of the props it reads.
         *
         * `php artisan route:list` showed it plainly — `admin/companies/…`
         * carried `web,auth,mfa,…` while `POST login` carried only
         * `guest,throttle:login`.
         *
         * This does not double-apply the group: the routes were not in it.
         */
        then: function (): void {
            Route::middleware('web')->group(__DIR__.'/../routes/auth.php');
        },
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        // Order matters. EnsureInstalled runs before anything that touches
        // the database, because on a fresh Hostinger upload there is no
        // schema yet and every other middleware would fatal.
        $middleware->web(prepend: [
            EnsureInstalled::class,
        ]);

        $middleware->web(append: [
            SetLocale::class,
            RecordAuditContext::class,
            // Presence, one throttled write per interval; a no-op for guests.
            TouchLastSeen::class,
            HandleInertiaRequests::class,
            AddLinkHeadersForPreloadedAssets::class,
        ]);

        $middleware->alias([
            'mfa' => EnsureMfaConfirmed::class,
            'permission' => EnsurePermission::class,
            // Satisfied by ANY listed permission — see EnsureAnyPermission for
            // why this is a separate alias rather than a flag.
            'permission_any' => EnsureAnyPermission::class,
            // Repair prompt §3 / §17: flags are enforced at the server
            // boundary, not by hiding navigation. See EnsureFeatureEnabled.
            'feature' => EnsureFeatureEnabled::class,
            // §7.11: personal surfaces need a phone somebody proved, not just
            // a session. Browsing stays open under §7.10.
            'verified' => EnsureVerifiedAccount::class,
            // §8.4: resolves the acting company from the user's own membership
            // so no portal route ever accepts a company id.
            'acting-company' => ResolveActingCompany::class,
            /*
             * v6 merge: the Telegram/account work introduced these two gates
             * and its routes name them, but no input archive registered the
             * aliases — the corrected v5 runtime carried the routes without
             * the bootstrap file that resolves them. Without these entries
             * every authenticated surface fails with
             * "Target class [account.active] does not exist".
             *
             * `account.active` runs after auth and before the link gate:
             * a suspended account must be refused before anything else
             * inspects it. `telegram.linked` is distinct from `verified`
             * because a typed phone is not a proven one.
             */
            'account.active' => EnsureAccountActive::class,
            'telegram.linked' => EnsureTelegramLinked::class,
        ]);

        // Spec 30.1: session fixation defence on every authenticated request.
        $middleware->trustProxies(at: '*');

        $middleware->validateCsrfTokens(except: [
            'webhooks/telegram/*',
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        // Never leak driver-level detail to a browser in production.
        $exceptions->dontReport([]);

        $exceptions->respond(function ($response, Throwable $e, Request $request) {
            if (! app()->environment('production')) {
                return $response;
            }

            if (in_array($response->getStatusCode(), [401, 402, 403, 404, 419, 429, 500, 503], true)) {
                return inertia('Error', [
                    'status' => $response->getStatusCode(),
                ])->toResponse($request)->setStatusCode($response->getStatusCode());
            }

            return $response;
        });
    })
    ->create();
