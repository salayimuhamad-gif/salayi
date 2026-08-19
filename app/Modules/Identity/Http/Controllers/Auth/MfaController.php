<?php

declare(strict_types=1);

namespace App\Modules\Identity\Http\Controllers\Auth;

use App\Modules\Identity\Http\Middleware\EnsureMfaConfirmed;
use App\Modules\Identity\Support\Totp;
use App\Modules\Notifications\Services\Notifier;
use App\Modules\Operations\Services\AuditLogger;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;
use Throwable;

/**
 * Administrator MFA enrolment and challenge (spec 30.1, 37.5).
 */
final class MfaController extends Controller
{
    public function __construct(
        private readonly AuditLogger $audit,
        private readonly Notifier $notifier,
    ) {}

    public function setup(Request $request): Response
    {
        $user = $request->user();

        // A secret is generated once and held in the session until confirmed.
        // Writing it to the user row before confirmation would leave an account
        // in a state where MFA is "enabled" with a secret nobody has scanned.
        $secret = $request->session()->get('mfa.pending_secret');

        if (! is_string($secret) || $secret === '') {
            $secret = Totp::generateSecret();
            $request->session()->put('mfa.pending_secret', $secret);
        }

        return Inertia::render('Auth/MfaSetup', [
            'secret' => $secret,
            'otpauthUri' => Totp::provisioningUri(
                $secret,
                (string) ($user->email ?? $user->name),
                (string) settings('branding.site_name', config('app.name')),
            ),
        ]);
    }

    public function confirm(Request $request): RedirectResponse
    {
        $validated = $request->validate(['code' => ['required', 'string']]);
        $user = $request->user();
        $secret = (string) $request->session()->get('mfa.pending_secret');

        if ($secret === '' || ! Totp::verify($secret, $validated['code'])) {
            $this->audit->security('mfa.enrolment.failed', ['user_id' => $user->id]);

            throw ValidationException::withMessages(['code' => __('identity.mfa.invalid_code')]);
        }

        $recovery = Totp::recoveryCodes();

        $user->forceFill([
            'mfa_secret' => Crypt::encryptString($secret),
            'mfa_recovery_codes' => $recovery,
            'mfa_confirmed_at' => now(),
        ])->save();

        $request->session()->forget('mfa.pending_secret');
        $request->session()->put(EnsureMfaConfirmed::SESSION_KEY, $request->session()->getId());

        $this->audit->record('mfa.enrolled', $user, severity: 'warning');

        /*
         * Tell the account holder their second factor changed (spec 22.3).
         *
         * `account_security`, which ConsentGate exempts — a security notice is
         * not marketing, and someone who has declined every optional channel is
         * still entitled to learn that MFA was enrolled on their account. This
         * is the notification that lets a person notice a takeover: whoever
         * enrolled the factor is not necessarily the owner.
         */
        $this->notifier->send(
            event: 'account_security',
            recipient: $user,
            replacements: ['event' => __('notifications.security_events.mfa_enrolled')],
            consentPurpose: 'account_security',
            priority: 'high',
        );

        // Recovery codes are shown once, from the session, and never stored in
        // readable form. Rendering them again later would mean holding them
        // decryptable somewhere a compromised session could reach.
        return redirect()->route('admin.mfa.recovery')->with('recovery_codes', $recovery);
    }

    public function recovery(Request $request): Response
    {
        return Inertia::render('Auth/MfaRecoveryCodes', [
            'codes' => $request->session()->get('recovery_codes', []),
        ]);
    }

    public function challenge(): Response
    {
        return Inertia::render('Auth/MfaChallenge');
    }

    public function verify(Request $request): RedirectResponse
    {
        $validated = $request->validate(['code' => ['required', 'string']]);
        $user = $request->user();
        $key = 'mfa:'.$user->id;

        if (RateLimiter::tooManyAttempts($key, 5)) {
            throw ValidationException::withMessages([
                'code' => __('identity.auth.throttled', ['seconds' => RateLimiter::availableIn($key)]),
            ]);
        }

        $secret = $this->decryptSecret($user->mfa_secret);

        if ($secret === null || ! Totp::verify($secret, $validated['code'])) {
            RateLimiter::hit($key, 300);
            $this->audit->security('mfa.challenge.failed', ['user_id' => $user->id]);

            throw ValidationException::withMessages(['code' => __('identity.mfa.invalid_code')]);
        }

        RateLimiter::clear($key);

        // Bound to the session id, so a stolen cookie replayed into a new
        // session does not inherit the challenge.
        $request->session()->put(EnsureMfaConfirmed::SESSION_KEY, $request->session()->getId());

        $this->audit->record('mfa.challenge.passed', $user);

        return redirect()->intended(route('admin.dashboard'));
    }

    private function decryptSecret(?string $encrypted): ?string
    {
        if ($encrypted === null) {
            return null;
        }

        try {
            return Crypt::decryptString($encrypted);
        } catch (Throwable) {
            return null;
        }
    }
}
