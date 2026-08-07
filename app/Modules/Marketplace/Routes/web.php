<?php

declare(strict_types=1);

use App\Modules\Marketplace\Http\Controllers\Public\OfferBrowseController;
use Illuminate\Support\Facades\Route;

// Registered once per enabled locale by LocalizedRoutes.
/*
 * Public offers marketplace (spec: marketplace.offers).
 *
 * Repair prompt §3 / §17: the flag is enforced here, at the server boundary.
 * Hiding the navigation entry is not sufficient — without this group the
 * surface stays fully reachable by typing its URL.
 */
Route::middleware('feature:marketplace.offers')->group(function (): void {
    Route::get('/offers', [OfferBrowseController::class, 'index'])->name('offers.index');
    Route::get('/offers/{publicId}', [OfferBrowseController::class, 'show'])->name('offers.show');
});
