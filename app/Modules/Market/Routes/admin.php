<?php

declare(strict_types=1);

/*
 * Market administration.
 *
 * Two flags, not one: price records feed market.intelligence, while computed
 * indices are a separate commercial surface under market.indices. A site may
 * legitimately collect and review prices before it publishes any index.
 *
 * Flag first, then permission — a disabled module should not report a
 * permission problem for a feature that is not running at all.
 */
use App\Modules\Market\Http\Controllers\Admin\IndexValueController;
use App\Modules\Market\Http\Controllers\Admin\MarketIndexController;
use App\Modules\Market\Http\Controllers\Admin\PriceRecordController;
use Illuminate\Support\Facades\Route;

Route::middleware(['feature:market.intelligence', 'permission:market.prices.view'])->group(function (): void {
    Route::get('/market/prices', [PriceRecordController::class, 'index'])->name('market.prices.index');
});

Route::middleware(['feature:market.intelligence', 'permission:market.prices.approve'])->group(function (): void {
    Route::post('/market/prices/publish', [PriceRecordController::class, 'publish'])->name('market.prices.publish');
    Route::post('/market/prices/{price}/outlier', [PriceRecordController::class, 'flagOutlier'])->name('market.prices.outlier');
});

Route::middleware(['feature:market.indices', 'permission:market.indices.view'])->group(function (): void {
    Route::get('/market/indices', [MarketIndexController::class, 'index'])->name('market.indices.index');
});

Route::middleware(['feature:market.indices', 'permission:market.indices.configure'])->group(function (): void {
    Route::post('/market/indices', [MarketIndexController::class, 'store'])->name('market.indices.store');
    Route::put('/market/indices/{index}', [MarketIndexController::class, 'update'])->name('market.indices.update');
    Route::post('/market/indices/{index}/rebuild', [MarketIndexController::class, 'rebuild'])->name('market.indices.rebuild');
    Route::post('/market/indices/{index}/transition', [MarketIndexController::class, 'transition'])->name('market.indices.transition');
});

Route::middleware(['feature:market.indices', 'permission:market.indices.view'])->group(function (): void {
    Route::get('/market/indices/{index}/values', [IndexValueController::class, 'index'])->name('market.indices.values');
});

Route::middleware(['feature:market.indices', 'permission:market.indices.configure'])->group(function (): void {
    Route::post('/market/indices/{index}/values/publish', [IndexValueController::class, 'publish'])
        ->name('market.indices.values.publish');
});
