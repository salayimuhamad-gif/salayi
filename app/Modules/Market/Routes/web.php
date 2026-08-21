<?php

declare(strict_types=1);

use App\Modules\Market\Http\Controllers\Public\MarketController;
use App\Modules\Market\Http\Controllers\Public\MarketMovementController;
use Illuminate\Support\Facades\Route;

// Registered once per enabled locale by LocalizedRoutes.
/*
 * Public market intelligence (spec: market.intelligence).
 *
 * Repair prompt §3 / §17: the flag is enforced here, at the server boundary.
 * Hiding the navigation entry is not sufficient — without this group the
 * surface stays fully reachable by typing its URL.
 */
Route::middleware('feature:market.intelligence')->group(function (): void {
    Route::get('/market', MarketController::class)->name('market.index');

    /*
     * Wave 4: movement derived on demand from the published index series.
     * Its own limiter, deliberately — the Wave 3 E2E repair documented what
     * happens when two surfaces spend one bucket (`map-features`), and this
     * endpoint must never be the next collision.
     */
    Route::get('/market/movement', MarketMovementController::class)
        ->middleware('throttle:market-movement')
        ->name('market.movement');
});
