<?php

declare(strict_types=1);

namespace App\Modules\Identity\Http\Controllers\Auth;

use App\Modules\Identity\Http\Middleware\EnsureMfaConfirmed;
use App\Modules\Identity\Models\User;
use App\Modules\Identity\Support\PhoneNumber;
use App\Modules\Identity\Support\PostLinkDestination;
use App\Modules\Operations\Services\AuditLogger;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

final class AuthenticatedSessionController extends Controller
{
    public function __construct(private readonly AuditLogger $audit) {}

    public function create(): Response
    {
        return Inertia::render('Auth/Login', [
            'canResetPassword' => true,
        ]);
    }

    /**
     * Spec 30.1: login throttling and session fixation defence.
     *
     * ONE FIELD, TWO KINDS OF IDENTIFIER. Ordinary accounts are created from
     * the registration form with a phone number and no email; administrators
     * were seeded with an email and no phone. Asking for "email" specifically
     * therefore locked every customer out of the only password door they have,
     * and left them pressing Start in Telegram on every single visit — the
     * behaviour this change exists to end.
     *
     * The field is now `login` and accepts either. Which one it is, is decided
     * by its SHAPE — an `@` means email — and never by trying one and then the
     * other: a fallback attempt would double the work an attacker gets per
     * submission and halve the value of the throttle.
     *
     * The phone is matched through the same keyed blind index the rest of the
     * platform uses, so a lookup never needs the plaintext number and a
     * database dump still yields nothing searchable.
     */
    public function store(Request $request): RedirectResponse
    {
        /*
         * Spaces and dashes are how people write numbers, not part of them.
         * Stripped before validation so "0750 123 4567" and "07501234567" are
         * one input — the same normalisation the registration form applies, so
         * the number that registered is the number that signs in.
         */
        $request->merge([
            'login' => trim((string) $request->input('login', '')),
        ]);

        $credentials = $request->validate([
            'login' => ['required', 'string', 'max:190'],
            'password' => ['required', 'string'],
            'remember' => ['sometimes', 'boolean'],
        ]);

        $identifier = $credentials['login'];

        /*
         * Keyed on a DIGEST of the identifier, never the identifier itself.
         * The old key embedded the raw email address, which put account
         * identifiers into cache keys; a phone number there would be worse.
         * The hash is equally usable as a bucket and reveals nothing.
         */
        $throttleKey = 'login:id:'.hash('sha256', mb_strtolower($identifier));

        if (RateLimiter::tooManyAttempts($throttleKey, 5)) {
            $this->audit->security('auth.login.throttled', ['identifier_hash' => hash('sha256', $identifier)]);

            throw ValidationException::withMessages([
                'login' => __('identity.auth.throttled', ['seconds' => RateLimiter::availableIn($throttleKey)]),
            ]);
        }

        if (! Auth::attempt(
            $this->credentialsFor($identifier, $credentials['password']),
            (bool) ($credentials['remember'] ?? false),
        )) {
            RateLimiter::hit($throttleKey, 900);
            $this->audit->security('auth.login.failed', ['identifier_hash' => hash('sha256', $identifier)]);

            // One message for "no such user", "wrong password" and "that
            // number is not a valid Iraqi number". A distinct response for any
            // of them turns the login form into an account enumeration oracle.
            throw ValidationException::withMessages(['login' => __('identity.auth.failed')]);
        }

        $user = $request->user();

        if (! $user->is_active || $user->suspended_at !== null) {
            Auth::logout();
            $this->audit->security('auth.login.blocked_inactive', ['user_id' => $user->id]);

            throw ValidationException::withMessages([
                'login' => __($user->suspended_at !== null ? 'identity.errors.account_suspended' : 'identity.errors.account_inactive'),
            ]);
        }

        RateLimiter::clear($throttleKey);

        // Session fixation defence: a new session id on every privilege change.
        $request->session()->regenerate();

        $user->forceFill([
            'last_login_at' => now(),
            'last_login_ip_hash' => hash('sha256', (string) $request->ip()),
        ])->saveQuietly();

        $this->audit->record('auth.login.succeeded', $user);

        return redirect()->intended($this->destinationFor($user));
    }

    /**
     * The credential array for one identifier — email OR phone, never both.
     *
     * A phone that cannot be normalised produces a blind index of a string
     * nobody holds, so the attempt fails exactly like a wrong password does.
     * That is deliberate: refusing it earlier, with its own message, would tell
     * an anonymous visitor which inputs are even shaped like accounts here.
     *
     * @return array<string, string>
     */
    private function credentialsFor(string $identifier, string $password): array
    {
        if (str_contains($identifier, '@')) {
            return ['email' => mb_strtolower($identifier), 'password' => $password];
        }

        return ['phone_index' => User::blindIndex(PhoneNumber::toE164($identifier)), 'password' => $password];
    }

    /**
     * Where a successful sign-in lands.
     *
     * Three audiences, and sending all of them to the admin dashboard — which
     * is what this used to do — meant an ordinary customer's first act after
     * signing in was a 403.
     *
     *   - an administrator goes to the dashboard, as before;
     *   - a customer who has not verified Telegram goes to the verification
     *     page, where their PERMANENT link is waiting. This is the §17 path:
     *     signing in is how somebody resumes a registration they abandoned,
     *     and it must not dead-end;
     *   - everybody else goes wherever the post-link resolver says, which is
     *     onboarding until it is finished and the profile afterwards.
     *
     * `EnsureTelegramLinked` would redirect an unverified account here anyway;
     * naming the destination means it arrives in one hop instead of two, and
     * the intended-URL machinery above still wins when there was one.
     */
    private function destinationFor(User $user): string
    {
        if ($user->isAdministrative()) {
            return route('admin.dashboard');
        }

        return $user->telegram_verified_at === null
            ? localized_route('account.telegram.link')
            : PostLinkDestination::for($user);
    }

    public function destroy(Request $request): RedirectResponse
    {
        $this->audit->record('auth.logout', $request->user());

        Auth::guard('web')->logout();

        // Clear the MFA marker explicitly. Relying on session invalidation
        // alone would leave it set if a driver ever reuses a session id.
        $request->session()->forget(EnsureMfaConfirmed::SESSION_KEY);
        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return redirect()->route('login');
    }
}
