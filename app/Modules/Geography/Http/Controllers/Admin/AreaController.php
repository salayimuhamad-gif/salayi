<?php

declare(strict_types=1);

namespace App\Modules\Geography\Http\Controllers\Admin;

use App\Modules\Geography\Enums\AreaType;
use App\Modules\Geography\Http\Requests\AreaRequest;
use App\Modules\Geography\Models\Area;
use App\Modules\Operations\Services\AuditLogger;
use App\Modules\Projects\Enums\PublicationStatus;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Inertia\Inertia;
use Inertia\Response;
use RuntimeException;

/**
 * Area administration (spec 10.2, 12.2).
 *
 * Areas are the prerequisite nothing else can proceed without: a project cannot
 * be published while `area_missing` blocks it, and the valuation engine's
 * wider-area fallback walks the materialised path. Ordering the list by `path`
 * rather than by name renders the hierarchy in place with no recursive query.
 */
final class AreaController extends Controller
{
    public function __construct(private readonly AuditLogger $audit) {}

    public function index(Request $request): Response
    {
        $areas = Area::query()
            ->when($request->string('q')->toString() !== '', function ($query) use ($request): void {
                $query->where('search_key', 'like', '%'.sorani_search_key($request->string('q')->toString()).'%');
            })
            // Path ordering renders a tree as a flat list, correctly nested,
            // without a recursive CTE the shared host may not support well.
            ->withCount('projects')
            ->orderBy('path')
            ->get()
            ->map(fn (Area $area): array => [
                'id' => $area->id,
                'name' => $area->name(),
                'slug' => $area->slug,
                'type' => $area->type->value,
                'depth' => $area->depth,
                'status' => $area->publication_status->value,
                'has_boundary' => $area->hasBoundary(),
                'missing_translations' => $area->missingTranslations('name'),
                'project_count' => $area->projects_count,
            ]);

        return Inertia::render('Admin/Areas/Index', [
            'areas' => $areas,
            'filters' => ['q' => $request->string('q')->toString()],
            'can' => [
                'create' => $request->user()?->hasPermission('geography.areas.create') ?? false,
                'publish' => $request->user()?->hasPermission('geography.areas.publish') ?? false,
            ],
        ]);
    }

    public function create(Request $request): Response
    {
        return Inertia::render('Admin/Areas/Form', $this->payload(null));
    }

    public function store(AreaRequest $request): RedirectResponse
    {
        $area = Area::query()->create($request->validated() + [
            'publication_status' => PublicationStatus::Draft,
            'created_by' => $request->user()?->id,
        ]);

        $this->audit->record('area.created', $area, $request->validated());

        return redirect()->route('admin.areas.edit', $area)->with('success', __('app.states.saved'));
    }

    public function edit(Area $area): Response
    {
        return Inertia::render('Admin/Areas/Form', $this->payload($area));
    }

    public function update(AreaRequest $request, Area $area): RedirectResponse
    {
        try {
            $area->fill($request->validated());
            $area->save();
        } catch (RuntimeException $e) {
            // The model guards the hierarchy independently of the request. If
            // one fires anyway, surface it as a field error rather than a 500.
            return back()->withErrors(['parent_id' => $e->getMessage()]);
        }

        $this->audit->recordModelChange('area.updated', $area);

        return back()->with('success', __('app.states.saved'));
    }

    public function transition(Request $request, Area $area): RedirectResponse
    {
        $validated = $request->validate(['status' => ['required', 'string']]);
        $target = PublicationStatus::tryFrom($validated['status']);

        if ($target === null || ! $area->publication_status->canTransitionTo($target)) {
            return back()->withErrors(['status' => __('marketplace.errors.illegal_transition')]);
        }

        if ($target === PublicationStatus::Published
            && ! ($request->user()?->hasPermission('geography.areas.publish') ?? false)) {
            abort(403, __('identity.errors.missing_permission'));
        }

        $area->publication_status = $target;
        $area->published_at ??= $target === PublicationStatus::Published ? now() : null;
        $area->save();

        $this->audit->record('area.transitioned', $area, ['to' => $target->value]);

        return back()->with('success', __('projects.transitioned', ['status' => $target->label()]));
    }

    /** @return array<string, mixed> */
    private function payload(?Area $area): array
    {
        return [
            'area' => $area === null ? null : [
                'id' => $area->id,
                ...$area->only([
                    'slug', 'name_ckb', 'name_ar', 'name_en',
                    'description_ckb', 'description_ar', 'description_en',
                    'parent_id', 'latitude', 'longitude', 'boundary_wkt', 'source', 'confidence',
                ]),
                'type' => $area->type->value,
                'depth' => $area->depth,
                'publication_status' => $area->publication_status->value,
                'allowed_transitions' => array_map(
                    static fn (PublicationStatus $s): string => $s->value,
                    $area->publication_status->allowedTransitions(),
                ),
                'missing_translations' => $area->missingTranslations('name'),
            ],
            'options' => [
                'types' => array_map(static fn (AreaType $t): array => [
                    'value' => $t->value,
                    'label' => __('geography.area_types.'.$t->value),
                    'depth' => $t->depth(),
                ], AreaType::cases()),
                'parents' => Area::query()
                    ->when($area !== null, fn ($q) => $q->where('id', '!=', $area->id))
                    ->orderBy('path')
                    ->get(['id', 'name_ckb', 'name_ar', 'name_en', 'depth', 'type'])
                    ->map(fn (Area $a): array => [
                        'id' => $a->id,
                        'name' => $a->name(),
                        'depth' => $a->depth,
                        'type' => $a->type->value,
                    ])->all(),
            ],
        ];
    }
}
