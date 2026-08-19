<?php

declare(strict_types=1);

namespace App\Modules\Identity\Http\Controllers;

use App\Modules\Identity\Models\TelegramLoginIntent;
use App\Modules\Identity\Services\AbandonedAccountPolicy;
use App\Modules\Identity\Services\TelegramBotResponder;
use App\Modules\Identity\Services\TelegramRegistrar;
use App\Modules\Identity\Services\TelegramReturnHandoff;
use App\Modules\Identity\Services\TelegramVerificationService;
use App\Modules\Identity\Support\PostLinkDestination;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\Auth;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Linking Telegram to an EXISTING signed-in account (correction C1,
 * corrected again in v4 for BLOCKER 1 and §5).
 *
 * This page exists for exactly the person the review found stranded: an
 * email/password account minted before the Telegram era, now facing the
 * mandatory-link gate with no door. The gate (`EnsureTelegramLinked`)
 * sends them here; here they get a bot button, a live poll, and every
 * state the flow can end in — pending, awaiting confirmation, success,
 * expired, conflict, cancelled — each with a way forward.
 *
 * Two v4 corrections shape this controller:
 *
 *   BLOCKER 1 — rendering the page is no longer an EVENT. `show()` RESUMES
 *   the live session-bound intent instead of minting a replacement, so a
 *   refresh, a mobile tab eviction, a second tab or a back-button
 *   navigation all display the same token the person may already have open
 *   in Telegram. Minting is now something the person does on purpose, via
 *   `restart()`.
 *
 *   §5 — a Telegram Start no longer completes the link by itself. It parks
 *   a candidate identity that this page shows back to the person, and only
 *   a `confirm()` from this authenticated browser finalises it. The browser
 *   never receives the candidate's Telegram id: it receives an HMAC handle
 *   bound to the intent token, and sends that same handle back, so a
 *   candidate swapped between render and click is detected instead of
 *   silently linked.
 *
 * Session binding remains the security spine throughout: the intent is
 * minted bound to THIS user and THIS browser session, every read and write
 * below is scoped to that session, and completion regenerates the session
 * id.
 *
 * v5, BLOCKER 3: every redirect and JSON redirect URL in this journey now
 * resolves through `localized_route()`. An Arabic user completing the link
 * from /ar/account/telegram/link lands on /ar/account/profile, not on the
 * Sorani default — the locale comes from the active request (SetLocale has
 * already read the route default, the session and the user preference, in
 * that order), which is exactly the "active request/session, then user"
 * source the correction requires. Nothing hard-codes a prefix.
 */
final class AccountTelegramLinkController extends Controller
{
    public function __construct(
        private readonly TelegramRegistrar $registrar,
        private readonly AbandonedAccountPolicy $policy,
        private readonly TelegramBotResponder $bot,
        private readonly TelegramReturnHandoff $handoff,
        private readonly TelegramVerificationService $verification,
    ) {}

    public function show(Request $request): Response|RedirectResponse
    {
        $user = $request->user();

        /*
         * Already verified — through EITHER door: there is nothing to do
         * here, and no token to mint. The WhatsApp half of this check is
         * load-bearing: rendering this page for a WhatsApp-verified account
         * would mint a live Telegram token for an account that no longer
         * needs one, and a live token is exactly the second verification
         * event the dual-method design must never allow.
         */
        if ($user->hasVerifiedAccount()) {
            /*
             * The canonical resolver, not a hardcoded profile route: a person
             * who has linked but not finished onboarding must land on
             * onboarding here exactly as they would from the poll, the
             * confirmation or the Telegram button. Hardcoding profile made
             * refreshing this page — or opening it in a second tab — send them
             * somewhere the rest of the flow never would.
             */
            return redirect()->to(PostLinkDestination::for($user->fresh()));
        }

        /*
         * The permanent verification token, resumed rather than replaced.
         *
         * `issueFor()` returns the token this account already holds when there
         * is one, so a refresh, a restored mobile tab, a second window or a
         * sign-in three weeks later all render the SAME deep link — the one
         * that may already be sitting open in the person's Telegram chat.
         *
         * The 10-minute, session-bound `account_link` intent is no longer
         * minted here. It is not removed: `poll()`, `confirm()` and `reject()`
         * below still serve one, so a token that was already in flight when
         * this shipped can still be completed by the browser that started it.
         * Nothing new enters that path.
         */
        $rawToken = $this->verification->issueFor($user);

        /*
         * A candidate parked by one of those in-flight intents, if this
         * session has one. Null for every account registered since — the new
         * flow has no confirmation step to park anything for.
         */
        $pending = $this->currentIntent($request);

        return Inertia::render('Account/TelegramLink', $this->payload($rawToken, $pending));
    }

    /**
     * The person asks for a NEW link, on purpose.
     *
     * This is the only browser-initiated path that destroys a live token, and
     * that is why it is a POST and why rendering the page does not do it. With
     * a permanent token the stakes are higher than they were: the link being
     * replaced may be one the person opened in Telegram weeks ago, and once it
     * is revoked, pressing it there will fail.
     *
     * It stays available because it is the honest answer to "somebody else has
     * my link" — the revocation half of "permanent until used OR revoked".
     */
    public function restart(Request $request): RedirectResponse
    {
        $user = $request->user();

        // Either door, same reasoning as show(): a verified account gets no
        // fresh token.
        if ($user->hasVerifiedAccount()) {
            /*
             * The canonical resolver, not a hardcoded profile route: a person
             * who has linked but not finished onboarding must land on
             * onboarding here exactly as they would from the poll, the
             * confirmation or the Telegram button. Hardcoding profile made
             * refreshing this page — or opening it in a second tab — send them
             * somewhere the rest of the flow never would.
             */
            return redirect()->to(PostLinkDestination::for($user->fresh()));
        }

        // Retires every live token for this account and issues one fresh one.
        $this->verification->mint($user);

        return redirect()->to(localized_route('account.telegram.link'));
    }

    /**
     * The browser's heartbeat. Answers ONLY for the session that minted
     * the intent, ONLY for this user, ONLY for the link purpose — and
     * names the state instead of leaving the person polling forever.
     */
    public function poll(Request $request): JsonResponse
    {
        $user = $request->user();

        $intent = $this->currentIntent($request);

        if ($intent === null) {
            /*
             * The ordinary path now: no intent, because the permanent
             * verification token does not use one. The question this branch
             * answers is simply "is the account verified yet", which is also
             * the right answer for a tab whose intent completed elsewhere.
             *
             * This is what makes §15's automatic detection work — and what
             * makes it survive the person closing the page, since the answer
             * comes from the account row rather than from anything held in
             * this session.
             */
            $fresh = $user->fresh();
            // Either door: a WhatsApp verification completed in another tab
            // moves this page on exactly as a Start would.
            $linked = $fresh->hasVerifiedAccount();

            if ($linked) {
                /*
                 * Rotate before telling the browser, exactly as the intent
                 * branch below does. This session id has been sitting in a
                 * poll loop since before the account was verified; the
                 * verified session gets a fresh one.
                 */
                $request->session()->regenerate();
            }

            return response()->json([
                'state' => $linked ? 'completed' : 'pending',
                // One resolver, everywhere: a second tab or an
                // already-completed poll must not disagree with the button in
                // the chat about where this person belongs.
                'redirect' => $linked ? PostLinkDestination::for($fresh) : null,
            ]);
        }

        if ($intent->result === TelegramLoginIntent::RESULT_CONFLICT) {
            return response()->json(['state' => 'conflict']);
        }

        if ($intent->cancelled_at !== null) {
            return response()->json(['state' => 'cancelled']);
        }

        if ($intent->consumed_at !== null && $user->fresh()->telegram_verified_at !== null) {
            /*
             * The link is real. Rotate the session id before telling the
             * browser: the pre-link session id has been sitting in a poll
             * loop and in the intent fingerprint's derivation input; the
             * post-link session gets a fresh one.
             */
            $request->session()->regenerate();

            return response()->json([
                'state' => 'completed',
                'redirect' => PostLinkDestination::for($user->fresh()),
            ]);
        }

        /*
         * §5: a candidate is parked and the person has to look at it. This
         * is checked BEFORE expiry so somebody who pressed Start at the
         * last second is not told "expired" while their confirmation is
         * sitting there — `confirm()` re-checks expiry under a lock and is
         * the authority on whether it is too late.
         */
        if ($intent->candidate_telegram_id !== null) {
            return response()->json([
                'state' => 'awaiting_confirmation',
                'candidate' => $intent->candidatePayload(),
                'candidate_handle' => $intent->candidateHandle(),
            ]);
        }

        if (! $intent->expires_at->isFuture()) {
            return response()->json(['state' => 'expired']);
        }

        return response()->json(['state' => 'pending']);
    }

    /**
     * §5: the person confirms, in the browser that started this, that the
     * Telegram account which pressed Start is theirs.
     *
     * The handle the browser echoes back is what makes this a confirmation
     * of a SPECIFIC identity rather than a blanket "yes, link whatever
     * arrived".
     */
    public function confirm(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'candidate_handle' => ['required', 'string', 'size:64'],
        ]);

        $intent = $this->currentIntent($request);

        if ($intent === null || ! $intent->candidateHandleMatches($validated['candidate_handle'])) {
            return response()->json(['state' => 'candidate_changed'], 409);
        }

        $result = $this->registrar->confirmAccountLink(
            $request->user(),
            $request->session()->getId(),
            (string) $intent->candidate_telegram_id,
        );

        if (! $result['ok']) {
            return response()->json([
                'state' => match ($result['reason'] ?? '') {
                    'conflict' => 'conflict',
                    'candidate_changed' => 'candidate_changed',
                    default => 'expired',
                },
            ], 409);
        }

        $request->session()->regenerate();

        /*
         * v7: tell the chat it worked, and give it a door back.
         *
         * Until now the bot went silent after the browser confirmed: the
         * person had a Telegram window still showing "go and confirm in your
         * browser" for a step they had already completed, and no way back to
         * the site from the app they were standing in. The confirmation and
         * the return button close that, in the language they registered in.
         *
         * Best-effort by design — the link is already durable, and a Telegram
         * API failure must not turn a successful link into an error response.
         */
        $chatId = $intent->candidate_telegram_id;
        $locale = $this->linkLocale($intent);
        $user = $request->user()->fresh();

        if ($chatId !== null) {
            /*
             * The button in the chat opens Telegram's in-app browser, which has
             * none of this session's cookies. It therefore carries a one-time
             * handoff token rather than a plain URL — otherwise it would land
             * the person on the login page while this tab quietly succeeded.
             */
            $this->bot->confirmLinked(
                $chatId,
                $locale,
                $this->handoff->mint($user, (string) $chatId, $locale),
            );
        }

        return response()->json([
            'state' => 'completed',
            'redirect' => PostLinkDestination::for($user),
        ]);
    }

    /**
     * The language the person chose on the website, not whatever their
     * Telegram client is set to.
     */
    private function linkLocale(TelegramLoginIntent $intent): string
    {
        $locale = (string) ($intent->locale ?? '');

        return in_array($locale, ['ckb', 'ar', 'en'], true) ? $locale : (string) config('app.locale', 'ckb');
    }

    /**
     * §5: "that is not me." The candidate is discarded and the intent dies
     * with it — a stranger got hold of this token, so the token is burnt
     * and the person starts again from a clean one.
     */
    public function reject(Request $request): JsonResponse
    {
        $this->registrar->rejectAccountLinkCandidate(
            $request->user(),
            $request->session()->getId(),
        );

        return response()->json(['state' => 'cancelled']);
    }

    /** The person changed their mind; the intent dies by their own hand. */
    /**
     * Abandon an unfinished registration and start over (v7).
     *
     * Account-first creates a real row before anything is proved, and that row
     * holds the person's phone number under a UNIQUE index. Someone who
     * changes their mind, mistypes their number, or simply cannot finish would
     * otherwise be locked out of their own number until the retention window
     * elapses — and told, unhelpfully, that it is already registered.
     *
     * This is the escape hatch, and it is deliberately narrow:
     *
     *   - only the SIGNED-IN account can abandon itself; there is no
     *     identifier to pass, so there is nothing to aim at somebody else;
     *   - only while UNLINKED. Once Telegram is attached, the account is
     *     proven and this route refuses — deleting a real account must never
     *     be one POST away;
     *   - only when the account owns nothing, enforced by the same fail-closed
     *     policy the scheduled cleanup uses. "Let me start over" must not
     *     become a way to erase data.
     *
     * The session is destroyed either way: whatever happens, the person asked
     * to stop being signed in as this account.
     */
    public function abandon(Request $request): RedirectResponse
    {
        $user = $request->user();
        $assessment = $this->policy->assessSelfAbandon($user);

        /*
         * Revoke first, and unconditionally.
         *
         * The token outlives the browser session by design, so walking away
         * without this would leave a working verification link pointing at an
         * account the person has just disowned. Done even when the release is
         * refused: whatever happens to the row, "I am finished with this
         * registration" must at minimum stop the link that was issued for it.
         */
        $this->verification->revokeAllFor($user, 'self_abandon');

        if ($assessment['eligible']) {
            $this->policy->release($user, 'self_abandon');
        }

        Auth::logout();
        $request->session()->invalidate();
        $request->session()->regenerateToken();

        /*
         * One message either way. If the account could not be released — it
         * had been linked in another tab, or it owns something — saying so
         * would describe an account to somebody who is now signed out of it.
         * They are sent to registration; if the number really is still held,
         * the conflict message there is what they will see.
         */
        return redirect()
            ->to(localized_route('register.show'))
            ->with('status', __('identity.register.abandoned'));
    }

    public function cancel(Request $request): JsonResponse
    {
        TelegramLoginIntent::query()
            ->where('purpose', TelegramLoginIntent::PURPOSE_ACCOUNT_LINK)
            ->where('user_id', $request->user()->id)
            ->whereNull('consumed_at')
            ->whereNull('cancelled_at')
            ->get()
            ->each(function (TelegramLoginIntent $intent): void {
                $intent->forceFill([
                    'cancelled_at' => now(),
                    'result' => TelegramLoginIntent::RESULT_CANCELLED,
                ])->save();
            });

        return response()->json(['state' => 'cancelled']);
    }

    /**
     * The intent THIS session is entitled to speak for.
     *
     * Scoped on the session fingerprint in the QUERY rather than fetched
     * and then checked: a different browser session does not get a
     * "pending" answer about somebody else's intent, it gets nothing,
     * because nothing it may see exists.
     */
    private function currentIntent(Request $request): ?TelegramLoginIntent
    {
        return TelegramLoginIntent::query()
            ->where('purpose', TelegramLoginIntent::PURPOSE_ACCOUNT_LINK)
            ->where('user_id', $request->user()->id)
            ->where('session_fingerprint', TelegramLoginIntent::fingerprint($request->session()->getId()))
            ->orderByDesc('id')
            ->first();
    }

    /**
     * What the verification page is given.
     *
     * A deep link and nothing else, for the ordinary case. There is no expiry
     * to count down, no code to display and no token to read out — §14's rule
     * is that the person sees a button and an instruction, never anything
     * technical. The raw token appears exactly once, inside the URL, and is
     * not rendered as text anywhere on the page.
     *
     * @return array<string, mixed>
     */
    private function payload(string $rawToken, ?TelegramLoginIntent $pending): array
    {
        $username = (string) config('services.telegram.bot_username', '');

        return [
            'deep_link' => $username === '' ? null : 'https://t.me/'.$username.'?start='.$rawToken,
            'bot_configured' => $username !== '',
            /*
             * Legacy, and null for everybody registered since this shipped:
             * the parked-candidate confirmation that an in-flight `account_link`
             * intent may still be waiting on. The page renders the
             * confirmation UI only when it is non-null, so the new flow never
             * shows a second step.
             */
            'candidate' => $pending?->candidatePayload(),
            'candidate_handle' => $pending === null || $pending->candidate_telegram_id === null
                ? null
                : $pending->candidateHandle(),
        ];
    }
}
