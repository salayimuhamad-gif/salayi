<?php

declare(strict_types=1);

use App\Modules\Install\Http\Controllers\InstallController;
use Illuminate\Support\Facades\Route;

/*
 * Installer routes (spec 33.1).
 *
 * Unauthenticated by necessity — no user exists yet — so the rate limiter is
 * the only control available. EnsureInstalled 404s this whole group once the
 * lock file is written.
 */
Route::prefix('install')
    ->name('install.')
    ->middleware(['throttle:install'])
    ->group(function (): void {
        Route::get('/', [InstallController::class, 'index'])->name('index');
        Route::get('/step/{step}', [InstallController::class, 'show'])->name('step');
        Route::post('/step/{step}', [InstallController::class, 'store'])->name('step.store');
        Route::post('/test/database', [InstallController::class, 'testDatabase'])->name('test.database');
        Route::post('/test/mail', [InstallController::class, 'testMail'])->name('test.mail');
        // Repair prompt §2.12. Neither endpoint echoes the credential back.
        Route::post('/test/telegram', [InstallController::class, 'testTelegram'])->name('test.telegram');
        Route::post('/test/ai', [InstallController::class, 'testAi'])->name('test.ai');
    });
