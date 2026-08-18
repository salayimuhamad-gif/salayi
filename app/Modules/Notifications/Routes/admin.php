<?php

declare(strict_types=1);

use App\Modules\Notifications\Http\Controllers\Admin\NotificationCenterController;
use App\Modules\Notifications\Http\Controllers\Admin\NotificationPreferencesController;
use Illuminate\Support\Facades\Route;

/*
 * The notification centre.
 *
 * ModuleServiceProvider wraps this file in ['web', 'auth', 'mfa'], which is the
 * whole authorisation story: a signed-in, MFA-confirmed user reads their own
 * notifications. No `permission:` middleware, on purpose — these rows belong to
 * the recipient, and the controller scopes every query to the authenticated id
 * rather than to anything the request supplies.
 */
Route::get('/notifications', [NotificationCenterController::class, 'index'])
    ->name('notifications.index');

Route::get('/notifications/{notification}', [NotificationCenterController::class, 'show'])
    ->whereNumber('notification')
    ->name('notifications.show');

Route::post('/notifications/read-all', [NotificationCenterController::class, 'markAllRead'])
    ->name('notifications.read_all');

Route::post('/notifications/{notification}/read', [NotificationCenterController::class, 'markRead'])
    ->whereNumber('notification')
    ->name('notifications.read');

/*
 * Delivery preferences (spec 22.2).
 *
 * Declared before the {notification} route would otherwise claim it — a GET to
 * /notifications/preferences must reach this and not be parsed as a numeric id.
 * The whereNumber constraint above already prevents that; the ordering makes it
 * true regardless of whether that constraint survives a future edit.
 */
Route::get('/notifications/preferences', [NotificationPreferencesController::class, 'edit'])
    ->name('notifications.preferences.edit');

Route::post('/notifications/preferences', [NotificationPreferencesController::class, 'update'])
    ->name('notifications.preferences.update');
