<?php

declare(strict_types=1);

use App\Modules\Identity\Http\Controllers\Admin\AdministratorsController;
use App\Modules\Identity\Http\Controllers\Admin\DashboardController;
use App\Modules\Identity\Http\Controllers\Admin\UsersController;
use Illuminate\Support\Facades\Route;

// Mounted by ModuleServiceProvider with web + auth + mfa already applied.
Route::get('/', DashboardController::class)->name('dashboard');

/*
 * The operators surface — the "roles machinery" the accounts surface defers
 * to. Listing and assignment are separate capabilities, exactly as the
 * registry declares them: a Security Auditor reads the role map without being
 * able to change it.
 */
Route::middleware('permission:identity.roles.view')->group(function (): void {
    Route::get('/administrators', [AdministratorsController::class, 'index'])
        ->name('administrators.index');
});

Route::middleware('permission:identity.roles.assign')->group(function (): void {
    Route::put('/administrators/{user}/roles', [AdministratorsController::class, 'updateRoles'])
        ->whereNumber('user')->name('administrators.roles.update');
});

Route::middleware('permission:identity.users.suspend')->group(function (): void {
    Route::post('/administrators/{user}/suspend', [AdministratorsController::class, 'suspend'])
        ->whereNumber('user')->name('administrators.suspend');
    Route::post('/administrators/{user}/reactivate', [AdministratorsController::class, 'reactivate'])
        ->whereNumber('user')->name('administrators.reactivate');
});

Route::middleware('permission:identity.sessions.revoke')->group(function (): void {
    Route::post('/administrators/{user}/logout', [AdministratorsController::class, 'forceLogout'])
        ->whereNumber('user')->name('administrators.logout');
});

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

/*
 * Export Sheet, behind its OWN permission: paging through the workspace and
 * walking away with the whole filtered population are different powers, the
 * same way leads.view and leads.export are. Registered before the numeric
 * wildcard cannot collide with it — {user} is whereNumber.
 */
Route::middleware('permission:identity.users.export')->group(function (): void {
    Route::get('/users/export', [UsersController::class, 'export'])->name('users.export');
});

Route::middleware('permission:identity.users.suspend')->group(function (): void {
    Route::post('/users/{user}/suspend', [UsersController::class, 'suspend'])
        ->whereNumber('user')->name('users.suspend');
    Route::post('/users/{user}/reactivate', [UsersController::class, 'reactivate'])
        ->whereNumber('user')->name('users.reactivate');
});

/*
 * There is deliberately no reveal endpoint on this surface any more: the
 * accounts workspace shows the number DIRECTLY to identity.users.contact
 * holders (actor-aware serialization in UsersController::row, page-access
 * audited). The Sales/Leads reveal ceremony is untouched at
 * POST /admin/leads/{lead}/phone.
 */

// Session revocation has its own permission — ending somebody's live
// sessions is a different power from suspending their account.
Route::middleware('permission:identity.sessions.revoke')->group(function (): void {
    Route::post('/users/{user}/logout', [UsersController::class, 'forceLogout'])
        ->whereNumber('user')->name('users.logout');
});

Route::middleware('permission:identity.users.update')->group(function (): void {
    Route::post('/users/{user}/recovery', [UsersController::class, 'sendRecovery'])
        ->whereNumber('user')->name('users.recovery');
});
