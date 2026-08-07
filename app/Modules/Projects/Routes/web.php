<?php

declare(strict_types=1);

use App\Modules\Projects\Http\Controllers\Public\ProjectProfileController;
use Illuminate\Support\Facades\Route;

/*
 * Public project surfaces. Mounted with the `web` stack by
 * ModuleServiceProvider, so SetLocale has already resolved the language before
 * these run.
 */
Route::get('/projects', [ProjectProfileController::class, 'index'])->name('projects.index');
Route::get('/projects/{slug}', [ProjectProfileController::class, 'show'])->name('projects.show');
