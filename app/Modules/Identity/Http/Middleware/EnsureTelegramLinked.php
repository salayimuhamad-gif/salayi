<?php

declare(strict_types=1);

namespace App\Modules\Identity\Http\Middleware;

use App\Modules\Identity\Models\User;
use App\Modules\Operations\Services\AuditLogger;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * The mandatory account-verification gate for personal features (spec §3.3).
 *
 * The product decision this enforces: an account may exist and browse, but the
 * Advisor, lead submission, the portfolio and the private profile are open
 * only to accounts that have been VERIFIED. Verification now has two doors of
 * equal standing, and this gate honours either:
 *
 *   - the Telegram link (`telegram_verified_at`), set behind the webhook's
 *     secret and replay ledger exactly as before; or
 *   - the WhatsApp one-time code (`whatsapp_verified_at`), set by
 *     WhatsAppVerificationService after a code delivered to the account's own
 *     number is typed back.
 *
 * The OR across the two lives in {@see User::hasVerifiedAccount()}
 * — ONE method, so no route can honour one door and forget the other. The
 * class keeps its historical name and its `telegram.linked` alias because
 * every route file names the alias; renaming the string across the codebase
 * would widen a security patch for no behavioural gain.
 *
 * This deliberately does NOT read `phone_verified` as the gate. A typed phone
 * number is unverified by definition, so gating on it would either lock every
 * new registrant out or pressure someone into falsely marking phones
 * verified. (The WhatsApp door does SET `phone_verified`, because a code
 * delivered to the number and typed back genuinely proves possession — but
 * the gate reads the account-verification timestamps, not the phone claim.)
 * Distinct claims, distinct columns, distinct gates.
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
        if ($user->hasVerifiedAccount()) {
            return $next($request);
        }

        /*
         * Signed in but unverified — the pending-verification state. Audited
         * because under the mandatory-verification model this state should be
         * brief; an account repeatedly hitting personal surfaces without
         * verifying is either a stuck registration or somebody probing, and
         * the operator should be able to see which.
         */
        $this->audit->security('identity.unlinked_access_refused', [
            'path' => $request->path(),
            'actor_id' => $user->id,
        ]);

        /*
         * Correction C1, widened for the second door: a SIGNED-IN unverified
         * account goes to the verification CHOICE page — its own
         * authenticated flow, where both methods are offered — never to guest
         * registration, which would bounce an authenticated visitor to `/`
         * and leave them with no door at all.
         */
        return $request->expectsJson()
            ? response()->json(['message' => __('identity.errors.telegram_link_required')], 403)
            : redirect()->to(localized_route('account.verify'));
    }
}
