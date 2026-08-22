<?php

declare(strict_types=1);

namespace App\Modules\Portfolio\Http\Controllers\Admin;

use App\Modules\Core\ValueObjects\Decimal;
use App\Modules\Operations\Services\AuditLogger;
use App\Modules\Portfolio\Exceptions\ValuationRulePublishException;
use App\Modules\Portfolio\Models\PortfolioProperty;
use App\Modules\Portfolio\Models\ValuationQuestion;
use App\Modules\Portfolio\Models\ValuationQuestionOption;
use App\Modules\Portfolio\Models\ValuationRuleSet;
use App\Modules\Portfolio\Services\ValuationAdjustments;
use App\Modules\Portfolio\Services\ValuationRuleEditor;
use App\Modules\Portfolio\Services\ValuationRulePublisher;
use App\Modules\Projects\Models\Project;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

/**
 * The valuation rule builder (Wave 6).
 *
 * Everything here operates on DRAFTS; the two lifecycle actions (publish,
 * retire) are the only writes that touch a non-draft, and the models refuse
 * anything else structurally — this controller's pre-checks exist to turn
 * that refusal into a translated sentence instead of a 500.
 *
 * Every structural write goes through the ValuationRuleEditor: one
 * transaction that locks the parent rule-set row, re-checks the PERSISTED
 * status, and only then resolves and mutates the children — so an edit that
 * raced a publish refuses with the same translated sentence instead of
 * landing on the published set. The pre-checks below stay as the fast path;
 * the editor's locked re-check is the authority.
 *
 * The authoring bound (±25 per option) is validated at this boundary AND
 * re-proven at publish, because the boundary can be bypassed by nobody but
 * time: a bound loosened here next year must still fail a publish until the
 * publisher agrees.
 */
final class ValuationRuleSetController extends Controller
{
    public function __construct(
        private readonly AuditLogger $audit,
        private readonly ValuationRulePublisher $publisher,
        private readonly ValuationRuleEditor $editor,
    ) {}

    public function index(Request $request): Response
    {
        $sets = ValuationRuleSet::query()
            ->withCount('questions')
            ->with('project:id,name_ckb,name_ar,name_en')
            ->orderByRaw("case status when 'active' then 0 when 'draft' then 1 else 2 end")
            ->orderByDesc('version')
            ->orderByDesc('id')
            ->get()
            ->map(fn (ValuationRuleSet $set): array => $this->setSummary($set));

        return Inertia::render('Admin/ValuationRules/Index', [
            'sets' => $sets,
            'projects' => $this->projectOptions(),
            'property_types' => PortfolioProperty::PROPERTY_TYPES,
            'can' => ['manage' => $this->canManage($request)],
        ]);
    }

    public function show(Request $request, int $set): Response
    {
        $model = ValuationRuleSet::query()
            ->with('project:id,name_ckb,name_ar,name_en')
            ->findOrFail($set);

        return Inertia::render('Admin/ValuationRules/Edit', [
            'set' => array_merge($this->setSummary($model), [
                /*
                 * The builder sees EVERYTHING, inactive rows included — an
                 * admin hiding a question must still find it to un-hide it.
                 * Percentages appear here and only here: this is the
                 * authoring surface, behind its own permission.
                 */
                'questions' => $model->questions()
                    ->with('options')
                    ->get()
                    ->map(static fn (ValuationQuestion $question): array => [
                        'id' => (int) $question->id,
                        'key' => $question->key,
                        'question_type' => $question->question_type,
                        'label_ckb' => $question->label_ckb,
                        'label_ar' => $question->label_ar,
                        'label_en' => $question->label_en,
                        'help_ckb' => $question->help_ckb,
                        'help_ar' => $question->help_ar,
                        'help_en' => $question->help_en,
                        'sort_order' => (int) $question->sort_order,
                        'is_active' => (bool) $question->is_active,
                        'options' => $question->options
                            ->map(static fn (ValuationQuestionOption $option): array => [
                                'id' => (int) $option->id,
                                'key' => $option->key,
                                'label_ckb' => $option->label_ckb,
                                'label_ar' => $option->label_ar,
                                'label_en' => $option->label_en,
                                'adjustment_percent' => (string) $option->adjustment_percent,
                                'sort_order' => (int) $option->sort_order,
                                'is_active' => (bool) $option->is_active,
                            ])
                            ->values()
                            ->all(),
                    ])
                    ->values()
                    ->all(),
            ]),
            'projects' => $this->projectOptions(),
            'property_types' => PortfolioProperty::PROPERTY_TYPES,
            'max_percent' => ValuationRulePublisher::MAX_ABS_ADJUSTMENT_PERCENT,
            'warning_threshold' => ValuationAdjustments::LARGE_ADJUSTMENT_WARNING,
            'can' => ['manage' => $this->canManage($request)],
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $validated = $this->validateSet($request);

        $set = ValuationRuleSet::query()->create([
            'name' => $validated['name'],
            'scope_transaction' => ValuationRuleSet::SCOPE_TRANSACTION_SALE,
            'project_id' => $validated['project_id'] ?? null,
            'property_types' => $validated['property_types'] ?? null,
            'version' => $this->publisher->nextVersion(
                ValuationRuleSet::SCOPE_TRANSACTION_SALE,
                $validated['project_id'] ?? null,
            ),
            'status' => ValuationRuleSet::STATUS_DRAFT,
            'created_by' => $request->user()?->id,
        ]);

        $this->audit->record('portfolio.valuation_rules.set_created', $set, [], [
            'version' => $set->version,
            'project_id' => $set->project_id,
        ]);

        return redirect(route('admin.portfolio.valuation-rules.show', $set->id))
            ->with('success', __('portfolio.valuation_rules.saved'));
    }

    public function update(Request $request, int $set): RedirectResponse
    {
        $model = ValuationRuleSet::query()->findOrFail($set);

        if ($refusal = $this->refuseUnlessDraft($model)) {
            return $refusal;
        }

        $validated = $this->validateSet($request);

        try {
            $model = $this->editor->withLockedDraft($set, function (ValuationRuleSet $locked) use ($validated): ValuationRuleSet {
                $locked->name = $validated['name'];
                $locked->property_types = $validated['property_types'] ?? null;

                /*
                 * Moving a draft to another scope family moves it to that
                 * family's version sequence — keeping the old number could
                 * collide with a version that already exists over there.
                 * Computed HERE, inside the lock, from the locked row.
                 */
                $newProject = $validated['project_id'] ?? null;

                if ($newProject !== $locked->project_id) {
                    $locked->project_id = $newProject;
                    $locked->version = $this->publisher->nextVersion($locked->scope_transaction, $newProject);
                }

                $locked->save();

                return $locked;
            });
        } catch (ValuationRulePublishException) {
            return back()->withErrors([
                'lifecycle' => __('portfolio.valuation_rules.errors.frozen'),
            ]);
        }

        $this->audit->recordModelChange('portfolio.valuation_rules.set_updated', $model);

        return back()->with('success', __('portfolio.valuation_rules.saved'));
    }

    public function destroy(Request $request, int $set): RedirectResponse
    {
        $model = ValuationRuleSet::query()->findOrFail($set);

        if ($model->isActive()) {
            return back()->withErrors([
                'lifecycle' => __('portfolio.valuation_rules.errors.delete_active'),
            ]);
        }

        /*
         * Draft or retired: deletable. History is untouched by construction —
         * valuations snapshot their adjustments and never join back here.
         * The locked re-check refuses a set that ACTIVATED after the fast
         * pre-check above.
         */
        try {
            $model = $this->editor->deleteSetIfNotActive($set);
        } catch (ValuationRulePublishException) {
            return back()->withErrors([
                'lifecycle' => __('portfolio.valuation_rules.errors.delete_active'),
            ]);
        }

        $this->audit->record('portfolio.valuation_rules.set_deleted', $model, [], [
            'status' => $model->status,
            'version' => $model->version,
        ], severity: 'warning');

        return redirect(route('admin.portfolio.valuation-rules.index'))
            ->with('success', __('portfolio.valuation_rules.deleted'));
    }

    public function publish(Request $request, int $set): RedirectResponse
    {
        $model = ValuationRuleSet::query()->findOrFail($set);

        try {
            $model = $this->publisher->publish($model);
        } catch (ValuationRulePublishException $e) {
            $this->audit->record('portfolio.valuation_rules.set_publish_refused', $model, [], [
                'error' => $e->errorKey,
            ] + $e->context, result: 'failure', severity: 'warning');

            return back()->withErrors([
                'lifecycle' => __('portfolio.valuation_rules.errors.'.$e->errorKey),
            ]);
        }

        $this->audit->record('portfolio.valuation_rules.set_published', $model, [], [
            'version' => $model->version,
        ]);

        return back()->with('success', __('portfolio.valuation_rules.published'));
    }

    public function retire(Request $request, int $set): RedirectResponse
    {
        $model = ValuationRuleSet::query()->findOrFail($set);

        try {
            $model = $this->publisher->retire($model);
        } catch (ValuationRulePublishException $e) {
            return back()->withErrors([
                'lifecycle' => __('portfolio.valuation_rules.errors.'.$e->errorKey),
            ]);
        }

        $this->audit->record('portfolio.valuation_rules.set_retired', $model, [], [
            'version' => $model->version,
        ], severity: 'warning');

        return back()->with('success', __('portfolio.valuation_rules.retired'));
    }

    public function duplicate(Request $request, int $set): RedirectResponse
    {
        $source = ValuationRuleSet::query()->findOrFail($set);

        $draft = $this->publisher->duplicateAsDraft($source, $request->user()?->id);

        $this->audit->record('portfolio.valuation_rules.set_duplicated', $draft, [], [
            'source_id' => $source->id,
            'version' => $draft->version,
        ]);

        return redirect(route('admin.portfolio.valuation-rules.show', $draft->id))
            ->with('success', __('portfolio.valuation_rules.duplicated'));
    }

    /**
     * The publish-time arithmetic on a hypothetical base and a hypothetical
     * answer set — the SAME Decimal path a real valuation takes, with no
     * way to persist anything. This is how an admin sees what a draft will
     * do before an owner ever does.
     */
    public function preview(Request $request, int $set): JsonResponse
    {
        $model = ValuationRuleSet::query()->findOrFail($set);

        $validated = $request->validate([
            'base' => ['required', 'numeric', 'gt:0'],
            'answers' => ['sometimes', 'array'],
            'answers.*' => ['nullable', 'integer'],
        ]);

        $questions = $model->questions()
            ->where('is_active', true)
            ->with(['options' => static function ($query): void {
                $query->where('is_active', true);
            }])
            ->get()
            ->keyBy('id');

        $applied = [];
        $total = Decimal::zero(3);

        foreach (($validated['answers'] ?? []) as $questionId => $optionId) {
            if ($optionId === null) {
                continue;
            }

            $question = is_numeric((string) $questionId) ? $questions->get((int) $questionId) : null;
            $option = $question instanceof ValuationQuestion
                ? $question->options->firstWhere('id', (int) $optionId)
                : null;

            if (! $option instanceof ValuationQuestionOption) {
                return response()->json([
                    'error' => __('portfolio.valuation_questions.invalid'),
                ], 422);
            }

            $percent = Decimal::of((string) $option->adjustment_percent, 3);
            $total = $total->add($percent);

            $applied[] = [
                'question_key' => $question->key,
                'option_key' => $option->key,
                'adjustment_percent' => $percent->toString(),
            ];
        }

        $base = Decimal::of((string) $validated['base'], 4);
        $factor = Decimal::of('1', 6)->add(Decimal::of($total, 6)->divide('100'));
        $refused = $factor->compareTo('0') <= 0;

        return response()->json([
            'base' => $base->toString(),
            'applied' => $applied,
            'total_percent' => $total->toString(),
            'factor' => $factor->toString(),
            'refused' => $refused,
            'final' => $refused ? null : $base->multiply($factor)->toString(),
            'warned' => $total->abs()->compareTo(ValuationAdjustments::LARGE_ADJUSTMENT_WARNING) >= 0,
        ]);
    }

    public function storeQuestion(Request $request, int $set): RedirectResponse
    {
        $model = ValuationRuleSet::query()->findOrFail($set);

        if ($refusal = $this->refuseUnlessDraft($model)) {
            return $refusal;
        }

        $validated = $this->validateQuestion($request, $model);

        try {
            $question = $this->editor->withLockedDraft($set, static function (ValuationRuleSet $locked) use ($validated): ValuationQuestion {
                return $locked->questions()->create($validated + [
                    'question_type' => ValuationQuestion::TYPE_SINGLE_SELECT,
                ]);
            });
        } catch (ValuationRulePublishException) {
            return $this->frozenRefusal();
        }

        $this->audit->record('portfolio.valuation_rules.question_created', $question, [], [
            'set_id' => $model->id,
            'key' => $question->key,
        ]);

        return back()->with('success', __('portfolio.valuation_rules.saved'));
    }

    public function updateQuestion(Request $request, int $set, int $question): RedirectResponse
    {
        $model = ValuationRuleSet::query()->findOrFail($set);

        if ($refusal = $this->refuseUnlessDraft($model)) {
            return $refusal;
        }

        // Existence first (404 parity with the pre-editor behaviour), then
        // syntax validation; the row the WRITE uses is re-resolved through
        // the locked set inside the transaction.
        $model->questions()->findOrFail($question);

        $validated = $this->validateQuestion($request, $model, $question);

        try {
            $row = $this->editor->withLockedDraft($set, static function (ValuationRuleSet $locked) use ($question, $validated): ValuationQuestion {
                /** @var ValuationQuestion $fresh */
                $fresh = $locked->questions()->findOrFail($question);
                $fresh->fill($validated);
                $fresh->save();

                return $fresh;
            });
        } catch (ValuationRulePublishException) {
            return $this->frozenRefusal();
        }

        $this->audit->recordModelChange('portfolio.valuation_rules.question_updated', $row);

        return back()->with('success', __('portfolio.valuation_rules.saved'));
    }

    public function destroyQuestion(Request $request, int $set, int $question): RedirectResponse
    {
        $model = ValuationRuleSet::query()->findOrFail($set);

        if ($refusal = $this->refuseUnlessDraft($model)) {
            return $refusal;
        }

        $model->questions()->findOrFail($question);

        try {
            $row = $this->editor->withLockedDraft($set, static function (ValuationRuleSet $locked) use ($question): ValuationQuestion {
                /** @var ValuationQuestion $fresh */
                $fresh = $locked->questions()->findOrFail($question);
                $fresh->delete();

                return $fresh;
            });
        } catch (ValuationRulePublishException) {
            return $this->frozenRefusal();
        }

        $this->audit->record('portfolio.valuation_rules.question_deleted', $row, [], [
            'set_id' => $model->id,
            'key' => $row->key,
        ], severity: 'warning');

        return back()->with('success', __('portfolio.valuation_rules.deleted'));
    }

    public function storeOption(Request $request, int $set, int $question): RedirectResponse
    {
        $model = ValuationRuleSet::query()->findOrFail($set);

        if ($refusal = $this->refuseUnlessDraft($model)) {
            return $refusal;
        }

        /** @var ValuationQuestion $parent */
        $parent = $model->questions()->findOrFail($question);

        $validated = $this->validateOption($request, $parent);

        try {
            $option = $this->editor->withLockedDraft($set, static function (ValuationRuleSet $locked) use ($question, $validated): ValuationQuestionOption {
                /** @var ValuationQuestion $freshParent */
                $freshParent = $locked->questions()->findOrFail($question);

                return $freshParent->options()->create($validated);
            });
        } catch (ValuationRulePublishException) {
            return $this->frozenRefusal();
        }

        $this->audit->record('portfolio.valuation_rules.option_created', $option, [], [
            'set_id' => $model->id,
            'question_key' => $parent->key,
            'key' => $option->key,
            'adjustment_percent' => (string) $option->adjustment_percent,
        ]);

        return back()->with('success', __('portfolio.valuation_rules.saved'));
    }

    public function updateOption(Request $request, int $set, int $question, int $option): RedirectResponse
    {
        $model = ValuationRuleSet::query()->findOrFail($set);

        if ($refusal = $this->refuseUnlessDraft($model)) {
            return $refusal;
        }

        /** @var ValuationQuestion $parent */
        $parent = $model->questions()->findOrFail($question);
        $parent->options()->findOrFail($option);

        $validated = $this->validateOption($request, $parent, $option);

        try {
            $row = $this->editor->withLockedDraft($set, static function (ValuationRuleSet $locked) use ($question, $option, $validated): ValuationQuestionOption {
                /** @var ValuationQuestion $freshParent */
                $freshParent = $locked->questions()->findOrFail($question);
                /** @var ValuationQuestionOption $fresh */
                $fresh = $freshParent->options()->findOrFail($option);
                $fresh->fill($validated);
                $fresh->save();

                return $fresh;
            });
        } catch (ValuationRulePublishException) {
            return $this->frozenRefusal();
        }

        $this->audit->recordModelChange('portfolio.valuation_rules.option_updated', $row);

        return back()->with('success', __('portfolio.valuation_rules.saved'));
    }

    public function destroyOption(Request $request, int $set, int $question, int $option): RedirectResponse
    {
        $model = ValuationRuleSet::query()->findOrFail($set);

        if ($refusal = $this->refuseUnlessDraft($model)) {
            return $refusal;
        }

        /** @var ValuationQuestion $parent */
        $parent = $model->questions()->findOrFail($question);
        $parent->options()->findOrFail($option);

        try {
            $row = $this->editor->withLockedDraft($set, static function (ValuationRuleSet $locked) use ($question, $option): ValuationQuestionOption {
                /** @var ValuationQuestion $freshParent */
                $freshParent = $locked->questions()->findOrFail($question);
                /** @var ValuationQuestionOption $fresh */
                $fresh = $freshParent->options()->findOrFail($option);
                $fresh->delete();

                return $fresh;
            });
        } catch (ValuationRulePublishException) {
            return $this->frozenRefusal();
        }

        $this->audit->record('portfolio.valuation_rules.option_deleted', $row, [], [
            'set_id' => $model->id,
            'question_key' => $parent->key,
            'key' => $row->key,
        ], severity: 'warning');

        return back()->with('success', __('portfolio.valuation_rules.deleted'));
    }

    // ------------------------------------------------------------------

    private function canManage(Request $request): bool
    {
        return $request->user()?->hasPermission('portfolio.valuation_rules.configure') ?? false;
    }

    /** The translated refusal for any structural edit to a non-draft. */
    private function refuseUnlessDraft(ValuationRuleSet $set): ?RedirectResponse
    {
        if ($set->isDraft()) {
            return null;
        }

        return $this->frozenRefusal();
    }

    /**
     * The same sentence whether the set was already published when the
     * request arrived (fast pre-check) or published while the request was
     * in flight (the editor's locked re-check).
     */
    private function frozenRefusal(): RedirectResponse
    {
        return back()->withErrors([
            'lifecycle' => __('portfolio.valuation_rules.errors.frozen'),
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private function validateSet(Request $request): array
    {
        return $request->validate([
            'name' => ['required', 'string', 'max:120'],
            'project_id' => ['nullable', 'integer', Rule::exists('projects', 'id')],
            'property_types' => ['nullable', 'array'],
            'property_types.*' => ['string', Rule::in(PortfolioProperty::PROPERTY_TYPES)],
        ]);
    }

    /**
     * @return array<model-property<ValuationQuestion>, mixed>
     */
    private function validateQuestion(Request $request, ValuationRuleSet $set, ?int $ignoreId = null): array
    {
        return $request->validate([
            'key' => [
                'required', 'string', 'max:64', 'regex:/^[a-z0-9_]+$/',
                Rule::unique('valuation_questions', 'key')
                    ->where('valuation_rule_set_id', $set->id)
                    ->ignore($ignoreId),
            ],
            'label_ckb' => ['required', 'string', 'max:255'],
            'label_ar' => ['required', 'string', 'max:255'],
            'label_en' => ['required', 'string', 'max:255'],
            'help_ckb' => ['nullable', 'string', 'max:255'],
            'help_ar' => ['nullable', 'string', 'max:255'],
            'help_en' => ['nullable', 'string', 'max:255'],
            'sort_order' => ['sometimes', 'integer', 'between:0,65000'],
            'is_active' => ['sometimes', 'boolean'],
        ]);
    }

    /**
     * @return array<model-property<ValuationQuestionOption>, mixed>
     */
    private function validateOption(Request $request, ValuationQuestion $question, ?int $ignoreId = null): array
    {
        return $request->validate([
            'key' => [
                'required', 'string', 'max:64', 'regex:/^[a-z0-9_]+$/',
                Rule::unique('valuation_question_options', 'key')
                    ->where('valuation_question_id', $question->id)
                    ->ignore($ignoreId),
            ],
            'label_ckb' => ['required', 'string', 'max:255'],
            'label_ar' => ['required', 'string', 'max:255'],
            'label_en' => ['required', 'string', 'max:255'],
            /*
             * The authoring bound (±25). `decimal:0,3` pins the precision
             * the column stores, so nothing silently rounds later.
             */
            'adjustment_percent' => ['required', 'numeric', 'decimal:0,3', 'between:-25,25'],
            'sort_order' => ['sometimes', 'integer', 'between:0,65000'],
            'is_active' => ['sometimes', 'boolean'],
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private function setSummary(ValuationRuleSet $set): array
    {
        return [
            'id' => (int) $set->id,
            'name' => $set->name,
            'scope_transaction' => $set->scope_transaction,
            'project_id' => $set->project_id,
            'project' => $set->project === null ? null : [
                'id' => (int) $set->project->id,
                'name_ckb' => (string) $set->project->name_ckb,
                'name_ar' => $set->project->name_ar,
                'name_en' => $set->project->name_en,
            ],
            'property_types' => $set->property_types,
            'version' => (int) $set->version,
            'status' => $set->status,
            'published_at' => $set->published_at?->toDateString(),
            'retired_at' => $set->retired_at?->toDateString(),
            'question_count' => (int) ($set->questions_count ?? $set->questions()->count()),
            'created_at' => $set->created_at?->toDateString(),
        ];
    }

    /**
     * @return list<array{id: int, name_ckb: string, name_ar: string|null, name_en: string|null}>
     */
    private function projectOptions(): array
    {
        return Project::query()
            ->where('publication_status', 'published')
            ->orderBy('name_ckb')
            ->get(['id', 'name_ckb', 'name_ar', 'name_en'])
            ->map(static fn (Project $project): array => [
                'id' => (int) $project->id,
                'name_ckb' => (string) $project->name_ckb,
                'name_ar' => $project->name_ar,
                'name_en' => $project->name_en,
            ])
            ->values()
            ->all();
    }
}
