<?php

declare(strict_types=1);

use App\Modules\Identity\Http\Controllers\Auth\AuthenticatedSessionController;
use App\Modules\Identity\Http\Controllers\Auth\MfaController;
use App\Modules\Identity\Http\Controllers\Auth\PasswordResetController;
use App\Modules\Identity\Http\Controllers\Auth\RegistrationController;
use App\Modules\Identity\Http\Controllers\Auth\TelegramAuthController;
use App\Modules\Identity\Http\Controllers\Auth\TelegramRecoveryController;
use Illuminate\Support\Facades\Route;

/*
 * Authentication (spec 22.1, 30.1).
 *
 * The MFA routes sit behind `auth` but NOT behind `mfa` — an administrator who
 * has not yet passed the challenge must be able to reach the challenge screen.
 * That is exactly the exemption EnsureMfaConfirmed makes for these route names.
 */
Route::middleware('guest')->group(function (): void {
    Route::get('/login', [AuthenticatedSessionController::class, 'create'])->name('login');
    Route::post('/login', [AuthenticatedSessionController::class, 'store'])->middleware('throttle:login');

    Route::get('/forgot-password', [PasswordResetController::class, 'request'])->name('password.request');
    Route::post('/forgot-password', [PasswordResetController::class, 'send'])
        ->middleware('throttle:password-reset')->name('password.email');
    Route::get('/reset-password/{token}', [PasswordResetController::class, 'edit'])->name('password.reset');
    Route::post('/reset-password', [PasswordResetController::class, 'update'])->name('password.store');

    /*
     * Telegram password recovery — a SEPARATE mechanism from both the email
     * broker above and every Telegram verification/link/handoff token. The
     * challenge lives in its own table (password_recovery_challenges), dies
     * in fifteen minutes or on first use, and can only ever change a
     * password: it never verifies, links, or signs anybody in.
     */
    Route::post('/forgot-password/telegram', [TelegramRecoveryController::class, 'send'])
        ->middleware('throttle:password-recovery')->name('password.recover.request');
    Route::get('/recover/{token}', [TelegramRecoveryController::class, 'show'])
        ->middleware('throttle:password-recovery-redeem')->name('password.recover.show');
    Route::post('/recover', [TelegramRecoveryController::class, 'store'])
        ->middleware('throttle:password-recovery-redeem')->name('password.recover.store');
});

Route::middleware('auth')->group(function (): void {
    /*
     * Logout is the ONE authenticated route deliberately outside
     * `account.active` (see the coverage test's allowlist): any session,
     * including a suspended one, must be able to end itself — and a person
     * choosing to leave should not be shown a suspension error on the way
     * out. The middleware would end the session anyway; this is about the
     * dignity of the exit.
     */
    Route::post('/logout', [AuthenticatedSessionController::class, 'destroy'])->name('logout');

    // H4: refused BEFORE the challenge — a suspended admin never sees MFA.
    Route::middleware('account.active')->prefix('admin')->name('admin.')->group(function (): void {
        Route::get('/mfa/setup', [MfaController::class, 'setup'])->name('mfa.setup');
        Route::post('/mfa/setup', [MfaController::class, 'confirm'])->name('mfa.setup.confirm');
        Route::get('/mfa/recovery', [MfaController::class, 'recovery'])->name('mfa.recovery');
        Route::get('/mfa/challenge', [MfaController::class, 'challenge'])->name('mfa.challenge');
        Route::post('/mfa/challenge', [MfaController::class, 'verify'])
            ->middleware('throttle:mfa')->name('mfa.challenge.verify');
    });
});

/*
 * Telegram sign-in (File one §7).
 *
 * The webhook is deliberately outside the `guest` group and outside `auth`: it
 * is called by Telegram, not by a browser, and is authenticated by its secret
 * header. It is CSRF-exempt via bootstrap/app.php, so the rate limiter and the
 * secret are the only controls — both are applied here.
 */
Route::middleware('guest')->group(function (): void {
    /*
     * Registration by Telegram Start (spec §3). The form and the poll live in
     * the guest group like their login siblings; completion never happens
     * here — only on the webhook, behind the secret.
     */
    Route::get('/register', [RegistrationController::class, 'show'])->name('register.show');
    Route::post('/register', [RegistrationController::class, 'store'])
        ->middleware('throttle:registration')->name('register.store');
    Route::get('/register/poll', [RegistrationController::class, 'poll'])
        ->middleware('throttle:telegram-poll')->name('register.poll');
    Route::post('/register/cancel', [RegistrationController::class, 'cancel'])
        ->name('register.cancel');

    Route::get('/login/telegram', [TelegramAuthController::class, 'show'])->name('telegram.login');
    Route::get('/login/telegram/poll', [TelegramAuthController::class, 'poll'])
        ->middleware('throttle:telegram-poll')->name('telegram.poll');
});

Route::post('/webhooks/telegram/updates', [TelegramAuthController::class, 'webhook'])
    ->middleware('throttle:telegram-webhook')
    ->name('telegram.webhook');
