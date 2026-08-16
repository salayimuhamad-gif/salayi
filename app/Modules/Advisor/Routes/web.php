<?php

declare(strict_types=1);

use App\Modules\Advisor\Http\Controllers\AdvisorController;
use App\Modules\Advisor\Http\Controllers\LifestyleController;
use Illuminate\Support\Facades\Route;

/*
 * Lifestyle search (File two §8).
 *
 * Behind the lifestyle.matching flag and enforced at the server boundary, per
 * repair prompt §3 — the flag has been enforceable since Phase 2 and this is
 * the first optional public surface built after it.
 *
 * Unauthenticated on purpose: a household may describe itself and see ranked
 * results before registering. The profile binds to their session and is claimed
 * on sign-up. Requiring an account first would put a long form in front of the
 * value it produces.
 */
/*
 * §7.11: a lifestyle profile holds a household's workplace and their children's
 * schools, so it needs a verified account. The RESULTS page is deliberately
 * outside this gate — ranked projects are the shop window, and requiring
 * verification to see them would put proof-of-phone in front of the value.
 */
Route::middleware(['feature:lifestyle.matching', 'telegram.linked'])->group(function (): void {
    Route::get('/lifestyle', [LifestyleController::class, 'edit'])->name('lifestyle.edit');
    Route::post('/lifestyle', [LifestyleController::class, 'update'])->name('lifestyle.update');
});

Route::middleware('feature:lifestyle.matching')->group(function (): void {
    Route::get('/lifestyle/results', [LifestyleController::class, 'results'])->name('lifestyle.results');
});

/*
 * The Sorani residential advisor (File one §6.3, File two §9).
 *
 * Behind advisor.residential and enforced at the server boundary. Every
 * mutating step is a POST so a crawler cannot advance somebody's interview by
 * following a link, and so each one is CSRF-protected.
 */
/*
 * §7.11 names "personal AI conversations" explicitly: a transcript records what
 * a household can afford and where they need to be.
 */
/*
 * Phase 14 rate limits: reply is the paid-AI vector (up to two completions
 * per call) and carries its own tight per-user limiter; the cheap mutations
 * share a looser one. The GET page and the no-op recommend redirect stay
 * unthrottled on purpose — neither spends money nor writes rows.
 */
Route::middleware(['feature:advisor.residential', 'telegram.linked'])->group(function (): void {
    Route::get('/advisor', [AdvisorController::class, 'show'])->name('advisor.show');
    Route::post('/advisor/reply', [AdvisorController::class, 'reply'])
        ->middleware('throttle:advisor-reply')
        ->name('advisor.reply');
    Route::post('/advisor/amend', [AdvisorController::class, 'amend'])
        ->middleware('throttle:advisor-write')
        ->name('advisor.amend');
    Route::post('/advisor/recommend', [AdvisorController::class, 'recommend'])->name('advisor.recommend');
    Route::post('/advisor/reset', [AdvisorController::class, 'reset'])
        ->middleware('throttle:advisor-write')
        ->name('advisor.reset');
    Route::post('/advisor/request', [AdvisorController::class, 'submit'])
        ->middleware('throttle:advisor-write')
        ->name('advisor.request');
});
