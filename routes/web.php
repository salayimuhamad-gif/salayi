<?php

declare(strict_types=1);

use App\Http\Middleware\SetLocale;
use App\Modules\Core\Http\Controllers\HomeController;
use App\Modules\Core\Support\LocalizedRoutes;
use Illuminate\Support\Facades\Route;
use Inertia\Inertia;

/*
 * Application-level web routes.
 *
 * Domain routes live inside their own module, under
 * app/Modules/<Domain>/Routes/, and are discovered automatically by
 * ModuleServiceProvider. Only genuinely cross-cutting surfaces belong here.
 */

/*
 * The default locale has no prefix under `prefix_except_default`, so
 * /ckb/anything is not a registered route. Redirecting rather than 404ing:
 * the URL is a reasonable guess, a shared link may already carry it, and a
 * permanent redirect consolidates any accumulated authority onto the canonical
 * form instead of discarding it.
 */
Route::get('/{defaultLocale}/{path?}', static function (string $defaultLocale, ?string $path = null) {
    return redirect('/'.ltrim((string) $path, '/'), 301);
})->where([
    'defaultLocale' => preg_quote((string) config('localization.default', 'ckb'), '/'),
    'path' => '.*',
])->name('locale.canonical_redirect');

/*
 * The homepage assembles real published data only (File one §4). A controller
 * rather than a closure because it has to ask four modules what they actually
 * have, and answer honestly when the answer is nothing.
 *
 * REGISTERED PER LOCALE, like every other public surface.
 *
 * These two routes were declared bare while all seventy-four module public
 * routes went through LocalizedRoutes::group(). The homepage was therefore the
 * only public page in the product with no `/ar` or `/en` form, and the
 * consequence was not theoretical: `useLocale().localized('/')` returns `/ar`
 * on an Arabic page, and that is what the top bar's brand link and every
 * "back to home" path emit — so on `/ar/projects` the site logo pointed at a
 * 404, in Arabic and English, on every page of the site.
 *
 * This does not change the locale architecture; it applies the architecture
 * that is already configured. `url_strategy` has been `prefix_except_default`
 * since Step 1, ckb stays unprefixed at `/`, and ar/en gain `/ar` and `/en`
 * exactly as they already have `/ar/projects` and `/en/projects`.
 *
 * The `/{defaultLocale}/…` redirect above is unaffected: its parameter is
 * constrained to `ckb`, so it never competes with these.
 */
LocalizedRoutes::group(function (): void {
    Route::get('/', HomeController::class)->name('home');

    // Served by the service worker when the device is offline (spec 9.1).
    Route::get('/offline', static fn () => Inertia::render('Public/Offline'))->name('offline');
});

/*
 * Explicit language switch. POST rather than GET so a crawler cannot change a
 * visitor's language by following a link, and so the action is CSRF-protected.
 * A signed-in user's choice is persisted to their profile; a guest's lives in
 * the session (spec 7.1 resolution order).
 */
Route::post('/locale/{locale}', static function (string $locale) {
    abort_unless(in_array($locale, enabled_locales(), true), 404);

    session()->put(SetLocale::SESSION_KEY, $locale);

    $user = auth()->user();

    if ($user !== null) {
        $user->forceFill(['preferred_locale' => $locale])->save();
    }

    return back();
})->name('locale.switch');
