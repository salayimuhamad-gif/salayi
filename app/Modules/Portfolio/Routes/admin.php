<?php

declare(strict_types=1);

/*
 * Valuation rule administration (Wave 6).
 *
 * Flag first, then permission — a disabled module should not report a
 * permission problem for a feature that is not running at all. View and
 * configure are separate doors: reading what governs owners' valuations is
 * an audit activity; changing it is a platform judgement.
 *
 * Owners have NO route here, and the public has none anywhere: rule content
 * reaches an owner only as the resolved question list on their own property
 * form.
 */

use App\Modules\Portfolio\Http\Controllers\Admin\ValuationRuleSetController;
use Illuminate\Support\Facades\Route;

Route::middleware(['feature:portfolio.valuation_rules', 'permission:portfolio.valuation_rules.view'])->group(function (): void {
    Route::get('/portfolio/valuation-rules', [ValuationRuleSetController::class, 'index'])
        ->name('portfolio.valuation-rules.index');
    Route::get('/portfolio/valuation-rules/{set}', [ValuationRuleSetController::class, 'show'])
        ->whereNumber('set')->name('portfolio.valuation-rules.show');
});

Route::middleware(['feature:portfolio.valuation_rules', 'permission:portfolio.valuation_rules.configure'])->group(function (): void {
    Route::post('/portfolio/valuation-rules', [ValuationRuleSetController::class, 'store'])
        ->name('portfolio.valuation-rules.store');
    Route::put('/portfolio/valuation-rules/{set}', [ValuationRuleSetController::class, 'update'])
        ->whereNumber('set')->name('portfolio.valuation-rules.update');
    Route::delete('/portfolio/valuation-rules/{set}', [ValuationRuleSetController::class, 'destroy'])
        ->whereNumber('set')->name('portfolio.valuation-rules.destroy');

    Route::post('/portfolio/valuation-rules/{set}/publish', [ValuationRuleSetController::class, 'publish'])
        ->whereNumber('set')->name('portfolio.valuation-rules.publish');
    Route::post('/portfolio/valuation-rules/{set}/retire', [ValuationRuleSetController::class, 'retire'])
        ->whereNumber('set')->name('portfolio.valuation-rules.retire');
    Route::post('/portfolio/valuation-rules/{set}/duplicate', [ValuationRuleSetController::class, 'duplicate'])
        ->whereNumber('set')->name('portfolio.valuation-rules.duplicate');

    // Preview computes; it deliberately has no way to persist anything —
    // a GET, because it reads and calculates and nothing else.
    Route::get('/portfolio/valuation-rules/{set}/preview', [ValuationRuleSetController::class, 'preview'])
        ->whereNumber('set')->name('portfolio.valuation-rules.preview');

    Route::post('/portfolio/valuation-rules/{set}/questions', [ValuationRuleSetController::class, 'storeQuestion'])
        ->whereNumber('set')->name('portfolio.valuation-rules.questions.store');
    Route::put('/portfolio/valuation-rules/{set}/questions/{question}', [ValuationRuleSetController::class, 'updateQuestion'])
        ->whereNumber('set')->whereNumber('question')->name('portfolio.valuation-rules.questions.update');
    Route::delete('/portfolio/valuation-rules/{set}/questions/{question}', [ValuationRuleSetController::class, 'destroyQuestion'])
        ->whereNumber('set')->whereNumber('question')->name('portfolio.valuation-rules.questions.destroy');

    Route::post('/portfolio/valuation-rules/{set}/questions/{question}/options', [ValuationRuleSetController::class, 'storeOption'])
        ->whereNumber('set')->whereNumber('question')->name('portfolio.valuation-rules.options.store');
    Route::put('/portfolio/valuation-rules/{set}/questions/{question}/options/{option}', [ValuationRuleSetController::class, 'updateOption'])
        ->whereNumber('set')->whereNumber('question')->whereNumber('option')
        ->name('portfolio.valuation-rules.options.update');
    Route::delete('/portfolio/valuation-rules/{set}/questions/{question}/options/{option}', [ValuationRuleSetController::class, 'destroyOption'])
        ->whereNumber('set')->whereNumber('question')->whereNumber('option')
        ->name('portfolio.valuation-rules.options.destroy');
});
