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
        $places = Place::query()
            ->when($request->string('q')->toString() !== '', fn ($q) => $q->where(
                'search_key', 'like', '%'.sorani_search_key($request->string('q')->toString()).'%',
            ))
            ->when($request->integer('category') > 0, fn ($q) => $q->where('place_category_id', $request->integer('category')))
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
                'has_source' => filled($place->source),
                'missing_translations' => $place->missingTranslations('name'),
            ]);

        return Inertia::render('Admin/Places/Index', [
            'places' => $places,
            'filters' => [
                'q' => $request->string('q')->toString(),
                'category' => $request->integer('category'),
            ],
            'categories' => $this->categoryOptions(),
            'can' => ['create' => $request->user()?->hasPermission('geography.places.create') ?? false],
        ]);
    }

    public function create(): Response
    {
        return Inertia::render('Admin/Places/Form', $this->payload(null));
    }

    public function store(PlaceRequest $request): RedirectResponse
    {
        $place = Place::query()->create($request->validated() + [
            'publication_status' => PublicationStatus::Draft,
            'created_by' => $request->user()?->id,
        ]);

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
