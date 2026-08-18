<?php

declare(strict_types=1);

use App\Modules\Branding\Http\Controllers\ManifestController;
use Illuminate\Support\Facades\Route;

/*
 * The PWA manifest (File one §12.1).
 *
 * Public and unauthenticated: a browser fetches it before anybody signs in, and
 * it contains only branding an anonymous visitor already sees on every page.
 *
 * The pwa flag is enforced inside the controller rather than by middleware, so
 * a disabled site returns a plain 404 rather than the feature-disabled HTML
 * page — which a browser would otherwise try to parse as a manifest.
 */
Route::get('/manifest.webmanifest', ManifestController::class)->name('pwa.manifest');
