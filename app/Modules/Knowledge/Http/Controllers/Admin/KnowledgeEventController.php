<?php

declare(strict_types=1);

namespace App\Modules\Knowledge\Http\Controllers\Admin;

use App\Modules\Geography\Models\Area;
use App\Modules\Knowledge\Enums\EvidenceClass;
use App\Modules\Knowledge\Enums\KnowledgeStatus;
use App\Modules\Knowledge\Http\Requests\KnowledgeEventRequest;
use App\Modules\Knowledge\Models\KnowledgeEvent;
use App\Modules\Operations\Services\AuditLogger;
use App\Modules\Projects\Models\Project;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Inertia\Inertia;
use Inertia\Response;
use RuntimeException;

/**
 * Knowledge timeline administration (spec 21).
 *
 * Step 7 built the seven-state workflow, the three-condition AI gate and the
 * source requirement, and nothing ever drove them.
 */
final class KnowledgeEventController extends Controller
{
    public function __construct(private readonly AuditLogger $audit) {}

    public function index(Request $request): Response
    {
        $events = KnowledgeEvent::query()
            ->when($request->string('q')->toString() !== '', fn ($q) => $q->where(
                'search_key', 'like', '%'.sorani_search_key($request->string('q')->toString()).'%',
            ))
            ->when($request->string('status')->toString() !== '',
                fn ($q) => $q->where('status', $request->string('status')->toString()))
            ->when($request->string('event_type')->toString() !== '',
                fn ($q) => $q->where('event_type', $request->string('event_type')->toString()))
            ->orderByDesc('effective_date')
            ->paginate(30)
            ->withQueryString()
            ->through(fn (KnowledgeEvent $e): array => [
                'id' => $e->id,
                'title' => $e->title_ckb,
                'event_type' => $e->event_type,
                'direction' => $e->direction,
                'strength' => $e->strength,
                'effective_date' => $e->effective_date->toDateString(),
                'expires_on' => $e->expires_on?->toDateString(),
                'status' => $e->status->value,
                'ai_usage_permitted' => $e->ai_usage_permitted,
                // The three conditions evaluated together, so an editor can see
                // why an event the AI "should" be using is not being used.
                'ai_usable' => $e->aiUsable(),
                'has_source' => filled($e->source),
                'allowed_transitions' => array_map(
                    static fn (KnowledgeStatus $s): string => $s->value,
                    $e->status->allowedTransitions(),
                ),
            ]);

        return Inertia::render('Admin/Knowledge/Index', [
            'events' => $events,
            'filters' => [
                'q' => $request->string('q')->toString(),
                'status' => $request->string('status')->toString(),
                'event_type' => $request->string('event_type')->toString(),
            ],
            'options' => $this->options(),
            'counts' => [
                'review' => KnowledgeEvent::query()->where('status', KnowledgeStatus::Review->value)->count(),
                'ai_usable' => KnowledgeEvent::query()->aiUsable()->count(),
            ],
            'can' => [
                'create' => $request->user()?->hasPermission('knowledge.events.create') ?? false,
                'approve' => $request->user()?->hasPermission('knowledge.events.review') ?? false,
            ],
        ]);
    }

    public function store(KnowledgeEventRequest $request): RedirectResponse
    {
        /*
         * Explicit assignment for the server-owned fields, found broken in
         * Phase 12: author_id is deliberately NOT fillable (it must never
         * arrive from a request), so passing it through create() threw a
         * MassAssignmentException on every submission — this endpoint had
         * never successfully created an event.
         */
        $event = new KnowledgeEvent($request->validated());
        $event->status = KnowledgeStatus::Draft;
        $event->author_id = $request->user()?->id;
        $event->save();

        $this->audit->record('knowledge_event.created', $event, $request->validated());

        return back()->with('success', __('app.states.saved'));
    }

    public function update(KnowledgeEventRequest $request, KnowledgeEvent $event): RedirectResponse
    {
        try {
            $event->fill($request->validated());
            $event->save();
        } catch (RuntimeException $e) {
            return back()->withErrors(['source' => $e->getMessage()]);
        }

        $this->audit->recordModelChange('knowledge_event.updated', $event);

        return back()->with('success', __('app.states.saved'));
    }

    public function transition(Request $request, KnowledgeEvent $event): RedirectResponse
    {
        $validated = $request->validate([
            'status' => ['required', 'string', 'in:'.implode(',', array_map(
                static fn (KnowledgeStatus $s): string => $s->value,
                KnowledgeStatus::cases(),
            ))],
        ]);

        $target = KnowledgeStatus::from($validated['status']);

        // Approval and publication are the points at which an event becomes
        // evidence, so they carry their own permission.
        if (in_array($target, [KnowledgeStatus::Approved, KnowledgeStatus::Published], true)
            && ! ($request->user()?->hasPermission('knowledge.events.review') ?? false)) {
            abort(403, __('identity.errors.missing_permission'));
        }

        try {
            $event->transitionTo($target);
            $event->reviewed_by = $request->user()?->id;
            $event->reviewed_at = now();

            if ($target === KnowledgeStatus::Published) {
                $event->publication_date ??= now()->toDateString();
            }

            $event->save();
        } catch (RuntimeException $e) {
            return back()->withErrors(['status' => $e->getMessage()]);
        }

        $this->audit->record('knowledge_event.transitioned', $event, [], [
            'to' => $target->value,
        ], severity: 'warning');

        return back()->with('success', __('app.states.saved'));
    }

    /** @return array<string, mixed> */
    private function options(): array
    {
        return [
            'event_types' => array_map(static fn (string $t): array => [
                'value' => $t,
                'label' => __('knowledge.event_types.'.$t),
            ], KnowledgeEventRequest::EVENT_TYPES),
            'statuses' => array_map(static fn (KnowledgeStatus $s): array => [
                'value' => $s->value,
                'label' => __('knowledge.statuses.'.$s->value),
                'ai_usable' => $s->isAiUsable(),
            ], KnowledgeStatus::cases()),
            'directions' => array_map(static fn (string $d): array => [
                'value' => $d,
                'label' => __('knowledge.direction.'.$d),
            ], ['positive', 'neutral', 'negative']),
            /*
             * Phase 12: what kind of claim the event makes. The advisory
             * composer keys its stance on this, so the form must offer the
             * closed set with its localized names and nothing else.
             */
            'evidence_classes' => array_map(static fn (EvidenceClass $c): array => [
                'value' => $c->value,
                'label' => $c->label(),
            ], EvidenceClass::cases()),
            'entity_types' => array_map(static fn (string $t): array => [
                'value' => $t,
                'label' => __('knowledge.entity_types.'.$t),
            ], ['project', 'area']),
            'projects' => Project::query()->orderBy('name_ckb')->limit(500)
                ->get(['id', 'name_ckb', 'name_ar', 'name_en'])
                ->map(fn (Project $p): array => ['value' => $p->id, 'label' => $p->name()])->all(),
            /*
             * Phase 12: the market-intelligence use case records insights
             * about AREAS ("prices rose in Ankawa"), and the request rules
             * always accepted entity_type=area — only this options list kept
             * the form project-only.
             */
            'areas' => Area::query()->orderBy('name_ckb')->limit(500)
                ->get(['id', 'name_ckb', 'name_ar', 'name_en'])
                ->map(fn (Area $a): array => ['value' => $a->id, 'label' => $a->name()])->all(),
        ];
    }
}
