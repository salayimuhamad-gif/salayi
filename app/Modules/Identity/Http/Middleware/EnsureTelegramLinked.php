<?php

declare(strict_types=1);

namespace App\Modules\Identity\Http\Middleware;

use App\Modules\Operations\Services\AuditLogger;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * The mandatory Telegram gate for personal features (spec §3.3).
 *
 * The product decision this enforces: an account may exist and browse, but the
 * Advisor, lead submission, the portfolio and the private profile are open
 * only to accounts that have completed the Telegram link. That link is
 * recorded as `telegram_verified_at`, which is set in exactly two places —
 * registration Start redemption and the Share-Contact login flow — both of
 * which sit behind the webhook's secret and replay ledger.
 *
 * This deliberately does NOT read `phone_verified`. The old gate did, and
 * under the new registration model a typed phone number is unverified by
 * definition, so gating on it would either lock every new registrant out or
 * pressure someone into falsely marking phones verified. Distinct claims,
 * distinct columns, distinct gates.
 *
 * `EnsureVerifiedAccount` still exists untouched for the surfaces that
 * genuinely need a proven phone (the company portal keeps it).
 */
final class EnsureTelegramLinked
{
    public function __construct(private readonly AuditLogger $audit) {}

    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if ($user === null) {
            // Not signed in at all: registration is the flow that produces a
            // linked account, so that is where an anonymous visitor goes.
            return $request->expectsJson()
                ? response()->json(['message' => __('identity.errors.telegram_link_required')], 401)
                : redirect()->to(localized_route('register.show'));
        }

        /*
         * Suspension used to be checked HERE (the v2 §7 sweep). Correction
         * H4 extracted that block verbatim into EnsureAccountActive, which
         * now runs BEFORE this gate on every authenticated stack — so by
         * this line the account is known-active, and this class is back to
         * doing exactly one job: the link.
         */
        if ($user->telegram_verified_at !== null) {
            return $next($request);
        }

        /*
         * Signed in but unlinked — the `pending_telegram_link` state. Audited
         * because under the mandatory-link model this state should be brief;
         * an account repeatedly hitting personal surfaces without a link is
         * either a stuck registration or somebody probing, and the operator
         * should be able to see which.
         */
        $this->audit->security('identity.unlinked_access_refused', [
            'path' => $request->path(),
            'actor_id' => $user->id,
        ]);

        /*
         * Correction C1: a SIGNED-IN unlinked account goes to the
         * account-link page — its own authenticated flow — never to guest
         * registration, which would bounce an authenticated visitor to `/`
         * and leave them with no door at all.
         */
        return $request->expectsJson()
            ? response()->json(['message' => __('identity.errors.telegram_link_required')], 403)
            : redirect()->to(localized_route('account.telegram.link'));
    }
}
