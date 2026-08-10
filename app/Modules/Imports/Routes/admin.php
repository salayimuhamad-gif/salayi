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
});

/*
 * Behind its own declared permission, matching the button that offers it.
 * The route sat in the imports.run group while the UI gated the action on
 * imports.rollback — so the registry's distinct rollback capability
 * authorised nothing, and a role holding run-but-not-rollback could reach an
 * action it was never shown. Undoing an accepted batch is a bigger decision
 * than running an import; the registry already says so.
 */
Route::middleware('permission:imports.rollback')->group(function (): void {
    Route::post('/imports/prices/{batch}/rollback', [PriceImportController::class, 'rollback'])->name('imports.prices.rollback');
});
