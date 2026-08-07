<?php

declare(strict_types=1);

use App\Modules\Operations\Http\Controllers\Admin\OperationsController;
use App\Modules\Operations\Http\Controllers\Admin\SystemSettingsController;
use Illuminate\Support\Facades\Route;

Route::middleware('permission:audit.logs.view')->group(function (): void {
    Route::get('/operations/audit', [OperationsController::class, 'audit'])->name('operations.audit');
});

Route::middleware('permission:system.settings.view')->group(function (): void {
    Route::get('/settings', [SystemSettingsController::class, 'index'])->name('system.settings');
    Route::get('/operations/health', [OperationsController::class, 'health'])->name('operations.health');
});

Route::middleware('permission:system.settings.update')->group(function (): void {
    Route::put('/settings/general', [SystemSettingsController::class, 'updateGeneral'])->name('system.settings.general.update');
});

Route::middleware('permission:system.integrations.update')->group(function (): void {
    Route::put('/settings/integrations', [SystemSettingsController::class, 'updateIntegrations'])->name('system.settings.integrations.update');
});
