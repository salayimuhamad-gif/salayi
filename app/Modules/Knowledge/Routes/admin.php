<?php

declare(strict_types=1);

use App\Modules\Knowledge\Http\Controllers\Admin\KnowledgeEventController;
use Illuminate\Support\Facades\Route;

Route::middleware('permission:knowledge.events.view')->group(function (): void {
    Route::get('/knowledge', [KnowledgeEventController::class, 'index'])->name('knowledge.index');
});

Route::middleware('permission:knowledge.events.create')->group(function (): void {
    Route::post('/knowledge', [KnowledgeEventController::class, 'store'])->name('knowledge.store');
});

Route::middleware('permission:knowledge.events.publish')->group(function (): void {
    Route::put('/knowledge/{event}', [KnowledgeEventController::class, 'update'])->name('knowledge.update');
    Route::post('/knowledge/{event}/transition', [KnowledgeEventController::class, 'transition'])
        ->name('knowledge.transition');
});
