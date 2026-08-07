<?php

declare(strict_types=1);

use App\Modules\Imports\Http\Controllers\Admin\PriceImportController;
use Illuminate\Support\Facades\Route;

Route::middleware('permission:imports.view')->group(function (): void {
    Route::get('/imports/prices', [PriceImportController::class, 'index'])->name('imports.prices.index');
});

Route::middleware('permission:imports.run')->group(function (): void {
    Route::post('/imports/prices/preview', [PriceImportController::class, 'preview'])->name('imports.prices.preview');
    Route::post('/imports/prices/accept', [PriceImportController::class, 'accept'])->name('imports.prices.accept');
    Route::post('/imports/prices/discard', [PriceImportController::class, 'discard'])->name('imports.prices.discard');
    Route::post('/imports/prices/{batch}/rollback', [PriceImportController::class, 'rollback'])->name('imports.prices.rollback');
});
