<?php

declare(strict_types=1);

namespace App\Modules\Projects\Http\Controllers\Admin;

use App\Modules\Notifications\Services\Notifier;
use App\Modules\Operations\Services\AuditLogger;
use App\Modules\Projects\Enums\RatingCategory;
use App\Modules\Projects\Enums\RatingType;
use App\Modules\Projects\Models\Project;
use App\Modules\Projects\Models\ProjectRating;
use App\Modules\Projects\Services\ProjectRatingService;
use App\Modules\Projects\Support\ProjectScope;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Rating entry and review (spec 13).
 */
final class RatingController extends Controller
{
    public function __construct(
        private readonly AuditLogger $audit,
        private readonly ProjectRatingService $ratings,
        private readonly Notifier $notifier,
    ) {}

    public function index(Request $request, Project $project): Response
    {
        ProjectScope::authorise($request, $project);

        return Inertia::render('Admin/Ratings/Index', [
            'project' => ['id' => $project->id, 'name' => $project->name()],
            'ratings' => ProjectRating::query()
                ->where('project_id', $project->id)
                ->orderBy('category')
                ->get()
                ->map(fn (ProjectRating $r): array => [
                    'id' => $r->id,
                    'category' => $r->category,
                    'type' => $r->type,
                    'value' => $r->value,
                    'sample_size' => $r->sample_size,
                    'reason' => $r->reason,
                    'source' => $r->source,
                    'review_status' => $r->review_status,
                    /*
                     * `type` is ALREADY a RatingType — the model casts it — and
                     * `(string) $enum` throws "Object of class RatingType could
                     * not be converted to string". Re-parsing an enum through
                     * its own string form was a fatal on every request that
                     * rendered this list.
                     */
                    'contributes_to_official' => $r->type->contributesToOfficialScore(),
                    'minimum_sample' => $r->type->minimumSampleSize(),
                ])->all(),
            // The same aggregation the public page will perform, shown to the
            // editor so they can see what a submission actually changes before
            // approving it.
            'preview' => $this->ratings->forProject($project, includeUnreviewed: false),
            'options' => [
                'categories' => array_map(static fn (RatingCategory $c): array => [
                    'value' => $c->value,
                    'label' => __('projects.rating_categories.'.$c->value),
                    'group' => $c->group(),
                    'inverted' => $c->isInverted(),
                ], RatingCategory::cases()),
                'types' => array_map(static fn (RatingType $t): array => [
                    'value' => $t->value,
                    'label' => __('projects.rating_types.'.$t->value),
                    'contributes' => $t->contributesToOfficialScore(),
                    'minimum_sample' => $t->minimumSampleSize(),
                    'ai_generated' => $t->isAiGenerated(),
                ], RatingType::cases()),
            ],
            'can' => ['review' => $request->user()?->hasPermission('projects.ratings.update') ?? false],
        ]);
    }

    public function store(Request $request, Project $project): RedirectResponse
    {
        ProjectScope::authorise($request, $project);

        /**
         * Declaring the key type makes the analyser check every validated key
         * against ProjectRating's real columns, so a rule added for a field the model
         * does not have fails here rather than silently never persisting.
         *
         * @var array<model-property<ProjectRating>, mixed> $validated
         */
        $validated = $request->validate([
            'category' => ['required', 'string', 'in:'.implode(',', array_map(
                static fn (RatingCategory $c): string => $c->value, RatingCategory::cases(),
            ))],
            'type' => ['required', 'string', 'in:'.implode(',', array_map(
                static fn (RatingType $t): string => $t->value, RatingType::cases(),
            ))],
            'value' => ['required', 'numeric', 'between:0,5'],
            'sample_size' => ['nullable', 'integer', 'min:1'],
            'reason' => ['nullable', 'string', 'max:2000'],
            'source' => ['nullable', 'string', 'max:191'],
        ]);

        $type = RatingType::from($validated['type']);

        // Spec 5 again: a rating that contributes to an official score is a
        // public fact, and a public fact needs a source. An expert judgement
        // with no stated basis is an opinion nobody can weigh.
        if ($type->contributesToOfficialScore() && trim((string) ($validated['source'] ?? '')) === '') {
            return back()->withErrors(['source' => __('projects.ratings.source_required')]);
        }

        $rating = ProjectRating::query()->create($validated + [
            'project_id' => $project->id,
            // Everything arrives unreviewed. Spec 13.4's protection against a
            // manufactured score only works if entry and approval are separate
            // acts by separate permissions.
            'review_status' => 'pending',
            'author_id' => $request->user()?->id,
            'effective_date' => now()->toDateString(),
        ]);

        $this->audit->record('project_rating.created', $rating, $validated);

        return back()->with('success', __('app.states.saved'));
    }

    public function review(Request $request, Project $project, int $rating): RedirectResponse
    {
        ProjectScope::authorise($request, $project);

        if (! ($request->user()?->hasPermission('projects.ratings.update') ?? false)) {
            abort(403, __('identity.errors.missing_permission'));
        }

        $validated = $request->validate([
            'review_status' => ['required', 'string', 'in:approved,rejected,pending'],
        ]);

        $model = ProjectRating::query()
            ->where('project_id', $project->id)
            ->findOrFail($rating);

        /*
         * The route carries `auth`, `mfa` and `permission:projects.ratings.update`,
         * so a reviewer is guaranteed here — an unauthenticated request never
         * reaches this method. Retrieving the user once states that contract
         * instead of repeating an optional access the middleware has ruled out.
         */
        $reviewer = $request->user();

        $model->forceFill([
            'review_status' => $validated['review_status'],
            'reviewed_by' => $reviewer->id,
        ])->save();

        $this->audit->record('project_rating.reviewed', $model, [], [
            'to' => $validated['review_status'],
        ], severity: 'warning');

        /*
         * The contributor is told the outcome (spec 13.4, 22.3).
         *
         * `moderation_outcome`, so it is not gated behind marketing consent —
         * a rating that was rejected is a decision about something the person
         * submitted, and review was previously invisible to them: they entered
         * a rating and never learned whether it counted.
         *
         * Skipped when the reviewer is the author, which happens routinely
         * when an editor approves their own entry. Sending someone a
         * notification about their own click is noise.
         */
        $authorId = $model->author_id === null ? null : (int) $model->author_id;

        if ($authorId !== null && $authorId !== $reviewer->id) {
            $this->notifier->send(
                event: 'rating_reviewed',
                recipient: $authorId,
                replacements: [
                    'project' => $project->name(),
                    // Its own key family, not app.states — that group has
                    // loading/empty/error and never had review vocabulary, so
                    // this would have rendered the raw key inside a message.
                    'status' => __('notifications.review_status.'.$validated['review_status']),
                ],
                consentPurpose: 'moderation_outcome',
                actionUrl: route('admin.projects.ratings.index', $project),
            );
        }

        return back()->with('success', __('app.states.saved'));
    }
}
