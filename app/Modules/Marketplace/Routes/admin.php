<?php

declare(strict_types=1);

use App\Modules\Marketplace\Http\Controllers\Admin\OfferController;
use App\Modules\Marketplace\Http\Controllers\Admin\OfferMediaController;
use Illuminate\Support\Facades\Route;

/*
 * Offer administration. Gated by the same flag as the public surface:
 * moderating offers that can never be published is busywork.
 *
 * Repair prompt §3 / §17: the flag is enforced here, at the server boundary.
 * Hiding the navigation entry is not sufficient — without this group the
 * surface stays fully reachable by typing its URL.
 */
Route::middleware('feature:marketplace.offers')->group(function (): void {
    Route::middleware('permission:marketplace.offers.view')->group(function (): void {
        Route::get('/offers', [OfferController::class, 'index'])->name('offers.index');
    });

    /*
 * EITHER platform moderation, or management of the acting company's own
 * offers. Removing `marketplace.offers.moderate` from the portal role without
 * updating these routes locked company users out of their own listings
 * entirely — a permission rename is only half a change.
 */
    Route::middleware('permission_any:marketplace.offers.moderate,marketplace.offers.manage_own')->group(function (): void {
        Route::get('/offers/create', [OfferController::class, 'create'])->name('offers.create');
        Route::post('/offers', [OfferController::class, 'store'])->name('offers.store');
    });

    /*
 * EITHER platform moderation, or management of the acting company's own
 * offers. Removing `marketplace.offers.moderate` from the portal role without
 * updating these routes locked company users out of their own listings
 * entirely — a permission rename is only half a change.
 */
    Route::middleware('permission_any:marketplace.offers.moderate,marketplace.offers.manage_own')->group(function (): void {
        Route::get('/offers/{offer}/edit', [OfferController::class, 'edit'])->name('offers.edit');
        Route::put('/offers/{offer}', [OfferController::class, 'update'])->name('offers.update');
        Route::post('/offers/{offer}/transition', [OfferController::class, 'transition'])->name('offers.transition');
    });

    Route::middleware('permission:marketplace.offers.view')->group(function (): void {
        Route::get('/offers/{offer}/media', [OfferMediaController::class, 'index'])->name('offers.media.index');
    });

    /*
 * EITHER platform moderation, or management of the acting company's own
 * offers. Removing `marketplace.offers.moderate` from the portal role without
 * updating these routes locked company users out of their own listings
 * entirely — a permission rename is only half a change.
 */
    Route::middleware('permission_any:marketplace.offers.moderate,marketplace.offers.manage_own')->group(function (): void {
        Route::post('/offers/{offer}/media', [OfferMediaController::class, 'store'])->name('offers.media.store');
        Route::delete('/offers/{offer}/media/{media}', [OfferMediaController::class, 'destroy'])->name('offers.media.destroy');
    });

    /*
 * EITHER platform moderation, or management of the acting company's own
 * offers. Removing `marketplace.offers.moderate` from the portal role without
 * updating these routes locked company users out of their own listings
 * entirely — a permission rename is only half a change.
 */
    /*
     * PLATFORM ONLY. The global queue shows every company's pending media; adding
     * `manage_own` here — which my previous blanket edit did — turned a seller's
     * permission over their own listings into a window onto every competitor's
     * unpublished stock.
     */
    Route::middleware('permission:marketplace.offers.moderate')->group(function (): void {
        Route::get('/offers-media/queue', [OfferMediaController::class, 'queue'])->name('offers.media.queue');
        Route::post('/offers/{offer}/media/{media}/moderate', [OfferMediaController::class, 'moderate'])
            ->name('offers.media.moderate');
    });
});
