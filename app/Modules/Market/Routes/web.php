<?php

declare(strict_types=1);

use App\Modules\Market\Http\Controllers\Public\MarketController;
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
});
