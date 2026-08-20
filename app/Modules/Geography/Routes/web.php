<?php

declare(strict_types=1);

use App\Modules\Geography\Http\Controllers\Public\AreaProfileController;
use App\Modules\Geography\Http\Controllers\Public\LocationResolveController;
use App\Modules\Geography\Http\Controllers\Public\MapExplorerController;
use App\Modules\Geography\Http\Controllers\Public\PlaceProfileController;
use Illuminate\Support\Facades\Route;

/*
 * Public Area profiles (spec 10.2, 12.2).
 *
 * Registered once per locale by LocalizedRoutes, like every other module
 * web.php. Gated on `geography.areas` so the navigation and the route are
 * switched by the same flag — the arrangement that was missing when the nav
 * rendered /areas unconditionally against no route at all.
 *
 * Slug rather than id: the homepage previously linked `/areas/{id}`, which is
 * stable but unreadable and unshareable.
 */
Route::middleware('feature:geography.areas')->group(function (): void {
    Route::get('/areas', [AreaProfileController::class, 'index'])->name('areas.index');
    Route::get('/areas/{slug}', [AreaProfileController::class, 'show'])
        ->where('slug', '[a-z0-9\-]+')
        ->name('areas.show');
});

/*
 * The public Map Explorer (File one §5, File two §10).
 *
 * The features endpoint is a GET because it is a read shaped entirely by its
 * query string — a viewport. That makes it cacheable at the edge and safe to
 * retry, both of which matter on Erbil mobile data where a dropped request is
 * routine.
 */
Route::middleware('feature:map.explorer')->group(function (): void {
    Route::get('/map', [MapExplorerController::class, 'index'])->name('map.index');
    Route::get('/map/features', [MapExplorerController::class, 'features'])
        ->middleware('throttle:map-features')
        ->name('map.features');
});

/*
 * The public Investment Map: the explorer's core configured down to approved
 * investment/project content, on its own flag so an operator can run either
 * surface — or both — independently. `/invest` rather than `/map/invest`
 * because the navigation marks sections active by path prefix, and a child
 * of /map would light two rail items at once.
 */
Route::middleware('feature:map.investment')->group(function (): void {
    Route::get('/invest', [MapExplorerController::class, 'invest'])->name('map.invest');
    Route::get('/invest/features', [MapExplorerController::class, 'investFeatures'])
        ->middleware('throttle:map-features')
        ->name('map.invest.features');
    Route::get('/invest/search', [MapExplorerController::class, 'investSearch'])
        ->middleware('throttle:map-search')
        ->name('map.invest.search');
});

/*
 * Wave 3: "Know the Price Where You Are" — the homepage location card's one
 * backend call. A GET because it is a read shaped entirely by its query
 * string, like /map/features above; unlike the map endpoints it carries a
 * person's coordinates, so the controller answers `no-store, private` and
 * nothing on this path persists.
 *
 * Its own NAMED limiter, not a ride on map-features — the map surfaces
 * already proved that two features sharing a bucket starve each other
 * (see GeographyServiceProvider), and this endpoint must not spend the map's
 * panning budget or be spent by it.
 */
Route::get('/location/resolve', LocationResolveController::class)
    ->middleware('throttle:location-resolve')
    ->name('location.resolve');

/*
 * Public Place profiles (spec 10.3, 12.2).
 *
 * Gated on `places.database` — the flag that already governs whether the
 * places dataset is a live surface at all — so the directory, the profile and
 * the nearby-amenity data are switched together rather than independently.
 */
Route::middleware('feature:places.database')->group(function (): void {
    Route::get('/places', [PlaceProfileController::class, 'index'])->name('places.index');
    Route::get('/places/{slug}', [PlaceProfileController::class, 'show'])
        ->where('slug', '[a-z0-9\-]+')
        ->name('places.show');
});
