<?php

declare(strict_types=1);

use App\Modules\Branding\Http\Controllers\Admin\BrandingController;
use App\Modules\Branding\Http\Controllers\Admin\FeatureFlagController;
use Illuminate\Support\Facades\Route;

Route::middleware('permission:branding.settings.view')->group(function (): void {
    Route::get('/branding', [BrandingController::class, 'edit'])->name('branding.edit');
});

Route::middleware('permission:branding.settings.update')->group(function (): void {
    Route::put('/branding', [BrandingController::class, 'update'])->name('branding.update');
    Route::post('/branding/asset', [BrandingController::class, 'upload'])->name('branding.asset.upload');
});

Route::middleware('permission:system.features.view')->group(function (): void {
    Route::get('/features', [FeatureFlagController::class, 'index'])->name('features.index');
});

Route::middleware('permission:system.features.update')->group(function (): void {
    Route::put('/features', [FeatureFlagController::class, 'update'])->name('features.update');
});
