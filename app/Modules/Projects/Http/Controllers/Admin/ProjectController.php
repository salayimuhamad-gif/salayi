<?php

declare(strict_types=1);

namespace App\Modules\Projects\Http\Controllers\Admin;

use App\Modules\Geography\Jobs\RecalculateNearbyPlaces;
use App\Modules\Geography\Models\Area;
use App\Modules\Geography\Services\AreaResolver;
use App\Modules\Operations\Services\AuditLogger;
use App\Modules\Projects\Enums\ConstructionStatus;
use App\Modules\Projects\Enums\DeliveryStatus;
use App\Modules\Projects\Enums\ProjectType;
use App\Modules\Projects\Enums\PublicationStatus;
use App\Modules\Projects\Http\Requests\ProjectRequest;
use App\Modules\Projects\Models\Developer;
use App\Modules\Projects\Models\Project;
use App\Modules\Projects\Support\ProjectScope;
use BackedEnum;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Inertia\Inertia;
use Inertia\Response;
use RuntimeException;

/**
 * Project administration (spec 12, 24).
 *
 * The interesting surface here is publication. Step 2 wrote
 * Project::publicationReadiness() and transitionTo() and nothing ever called
 * them; this controller is where those guards first face a user. The readiness
 * result is sent to the browser on every edit, so an administrator sees WHY a
 * project cannot go live while they are still filling the form — rather than
 * pressing Publish and receiving a refusal.
 */
final class ProjectController extends Controller
{
    public function __construct(private readonly AuditLogger $audit) {}

    public function index(Request $request): Response
    {
        /*
         * Scoped. This listed EVERY project on the platform to any user with
         * projects.view — including company account managers, who could read
         * their competitors' pipelines.
         */
        $projects = ProjectScope::apply(Project::query(), $request)
            ->when($request->string('q')->toString() !== '', function ($query) use ($request): void {
                $key = sorani_search_key($request->string('q')->toString());
                $query->where('search_key', 'like', '%'.$key.'%');
            })
            ->when($request->string('status')->toString() !== '', fn ($q) => $q->where('publication_status', $request->string('status')->toString()))
            /*
             * The map-readiness filter: the public maps place ONLY projects
             * with a real point (whereNotNull latitude AND longitude — a
             * boundary alone draws no marker), so this is the operational
             * answer to "why is my published project not on the map".
             * Nothing here invents a coordinate; it shows who lacks one.
             */
            ->when($request->string('map_ready')->toString() === '1', fn ($q) => $q
                ->whereNotNull('latitude')->whereNotNull('longitude'))
            ->when($request->string('map_ready')->toString() === '0', fn ($q) => $q
                ->where(fn ($inner) => $inner->whereNull('latitude')->orWhereNull('longitude')))
            ->with('area:id,name_ckb,name_ar,name_en')
            ->orderByDesc('updated_at')
            ->paginate(20)
            ->withQueryString()
            ->through(fn (Project $project): array => [
                'id' => $project->id,
                'name' => $project->name(),
                'slug' => $project->slug,
                'status' => $project->publication_status->value,
                'type' => $project->project_type->value,
                'construction_status' => $project->construction_status->value,
                'area' => $project->area?->name(),
                'has_geometry' => $project->hasRequiredGeometry(),
                // Marker-eligibility, exactly as the public map queries it.
                'map_ready' => $project->latitude !== null && $project->longitude !== null,
                'missing_translations' => $project->missingTranslations('name'),
                'updated_at' => $project->updated_at?->toIso8601String(),
            ]);

        return Inertia::render('Admin/Projects/Index', [
            'projects' => $projects,
            'filters' => [
                'q' => $request->string('q')->toString(),
                'status' => $request->string('status')->toString(),
                'map_ready' => $request->string('map_ready')->toString(),
            ],
            'statuses' => array_map(static fn (PublicationStatus $s): string => $s->value, PublicationStatus::cases()),
            'can' => [
                /*
                 * Separate capabilities, because they are separate rights and
                 * a single `create` flag rendered a button that always 403'd
                 * for company users — the worst kind of interface, one that
                 * offers something the server refuses.
                 */
                'create_scoped' => $request->user()?->hasPermission('projects.create_scoped') ?? false,
                'create_unscoped' => $request->user()?->hasPermission('projects.create_unscoped') ?? false,
                'use_wizard' => ($request->user()?->hasPermission('projects.create_scoped') ?? false)
                    && (bool) feature('projects.wizard'),
                'publish' => $request->user()?->hasPermission('projects.publish') ?? false,
            ],
        ]);
    }

    public function create(Request $request): Response
    {
        return Inertia::render('Admin/Projects/Form', $this->formPayload($request, null));
    }

    public function store(ProjectRequest $request): RedirectResponse
    {
        $project = Project::query()->create($request->validated() + [
            'publication_status' => PublicationStatus::Draft,
            'created_by' => $request->user()?->id,
        ]);

        $this->audit->record('project.created', $project, $request->validated());

        return redirect()
            ->route('admin.projects.edit', $project)
            ->with('success', __('app.states.saved'));
    }

    public function edit(Request $request, Project $project): Response
    {
        ProjectScope::authorise($request, $project);

        return Inertia::render('Admin/Projects/Form', $this->formPayload($request, $project));
    }

    public function update(ProjectRequest $request, Project $project): RedirectResponse
    {
        ProjectScope::authorise($request, $project);

        $project->fill($request->validated());
        $changes = $project->getDirty();
        $project->save();

        $this->audit->recordModelChange('project.updated', $project);

        return back()->with('success', __('app.states.saved'));
    }

    /**
     * Publication.
     *
     * Both guards run: transitionTo() refuses an illegal workflow move, and
     * refuses to publish an incomplete record. The failure comes back as a
     * flash error naming the blockers rather than a generic message, because
     * "this project cannot be published" without saying why is the kind of
     * message that generates a support call.
     */
    public function transition(Request $request, Project $project): RedirectResponse
    {
        // Route-model binding resolves any id in the table; it is not
        // authorisation.
        ProjectScope::authorise($request, $project);

        $validated = $request->validate([
            'status' => ['required', 'string', 'in:'.implode(',', array_map(
                static fn (PublicationStatus $s): string => $s->value,
                PublicationStatus::cases(),
            ))],
        ]);

        $target = PublicationStatus::from($validated['status']);

        if ($target === PublicationStatus::Published
            && ! ($request->user()?->hasPermission('projects.publish') ?? false)) {
            abort(403, __('identity.errors.missing_permission'));
        }

        try {
            $project->transitionTo($target);
            $project->save();
        } catch (RuntimeException $e) {
            $this->audit->security('project.transition.refused', [
                'project_id' => $project->id,
                'target' => $target->value,
            ], result: 'refused');

            return back()->withErrors(['status' => $e->getMessage()]);
        }

        $this->audit->record('project.transitioned', $project, [
            'to' => $target->value,
        ], severity: $target === PublicationStatus::Published ? 'warning' : 'info');

        return back()->with('success', __('projects.transitioned', ['status' => $target->label()]));
    }

    /**
     * Queue a nearby-place recalculation by hand.
     *
     * The observers cover project moves and place changes, but an editor who
     * has just imported places, or who is looking at a stale badge, should not
     * have to wait for an hourly sweep they cannot see. The job is unique per
     * project, so pressing this repeatedly costs nothing.
     */
    public function recalculateNearby(Request $request, Project $project): RedirectResponse
    {
        // Route-model binding resolves any id in the table; it is not
        // authorisation.
        ProjectScope::authorise($request, $project);

        if ($project->coordinates() === null) {
            return back()->withErrors(['nearby' => __('geography.nearby.needs_location')]);
        }

        RecalculateNearbyPlaces::dispatch($project->id);

        $this->audit->record('nearby.recalculation_requested', $project);

        return back()->with('success', __('geography.nearby.queued'));
    }

    /** @return array<string, mixed> */
    private function formPayload(Request $request, ?Project $project): array
    {
        /*
         * Developer options are SCOPED. The form sent every Developer on the
         * platform to a company user, which is both a disclosure — the full
         * list of who builds in Erbil — and an invitation, since the field was
         * validated with `exists` alone and would have accepted any of them.
         */
        $permittedDeveloperIds = ProjectScope::permittedDeveloperIds($request);

        return [
            'project' => $project === null ? null : [
                'id' => $project->id,
                ...$project->only([
                    'slug', 'name_ckb', 'name_ar', 'name_en',
                    'description_ckb', 'description_ar', 'description_en',
                    'developer_id', 'area_id', 'latitude', 'longitude', 'boundary_wkt',
                    'completion_percent', 'land_area_sqm', 'building_count', 'unit_count',
                    'source', 'official_url', 'confidence',
                ]),
                'project_type' => $project->project_type->value,
                'construction_status' => $project->construction_status->value,
                'delivery_status' => $project->delivery_status->value,
                'launch_date' => $project->launch_date?->toDateString(),
                'expected_delivery' => $project->expected_delivery?->toDateString(),
                'actual_delivery' => $project->actual_delivery?->toDateString(),
                'publication_status' => $project->publication_status->value,
                // The Step 2 gate, surfaced while the form is still open.
                'readiness' => $project->publicationReadiness(),
                'allowed_transitions' => array_map(
                    static fn (PublicationStatus $s): string => $s->value,
                    $project->publication_status->allowedTransitions(),
                ),
                'missing_translations' => $project->missingTranslations('name'),
                'nearby_count' => $project->nearbyPlaces()->where('is_hidden', false)->count(),
                'nearby_stale' => $project->nearbyPlaces()->where('is_stale', true)->exists(),
                'amenity_score' => $project->nearby_amenity_score,
            ],
            'options' => [
                'types' => $this->options(ProjectType::cases(), 'projects.types'),
                'construction' => $this->options(ConstructionStatus::cases(), 'projects.construction_statuses'),
                'delivery' => $this->options(DeliveryStatus::cases(), 'projects.delivery_statuses'),
                /*
                 * publication_status and path MUST be selected: the ancestry
                 * filter below reads both (path via ancestorIds()), and the
                 * strict-model mode turns the omission into a 500 on the
                 * whole page the moment the first area row exists — found in
                 * Phase 12 when the browser fixtures gained one.
                 */
                'areas' => Area::query()->orderBy('path')
                    ->where('publication_status', 'published')
                    ->get(['id', 'name_ckb', 'name_ar', 'name_en', 'depth', 'path', 'publication_status'])
                    ->filter(static fn (Area $area): bool => app(AreaResolver::class)->ancestryIsPublished($area))
                    ->values()
                    ->map(fn (Area $a): array => ['id' => $a->id, 'name' => $a->name(), 'depth' => $a->depth])->all(),
                'developers' => Developer::query()
                    ->when(
                        $permittedDeveloperIds !== null,
                        static fn ($q) => $q->whereIn('id', $permittedDeveloperIds ?? []),
                    )
                    ->orderBy('name_ckb')->get(['id', 'name_ckb', 'name_ar', 'name_en'])
                    ->map(fn (Developer $d): array => ['id' => $d->id, 'name' => $d->name()])->all(),
            ],
            'can' => [
                'publish' => $request->user()?->hasPermission('projects.publish') ?? false,
                'update' => $request->user()?->hasPermission('projects.update') ?? false,
            ],
        ];
    }

    /**
     * @param  array<int, BackedEnum>  $cases
     * @return list<array{value: string, label: string}>
     */
    private function options(array $cases, string $group): array
    {
        return array_map(static fn ($case): array => [
            'value' => $case->value,
            'label' => __($group.'.'.$case->value),
        ], $cases);
    }
}
