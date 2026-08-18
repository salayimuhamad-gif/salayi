<?php

declare(strict_types=1);

use App\Modules\Notifications\Http\Controllers\UnsubscribeController;
use Illuminate\Support\Facades\Route;

/*
 * Public notification routes.
 *
 * Registered through LocalizedRoutes (see ModuleServiceProvider), so the link
 * exists in every enabled language and the recipient lands in their own.
 *
 * Unauthenticated on purpose: spec 22.3 promises a way to stop the messages,
 * and a promise that first requires remembering a password is not one.
 *
 * The token is constrained to the characters the signer emits, so a request
 * carrying a path traversal or an SQL fragment never reaches the controller.
 */
Route::get('/unsubscribe/{token}', [UnsubscribeController::class, 'show'])
    ->where('token', '[A-Za-z0-9._-]+')
    ->name('notifications.unsubscribe');

Route::post('/unsubscribe/{token}', [UnsubscribeController::class, 'store'])
    ->where('token', '[A-Za-z0-9._-]+')
    ->name('notifications.unsubscribe.confirm');

Route::post('/unsubscribe/{token}/undo', [UnsubscribeController::class, 'resubscribe'])
    ->where('token', '[A-Za-z0-9._-]+')
    ->name('notifications.unsubscribe.undo');
