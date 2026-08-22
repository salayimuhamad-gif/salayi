<?php

declare(strict_types=1);

namespace App\Modules\Portfolio\Http\Controllers;

use App\Modules\Core\ValueObjects\Decimal;
use App\Modules\Geography\Models\Area;
use App\Modules\Operations\Services\AuditLogger;
use App\Modules\Portfolio\Models\PortfolioMedia;
use App\Modules\Portfolio\Models\PortfolioProperty;
use App\Modules\Portfolio\Models\PortfolioPropertyAnswer;
use App\Modules\Portfolio\Models\PortfolioValuation;
use App\Modules\Portfolio\Models\PortfolioValuationAdjustment;
use App\Modules\Portfolio\Models\ValuationQuestion;
use App\Modules\Portfolio\Models\ValuationQuestionOption;
use App\Modules\Portfolio\Services\PortfolioMediaService;
use App\Modules\Portfolio\Services\PortfolioSummaryService;
use App\Modules\Portfolio\Services\PortfolioValuer;
use App\Modules\Portfolio\Services\ValuationAdjustments;
use App\Modules\Portfolio\Services\ValuationRuleResolver;
use App\Modules\Projects\Models\Project;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

/**
 * A person's own property portfolio (File one §9 Portfolio).
 *
 * Every query starts from `$request->user()->id`. There is no route parameter
 * that identifies an owner, and no branch that reads one — the failure this
 * prevents is somebody incrementing an id and reading another household's
 * purchase price, address and valuation.
 *
 * §9.3 makes GPS optional AND private, which are two separate promises. Optional
 * is enforced at validation; private is enforced here, in what leaves the
 * server: coordinates are never returned to the browser once stored. The owner
 * pinned the location themselves, so echoing it back adds nothing they do not
 * already know while creating one more surface it can leak from.
 */
final class PortfolioController extends Controller
{
    public function __construct(
        private readonly AuditLogger $audit,
        private readonly ValuationAdjustments $adjustments,
        private readonly ValuationRuleResolver $resolver,
    ) {}

    public function index(Request $request, PortfolioSummaryService $summary): Response
    {
        /*
         * H9: paginated. `get()` was fine for a person with six properties
         * and a memory problem for an agency account with six hundred — and
         * every property here carries decrypted labels and valuations, so
         * the cost per row is real. Page size is fixed server-side; there
         * is no client-controlled per_page to inflate.
         */
        $properties = $this->ownedQuery($request)
            ->with(['latestValuation'])
            ->latest('id')
            ->paginate(24)
            ->withQueryString()
            ->through(fn (PortfolioProperty $property): array => $this->summarise($property));

        return Inertia::render('Account/Portfolio/Index', [
            /*
             * Wave 5 §6/§13: the derived dashboard band. Computed from the
             * same rows the cards below render — per-currency exact totals,
             * coverage and the latest valued date — so the band can never
             * disagree with the list.
             */
            'summary' => $summary->summarise($request->user()->id),
            'properties' => $properties,
            // The add/edit form's option lists: published entities only, the
            // three name columns so the form speaks the page's language.
            'projects' => Project::query()
                ->where('publication_status', 'published')
                ->orderBy('name_ckb')
                ->get(['id', 'name_ckb', 'name_ar', 'name_en']),
            'areas' => Area::query()
                ->where('publication_status', 'published')
                ->orderBy('name_ckb')
                ->get(['id', 'name_ckb', 'name_ar', 'name_en']),
        ]);
    }

    public function show(Request $request, int $property): Response
    {
        $model = $this->ownedQuery($request)->findOrFail($property);

        return Inertia::render('Account/Portfolio/Show', [
            'property' => $this->summarise($model),
            /*
             * §6.8: labels, kinds and sizes ONLY — the storage path never
             * leaves the server; the page reaches bytes through the
             * authorised streaming route and nothing else.
             */
            // v4 BLOCKER 8: only READY rows reach the page. A staging or
            // orphaned row listed here would render a download link that
            // cannot resolve.
            'media' => $model->readyMedia()
                ->orderByDesc('id')
                ->get()
                ->map(fn (PortfolioMedia $media): array => $media->toDisplayArray())
                ->values(),
            'projects' => Project::query()
                ->where('publication_status', 'published')
                ->orderBy('name_ckb')
                ->get(['id', 'name_ckb', 'name_ar', 'name_en']),
            'areas' => Area::query()
                ->where('publication_status', 'published')
                ->orderBy('name_ckb')
                ->get(['id', 'name_ckb', 'name_ar', 'name_en']),
            /*
             * §9.5: the history is append-only and is shown in full. A single
             * current figure invites "the platform says it is worth X"; the
             * series shows a valuation is an estimate that moves, which is the
             * honest reading and the one that survives a market correction.
             */
            /*
             * `portfolio_valuations` has no timestamps — it stamps
             * `calculated_at`, which is the meaningful moment for a valuation.
             * Ordering by `created_at` referenced a column that does not exist,
             * so this query failed outright rather than merely showing a blank
             * date.
             */
            'history' => $model->valuations()
                ->orderByDesc('calculated_at')
                ->limit(24)
                /*
                 * Wave 6: each row carries ITS OWN adjustment snapshots —
                 * keys, labels and percents as they read at calculation
                 * time. History never joins back to the live rule tables,
                 * so retiring or deleting a rule set changes nothing here.
                 */
                ->with('adjustments')
                ->get()
                ->map(fn (PortfolioValuation $valuation): array => [
                    'midpoint' => $valuation->midpoint === null ? null : (string) $valuation->midpoint,
                    'low' => $valuation->low === null ? null : (string) $valuation->low,
                    'high' => $valuation->high === null ? null : (string) $valuation->high,
                    'base_midpoint' => $valuation->base_midpoint === null ? null : (string) $valuation->base_midpoint,
                    'adjustment_total_percent' => $valuation->adjustment_total_percent === null
                        ? null
                        : (string) $valuation->adjustment_total_percent,
                    'adjustments' => $this->adjustmentRows($valuation),
                    'currency' => $valuation->currency,
                    'confidence' => $valuation->confidence,
                    'match_level' => $valuation->match_level,
                    'match_label' => $valuation->match_label,
                    'methodology' => $valuation->methodology,
                    'comparison_count' => $valuation->comparison_count,
                    'no_valuation_reason' => $valuation->no_valuation_reason,
                    'created_at' => $valuation->calculated_at->toDateString(),
                ])
                ->values()
                ->all(),
            /*
             * Wave 6: the question surface for the edit form — null with
             * the flag off, so the page cannot even hint at the feature.
             */
            'valuation_rules' => $this->valuationRulesProps($model),
            // The current estimate's breakdown, straight from its own
            // snapshot rows (flag-independent: history explains itself).
            'valuation_adjustments' => $model->latestValuation === null
                ? []
                : $this->adjustmentRows($model->latestValuation),
            'adjustment_warning_threshold' => ValuationAdjustments::LARGE_ADJUSTMENT_WARNING,
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $validated = $this->validateProperty($request);

        /*
         * Wave 6: answers are validated BEFORE the property exists, against
         * the scope the VALIDATED input describes, so a refused answer set
         * leaves nothing behind — not even the property row.
         */
        $answerChanges = $this->validateAnswers(
            $request,
            $validated['project_id'] ?? null,
            $validated['property_type'],
        );

        $hasPin = ($validated['latitude'] ?? null) !== null;

        $property = new PortfolioProperty;
        $property->fill([
            'user_id' => $request->user()->id,
            'property_type' => $validated['property_type'],
            'project_id' => $validated['project_id'] ?? null,
            'area_id' => $validated['area_id'] ?? null,
            'unit_type' => $validated['unit_type'] ?? null,
            'size_sqm' => $validated['size_sqm'] ?? null,
            'rooms' => $validated['rooms'] ?? null,
            'floor' => $validated['floor'] ?? null,
            'purchase_price' => $validated['purchase_price'] ?? null,
            'purchase_date' => $validated['purchase_date'] ?? null,
            'ownership_percent' => $validated['ownership_percent'] ?? null,
            'occupancy_status' => $validated['occupancy_status'] ?? null,
            'currency' => $validated['currency'],
            'latitude' => $hasPin ? $validated['latitude'] : null,
            'longitude' => $hasPin ? $validated['longitude'] : null,
            /*
             * H9: one precision vocabulary, saved consistently. With a pin,
             * the owner's declared precision (default exact); without one,
             * `area_only` — the area reference IS the location information.
             */
            'location_precision' => $hasPin
                ? ($validated['location_precision'] ?? PortfolioProperty::PRECISION_EXACT)
                : PortfolioProperty::PRECISION_AREA_ONLY,
            'consent_valuation' => $validated['consent_valuation'],
        ]);

        // Label and notes are encrypted at rest: a label is routinely the
        // street address, and notes routinely contain the tenant's name.
        $property->setLabel($validated['label']);
        $property->setNotes($validated['notes'] ?? null);
        $property->save();

        $this->applyAnswerChanges($property, $answerChanges);

        $this->audit->record('portfolio.property_created', $property);

        return redirect(localized_route('portfolio.index'))->with('success', __('portfolio.saved'));
    }

    /**
     * Edit (spec §11), with the same shape and the same encryption as store —
     * ownership stays structural: the row is fetched through ownedQuery(), so
     * there is no way to reach another person's property to edit.
     */
    public function update(Request $request, int $property): RedirectResponse
    {
        $model = $this->ownedQuery($request)->findOrFail($property);

        $validated = $this->validateProperty($request, updating: true);

        /*
         * Wave 6: validated against the scope this SAVE will produce — an
         * edit that moves the property to another project or type is judged
         * by the destination's questions, not the ones it is leaving.
         */
        $answerChanges = $this->validateAnswers(
            $request,
            $validated['project_id'] ?? null,
            $validated['property_type'],
        );

        /*
         * H9 location semantics on edit. §9.3 keeps stored coordinates
         * server-side, so the form cannot echo them back — absence of
         * coordinates in the request therefore means "keep what is saved",
         * and each change is an explicit act:
         *
         *   remove_location        → pin gone, precision back to area_only;
         *   a new coordinate pair  → replaces the pin (precision as declared);
         *   precision alone, while → re-declares how exact the EXISTING pin
         *   a pin exists             is, without retyping numbers the owner
         *                            cannot see.
         */
        if ($validated['remove_location'] ?? false) {
            $model->latitude = null;
            $model->longitude = null;
            $model->location_precision = PortfolioProperty::PRECISION_AREA_ONLY;
        } elseif (($validated['latitude'] ?? null) !== null) {
            $model->latitude = $validated['latitude'];
            $model->longitude = $validated['longitude'];
            $model->location_precision = $validated['location_precision'] ?? PortfolioProperty::PRECISION_EXACT;
        } elseif ($model->latitude !== null && ($validated['location_precision'] ?? null) !== null) {
            $model->location_precision = $validated['location_precision'];
        }

        $model->fill([
            'property_type' => $validated['property_type'],
            'project_id' => $validated['project_id'] ?? null,
            'area_id' => $validated['area_id'] ?? null,
            'unit_type' => $validated['unit_type'] ?? null,
            'size_sqm' => $validated['size_sqm'] ?? null,
            'rooms' => $validated['rooms'] ?? null,
            'floor' => $validated['floor'] ?? null,
            'purchase_price' => $validated['purchase_price'] ?? null,
            'purchase_date' => $validated['purchase_date'] ?? null,
            'ownership_percent' => $validated['ownership_percent'] ?? null,
            'occupancy_status' => $validated['occupancy_status'] ?? null,
            'currency' => $validated['currency'],
            'consent_valuation' => $validated['consent_valuation'],
        ]);

        $model->setLabel($validated['label']);
        $model->setNotes($validated['notes'] ?? null);
        $model->save();

        $this->applyAnswerChanges($model, $answerChanges);

        $this->audit->record('portfolio.property_updated', $model);

        return redirect(localized_route('portfolio.show', [$model->id]))->with('success', __('portfolio.saved'));
    }

    /**
     * The valuation trigger (spec §11).
     *
     * Consent first: estimating the worth of somebody's home without being
     * asked is not a neutral act, and the row records whether they asked.
     * The result is appended to the history WHETHER OR NOT it is a number —
     * an honest "insufficient evidence" is a valuation event too, and
     * showing it beats pretending the button did nothing.
     */
    public function requestValuation(Request $request, int $property, PortfolioValuer $valuer): RedirectResponse
    {
        $model = $this->ownedQuery($request)->findOrFail($property);

        if (! $model->consent_valuation) {
            return back()->withErrors(['valuation' => __('portfolio.valuation_actions.consent_required')]);
        }

        /*
         * Wave 6: answers submitted WITH the request are persisted first,
         * so "submitted beats persisted" is literal — by the time the
         * valuer reads the answer rows, the submission is the answer rows.
         */
        $this->applyAnswerChanges(
            $model,
            $this->validateAnswers($request, $model->project_id, $model->property_type),
        );

        $valuation = $valuer->value($model);

        /*
         * Audit carries COUNTS and the total, never labels or answer
         * content — enough to explain "why did the figure move" without
         * copying an owner's property details into the log.
         */
        $total = $valuation->adjustment_total_percent;

        $this->audit->record('portfolio.valuation_requested', $model, [], [
            'valuation_id' => $valuation->id,
            'outcome' => $valuation->no_valuation_reason ?? 'valued',
            'answered_count' => $valuation->adjustments()->count(),
            'total_percent' => $total === null ? null : (string) $total,
            'warned' => $total !== null && Decimal::of((string) $total, 3)
                ->abs()
                ->compareTo(ValuationAdjustments::LARGE_ADJUSTMENT_WARNING) >= 0,
        ]);

        return back()->with(
            'success',
            $valuation->no_valuation_reason === null
                ? __('portfolio.valuation_actions.completed')
                : __('portfolio.valuation_actions.insufficient'),
        );
    }

    /**
     * §9.7 export.
     *
     * The owner's own data, in full, including the decrypted label and notes —
     * they wrote them. The valuation history is included because it is the part
     * they cannot reconstruct from memory, and an export that omitted it would
     * be a worse record than the screen it was exported from.
     */
    public function export(Request $request): JsonResponse
    {
        $properties = $this->ownedQuery($request)
            ->with([
                'valuations' => static fn ($query) => $query->orderByDesc('calculated_at'),
                /*
                 * v4 BLOCKER 8: the export must describe what the person
                 * actually HAS. An in-flight or orphaned row is not a
                 * document they hold, and putting it in an export is how a
                 * spreadsheet ends up disagreeing with the portfolio page.
                 */
                'media' => static fn ($query) => $query
                    ->where('status', PortfolioMedia::STATUS_READY)
                    ->whereNull('deletion_pending_at')
                    ->orderByDesc('id'),
                'project:id,name_ckb,name_ar,name_en',
                'area:id,name_ckb,name_ar,name_en',
            ])
            ->get();

        $this->audit->record('portfolio.exported', null, [], [
            'actor_id' => $request->user()->id,
            'property_count' => $properties->count(),
        ]);

        return response()->json([
            'exported_at' => now()->toIso8601String(),
            'properties' => $properties
                ->map(fn (PortfolioProperty $p): array => $this->exportRecord($p))
                ->values(),
        ])->header('Content-Disposition', 'attachment; filename="portfolio.json"');
    }

    /**
     * §9.7 deletion.
     *
     * A real delete, not a flag. Somebody removing their home from a platform
     * means it, and a soft-deleted row that still holds an encrypted address
     * has not honoured the request — it has only hidden it from the screen.
     */
    public function destroy(Request $request, int $property): RedirectResponse
    {
        $model = $this->ownedQuery($request)->findOrFail($property);

        /*
         * §6.8, corrected in H8: files first, and the cleanup may now ABORT
         * (a stuck file throws rather than letting the cascade orphan its
         * bytes) — so the deletion audit moves AFTER the point of no return.
         * An aborted attempt leaves the property standing and logs nothing
         * that did not happen.
         */
        app(PortfolioMediaService::class)->deleteAllFor($model);

        $model->valuations()->delete();
        $model->forceDelete();

        $this->audit->record('portfolio.property_deleted', $model, [], [
            'actor_id' => $request->user()->id,
        ], severity: 'warning');

        return redirect(localized_route('portfolio.index'))->with('success', __('portfolio.deleted'));
    }

    /**
     * The only query root in this controller.
     *
     * Written once so no action can forget it. `findOrFail` on this scope
     * returns 404 for another owner's id rather than 403 — a stranger should
     * not learn that a property with that id exists.
     *
     * @return Builder<PortfolioProperty>
     */
    private function ownedQuery(Request $request): Builder
    {
        return PortfolioProperty::query()->where('user_id', $request->user()->id);
    }

    /**
     * The one validation surface for store and update (H9).
     *
     * Everything the review flagged is closed here, in one place:
     * the currency is normalised (trimmed, uppercased) BEFORE the rule so
     * "usd" becomes USD rather than a refusal, and then allowlisted — three
     * characters of anything is not a currency; property and unit types
     * come from the model's vocabularies; project and area must be rows a
     * person could actually see — `exists` alone would let an id-guesser
     * attach their home to an unpublished draft; and a coordinate is only
     * accepted as a pair, because half a coordinate is not an imprecise
     * location, it is no location wearing the costume of one.
     *
     * @return array<string, mixed>
     */
    private function validateProperty(Request $request, bool $updating = false): array
    {
        $currency = $request->input('currency');

        if (is_string($currency)) {
            $request->merge(['currency' => strtoupper(trim($currency))]);
        }

        $rules = [
            'label' => ['required', 'string', 'max:120'],
            'property_type' => ['required', 'string', Rule::in(PortfolioProperty::PROPERTY_TYPES)],
            'project_id' => [
                'nullable', 'integer',
                Rule::exists('projects', 'id')->where('publication_status', 'published'),
            ],
            'area_id' => [
                'nullable', 'integer',
                Rule::exists('areas', 'id')->where('publication_status', 'published'),
            ],
            'unit_type' => ['nullable', 'string', Rule::in(PortfolioProperty::UNIT_TYPES)],
            'size_sqm' => ['nullable', 'numeric', 'min:1', 'max:100000'],
            'rooms' => ['nullable', 'integer', 'between:0,50'],
            'floor' => ['nullable', 'integer', 'between:-5,200'],
            'purchase_price' => ['nullable', 'numeric', 'min:0'],
            'purchase_date' => ['nullable', 'date', 'before_or_equal:today'],
            'ownership_percent' => ['nullable', 'numeric', 'between:0.01,100'],
            'occupancy_status' => ['nullable', 'in:owner_occupied,rented,vacant'],
            'currency' => ['required', 'string', Rule::in(PortfolioProperty::CURRENCIES)],
            // §9.3: optional — a portfolio entry with no coordinate is a
            // complete entry. But optional TOGETHER: each half requires
            // the other.
            'latitude' => ['nullable', 'required_with:longitude', 'numeric', 'between:-90,90'],
            'longitude' => ['nullable', 'required_with:latitude', 'numeric', 'between:-180,180'],
            'location_precision' => ['nullable', 'string', Rule::in(PortfolioProperty::PIN_PRECISIONS)],
            // Valuation is opt-in: estimating the worth of somebody's home
            // without being asked is not a neutral act.
            'consent_valuation' => ['required', 'boolean'],
            'notes' => ['nullable', 'string', 'max:2000'],
        ];

        if ($updating) {
            $rules['remove_location'] = ['sometimes', 'boolean'];
        }

        return $request->validate($rules);
    }

    /**
     * One property, complete, for the owner's export (H9).
     *
     * Null stays null: the old export cast every valuation figure through
     * (string), which turned an honest "no number" into "" — a different
     * claim. A refused valuation exports its nulls and its reason, exactly
     * as the screen showed them.
     *
     * @return array<string, mixed>
     */
    private function exportRecord(PortfolioProperty $p): array
    {
        return [
            'label' => $p->label(),
            'property_type' => $p->property_type,
            'unit_type' => $p->unit_type,
            'size_sqm' => $this->stringOrNull($p->size_sqm),
            'rooms' => $p->rooms,
            'floor' => $p->floor,
            'purchase_price' => $this->stringOrNull($p->purchase_price),
            'purchase_date' => $p->purchase_date?->toDateString(),
            'ownership_percent' => $this->stringOrNull($p->ownership_percent),
            'occupancy_status' => $p->occupancy_status,
            'currency' => $p->currency,
            'consent_valuation' => (bool) $p->consent_valuation,
            // The published names the owner picked from — safe labels,
            // not internal references.
            'project' => $this->referenceLabel($p->project),
            'area' => $this->referenceLabel($p->area),
            /*
             * §9.3: raw coordinates never leave the server, and the export
             * is no exception — the owner typed them and the UI has never
             * shown them since, so exporting them would make the export
             * file the one place the pin exists in plain text. The export
             * records THAT a location is saved and how precise it is.
             */
            'has_location' => $p->latitude !== null,
            'location_precision' => $p->location_precision,
            'notes' => $p->notes(),
            'created_at' => $p->created_at?->toIso8601String(),
            'valuations' => $p->valuations->map(fn (PortfolioValuation $v): array => [
                'midpoint' => $this->stringOrNull($v->midpoint),
                'low' => $this->stringOrNull($v->low),
                'high' => $this->stringOrNull($v->high),
                'currency' => $v->currency,
                'confidence' => $v->confidence,
                'match_level' => $v->match_level,
                'match_label' => $v->match_label,
                'methodology' => $v->methodology,
                'comparison_count' => $v->comparison_count,
                'excluded_asking_count' => $v->excluded_asking_count,
                'no_valuation_reason' => $v->no_valuation_reason,
                'period_from' => $v->data_period_from?->toDateString(),
                'period_to' => $v->data_period_to?->toDateString(),
                'calculated_at' => $v->calculated_at->toIso8601String(),
            ])->values()->all(),
            /*
             * §6.8 again: labels, kinds, sizes — the same display array the
             * page uses, which by construction has no storage path.
             */
            'media_count' => $p->media->count(),
            'media' => $p->media
                ->map(static fn (PortfolioMedia $m): array => $m->toDisplayArray())
                ->values()
                ->all(),
        ];
    }

    private function stringOrNull(mixed $value): ?string
    {
        return $value === null ? null : (string) $value;
    }

    /**
     * @return array{id: int, name_ckb: string, name_ar: string|null, name_en: string|null}|null
     */
    private function referenceLabel(Project|Area|null $model): ?array
    {
        return $model === null ? null : [
            'id' => (int) $model->id,
            'name_ckb' => (string) $model->name_ckb,
            'name_ar' => $model->name_ar,
            'name_en' => $model->name_en,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function summarise(PortfolioProperty $property): array
    {
        $valuation = $property->latestValuation;

        return [
            'id' => $property->id,
            'label' => $property->label(),
            'property_type' => $property->property_type,
            'size_sqm' => $property->size_sqm,
            'currency' => $property->currency,
            'unit_type' => $property->unit_type,
            'rooms' => $property->rooms,
            'floor' => $property->floor,
            'project_id' => $property->project_id,
            'area_id' => $property->area_id,
            'purchase_price' => $property->purchase_price === null ? null : (string) $property->purchase_price,
            'purchase_date' => $property->purchase_date?->toDateString(),
            'ownership_percent' => $property->ownership_percent === null ? null : (string) $property->ownership_percent,
            'occupancy_status' => $property->occupancy_status,
            'consent_valuation' => (bool) $property->consent_valuation,
            /*
             * §9.3: coordinates are deliberately absent. The owner set the pin;
             * returning it teaches them nothing and gives it one more place to
             * leak from. `has_location` plus the declared precision is all
             * the interface needs — the edit form must know whether a pin
             * exists and how exact the owner called it, without the numbers.
             */
            'has_location' => $property->latitude !== null,
            'location_precision' => $property->location_precision,

            /*
             * §9.4 requires all of these together. A midpoint on its own is the
             * number people quote and the one they should trust least — the
             * range, the confidence and the match level are what make it
             * defensible, and the excluded-asking-price note is what stops a
             * reader assuming listing prices were counted.
             */
            'valuation' => $valuation === null ? null : [
                'midpoint' => $valuation->midpoint === null ? null : (string) $valuation->midpoint,
                'low' => $valuation->low === null ? null : (string) $valuation->low,
                'high' => $valuation->high === null ? null : (string) $valuation->high,
                /*
                 * Wave 6: the pre-adjustment engine figures and the exact
                 * total, null on every row the rule engine never touched —
                 * absence is the honest statement about how an old row was
                 * computed.
                 */
                'base_midpoint' => $valuation->base_midpoint === null ? null : (string) $valuation->base_midpoint,
                'base_low' => $valuation->base_low === null ? null : (string) $valuation->base_low,
                'base_high' => $valuation->base_high === null ? null : (string) $valuation->base_high,
                'adjustment_total_percent' => $valuation->adjustment_total_percent === null
                    ? null
                    : (string) $valuation->adjustment_total_percent,
                'confidence' => $valuation->confidence,
                'no_valuation_reason' => $valuation->no_valuation_reason,
                'calculated_at' => $valuation->calculated_at->toDateString(),
                'match_level' => $valuation->match_level,
                'match_label' => $valuation->match_label,
                'period_from' => $valuation->data_period_from?->toDateString(),
                'period_to' => $valuation->data_period_to?->toDateString(),
                'methodology' => $valuation->methodology,
                'comparison_count' => $valuation->comparison_count,
                /*
                 * §9.4's "excluded asking-price note", as a COUNT rather than a
                 * boolean. "Twelve asking prices were excluded" tells a reader
                 * that listing prices exist and were deliberately not counted;
                 * "asking prices excluded: yes" reads as boilerplate.
                 */
                'excluded_asking_count' => $valuation->excluded_asking_count,
                'created_at' => $valuation->calculated_at->toDateString(),
            ],
        ];
    }

    /**
     * One valuation's adjustment snapshots, exactly as stored — trilingual
     * labels and the applied percent, never a join to the live rule tables.
     *
     * @return list<array<string, string|int>>
     */
    private function adjustmentRows(PortfolioValuation $valuation): array
    {
        return $valuation->adjustments
            ->map(static fn (PortfolioValuationAdjustment $row): array => [
                'question_key' => $row->question_key,
                'question_ckb' => $row->question_ckb,
                'question_ar' => $row->question_ar,
                'question_en' => $row->question_en,
                'option_ckb' => $row->option_ckb,
                'option_ar' => $row->option_ar,
                'option_en' => $row->option_en,
                'adjustment_percent' => (string) $row->adjustment_percent,
                'position' => (int) $row->position,
            ])
            ->values()
            ->all();
    }

    /**
     * The Wave 6 question surface for the edit form.
     *
     * Null while the flag is off — the page then has nothing to render or
     * hint at. With the flag on: the resolved active set's active questions
     * WITHOUT their percentages (the browser shows choices, the server owns
     * the numbers), the owner's valid persisted answers for rehydration,
     * and a count of stale answers so the form can say why an old choice
     * is no longer preselected.
     *
     * @return array{
     *     questions: list<array<string, mixed>>,
     *     answers: array<int, int>,
     *     stale: int,
     * }|null
     */
    private function valuationRulesProps(PortfolioProperty $model): ?array
    {
        if (! feature(ValuationAdjustments::FLAG)) {
            return null;
        }

        $plan = $this->adjustments->planFor($model);

        $questions = [];

        if ($plan['rule_set_id'] !== null) {
            $questions = ValuationQuestion::query()
                ->where('valuation_rule_set_id', $plan['rule_set_id'])
                ->where('is_active', true)
                ->orderBy('sort_order')
                ->orderBy('id')
                ->with(['options' => static function ($query): void {
                    $query->where('is_active', true);
                }])
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
                    'options' => $question->options
                        ->map(static fn (ValuationQuestionOption $option): array => [
                            'id' => (int) $option->id,
                            'key' => $option->key,
                            'label_ckb' => $option->label_ckb,
                            'label_ar' => $option->label_ar,
                            'label_en' => $option->label_en,
                        ])
                        ->values()
                        ->all(),
                ])
                ->values()
                ->all();
        }

        $answers = [];

        foreach ($plan['applied'] as $row) {
            $answers[$row['question_id']] = $row['option_id'];
        }

        return [
            'questions' => $questions,
            'answers' => $answers,
            'stale' => count($plan['stale']),
        ];
    }

    /**
     * Validate a submitted `answers` payload against the server's own
     * authority chain, refusing the WHOLE submission on the first break.
     *
     * The chain, per answer: the question must be an ACTIVE question of the
     * ACTIVE set that applies to the given scope, and the option must be an
     * ACTIVE option of THAT question. Anything else — a tampered id, a
     * cross-set question, a retired option — fails validation, and because
     * this runs before any write, a refusal persists NOTHING. The payload
     * carries ids only; a percentage has no field to arrive in.
     *
     * @return array{writes: array<int, int>, clears: list<int>}
     */
    private function validateAnswers(Request $request, ?int $projectId, string $propertyType): array
    {
        $none = ['writes' => [], 'clears' => []];

        // Flag off: the form never offered questions, so a stray payload
        // is ignored rather than judged — baseline behaviour, byte for byte.
        if (! feature(ValuationAdjustments::FLAG) || ! $request->has('answers')) {
            return $none;
        }

        $raw = $request->input('answers');

        if (! is_array($raw)) {
            throw ValidationException::withMessages([
                'answers' => __('portfolio.valuation_questions.invalid'),
            ]);
        }

        if ($raw === []) {
            return $none;
        }

        $set = $this->resolver->activeSetFor($projectId, $propertyType);

        $questions = $set === null
            ? collect()
            : ValuationQuestion::query()
                ->where('valuation_rule_set_id', $set->id)
                ->where('is_active', true)
                ->with(['options' => static function ($query): void {
                    $query->where('is_active', true);
                }])
                ->get()
                ->keyBy('id');

        $writes = [];
        $clears = [];

        foreach ($raw as $questionId => $optionId) {
            $question = is_numeric((string) $questionId) ? $questions->get((int) $questionId) : null;

            if (! $question instanceof ValuationQuestion) {
                throw ValidationException::withMessages([
                    'answers' => __('portfolio.valuation_questions.invalid'),
                ]);
            }

            // Null (or blank) clears the stored answer: "no answer" is a
            // legitimate state and the form never invents a default.
            if ($optionId === null || $optionId === '') {
                $clears[] = (int) $question->id;

                continue;
            }

            $option = is_numeric($optionId)
                ? $question->options->firstWhere('id', (int) $optionId)
                : null;

            if (! $option instanceof ValuationQuestionOption) {
                throw ValidationException::withMessages([
                    'answers' => __('portfolio.valuation_questions.invalid'),
                ]);
            }

            $writes[(int) $question->id] = (int) $option->id;
        }

        return ['writes' => $writes, 'clears' => $clears];
    }

    /**
     * Persist a VALIDATED answer change set: one row per question per
     * property, replaced on change, removed on clear — all or nothing.
     *
     * @param  array{writes: array<int, int>, clears: list<int>}  $changes
     */
    private function applyAnswerChanges(PortfolioProperty $property, array $changes): void
    {
        if ($changes['writes'] === [] && $changes['clears'] === []) {
            return;
        }

        DB::transaction(static function () use ($property, $changes): void {
            foreach ($changes['writes'] as $questionId => $optionId) {
                PortfolioPropertyAnswer::query()->updateOrCreate(
                    [
                        'portfolio_property_id' => $property->id,
                        'valuation_question_id' => $questionId,
                    ],
                    ['valuation_question_option_id' => $optionId],
                );
            }

            if ($changes['clears'] !== []) {
                PortfolioPropertyAnswer::query()
                    ->where('portfolio_property_id', $property->id)
                    ->whereIn('valuation_question_id', $changes['clears'])
                    ->delete();
            }
        });
    }
}
