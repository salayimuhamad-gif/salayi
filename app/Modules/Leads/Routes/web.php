<?php

declare(strict_types=1);

use App\Modules\Leads\Http\Controllers\Account\AccountRequestsController;
use Illuminate\Support\Facades\Route;

/*
 * The person's own submitted requests (spec §7.3): what they asked the
 * MyHawler team for, and where each request stands. Behind the Telegram gate
 * like every personal surface, and scoped structurally to the signed-in
 * account — the controller queries by $request->user() and nothing else.
 */
Route::middleware(['auth', 'account.active', 'telegram.linked'])->group(function (): void {
    Route::get('/account/requests', [AccountRequestsController::class, 'index'])->name('account.requests');
});
