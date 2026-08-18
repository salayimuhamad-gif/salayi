<?php

declare(strict_types=1);

use App\Modules\Companies\Http\Controllers\Portal\PortalController;
use Illuminate\Support\Facades\Route;

/*
 * The company portal (File one §8.2).
 *
 * A prefix of its own, not a corner of /admin. §8.2 asks for a dashboard
 * separate from the internal Super Admin workspace, and sharing the admin
 * prefix would mean sharing its middleware, its layout and eventually its
 * assumptions about who is looking.
 *
 * `acting-company` resolves the membership and is what makes §8.4 automatic:
 * no route below takes a company id, so none of them can be pointed at another
 * company's data.
 */
Route::middleware(['auth', 'account.active', 'verified', 'feature:companies.portal', 'acting-company'])
    ->prefix('portal')
    ->name('portal.')
    ->group(function (): void {
        Route::get('/', [PortalController::class, 'dashboard'])->name('dashboard');
        Route::get('/offers', [PortalController::class, 'offers'])->name('offers');
        Route::post('/switch', [PortalController::class, 'switchCompany'])->name('switch');
    });
