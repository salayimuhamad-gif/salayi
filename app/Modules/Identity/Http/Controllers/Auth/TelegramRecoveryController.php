<?php

declare(strict_types=1);

namespace App\Modules\Identity\Http\Controllers\Auth;

use App\Http\Middleware\SetLocale;
use App\Modules\Identity\Services\TelegramPasswordRecovery;
use App\Modules\Notifications\Services\Notifier;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Validation\Rules\Password as PasswordRule;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Telegram password recovery, HTTP surface.
 *
 * Thin by design: every security decision — enumeration silence, TTL,
 * single use, identity binding, attempt budgets, session invalidation —
 * lives in {@see TelegramPasswordRecovery}. What this controller owns is
 * the conversation: the same neutral sentence for every request outcome,
 * and the same neutral sentence for every dead link, so nothing about an
 * account can be read off the responses.
 */
final class TelegramRecoveryController extends Controller
{
    public function __construct(
        private readonly TelegramPasswordRecovery $recovery,
        private readonly Notifier $notifier,
    ) {}

    /**
     * Ask for a recovery message. Answers identically whether the phone
     * exists, is unlinked, or is suspended — see the service.
     */
    public function send(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'phone' => ['required', 'string', 'max:32'],
        ]);

        $this->recovery->requestFor($validated['phone'], app()->getLocale());

        return back()->with('status', __('identity.recovery.sent_notice'));
    }

    /**
     * The reset form behind the chat link.
     *
     * A dead link — unknown, expired, spent, revoked, identity-changed, or
     * over its attempt budget — lands on the request form with one neutral
     * notice; rendering a password form that can only fail would be a
     * runaround, and detailing the reason would leak account state.
     */
    public function show(Request $request, string $token): Response|RedirectResponse
    {
        $challenge = ctype_alnum($token) ? $this->recovery->usable($token) : null;

        if ($challenge === null) {
            return redirect()
                ->route('password.request')
                ->with('status', __('identity.recovery.invalid'));
        }

        // The person may arrive in Telegram's in-app browser with no session
        // at all; the challenge remembers the language they asked in.
        $request->session()->put(SetLocale::SESSION_KEY, $challenge->locale);
        app()->setLocale($challenge->locale);

        return Inertia::render('Auth/RecoverTelegram', ['token' => $token]);
    }

    public function store(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'token' => ['required', 'string', 'max:64'],
            'password' => ['required', 'confirmed', PasswordRule::defaults()],
        ]);

        $user = $this->recovery->reset($validated['token'], $validated['password']);

        if ($user === null) {
            return redirect()
                ->route('password.request')
                ->with('status', __('identity.recovery.invalid'));
        }

        /*
         * The same security notice the email reset sends, for the same
         * reason: a password change the account holder did not make is the
         * one event they must hear about. Consent-exempt, urgent.
         */
        $this->notifier->send(
            event: 'account_security',
            recipient: (int) $user->id,
            replacements: ['event' => __('notifications.security_events.password_reset')],
            consentPurpose: 'account_security',
            priority: 'urgent',
        );

        return redirect()->route('login')->with('status', __('identity.auth.password_reset'));
    }
}
