<?php

declare(strict_types=1);

namespace App\Modules\Identity\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Blocks every administrative surface until MFA is enrolled AND challenged
 * for this session (spec 30.1 "MFA for administrators", 37.5).
 *
 * Three distinct states, deliberately not collapsed:
 *
 *   not enrolled  -> forced to /admin/mfa/setup. An administrator cannot
 *                    reach any admin page before enrolling; there is no
 *                    "remind me later".
 *   enrolled, not challenged this session -> /admin/mfa/challenge.
 *   challenged    -> through.
 *
 * The challenge marker is bound to the session id, so a session fixation or a
 * stolen cookie replayed into a new session does not inherit the challenge.
 */
final class EnsureMfaConfirmed
{
    public const SESSION_KEY = 'mfa.confirmed_for_session';

    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if ($user === null) {
            return redirect()->guest(route('login'));
        }

        if (! $user->requiresMfa()) {
            return $next($request);
        }

        if (! $user->hasMfaEnabled()) {
            if ($request->routeIs('admin.mfa.setup*')) {
                return $next($request);
            }

            return redirect()->route('admin.mfa.setup');
        }

        if ($request->session()->get(self::SESSION_KEY) === $request->session()->getId()) {
            return $next($request);
        }

        if ($request->routeIs('admin.mfa.challenge*')) {
            return $next($request);
        }

        return redirect()->route('admin.mfa.challenge');
    }
}
