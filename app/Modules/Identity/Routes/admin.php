<?php

declare(strict_types=1);

use App\Modules\Identity\Http\Controllers\Admin\DashboardController;
use App\Modules\Identity\Http\Controllers\Admin\UsersController;
use Illuminate\Support\Facades\Route;

// Mounted by ModuleServiceProvider with web + auth + mfa already applied.
Route::get('/', DashboardController::class)->name('dashboard');

/*
 * Member accounts (spec §8). Reading, managing and revealing are three
 * capabilities on purpose: the list never contains a number, suspension is a
 * separate lever, and the reveal is its own audited act behind its own
 * permission — mirroring exactly how the leads surface divides the same
 * powers.
 */
Route::middleware('permission:identity.users.view')->group(function (): void {
    Route::get('/users', [UsersController::class, 'index'])->name('users.index');
    Route::get('/users/{user}', [UsersController::class, 'show'])
        ->whereNumber('user')->name('users.show');
});

Route::middleware('permission:identity.users.suspend')->group(function (): void {
    Route::post('/users/{user}/suspend', [UsersController::class, 'suspend'])
        ->whereNumber('user')->name('users.suspend');
    Route::post('/users/{user}/reactivate', [UsersController::class, 'reactivate'])
        ->whereNumber('user')->name('users.reactivate');
});

Route::middleware('permission:identity.users.contact')->group(function (): void {
    Route::post('/users/{user}/phone', [UsersController::class, 'revealPhone'])
        ->whereNumber('user')->name('users.phone.reveal');
});
