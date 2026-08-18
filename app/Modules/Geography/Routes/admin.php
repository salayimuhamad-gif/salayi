<?php

declare(strict_types=1);

/*
 * Geography administration.
 *
 * Areas are deliberately NOT flagged. Every project belongs to an area and the
 * hierarchy underpins project administration, so gating it behind an optional
 * flag would break a core surface rather than an optional one.
 *
 * Places ARE flagged (places.database): the landmark database, nearby-distance
 * calculation and lifestyle matching are the optional layer built on top.
 */
use App\Modules\Geography\Http\Controllers\Admin\AreaController;
use App\Modules\Geography\Http\Controllers\Admin\PlaceCategoryController;
use App\Modules\Geography\Http\Controllers\Admin\PlaceController;
use Illuminate\Support\Facades\Route;

Route::middleware('permission:geography.areas.view')->group(function (): void {
    Route::get('/areas', [AreaController::class, 'index'])->name('areas.index');
});

Route::middleware('permission:geography.areas.create')->group(function (): void {
    Route::get('/areas/create', [AreaController::class, 'create'])->name('areas.create');
    Route::post('/areas', [AreaController::class, 'store'])->name('areas.store');
});

Route::middleware('permission:geography.areas.update')->group(function (): void {
    Route::get('/areas/{area}/edit', [AreaController::class, 'edit'])->name('areas.edit');
    Route::put('/areas/{area}', [AreaController::class, 'update'])->name('areas.update');
    Route::post('/areas/{area}/transition', [AreaController::class, 'transition'])->name('areas.transition');
});

Route::middleware(['feature:places.database', 'permission:geography.places.view'])->group(function (): void {
    Route::get('/places', [PlaceController::class, 'index'])->name('places.index');
});

Route::middleware(['feature:places.database', 'permission:geography.places.create'])->group(function (): void {
    Route::get('/places/create', [PlaceController::class, 'create'])->name('places.create');
    Route::post('/places', [PlaceController::class, 'store'])->name('places.store');
});

Route::middleware(['feature:places.database', 'permission:geography.places.update'])->group(function (): void {
    Route::get('/places/{place}/edit', [PlaceController::class, 'edit'])->name('places.edit');
    Route::put('/places/{place}', [PlaceController::class, 'update'])->name('places.update');
    Route::post('/places/{place}/transition', [PlaceController::class, 'transition'])->name('places.transition');
});

/*
 * Place category administration (File two §5.1): the admin adds types and
 * changes icons and names without a code change. Behind the same flag as the
 * places database it configures.
 */
Route::middleware(['feature:places.database', 'permission:geography.places.view'])->group(function (): void {
    Route::get('/places/categories', [PlaceCategoryController::class, 'index'])->name('places.categories.index');
});

Route::middleware(['feature:places.database', 'permission:geography.places.create'])->group(function (): void {
    Route::post('/places/categories', [PlaceCategoryController::class, 'store'])->name('places.categories.store');
    Route::put('/places/categories/{category}', [PlaceCategoryController::class, 'update'])->name('places.categories.update');
    Route::post('/places/categories/{category}/deactivate', [PlaceCategoryController::class, 'deactivate'])
        ->name('places.categories.deactivate');
});
