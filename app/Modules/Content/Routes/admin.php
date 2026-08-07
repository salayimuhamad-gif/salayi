<?php

declare(strict_types=1);

use App\Modules\Content\Http\Controllers\Admin\ContentController;
use Illuminate\Support\Facades\Route;

/*
 * Content administration (File one §10).
 *
 * Writing and publishing are separately permissioned. An editor who could set
 * status directly would make the approval step documentation rather than
 * control.
 */
Route::middleware('permission:content.view')->group(function (): void {
    Route::get('/content', [ContentController::class, 'index'])->name('content.index');
});

Route::middleware('permission:content.create')->group(function (): void {
    Route::post('/content', [ContentController::class, 'store'])->name('content.store');
});

Route::middleware('permission:content.update')->group(function (): void {
    Route::put('/content/{content}', [ContentController::class, 'update'])->name('content.update');
});

Route::middleware('permission:content.publish')->group(function (): void {
    Route::post('/content/{content}/publish', [ContentController::class, 'publish'])->name('content.publish');
    Route::post('/content/{content}/unpublish', [ContentController::class, 'unpublish'])->name('content.unpublish');
});
