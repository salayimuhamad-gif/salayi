<?php

declare(strict_types=1);

namespace App\Modules\Identity\Http\Controllers\Auth;

use App\Modules\Notifications\Services\Notifier;
use App\Modules\Operations\Services\AuditLogger;
use Illuminate\Auth\Events\PasswordReset;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Str;
use Illuminate\Validation\Rules\Password as PasswordRule;
use Inertia\Inertia;
use Inertia\Response;

final class PasswordResetController extends Controller
{
    public function __construct(
        private readonly AuditLogger $audit,
        private readonly Notifier $notifier,
    ) {}

    public function request(): Response
    {
        return Inertia::render('Auth/ForgotPassword');
    }

    /**
     * Always reports success.
     *
     * Telling an anonymous visitor whether an address is registered turns this
     * form into an account enumeration oracle, which matters more here than
     * usual: the accounts are named administrators of a named company.
     */
    public function send(Request $request): RedirectResponse
    {
        $validated = $request->validate(['email' => ['required', 'email']]);

        Password::sendResetLink($validated);

        $this->audit->record('auth.password_reset.requested', null, [], [
            'email_hash' => hash('sha256', $validated['email']),
        ]);

        return back()->with('status', __('identity.auth.reset_link_sent'));
    }

    public function edit(Request $request, string $token): Response
    {
        return Inertia::render('Auth/ResetPassword', [
            'token' => $token,
            'email' => $request->string('email')->toString(),
        ]);
    }

    public function update(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'token' => ['required'],
            'email' => ['required', 'email'],
            'password' => ['required', 'confirmed', PasswordRule::defaults()],
        ]);

        $resetUserId = null;

        $status = Password::reset($validated, function ($user, string $password) use (&$resetUserId): void {
            $user->forceFill([
                'password' => $password,
                'remember_token' => Str::random(60),
            ])->save();

            // Captured here because Password::reset does not hand the user
            // back, and the notice must name a specific account rather than
            // trusting the email in the request body.
            $resetUserId = (int) $user->id;

            event(new PasswordReset($user));
        });

        if ($status !== Password::PASSWORD_RESET) {
            $this->audit->security('auth.password_reset.failed', [
                'email_hash' => hash('sha256', $validated['email']),
            ]);

            return back()->withErrors(['email' => __($status)]);
        }

        $this->audit->record('auth.password_reset.completed', null, [], [
            'email_hash' => hash('sha256', $validated['email']),
        ], severity: 'warning');

        /*
         * A password change is the single most useful security notice there
         * is: it is how an account holder discovers a reset they did not
         * request, while the attacker still has to be locked out manually.
         * Exempt from the consent gate for that reason.
         */
        $this->notifier->send(
            event: 'account_security',
            recipient: $resetUserId,
            replacements: ['event' => __('notifications.security_events.password_reset')],
            consentPurpose: 'account_security',
            priority: 'urgent',
        );

        return redirect()->route('login')->with('status', __('identity.auth.password_reset'));
    }
}
