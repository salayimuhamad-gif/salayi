<?php

declare(strict_types=1);

namespace App\Modules\Geography\Http\Controllers\Admin;

use App\Modules\Geography\Http\Requests\PlaceRequest;
use App\Modules\Geography\Models\Area;
use App\Modules\Geography\Models\Place;
use App\Modules\Geography\Models\PlaceCategory;
use App\Modules\Geography\Services\NearbyPlaceCalculator;
use App\Modules\Operations\Services\AuditLogger;
use App\Modules\Projects\Enums\PublicationStatus;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Place administration (spec 11).
 */
final class PlaceController extends Controller
{
    public function __construct(
        private readonly AuditLogger $audit,
        private readonly NearbyPlaceCalculator $nearby,
    ) {}

    public function index(Request $request): Response
    {
        /*
         * The review filters (Map Phase 2). An OSM import can add hundreds of
         * draft rows at once; source + status + verification + area is the
         * minimum slice that turns "12,000 places" back into a reviewable
         * queue. Values are validated by whitelisting, not trusted.
         */
        $status = $request->string('status')->toString();
        $verification = $request->string('verification')->toString();
        $source = $request->string('source')->toString();

        $places = Place::query()
            ->when($request->string('q')->toString() !== '', fn ($q) => $q->where(
                'search_key', 'like', '%'.sorani_search_key($request->string('q')->toString()).'%',
            ))
            ->when($request->integer('category') > 0, fn ($q) => $q->where('place_category_id', $request->integer('category')))
            ->when(
                PublicationStatus::tryFrom($status) !== null,
                fn ($q) => $q->where('publication_status', $status),
            )
            ->when(
                in_array($verification, ['unverified', 'verified', 'rejected'], true),
                fn ($q) => $q->where('verification_status', $verification),
            )
            ->when($source !== '', fn ($q) => $q->where('source', $source))
            ->when($request->integer('area') > 0, fn ($q) => $q->where('area_id', $request->integer('area')))
            ->with(['category:id,key,name_ckb,name_ar,name_en', 'area:id,name_ckb,name_ar,name_en'])
            ->orderByDesc('updated_at')
            ->paginate(25)
            ->withQueryString()
            ->through(fn (Place $place): array => [
                'id' => $place->id,
                'name' => $place->name(),
                'category' => $place->category?->name(),
                'area' => $place->area?->name(),
                'status' => $place->publication_status->value,
                'operational_status' => $place->operational_status,
                'source' => $place->source,
                'verification_status' => $place->verification_status,
                'has_source' => filled($place->source),
                'missing_translations' => $place->missingTranslations('name'),
            ]);

        return Inertia::render('Admin/Places/Index', [
            'places' => $places,
            'filters' => [
                'q' => $request->string('q')->toString(),
                'category' => $request->integer('category'),
                'status' => $status,
                'verification' => $verification,
                'source' => $source,
                'area' => $request->integer('area'),
            ],
            'categories' => $this->categoryOptions(),
            'areas' => Area::query()->orderBy('path')->get(['id', 'name_ckb', 'name_ar', 'name_en'])
                ->map(fn (Area $a): array => ['value' => $a->id, 'label' => $a->name()])->all(),
            'sources' => Place::query()
                ->whereNotNull('source')
                ->distinct()
                ->orderBy('source')
                ->pluck('source')
                ->all(),
            'can' => [
                'create' => $request->user()?->hasPermission('geography.places.create') ?? false,
                'verify' => $request->user()?->hasPermission('geography.places.verify') ?? false,
                'update' => $request->user()?->hasPermission('geography.places.update') ?? false,
            ],
        ]);
    }

    public function create(): Response
    {
        return Inertia::render('Admin/Places/Form', $this->payload(null));
    }

    public function store(PlaceRequest $request): RedirectResponse
    {
        $place = new Place($request->validated() + [
            'publication_status' => PublicationStatus::Draft,
            'created_by' => $request->user()?->id,
        ]);

        // An area picked in the form is a person's decision, and the
        // provenance columns exist precisely so the importer's automatic
        // pass can tell it apart from its own work and leave it alone.
        if ($place->area_id !== null) {
            $place->area_is_manual = true;
            $place->area_assigned_at = now();
            $place->area_match_type = 'manual';
        }

        $place->save();

        $this->audit->record('place.created', $place, $request->validated());

        return redirect()->route('admin.places.edit', $place)->with('success', __('app.states.saved'));
    }

    public function edit(Place $place): Response
    {
        return Inertia::render('Admin/Places/Form', $this->payload($place));
    }

    public function update(PlaceRequest $request, Place $place): RedirectResponse
    {
        $movedFrom = $place->coordinates();

        $place->fill($request->validated());

        // A changed area link in the form is a human correction (Map Phase
        // 2): record it as manual so the OSM importer's refresh pass never
        // silently reverts it. An untouched link keeps its provenance.
        if ($place->isDirty('area_id')) {
            $place->area_is_manual = true;
            $place->area_assigned_at = now();
            $place->area_match_type = 'manual';
        }

        $place->save();

        // Spec 10.5 step 7: a moved place invalidates every frozen distance
        // that referenced it. The snapshots keep their published figures and
        // are flagged, rather than being silently rewritten.
        $moved = $movedFrom !== null
            && ($new = $place->coordinates()) !== null
            && ! $movedFrom->equals($new, 5.0);

        if ($moved) {
            $stale = $this->nearby->markStaleForPlace($place->id);
            $this->audit->record('place.moved', $place, ['snapshots_marked_stale' => $stale], severity: 'warning');
        }

        $this->audit->recordModelChange('place.updated', $place);

        return back()->with('success', __('app.states.saved'));
    }

    public function transition(Request $request, Place $place): RedirectResponse
    {
        $validated = $request->validate(['status' => ['required', 'string']]);
        $target = PublicationStatus::tryFrom($validated['status']);

        if ($target === null || ! $place->publication_status->canTransitionTo($target)) {
            return back()->withErrors(['status' => __('marketplace.errors.illegal_transition')]);
        }

        if ($target === PublicationStatus::Published
            && ! ($request->user()?->hasPermission('geography.places.verify') ?? false)) {
            abort(403, __('identity.errors.missing_permission'));
        }

        $place->publication_status = $target;
        $place->save();

        $this->audit->record('place.transitioned', $place, ['to' => $target->value]);

        return back()->with('success', __('projects.transitioned', ['status' => $target->label()]));
    }

    /**
     * Bulk review for imported rows (Map Phase 2).
     *
     * Two actions only — publish and unpublish — because those are the two
     * decisions a review queue of hundreds of OSM drafts actually needs in
     * bulk; anything finer stays a one-row edit. No bulk delete: the safe
     * pattern for retiring a row remains the existing per-place flow.
     *
     * Publishing walks each row through its LEGAL transition path
     * (draft -> in_review -> published) rather than teleporting: every hop
     * respects the same state machine transition() enforces. Intermediate
     * hops save quietly; the final hop uses a normal save, so PlaceObserver
     * sees exactly one visibility change per place and queues the existing
     * nearby-place recalculation on the maintenance queue — the Phase 2
     * pipeline's hand-off to the machinery that already exists.
     *
     * Bounded to one admin page's worth of selection per request so the
     * observer's per-place project scan stays a bounded cost on shared
     * hosting.
     */
    public function bulkTransition(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'action' => ['required', 'string', 'in:publish,unpublish'],
            'ids' => ['required', 'array', 'min:1', 'max:200'],
            'ids.*' => ['integer'],
        ]);

        if ($validated['action'] === 'publish'
            && ! ($request->user()?->hasPermission('geography.places.verify') ?? false)) {
            abort(403, __('identity.errors.missing_permission'));
        }

        $target = $validated['action'] === 'publish'
            ? PublicationStatus::Published
            : PublicationStatus::Unpublished;

        $changed = 0;
        $skipped = 0;

        Place::query()
            ->whereIn('id', $validated['ids'])
            ->get()
            ->each(function (Place $place) use ($target, &$changed, &$skipped): void {
                if ($place->publication_status === $target) {
                    $skipped++;

                    return;
                }

                if (! $this->walkTo($place, $target)) {
                    $skipped++;

                    return;
                }

                $changed++;
            });

        $this->audit->record('places.bulk_transitioned', null, [], [
            'action' => $validated['action'],
            'requested' => count($validated['ids']),
            'changed' => $changed,
            'skipped' => $skipped,
        ]);

        return back()->with('success', __('geography.osm.bulk_done', [
            'changed' => $changed,
            'skipped' => $skipped,
        ]));
    }

    /**
     * Advance one place to the target through legal hops only. Returns false
     * — leaving the row untouched — when no legal path exists (an archived
     * row is not silently resurrected by a bulk action).
     */
    private function walkTo(Place $place, PublicationStatus $target): bool
    {
        // Each state's next hop TOWARD published; unpublish is single-hop.
        $nextHop = [
            PublicationStatus::Draft->value => PublicationStatus::InReview,
            PublicationStatus::InReview->value => PublicationStatus::Published,
            PublicationStatus::Unpublished->value => PublicationStatus::Published,
        ];

        $hops = [];
        $cursor = $place->publication_status;

        while ($cursor !== $target) {
            $next = $target === PublicationStatus::Unpublished
                ? ($cursor === PublicationStatus::Published ? PublicationStatus::Unpublished : null)
                : ($nextHop[$cursor->value] ?? null);

            if ($next === null || ! $cursor->canTransitionTo($next) || count($hops) >= 3) {
                return false;
            }

            $hops[] = $next;
            $cursor = $next;
        }

        foreach ($hops as $index => $hop) {
            $place->publication_status = $hop;

            if ($index === count($hops) - 1) {
                $place->save();
            } else {
                $place->saveQuietly();
            }
        }

        return true;
    }

    /** @return array<string, mixed> */
    private function payload(?Place $place): array
    {
        return [
            'place' => $place === null ? null : [
                'id' => $place->id,
                ...$place->only([
                    'name_ckb', 'name_ar', 'name_en', 'place_category_id', 'subcategory',
                    'area_id', 'latitude', 'longitude',
                    'address_ckb', 'address_ar', 'address_en',
                    'website', 'operational_status', 'is_public',
                    'source', 'source_url', 'confidence',
                ]),
                'publication_status' => $place->publication_status->value,
                'allowed_transitions' => array_map(
                    static fn (PublicationStatus $s): string => $s->value,
                    $place->publication_status->allowedTransitions(),
                ),
            ],
            'options' => [
                'categories' => $this->categoryOptions(),
                'areas' => Area::query()->orderBy('path')->get(['id', 'name_ckb', 'name_ar', 'name_en', 'depth'])
                    ->map(fn (Area $a): array => ['value' => $a->id, 'label' => $a->name(), 'depth' => $a->depth])->all(),
                'operational_statuses' => array_map(
                    static fn (string $s): array => ['value' => $s, 'label' => __('geography.operational.'.$s)],
                    ['operating', 'temporarily_closed', 'permanently_closed', 'under_construction', 'planned'],
                ),
            ],
        ];
    }

    /** @return list<array{value: int, label: string}> */
    private function categoryOptions(): array
    {
        return PlaceCategory::query()
            ->where('is_active', true)
            ->orderBy('group')
            ->orderBy('sort_order')
            ->get()
            ->map(fn (PlaceCategory $c): array => ['value' => $c->id, 'label' => $c->name()])
            ->all();
    }
}
