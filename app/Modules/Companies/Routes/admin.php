<?php

declare(strict_types=1);

use App\Modules\Companies\Http\Controllers\Admin\CompanyController;
use App\Modules\Companies\Http\Controllers\Admin\CompanyDeveloperController;
use Illuminate\Support\Facades\Route;

Route::middleware(['feature:companies.portal', 'permission:companies.view'])->group(function (): void {
    Route::get('/companies', [CompanyController::class, 'index'])->name('companies.index');
});

Route::middleware(['feature:companies.portal', 'permission:companies.create'])->group(function (): void {
    Route::get('/companies/create', [CompanyController::class, 'create'])->name('companies.create');
    Route::post('/companies', [CompanyController::class, 'store'])->name('companies.store');
});

/*
 * Either platform-wide company administration, or scoped administration of the
 * acting company. The controller decides which applies — a route group cannot
 * know which company is in front of the visitor.
 */
Route::middleware(['feature:companies.portal', 'permission_any:companies.update,companies.update_own'])->group(function (): void {
    Route::get('/companies/{company}/edit', [CompanyController::class, 'edit'])->name('companies.edit');
    Route::put('/companies/{company}', [CompanyController::class, 'update'])->name('companies.update');
});

Route::middleware(['feature:companies.portal', 'permission:companies.verify'])->group(function (): void {
    Route::post('/companies/{company}/verify', [CompanyController::class, 'verify'])->name('companies.verify');
});

Route::middleware(['feature:companies.portal', 'permission:companies.associations.manage'])->group(function (): void {
    Route::post('/companies/{company}/associations', [CompanyController::class, 'associate'])->name('companies.associate');
    Route::delete('/companies/{company}/associations/{association}', [CompanyController::class, 'revokeAssociation'])
        ->name('companies.associations.revoke');
});

/*
 * Company↔developer link review (spec 11.2).
 *
 * PLATFORM-ONLY, via projects.create_unscoped. A company approving its own
 * claim to represent a developer is not a control — which is the whole reason
 * a Wizard-created link starts as pending.
 */
Route::middleware('permission:projects.create_unscoped')->group(function (): void {
    Route::get('/company-developers', [CompanyDeveloperController::class, 'index'])
        ->name('company-developers.index');
    Route::post('/companies/{company}/developers', [CompanyDeveloperController::class, 'store'])
        ->name('companies.developers.store');
    Route::post('/company-developers/{link}/approve', [CompanyDeveloperController::class, 'approve'])
        ->name('company-developers.approve');
    Route::post('/company-developers/{link}/reject', [CompanyDeveloperController::class, 'reject'])
        ->name('company-developers.reject');
    Route::post('/company-developers/{link}/revoke', [CompanyDeveloperController::class, 'revoke'])
        ->name('company-developers.revoke');
});
