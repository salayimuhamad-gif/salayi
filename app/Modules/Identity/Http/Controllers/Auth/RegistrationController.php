<?php

declare(strict_types=1);

namespace App\Modules\Identity\Http\Controllers\Auth;

use App\Http\Middleware\SetLocale;
use App\Modules\Identity\Models\TelegramLoginIntent;
use App\Modules\Identity\Models\User;
use App\Modules\Identity\Services\TelegramRegistrar;
use App\Modules\Identity\Services\TelegramVerificationService;
use App\Modules\Identity\Support\PhoneNumber;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\App;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\Rules\Password as PasswordRule;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Ordinary-user registration — ACCOUNT-FIRST (v7).
 *
 * The form creates the account, signs it in, and sends the person to the
 * Telegram linking page. This replaced the Telegram-first model, in which the
 * form minted an intent and the WEBHOOK created the account on `/start`; some
 * of that machinery still exists below and is deliberately retained so any
 * Start token in flight at deployment still resolves, but nothing mints new
 * registration intents.
 *
 * What survived the change is the property that mattered: the browser still
 * cannot LINK an account. Completion happens only on the webhook behind the
 * secret header, and then only after the account's own session confirms the
 * candidate on screen. An account created here is deliberately powerless until
 * that happens — `telegram.linked` refuses it everywhere.
 */
final class RegistrationController extends Controller
{
    public function __construct(
        private readonly TelegramRegistrar $registrar,
        private readonly TelegramVerificationService $verification,
    ) {}

    /**
     * The registration form.
     *
     * The `pending` payload is legacy: under account-first no new registration
     * intent is minted, so it is null for every new visitor. It is still
     * rendered because a session that began before this release may still hold
     * one, and that person must be able to finish the way they started.
     */
    public function show(Request $request): Response|RedirectResponse
    {
        if (Auth::check()) {
            return redirect('/');
        }

        $pending = $this->sessionIntent($request);

        return Inertia::render('Auth/Register', [
            'bot_configured' => (string) config('services.telegram.bot_username', '') !== '',
            'pending' => $pending === null ? null : $this->pendingPayload($pending),
            'locales' => ['ckb', 'ar', 'en'],
            /*
             * The form states the actual configured requirement rather than a
             * number written into a translation string, which would drift the
             * first time an operator changes the policy and leave the hint
             * quietly lying about what will be accepted.
             */
            'password_min_length' => (int) config('mulkihawler.security.password_min_length', 12),
        ]);
    }

    /**
     * Validate the typed details, CREATE the account, sign it in, and hand off
     * to Telegram linking.
     *
     * Phone validation is strict about the ONE thing the blind index needs —
     * a normalisable Iraqi number — and silent about everything else. Telling
     * an attacker which numbers exist is user enumeration, so a number already
     * in use is NOT rejected by validation; the duplicate is refused below with
     * one message that is identical for every refusal, so this form cannot be
     * used as a phone-number oracle. (Under Telegram-first the conflict
     * surfaced at redemption, where
     * the reply is generic and the audit log is specific.
     */
    public function store(Request $request): RedirectResponse
    {
        // Spaces and dashes are how people WRITE numbers, not part of the
        // number. Stripped before validation so "0750 123 4567" is the same
        // input as "07501234567" instead of a rejection.
        $request->merge([
            'phone' => preg_replace('/[\s\-]+/', '', (string) $request->input('phone', '')),
        ]);

        $validated = $request->validate([
            'name' => ['required', 'string', 'min:2', 'max:120'],
            'phone' => ['required', 'string', 'regex:/^(\+?964|0)?7[0-9]{9}$/'],
            /*
             * The platform's own policy, not a softer one invented for
             * customers. `PasswordRule::defaults()` is what the administrator
             * password reset already enforces, and running two different
             * strengths would mean a customer who later sets an email and uses
             * the reset flow is held to a rule their original password never
             * had to meet. The strength itself is operator-tunable through
             * `mulkihawler.security.password_min_length`.
             */
            'password' => ['required', 'confirmed', PasswordRule::defaults()],
            'locale' => ['required', 'in:ckb,ar,en'],
            'accept_terms' => ['accepted'],
            'consent_contact' => ['sometimes', 'boolean'],
        ]);

        /*
         * Any pending Telegram-first intent this session still holds is
         * cancelled: the account-first flow does not use one, and leaving a
         * live token behind would let a Start complete a registration for a
         * session that has already registered by other means.
         */
        $this->cancelSessionIntent($request);

        /*
         * H5: the CHOSEN language, immediately, and BEFORE the redirect is
         * built. The person picked a locale on the form; every later request
         * must agree, including the localized route this method returns.
         */
        $request->session()->put(SetLocale::SESSION_KEY, $validated['locale']);

        /*
         * Putting the locale in the session governs the NEXT request. This
         * request is still rendering in whatever language the form was served
         * in, and both strings below are translated HERE — so a Kurdish page
         * with Arabic selected would flash a Kurdish message onto an Arabic
         * page. The translator is switched explicitly so the message, the
         * redirect and the destination all speak the same language.
         */
        App::setLocale($validated['locale']);

        $result = $this->registrar->createUnlinkedAccount(
            [
                'name' => trim($validated['name']),
                'phone' => $this->toE164($validated['phone']),
                'password' => $validated['password'],
                'locale' => $validated['locale'],
                'consent_contact' => (bool) ($validated['consent_contact'] ?? false),
            ],
            $this->hash($request->ip()),
            $this->hash($request->userAgent()),
        );

        if (! $result['ok']) {
            /*
             * One message for every refusal, and it still does not say the
             * number is already registered — that would turn this form into a
             * phone-number oracle, and an anonymous visitor may be typing
             * somebody else's number.
             *
             * What changed is the part that was genuinely broken: the message
             * now comes with real doors. The page renders "sign in" and
             * "continue verification" links beneath it, so a person who really
             * does own the number is one tap from their account instead of
             * staring at a refusal with nowhere to go. Neither link asserts
             * that an account exists; they are the same two links a first-time
             * visitor would be offered.
             */
            return back()
                ->withInput($request->except(['phone', 'password', 'password_confirmation']))
                ->withErrors(['phone' => __('identity.register.conflict')]);
        }

        $user = $result['user'];

        /*
         * The permanent verification token, minted at the moment the account
         * exists to own it and BEFORE the redirect — so the page the person
         * lands on has a live link to render, and so the link exists even if
         * they never reach that page at all.
         *
         * It carries no clock. Somebody who registers now and presses Start
         * next month verifies successfully; see TelegramVerificationToken.
         */
        $this->verification->mint($user, $validated['locale']);

        /*
         * Session rotation BEFORE authentication, so any session id captured
         * by an attacker prior to this point cannot become an authenticated
         * one — the same ordering the poll used, kept deliberately.
         */
        $request->session()->regenerate();

        Auth::login($user, remember: true);

        /*
         * v7 account-first: the account now exists, is signed in, and is
         * unlinked — which is exactly the state `telegram.linked` refuses.
         * So the destination is the linking page, in the language the person
         * chose, and NOT onboarding: sending a fresh account to a gated route
         * would bounce it straight back here and look like a loop.
         */
        return redirect()
            ->to(localized_route('account.telegram.link', locale: $validated['locale']))
            ->with('status', __('identity.register.account_created'));
    }

    /**
     * H5: the person changed their mind — the pending intent dies by their
     * own hand, and the poll reports `cancelled` so every open tab stops.
     */
    public function cancel(Request $request): JsonResponse
    {
        $this->cancelSessionIntent($request);

        return response()->json(['state' => 'cancelled']);
    }

    private function cancelSessionIntent(Request $request): void
    {
        /*
         * By id, not through sessionIntent(): that helper now excludes
         * cancelled intents (and expiry-graces the rest), while cancel must
         * reach an EXPIRED intent too — the pending screen's restart cancels
         * first precisely so the ghost cannot outlive the grace window.
         * Idempotent by construction: a second cancel finds the timestamps
         * already set and writes nothing.
         */
        $id = $request->session()->get('registration_intent_id');

        if (! is_int($id) && ! is_string($id)) {
            return;
        }

        $intent = TelegramLoginIntent::query()
            ->whereKey($id)
            ->where('purpose', TelegramLoginIntent::PURPOSE_REGISTRATION)
            ->first();

        if ($intent !== null && $intent->consumed_at === null && $intent->cancelled_at === null) {
            $intent->forceFill([
                'cancelled_at' => now(),
                'result' => TelegramLoginIntent::RESULT_CANCELLED,
            ])->save();
        }
    }

    /**
     * Has this session's registration been completed by a Start yet?
     *
     * Mirrors the login poll deliberately: the session's own newest intent is
     * the only one visible, no intent id is accepted from the client, and a
     * successful answer performs the sign-in with a session rotation so an id
     * captured before authentication is worthless after it.
     */
    public function poll(Request $request): JsonResponse
    {
        $intent = $this->sessionIntent($request, consumedToo: true, cancelledToo: true);

        if ($intent === null) {
            return response()->json(['state' => 'pending', 'completed' => false]);
        }

        /*
         * H5: a NAMED state for every way this can end, so nobody polls a
         * corpse. Conflict deliberately says nothing about WHICH identifier
         * collided; the recovery it offers is the returning Telegram login —
         * an existing account's own door — never a merge.
         */
        if ($intent->result === TelegramLoginIntent::RESULT_CONFLICT) {
            return response()->json([
                'state' => 'conflict',
                'completed' => false,
                'recovery' => localized_route('telegram.login', locale: $intent->locale),
            ]);
        }

        if ($intent->cancelled_at !== null) {
            return response()->json(['state' => 'cancelled', 'completed' => false]);
        }

        if ($intent->consumed_at === null || $intent->user_id === null) {
            if (! $intent->expires_at->isFuture()) {
                return response()->json(['state' => 'expired', 'completed' => false]);
            }

            return response()->json(['state' => 'pending', 'completed' => false]);
        }

        $user = User::query()->find($intent->user_id);

        // H4: the ONE rule. A suspended account does not get signed in by
        // its own registration completing — same quiet non-answer as any
        // other pending state, no existence signal.
        if ($user === null || ! $user->mayAuthenticate()) {
            return response()->json(['state' => 'pending', 'completed' => false]);
        }

        $request->session()->regenerate();

        Auth::login($user, remember: true);

        return response()->json([
            'state' => 'completed',
            'completed' => true,
            // The person chose their language on the form; the intent
            // remembers it, and the landing page must speak it (§6.6).
            'redirect' => localized_route('account.onboarding', locale: $intent->locale),
        ]);
    }

    /**
     * The newest registration intent bound to this session's fingerprint.
     */
    private function sessionIntent(Request $request, bool $consumedToo = false, bool $cancelledToo = false): ?TelegramLoginIntent
    {
        $id = $request->session()->get('registration_intent_id');

        if (! is_int($id) && ! is_string($id)) {
            return null;
        }

        $query = TelegramLoginIntent::query()
            ->whereKey($id)
            ->where('purpose', TelegramLoginIntent::PURPOSE_REGISTRATION)
            // A short grace past expiry so the pending screen can SAY it
            // expired instead of silently showing a fresh form.
            ->where('expires_at', '>', now()->subMinutes(5));

        if (! $consumedToo) {
            $query->whereNull('consumed_at');
        }

        /*
         * Cancelled is TERMINAL for the SHOW path: the person ended it, and
         * the restart contract is that a revisit renders a fresh form — a
         * cancelled intent must never haunt the next page load. (The
         * browser matrix found the ghost.) The POLL path opts back in,
         * because "the browser sees cancelled without guessing" is its own
         * correction, and reporting a state requires seeing it.
         */
        if (! $cancelledToo) {
            $query->whereNull('cancelled_at');
        }

        return $query->first();
    }

    /** @return array<string, mixed> */
    private function pendingPayload(TelegramLoginIntent $intent): array
    {
        $username = (string) config('services.telegram.bot_username', '');

        return [
            'deep_link' => $username === ''
                ? null
                : sprintf('https://t.me/%s?start=%s', $username, $intent->token),
            'expires_in_seconds' => max(0, now()->diffInSeconds($intent->expires_at, false)),
            'name' => $intent->registration_name,
        ];
    }

    /**
     * `+9647XXXXXXXXX`, from any of the ways an Iraqi number is written.
     *
     * The rule moved to {@see PhoneNumber} verbatim, because signing in by
     * phone needs the SAME rule and a second copy of it is a second chance to
     * disagree — which, on a blind index, means the same person silently
     * getting two accounts.
     */
    private function toE164(string $phone): string
    {
        return PhoneNumber::toE164($phone);
    }

    private function hash(?string $value): ?string
    {
        return $value === null ? null : hash('sha256', $value);
    }
}
