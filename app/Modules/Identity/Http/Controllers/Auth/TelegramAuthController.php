<?php

declare(strict_types=1);

namespace App\Modules\Identity\Http\Controllers\Auth;

use App\Modules\Identity\Models\TelegramLoginIntent;
use App\Modules\Identity\Models\User;
use App\Modules\Identity\Services\TelegramAuthenticator;
use App\Modules\Identity\Services\TelegramBotResponder;
use App\Modules\Identity\Services\TelegramRegistrar;
use App\Modules\Identity\Services\TelegramVerificationService;
use App\Modules\Identity\Services\TelegramWebhookInbox;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\Auth;
use Inertia\Inertia;
use Inertia\Response;
use Throwable;

/**
 * Telegram sign-in (File one §7).
 *
 * Three surfaces with deliberately different trust levels:
 *
 *   - `start` and `poll` are browser routes. They may create and observe an
 *     intent but can never verify anything.
 *   - `webhook` is Telegram's route. It is the ONLY place a phone becomes
 *     verified, and it is unauthenticated by necessity — so it is protected by
 *     a secret header, a replay ledger, and a rate limiter rather than by a
 *     session.
 *
 * Keeping verification exclusively on the webhook side is what makes §7.4
 * enforceable. If the browser could report "the user shared their contact",
 * then anyone able to post to that endpoint could claim any number.
 */
final class TelegramAuthController extends Controller
{
    public function __construct(
        private readonly TelegramAuthenticator $auth,
        private readonly TelegramRegistrar $registrar,
        private readonly TelegramBotResponder $bot,
        private readonly TelegramWebhookInbox $inbox,
        private readonly TelegramVerificationService $verification,
    ) {}

    /**
     * Show the sign-in screen and mint an intent for this session.
     */
    public function show(Request $request): Response
    {
        $intent = TelegramLoginIntent::mint(
            $request->session()->getId(),
            app()->getLocale(),
            $this->hash($request->ip()),
            $this->hash($request->userAgent()),
        );

        $username = (string) config('services.telegram.bot_username', '');

        return Inertia::render('Auth/TelegramLogin', [
            // The deep link carries the token as the bot's start parameter, so
            // pressing it opens the right conversation with the right intent.
            'deep_link' => $username === ''
                ? null
                : sprintf('https://t.me/%s?start=%s', $username, $intent->token),
            'code' => $intent->code(),
            'expires_in_seconds' => 600,
            'bot_configured' => $username !== '',
        ]);
    }

    /**
     * Has this session's intent been redeemed yet?
     *
     * The browser polls because the verification happens out-of-band, in
     * Telegram. The intent id is not accepted from the request: a client that
     * could name an intent could poll somebody else's and learn when they
     * signed in. The session's own most recent intent is the only one visible.
     */
    public function poll(Request $request): JsonResponse
    {
        $fingerprint = TelegramLoginIntent::fingerprint($request->session()->getId());

        /*
         * H4: the poll answers ONLY for LOGIN intents. Without the purpose
         * filter, this session's latest intent of ANY purpose — a consumed
         * registration, an account link — could sign the session in through
         * the wrong door.
         */
        $intent = TelegramLoginIntent::query()
            ->where('session_fingerprint', $fingerprint)
            ->where('purpose', TelegramLoginIntent::PURPOSE_LOGIN)
            ->latest('id')
            ->first();

        if ($intent === null || $intent->user_id === null || $intent->consumed_at === null) {
            return response()->json(['authenticated' => false]);
        }

        $user = User::query()->find($intent->user_id);

        // The ONE rule, here too: redemption already refused a suspended
        // account, but the poll must not trust that nothing changed since.
        if ($user === null || ! $user->mayAuthenticate()) {
            return response()->json(['authenticated' => false]);
        }

        /*
         * §7.6: rotate the session on sign-in. Without this, a session id
         * captured before authentication is still valid after it — the
         * definition of session fixation, and the whole point of doing the
         * verification in a second channel is undone by it.
         */
        $request->session()->regenerate();

        Auth::login($user, remember: true);

        return response()->json(['authenticated' => true]);
    }

    /**
     * Telegram's callback.
     *
     * CSRF-exempt by necessity (bootstrap/app.php excludes webhooks/telegram/*)
     * and therefore authenticated by the secret header instead. Always answers
     * 200 to a verified sender: Telegram retries non-2xx deliveries, and a
     * retry of a request we deliberately refused is noise, not recovery.
     */
    public function webhook(Request $request): JsonResponse
    {
        if (! $this->auth->verifySignature($request->header('X-Telegram-Bot-Api-Secret-Token'))) {
            // 404, not 401: an unauthenticated caller should not learn that a
            // Telegram webhook lives at this path.
            abort(404);
        }

        $updateId = (int) $request->input('update_id', 0);

        if ($updateId === 0) {
            return response()->json(['ok' => true]);
        }

        $message = (array) $request->input('message', []);
        $contact = (array) ($message['contact'] ?? []);

        /*
         * Correction v3 C2: the inbox decides. A completed event is refused
         * (a genuine replay); a fresh in-flight claim is acked while its
         * owner works; a failed or stuck one is reclaimed and retried. Any
         * database error OTHER than the unique-key collision escapes claim()
         * entirely — the webhook answers 5xx and Telegram redelivers into a
         * healthy inbox instead of the update being mislabelled a duplicate.
         */
        $claim = $this->inbox->claim($updateId, $contact === [] ? 'message' : 'contact');

        if (! $claim['claimed']) {
            return response()->json(['ok' => true, 'duplicate' => true]);
        }

        try {
            $response = $this->dispatchUpdate($message, $contact);
            $this->inbox->complete($claim['event_id']);

            return $response;
        } catch (Throwable $exception) {
            // Reclaimable, with only the exception's class name recorded —
            // never payload, never identifiers.
            $this->inbox->fail($claim['event_id'], $exception);

            throw $exception;
        }
    }

    /**
     * The purpose-dispatch that used to live inline in webhook(); extracted
     * so the inbox lifecycle wraps ALL of it — redemptions and bot replies
     * alike — in one completable, failable unit.
     *
     * @param  array<string, mixed>  $message
     * @param  array<string, mixed>  $contact
     */
    private function dispatchUpdate(array $message, array $contact): JsonResponse
    {
        $token = $this->tokenFrom($message);
        $chatId = $message['chat']['id'] ?? null;
        $locale = $this->localeFrom($message);

        /*
         * A /start carrying a token and no contact is where the flows diverge,
         * by the INTENT'S declared purpose — never by anything the sender can
         * claim:
         *
         *   - registration: the Start itself is the proof. The typed details
         *     travel on the intent, the Telegram identity arrives here, and
         *     the account is created now. `phone_verified` stays false; the
         *     Start proved a Telegram account, not a phone.
         *   - login, sender already linked: the stored Telegram identity IS
         *     the credential, so a returning person is one press from signed
         *     in — no repeated contact-share (spec §3.5).
         *   - login, sender unknown: exactly the behaviour that always
         *     existed — ask for the contact, the only thing that can verify a
         *     phone. Legacy sign-ins keep working unchanged.
         */
        if ($contact === []) {
            if ($token !== null && $chatId !== null) {
                $intent = TelegramLoginIntent::query()->where('token', $token)->first();
                $from = (array) ($message['from'] ?? []);
                $senderId = (string) ($from['id'] ?? '');

                if ($intent !== null && $intent->purpose === TelegramLoginIntent::PURPOSE_REGISTRATION) {
                    $result = $this->registrar->redeemStart($intent, $from);

                    /*
                     * The language the person CHOSE on the website wins over
                     * the language their Telegram client happens to be set
                     * to. Somebody running Telegram in English who registered
                     * in Kurdish must be sent back to the Kurdish site, with
                     * a Kurdish button — otherwise the return button drops
                     * them halfway through their own journey in a language
                     * they did not pick.
                     */
                    $replyLocale = $this->intentLocale($intent, $locale);

                    $result['ok']
                        ? $this->bot->confirmRegistration($chatId, $replyLocale)
                        : $this->bot->reportFailure($chatId, $replyLocale);

                    return response()->json(['ok' => $result['ok']]);
                }

                if ($intent !== null && $intent->purpose === TelegramLoginIntent::PURPOSE_ACCOUNT_LINK) {
                    $result = $this->registrar->redeemAccountLink($intent, $from);

                    /*
                     * v4 §5: three outcomes now, not two. A Start that
                     * parked a candidate has NOT linked anything — telling
                     * the person it succeeded would be false, and would
                     * leave the confirmation in the browser unpressed.
                     */
                    $replyLocale = $this->intentLocale($intent, $locale);

                    if ($result['ok'] && ($result['pending_confirmation'] ?? false)) {
                        $this->bot->requestBrowserConfirmation($chatId, $replyLocale);

                        return response()->json(['ok' => true]);
                    }

                    $result['ok']
                        ? $this->bot->confirmLinked($chatId, $replyLocale)
                        : $this->bot->reportFailure($chatId, $replyLocale);

                    return response()->json(['ok' => $result['ok']]);
                }

                /*
                 * The simplified registration verification (§7).
                 *
                 * Checked only when no intent owns this token, so every
                 * existing flow above keeps its exact behaviour and this one
                 * can never intercept a login, a registration or an
                 * account-link Start. The two token spaces are 32 random bytes
                 * each and live in different tables, so "no intent" really does
                 * mean "not one of those".
                 *
                 * This is the ONE press. It links, verifies, consumes the token
                 * and answers — there is no browser confirmation on this path
                 * and no second message. See TelegramVerificationService for
                 * why that is safe for an account that has no Telegram identity
                 * yet, and why the account-link flow above still requires one.
                 */
                if ($intent === null) {
                    $verification = $this->verification->redeem($token, $from);
                    $replyLocale = $this->verificationLocale($verification, $locale);

                    if ($verification['ok']) {
                        $this->bot->confirmVerified($chatId, $replyLocale);

                        return response()->json(['ok' => true]);
                    }

                    /*
                     * `unknown_token` is the ONLY reason that falls through. It
                     * means this token belongs to neither table, so the legacy
                     * Share-Contact prompt below is still the right answer —
                     * exactly what an unrecognised Start has always received.
                     * Every other reason is a real refusal about a real token,
                     * and answering it here stops a person who genuinely has a
                     * problem from being handed an unrelated contact button.
                     */
                    if (($verification['reason'] ?? '') !== 'unknown_token') {
                        /*
                         * `already_verified` is the dual-method resolution:
                         * the account finished through WhatsApp while this
                         * link sat in the chat, so the press gets the truth
                         * — verified, nothing further needed — rather than a
                         * failure that sends a perfectly fine account to
                         * support.
                         */
                        match ($verification['reason'] ?? '') {
                            'conflict' => $this->bot->reportAlreadyConnected($chatId, $replyLocale),
                            'already_verified' => $this->bot->reportAccountAlreadyVerified($chatId, $replyLocale),
                            default => $this->bot->reportFailure($chatId, $replyLocale),
                        };

                        return response()->json(['ok' => false]);
                    }
                }

                if ($intent !== null && $senderId !== '') {
                    $returning = $this->registrar->redeemReturningLogin($intent, $senderId);

                    if ($returning['ok']) {
                        $this->bot->confirmSignIn($chatId, $locale);

                        return response()->json(['ok' => true]);
                    }
                }

                $this->bot->requestContact($chatId, $token, $locale);
            }

            return response()->json(['ok' => true]);
        }

        if ($token === null) {
            return response()->json(['ok' => true, 'reason' => 'no_intent']);
        }

        $result = $this->auth->redeem($token, $contact, (array) ($message['from'] ?? []));

        /*
         * The person is in Telegram, not looking at the browser tab that is
         * polling. Telling them there rather than leaving the chat silent is
         * what makes the hand-back comprehensible.
         */
        if ($chatId !== null) {
            $result['ok']
                ? $this->bot->confirmSignIn($chatId, $locale)
                : $this->bot->reportFailure($chatId, $locale);
        }

        // The reason is returned for the bot to phrase, never the failure
        // detail — a caller who guessed a token should not learn whether it
        // was unknown, expired or already spent.
        return response()->json(['ok' => $result['ok']]);
    }

    public function destroy(Request $request): RedirectResponse
    {
        Auth::logout();
        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return redirect('/');
    }

    /**
     * The intent token carried by /start, or by the reply the contact answered.
     *
     * @param  array<string, mixed>  $message  raw Telegram update; unverified until the signature check passes
     */
    private function tokenFrom(array $message): ?string
    {
        $text = (string) ($message['text'] ?? '');

        if (preg_match('/\/start\s+([a-f0-9]{64})/i', $text, $matches) === 1) {
            return $matches[1];
        }

        $replyText = (string) ($message['reply_to_message']['text'] ?? '');

        if (preg_match('/([a-f0-9]{64})/i', $replyText, $matches) === 1) {
            return $matches[1];
        }

        return null;
    }

    /**
     * The language the verification token remembers, falling back to the
     * Telegram client's only when the redemption could not name one — an
     * unknown token has no account and therefore no recorded choice.
     *
     * @param  array{locale?: string}  $verification
     */
    private function verificationLocale(array $verification, string $fallback): string
    {
        $locale = (string) ($verification['locale'] ?? '');

        return in_array($locale, ['ckb', 'ar', 'en'], true) ? $locale : $fallback;
    }

    /**
     * The locale recorded on the intent when the person filled in the form,
     * falling back to the Telegram client's language only when the intent
     * carries nothing usable.
     */
    private function intentLocale(TelegramLoginIntent $intent, string $fallback): string
    {
        $locale = (string) ($intent->locale ?? '');

        return in_array($locale, ['ckb', 'ar', 'en'], true) ? $locale : $fallback;
    }

    /**
     * The person's own Telegram language, when it is one this platform speaks.
     *
     * Sorani-first: an unknown or absent language falls back to the site
     * default rather than to English, which for this deployment is ckb.
     *
     * @param  array<string, mixed>  $message  raw Telegram update; unverified until the signature check passes
     */
    private function localeFrom(array $message): string
    {
        $code = (string) ($message['from']['language_code'] ?? '');
        $code = substr($code, 0, 2);

        $map = ['ku' => 'ckb', 'ck' => 'ckb', 'ar' => 'ar', 'en' => 'en'];

        return $map[$code] ?? (string) config('app.locale', 'ckb');
    }

    private function hash(?string $value): ?string
    {
        return $value === null ? null : hash('sha256', $value);
    }
}
