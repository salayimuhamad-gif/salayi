<?php

declare(strict_types=1);

namespace App\Modules\Identity\Http\Middleware;

use App\Modules\Operations\Services\AuditLogger;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Verified-account gate (File one §7.11).
 *
 * §7.10 and §7.11 pull in opposite directions on purpose, and the split is the
 * product decision: **browsing stays open, personal data does not.** Anyone may
 * read the market, the projects and the areas without an account. But a saved
 * search, a portfolio, an alert, a personal advisor conversation or a callback
 * request all either store something about a person or put them in front of a
 * salesperson — and each of those needs a phone somebody actually proved.
 *
 * "Signed in" is not the test. `auth` would be satisfied by an account created
 * with any string in the email field; §7.11 asks for a VERIFIED account, and
 * the only thing this product treats as proof is a phone shared through
 * Telegram's own button (§7.4). So the check is `phone_verified`, not
 * `Auth::check()`.
 *
 * Usage: `->middleware('verified')`.
 */
final class EnsureVerifiedAccount
{
    public function __construct(private readonly AuditLogger $audit) {}

    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if ($user === null) {
            // Not an error — they simply have not started. Send them to the
            // one flow that can produce a verified account.
            return $request->expectsJson()
                ? response()->json(['message' => __('identity.errors.verification_required')], 401)
                : redirect()->route('telegram.login');
        }

        if ((bool) $user->phone_verified === true) {
            return $next($request);
        }

        /*
         * Signed in but unverified. Worth auditing rather than silently
         * redirecting: an account reaching personal surfaces without a proven
         * phone means either a gap in the sign-up paths or somebody probing,
         * and both are things an operator should be able to see afterwards.
         */
        $this->audit->security('identity.unverified_access_refused', [
            'path' => $request->path(),
            'actor_id' => $user->id,
        ]);

        return $request->expectsJson()
            ? response()->json(['message' => __('identity.errors.verification_required')], 403)
            : redirect()->route('telegram.login');
    }
}
