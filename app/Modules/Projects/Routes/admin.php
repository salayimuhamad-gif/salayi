<?php

declare(strict_types=1);

use App\Modules\Projects\Http\Controllers\Admin\DeveloperController;
use App\Modules\Projects\Http\Controllers\Admin\ProjectController;
use App\Modules\Projects\Http\Controllers\Admin\ProjectDraftAdminController;
use App\Modules\Projects\Http\Controllers\Admin\ProjectMediaController;
use App\Modules\Projects\Http\Controllers\Admin\ProjectWizardController;
use App\Modules\Projects\Http\Controllers\Admin\RatingController;
use App\Modules\Projects\Support\WizardStep;
use Illuminate\Support\Facades\Route;

/*
 * The acting-company switcher, available from ANY admin screen rather than
 * only from inside the Wizard. Gated on projects.view because every surface it
 * affects is.
 */
Route::middleware('permission:projects.view')->group(function (): void {
    Route::post('/acting-company', [ProjectWizardController::class, 'switchCompany'])
        ->name('acting-company.switch');

    /*
     * Administrator draft listing (spec 12.1, 26.1). Scoped like everything
     * else — a company administrator sees their own drafts, a platform
     * operator sees all.
     */
    Route::get('/project-drafts', [ProjectDraftAdminController::class, 'index'])
        ->name('project-drafts.index');
    Route::post('/project-drafts/{draft}/recover', [ProjectDraftAdminController::class, 'recover'])
        ->name('project-drafts.recover');
    Route::delete('/project-drafts/{draft}', [ProjectDraftAdminController::class, 'purge'])
        ->name('project-drafts.purge');

    Route::get('/projects', [ProjectController::class, 'index'])->name('projects.index');
});

/*
 * The explanatory page sits OUTSIDE both the permission group and the feature
 * flag. Inside them it was unreachable by exactly the people it exists for: a
 * user without projects.create_scoped was rejected by the middleware before
 * the permission-denied page could render.
 *
 * Still authenticated — it reveals nothing beyond which of two states applies.
 */
Route::get('/projects/wizard/unavailable', [ProjectWizardController::class, 'unavailable'])
    ->name('projects.wizard.unavailable');

/*
 * The ENTRY point sits outside both gates so it can explain them.
 *
 * Inside `permission:projects.create_scoped` the middleware returned a bare
 * 403 to exactly the people who needed to know why — and inside the feature
 * group, switching the flag off produced the same opaque refusal. start()
 * checks both itself and redirects to the explanatory page; every operational
 * route below stays protected, so nothing is actually reachable that was not
 * before.
 */
Route::get('/projects/wizard', [ProjectWizardController::class, 'start'])
    ->name('projects.wizard.start');

/*
 * SCOPED creation. A company account manager holds projects.create_scoped and
 * not projects.create_unscoped, so the Wizard is reachable for them while
 * platform-wide creation is not. Using the generic `projects.create` here made
 * the split permissions decorative: they existed in the registry and nothing
 * enforced them.
 */
/*
 * EITHER creation permission opens the Wizard.
 *
 * The group required projects.create_scoped alone, so a platform-only operator
 * holding projects.create_unscoped was refused every operational route — show,
 * save, media, nearby, touch, submit — even though creating projects is
 * precisely their job.
 *
 * This gate only establishes that the person may create SOMETHING. Which mode
 * they are in, and whether the draft in front of them matches it, is checked
 * per request in authoriseDraft() against the stored session context.
 */
Route::middleware('permission_any:projects.create_scoped,projects.create_unscoped')->group(function (): void {
    /*
     * The Project Creation Wizard (spec 12.1). It records a company
     * association; the legacy single form does not, which is why the two now
     * carry different permissions.
     *
     * `nearby` is registered before the {step} route so the literal segment
     * is not swallowed by the wildcard.
     */
    /*
     * The flag is enforced on every operational route. The ENTRY url is
     * deliberately outside it, so `start()` can render the feature-disabled
     * explanation rather than the middleware returning a bare 403 — a visitor
     * who followed a link cannot otherwise tell whether the feature is off,
     * they lack a permission, or something broke.
     */
    Route::middleware('feature:projects.wizard')->group(function (): void {
        /*
         * ORDER MATTERS, and it was wrong.
         *
         * `/{draft}/{step}` was registered BEFORE the literal endpoints, so
         * Laravel matched it first: POST /projects/wizard/5/submit dispatched to
         * save() with step="submit", and GET /projects/wizard/done/7 dispatched to
         * show() with draft="done". Every literal route below therefore comes
         * first, and the wildcards are constrained so they cannot swallow them
         * even if somebody reorders this file again.
         */

        // --- literal, no parameters

        Route::get('/projects/wizard/company', [ProjectWizardController::class, 'company'])
            ->name('projects.wizard.company');
        Route::post('/projects/wizard/company', [ProjectWizardController::class, 'chooseCompany'])
            ->name('projects.wizard.company.choose');
        Route::get('/projects/wizard/nearby', [ProjectWizardController::class, 'nearby'])
            ->name('projects.wizard.nearby');

        // --- literal prefix, then a parameter
        Route::get('/projects/wizard/done/{project}', [ProjectWizardController::class, 'done'])
            ->whereNumber('project')
            ->name('projects.wizard.done');

        // --- {draft} plus a literal action
        Route::post('/projects/wizard/{draft}/submit', [ProjectWizardController::class, 'submit'])
            ->whereNumber('draft')
            ->name('projects.wizard.submit');
        Route::post('/projects/wizard/{draft}/touch', [ProjectWizardController::class, 'touch'])
            ->whereNumber('draft')
            ->name('projects.wizard.touch');
        Route::get('/projects/wizard/{draft}/media/{media}/preview', [ProjectWizardController::class, 'previewMedia'])
            ->whereNumber(['draft', 'media'])
            ->name('projects.wizard.media.preview');
        Route::post('/projects/wizard/{draft}/media', [ProjectWizardController::class, 'uploadMedia'])
            ->whereNumber('draft')
            ->name('projects.wizard.media.upload');
        Route::patch('/projects/wizard/{draft}/media', [ProjectWizardController::class, 'updateMedia'])
            ->whereNumber('draft')
            ->name('projects.wizard.media.update');
        Route::delete('/projects/wizard/{draft}', [ProjectWizardController::class, 'destroy'])
            ->whereNumber('draft')
            ->name('projects.wizard.destroy');

        /*
         * --- wildcards LAST, and constrained.
         *
         * `{step}` accepts only the seven real step names, so a literal action
         * that gains a route later cannot be captured here by accident.
         */
        Route::get('/projects/wizard/{draft}/{step}', [ProjectWizardController::class, 'show'])
            ->whereNumber('draft')
            ->whereIn('step', WizardStep::all())
            ->name('projects.wizard.show');
        Route::post('/projects/wizard/{draft}/{step}', [ProjectWizardController::class, 'save'])
            ->whereNumber('draft')
            ->whereIn('step', WizardStep::all())
            ->name('projects.wizard.save');
    });

});

/*
 * PLATFORM-UNSCOPED creation, in its OWN group.
 *
 * It was nested inside the scoped group, so reaching it required BOTH
 * permissions — which only worked because platform roles happen to hold both.
 * A platform role granted unscoped-only would have been locked out of the
 * legacy form by a permission it had no reason to hold.
 *
 * ProjectController::store() writes a project with no company association, so
 * a company user reaching it would create a record belonging to nobody.
 */
Route::middleware('permission:projects.create_unscoped')->group(function (): void {
    Route::get('/projects/create', [ProjectController::class, 'create'])->name('projects.create');
    Route::post('/projects', [ProjectController::class, 'store'])->name('projects.store');
});

Route::middleware('permission:projects.update')->group(function (): void {
    Route::get('/projects/{project}/edit', [ProjectController::class, 'edit'])->name('projects.edit');
    Route::put('/projects/{project}', [ProjectController::class, 'update'])->name('projects.update');
    Route::post('/projects/{project}/transition', [ProjectController::class, 'transition'])->name('projects.transition');
    Route::post('/projects/{project}/nearby/recalculate', [ProjectController::class, 'recalculateNearby'])
        ->name('projects.nearby.recalculate');
});

Route::middleware('permission:projects.view')->group(function (): void {
    Route::get('/developers', [DeveloperController::class, 'index'])->name('developers.index');
});

/*
 * Developers are PLATFORM reference data shared by every company. A company
 * user editing one would rewrite a record other companies' projects point at.
 * `developers.manage` is a dedicated permission rather than a reuse of
 * projects.update, which CompanyAccountManager holds.
 */
Route::middleware('permission:developers.manage')->group(function (): void {
    Route::get('/developers/create', [DeveloperController::class, 'create'])->name('developers.create');
    Route::post('/developers', [DeveloperController::class, 'store'])->name('developers.store');
    Route::get('/developers/{developer}/edit', [DeveloperController::class, 'edit'])->name('developers.edit');
    Route::put('/developers/{developer}', [DeveloperController::class, 'update'])->name('developers.update');
});

Route::middleware('permission:projects.view')->group(function (): void {
    Route::get('/projects/{project}/ratings', [RatingController::class, 'index'])->name('projects.ratings.index');
});

Route::middleware('permission:projects.ratings.update')->group(function (): void {
    Route::post('/projects/{project}/ratings', [RatingController::class, 'store'])->name('projects.ratings.store');
});

Route::middleware('permission:projects.ratings.update')->group(function (): void {
    Route::post('/projects/{project}/ratings/{rating}/review', [RatingController::class, 'review'])
        ->name('projects.ratings.review');
});

Route::middleware('permission:projects.view')->group(function (): void {
    Route::get('/projects/{project}/media', [ProjectMediaController::class, 'index'])->name('projects.media.index');
});

Route::middleware('permission:projects.update')->group(function (): void {
    Route::post('/projects/{project}/media', [ProjectMediaController::class, 'store'])->name('projects.media.store');
    Route::put('/projects/{project}/media/{media}', [ProjectMediaController::class, 'update'])->name('projects.media.update');
    Route::delete('/projects/{project}/media/{media}', [ProjectMediaController::class, 'destroy'])->name('projects.media.destroy');
});
