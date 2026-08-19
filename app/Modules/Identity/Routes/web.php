<?php

declare(strict_types=1);

use App\Modules\Identity\Http\Controllers\Account\ConsentController;
use App\Modules\Identity\Http\Controllers\Account\OnboardingController;
use App\Modules\Identity\Http\Controllers\Account\ProfileController;
use App\Modules\Identity\Http\Controllers\AccountTelegramLinkController;
use App\Modules\Identity\Http\Controllers\Auth\TelegramReturnController;
use Illuminate\Support\Facades\Route;

/*
 * The signed-in account surface (spec §5).
 *
 * Loaded through the module loader's LocalizedRoutes wrapper like every other
 * public-module route file, so /account/onboarding exists in all three
 * locales exactly as /account/portfolio does.
 *
 * `telegram.linked` and not merely `auth`: onboarding is the first stop AFTER
 * the link completes, and an unlinked session arriving here is a registration
 * that has not finished — the gate sends it back to finish.
 */
Route::middleware(['auth', 'account.active', 'telegram.linked'])->group(function (): void {
    Route::get('/account/onboarding', [OnboardingController::class, 'show'])->name('account.onboarding');
    Route::post('/account/onboarding', [OnboardingController::class, 'store'])->name('account.onboarding.store');

    Route::get('/account/profile', [ProfileController::class, 'show'])->name('account.profile');
    Route::put('/account/profile', [ProfileController::class, 'update'])->name('account.profile.update');
    Route::post('/account/profile/photo', [ProfileController::class, 'storePhoto'])
        ->middleware('throttle:profile-photo')->name('account.profile.photo');
    Route::delete('/account/profile/photo', [ProfileController::class, 'destroyPhoto'])
        ->name('account.profile.photo.destroy');

    /*
     * v4 BLOCKER 6: contact-consent self service. Separate from the profile
     * because it IS separate — `contact_preference` on the profile says how
     * to reach somebody, this says whether they may be reached at all, and
     * putting them on one screen is how the two got confused in the first
     * place.
     */
    Route::get('/account/privacy', [ConsentController::class, 'show'])->name('account.privacy');
    Route::post('/account/privacy/withdraw', [ConsentController::class, 'withdraw'])
        ->name('account.privacy.withdraw');
    Route::post('/account/privacy/grant', [ConsentController::class, 'grant'])
        ->name('account.privacy.grant');
});

/*
 * The account-link surface (correction C1): `auth` WITHOUT the
 * `telegram.linked` gate, because this is where the gate SENDS people.
 * An unlinked-but-signed-in account must be able to reach exactly these
 * three routes, and nothing else personalised, until the link completes.
 */
Route::middleware(['auth', 'account.active'])->group(function (): void {
    Route::get('/account/telegram/link', [AccountTelegramLinkController::class, 'show'])
        ->name('account.telegram.link');
    Route::get('/account/telegram/link/poll', [AccountTelegramLinkController::class, 'poll'])
        ->middleware('throttle:telegram-link-poll')->name('account.telegram.link.poll');
    Route::post('/account/telegram/link/cancel', [AccountTelegramLinkController::class, 'cancel'])
        ->name('account.telegram.link.cancel');

    /*
     * v7: abandon an unfinished account-first registration and start over.
     * Inside this group because it is only reachable by a signed-in account,
     * and outside `telegram.linked` because an unlinked account is exactly
     * who needs it. The controller refuses once the account IS linked.
     */
    Route::post('/account/registration/abandon', [AccountTelegramLinkController::class, 'abandon'])
        ->middleware('throttle:6,1')
        ->name('account.registration.abandon');

    /*
     * v4 BLOCKER 1: minting a replacement token is now an explicit act, so
     * it is a POST with a name of its own rather than a side effect of
     * rendering the page.
     */
    Route::post('/account/telegram/link/restart', [AccountTelegramLinkController::class, 'restart'])
        ->name('account.telegram.link.restart');

    /*
     * v4 §5: the browser confirmation step. `confirm` is throttled on the
     * same bucket as the poll — it is reachable only with a parked
     * candidate and a matching handle, but it is the last gate before a
     * durable identity link and does not need to be cheap to hammer.
     */
    Route::post('/account/telegram/link/confirm', [AccountTelegramLinkController::class, 'confirm'])
        ->middleware('throttle:telegram-link-poll')->name('account.telegram.link.confirm');
    Route::post('/account/telegram/link/reject', [AccountTelegramLinkController::class, 'reject'])
        ->name('account.telegram.link.reject');
});

/*
 * v7 BLOCKER 2: the return handoff from Telegram's in-app browser.
 *
 * GUEST-reachable by necessity — the whole point is that this browser has no
 * session yet — so it is throttled hard and the token is single-use and
 * short-lived. It is in the public (localized) route file so the neutral page
 * exists in all three languages.
 */
Route::middleware(['throttle:10,1'])->group(function (): void {
    Route::get('/account/return/{token}', TelegramReturnController::class)
        ->where('token', '[A-Za-z0-9]{32,128}')
        ->name('telegram.return');

    Route::get('/account/return-expired', [TelegramReturnController::class, 'expired'])
        ->name('telegram.return.expired');

    /*
     * The page the FIRST Telegram message points at, before browser
     * confirmation exists.
     *
     * Telegram opens links in an in-app browser that carries none of this
     * site's cookies, and at that moment no handoff has been minted — the
     * person has not confirmed anything yet. Pointing that button at the
     * authenticated linking page therefore landed a cold browser on the login
     * screen. This page is guest-safe, says only "go back to the tab you
     * started in", and authenticates nobody.
     */
    Route::get('/account/return-to-tab', [TelegramReturnController::class, 'returnToTab'])
        ->name('telegram.return.to_tab');
});

/*
 * Browser-test introspection for the return handoff.
 *
 * The handoff token only ever exists inside a Telegram message, and the browser
 * suite runs against a faked Telegram API — so without this the fresh-context
 * test could not obtain the URL a real person would tap, and the most important
 * assertion in the suite (that a cookie-less browser is authenticated) could
 * not be made at all.
 *
 * It is registered ONLY when the application is in the `testing` or `local`
 * environment. In production this route does not exist — asserted by
 * test_the_handoff_introspection_route_cannot_exist_in_production — and it
 * returns only the URL that was just sent to the chat, which the person
 * holding that chat already has.
 */
if (app()->environment(['testing', 'local'])) {
    Route::get('/__testing__/last-return-handoff', function () {
        $url = cache()->get('testing:last-return-handoff');

        abort_if($url === null, 404);

        return response()->json(['url' => $url]);
    })->name('testing.last_return_handoff');

    Route::get('/__testing__/last-telegram-button/{destination}', function (string $destination) {
        $payload = cache()->get('testing:last-telegram-button:'.$destination);

        abort_if($payload === null, 404);

        return response()->json($payload);
    })->where('destination', '[a-z_]+')->name('testing.last_telegram_button');
}
