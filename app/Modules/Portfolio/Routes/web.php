<?php

declare(strict_types=1);

use App\Modules\Portfolio\Http\Controllers\PortfolioController;
use App\Modules\Portfolio\Http\Controllers\PortfolioMediaController;
use Illuminate\Support\Facades\Route;

/*
 * A person's own portfolio (File one §9).
 *
 * `verified` as well as `auth`: a portfolio holds a home's address, purchase
 * price and valuation history, which is exactly the class §7.11 names. An
 * account created without a proven phone must not accumulate that.
 *
 * No route carries an owner id. Ownership comes from the session, so there is
 * no parameter to tamper with.
 */
Route::middleware(['auth', 'account.active', 'telegram.linked', 'feature:portfolio'])
    ->prefix('account/portfolio')
    ->name('portfolio.')
    ->group(function (): void {
        Route::get('/', [PortfolioController::class, 'index'])->name('index');
        Route::post('/', [PortfolioController::class, 'store'])->name('store');
        Route::get('/export', [PortfolioController::class, 'export'])->name('export');
        Route::get('/{property}', [PortfolioController::class, 'show'])
            ->whereNumber('property')->name('show');
        Route::put('/{property}', [PortfolioController::class, 'update'])
            ->whereNumber('property')->name('update');
        Route::post('/{property}/valuation', [PortfolioController::class, 'requestValuation'])
            ->middleware('throttle:portfolio-valuation')
            ->whereNumber('property')->name('valuation');
        Route::delete('/{property}', [PortfolioController::class, 'destroy'])
            ->whereNumber('property')->name('destroy');

        /*
         * Media (correction §6.8). Streaming download rather than a public
         * URL: the files live on the private disk and only this authorised
         * route reaches them.
         */
        Route::post('/{property}/media', [PortfolioMediaController::class, 'store'])
            ->whereNumber('property')
            ->middleware('throttle:portfolio-media')
            ->name('media.store');
        Route::get('/{property}/media/{media}', [PortfolioMediaController::class, 'download'])
            ->whereNumber('property')->whereNumber('media')->name('media.download');
        Route::delete('/{property}/media/{media}', [PortfolioMediaController::class, 'destroy'])
            ->whereNumber('property')->whereNumber('media')->name('media.destroy');
    });
