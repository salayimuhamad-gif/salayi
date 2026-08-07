<?php

declare(strict_types=1);

use App\Modules\Companies\Http\Controllers\Public\CompanyDirectoryController;
use Illuminate\Support\Facades\Route;

/*
 * Public company directory (File one §8.1, File two §11).
 *
 * Behind companies.portal, the same flag that gates company administration —
 * a deployment that has not launched companies should show neither the admin
 * nor the directory, and two flags for one module would eventually disagree.
 *
 * Read-only and unauthenticated: §7.10 keeps browsing open, and a developer's
 * public profile is exactly the sort of thing a buyer looks at before deciding
 * whether to create an account.
 */
Route::middleware('feature:companies.portal')->group(function (): void {
    Route::get('/companies', [CompanyDirectoryController::class, 'index'])->name('companies.index');
    Route::get('/companies/{slug}', [CompanyDirectoryController::class, 'show'])->name('companies.show');
});
