<?php

declare(strict_types=1);

namespace App\Modules\Identity\Http\Middleware;

use App\Modules\Operations\Services\AuditLogger;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * THE active-account gate (corrections H4 / M12).
 *
 * One rule — `User::mayAuthenticate()`: active AND not suspended — applied
 * in ONE place to every authenticated surface, member and admin alike.
 * Before this middleware the rule lived scattered: inside the Telegram
 * link gate for member pages, inside hasPermission() for admin actions,
 * and nowhere at all for any authenticated route that used neither. A
 * rule that every route has to remember is a rule some route forgets;
 * this class is the remembering.
 *
 * On refusal the SESSION dies — logout, invalidate, token regenerate — so
 * a suspended account's cookie stops working on its next request, not at
 * its next login. The refusal is audited (path and actor only) and
 * answered as JSON 403 or a redirect to the sign-in screen, which is a
 * guest route, so no loop is possible.
 *
 * Ordering note: this runs AFTER `auth` (it needs the user) and BEFORE
 * `mfa` and `telegram.linked` — a suspended admin is refused before ever
 * seeing an MFA challenge, and a suspended member before the link gate
 * can bounce them anywhere.
 */
final class EnsureAccountActive
{
    public function __construct(private readonly AuditLogger $audit) {}

    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if ($user === null || $user->mayAuthenticate()) {
            return $next($request);
        }

        $this->audit->security('identity.suspended_access_refused', [
            'path' => $request->path(),
            'actor_id' => $user->id,
        ]);

        auth()->guard('web')->logout();
        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return $request->expectsJson()
            ? response()->json(['message' => __('identity.errors.account_suspended')], 403)
            : redirect()->route('login')->withErrors(['email' => __('identity.errors.account_suspended')]);
    }
}
