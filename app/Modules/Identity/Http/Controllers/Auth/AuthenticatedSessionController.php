<?php

declare(strict_types=1);

namespace App\Modules\Identity\Http\Controllers\Auth;

use App\Modules\Identity\Http\Middleware\EnsureMfaConfirmed;
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
     */
    public function store(Request $request): RedirectResponse
    {
        $credentials = $request->validate([
            'email' => ['required', 'string', 'email'],
            'password' => ['required', 'string'],
            'remember' => ['sometimes', 'boolean'],
        ]);

        $throttleKey = 'login:email:'.mb_strtolower($credentials['email']);

        if (RateLimiter::tooManyAttempts($throttleKey, 5)) {
            $this->audit->security('auth.login.throttled', ['email_hash' => hash('sha256', $credentials['email'])]);

            throw ValidationException::withMessages([
                'email' => __('identity.auth.throttled', ['seconds' => RateLimiter::availableIn($throttleKey)]),
            ]);
        }

        if (! Auth::attempt(
            ['email' => $credentials['email'], 'password' => $credentials['password']],
            (bool) ($credentials['remember'] ?? false),
        )) {
            RateLimiter::hit($throttleKey, 900);
            $this->audit->security('auth.login.failed', ['email_hash' => hash('sha256', $credentials['email'])]);

            // One message for both "no such user" and "wrong password". A
            // distinct response for each turns the login form into an account
            // enumeration oracle.
            throw ValidationException::withMessages(['email' => __('identity.auth.failed')]);
        }

        $user = $request->user();

        if (! $user->is_active || $user->suspended_at !== null) {
            Auth::logout();
            $this->audit->security('auth.login.blocked_inactive', ['user_id' => $user->id]);

            throw ValidationException::withMessages([
                'email' => __($user->suspended_at !== null ? 'identity.errors.account_suspended' : 'identity.errors.account_inactive'),
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

        return redirect()->intended(route('admin.dashboard'));
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
