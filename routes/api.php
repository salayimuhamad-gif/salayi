<?php

declare(strict_types=1);

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;

/*
 * Versioned internal API (spec 28.1). Domain endpoints are added by their own
 * module under Routes/api.php and mounted at /api/v1.
 */

Route::middleware('throttle:api')->prefix('v1')->name('api.v1.')->group(function (): void {
    Route::get('/health', static fn (Request $request) => response()->json([
        'status' => 'ok',
        'version' => config('mulkihawler.version'),
        'locale' => app()->getLocale(),
        'time' => now()->toIso8601String(),
    ]))->name('health');
});
