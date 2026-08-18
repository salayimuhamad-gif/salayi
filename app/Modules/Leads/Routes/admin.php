<?php

declare(strict_types=1);

use App\Modules\Leads\Http\Controllers\Admin\SalesWorkspaceController;
use Illuminate\Support\Facades\Route;

/*
 * The sales workspace (File one §9 Leads 2).
 *
 * `leads.view` gates reading; `leads.contact` is deliberately NOT used here,
 * because nothing in this controller exposes a contact detail. Revealing a
 * phone number is a separate audited act through PhoneRevealService, and
 * keeping the permissions separate is what stops "can see the pipeline" from
 * quietly becoming "can read everyone's number".
 */
Route::middleware('permission:leads.view')->group(function (): void {
    Route::get('/leads', [SalesWorkspaceController::class, 'index'])->name('leads.index');
    Route::get('/leads/{lead}', [SalesWorkspaceController::class, 'show'])
        ->whereNumber('lead')->name('leads.show');
    Route::post('/leads/{lead}/notes', [SalesWorkspaceController::class, 'addNote'])
        ->whereNumber('lead')->name('leads.notes.store');
    Route::post('/leads/{lead}/tasks', [SalesWorkspaceController::class, 'addTask'])
        ->whereNumber('lead')->name('leads.tasks.store');
    Route::post('/leads/{lead}/tasks/{task}/complete', [SalesWorkspaceController::class, 'completeTask'])
        ->whereNumber(['lead', 'task'])->name('leads.tasks.complete');
});

/*
 * Moving a lead through the pipeline is an assignment action, not a reading
 * one — a person who may look at the board should not be able to declare a
 * deal won.
 */
/*
 * The reveal is its own permission tier (spec §9): `leads.contact` releases a
 * person's number; `leads.view` never does. The service behind it demands a
 * reason, enforces the hourly rate and the consent gate, and audits without
 * the number — this route contributes the permission wall and nothing else.
 */
Route::middleware('permission:leads.contact')->group(function (): void {
    Route::post('/leads/{lead}/phone', [SalesWorkspaceController::class, 'revealPhone'])
        ->whereNumber('lead')->name('leads.phone.reveal');
});

Route::middleware('permission:leads.assign')->group(function (): void {
    Route::post('/leads/{lead}/stage', [SalesWorkspaceController::class, 'moveStage'])
        ->whereNumber('lead')->name('leads.stage');
});
