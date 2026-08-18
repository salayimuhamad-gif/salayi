<?php

declare(strict_types=1);

namespace App\Modules\Marketplace\Http\Controllers\Admin;

use App\Modules\Companies\Models\Company;
use App\Modules\Geography\Models\Area;
use App\Modules\Market\Enums\PropertyType;
use App\Modules\Marketplace\Enums\OfferStatus;
use App\Modules\Marketplace\Http\Requests\OfferRequest;
use App\Modules\Marketplace\Models\Offer;
use App\Modules\Marketplace\Models\OfferStatusHistory;
use App\Modules\Marketplace\Support\OfferScope;
use App\Modules\Notifications\Services\Notifier;
use App\Modules\Operations\Services\AuditLogger;
use App\Modules\Projects\Models\Project;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Inertia\Inertia;
use Inertia\Response;
use RuntimeException;

/**
 * Offer administration and moderation (spec 19).
 *
 * Step 5 built the eleven-state workflow and the moderator gate and nothing
 * ever drove them. The state machine is the product here: a company cannot move
 * its own offer to Published, and every path to Published passes through
 * Approved, which only a moderator can set.
 */
final class OfferController extends Controller
{
    public function __construct(
        private readonly AuditLogger $audit,
        private readonly Notifier $notifier,
    ) {}

    public function index(Request $request): Response
    {
        $offers = OfferScope::restrict($request, Offer::query())
            ->when($request->string('q')->toString() !== '', fn ($q) => $q->where(
                'search_key', 'like', '%'.sorani_search_key($request->string('q')->toString()).'%',
            ))
            ->when($request->string('status')->toString() !== '',
                fn ($q) => $q->where('status', $request->string('status')->toString()))
            ->when($request->boolean('queue'),
                // The moderation queue: everything waiting on a human.
                fn ($q) => $q->whereIn('status', [
                    OfferStatus::Submitted->value,
                    OfferStatus::UnderReview->value,
                ]))
            ->with(['company:id,name_ckb,name_ar,name_en', 'project:id,name_ckb,name_ar,name_en'])
            ->orderByDesc('submitted_at')
            ->orderByDesc('updated_at')
            ->paginate(25)
            ->withQueryString()
            ->through(fn (Offer $o): array => [
                'id' => $o->id,
                'title' => $o->title_ckb,
                'company' => $o->company?->name(),
                'project' => $o->project?->name(),
                'offer_type' => $o->offer_type,
                'property_type' => $o->property_type,
                'price' => $o->price,
                'currency' => $o->currency,
                'status' => $o->status->value,
                'is_pending' => $o->status->isPending(),
                'is_sponsored' => $o->is_sponsored,
                'disclosure_label' => $o->disclosure_label,
                'has_source' => filled($o->source),
                'allowed_transitions' => array_map(
                    static fn (OfferStatus $s): array => [
                        'value' => $s->value,
                        // The interface disables what the actor cannot set,
                        // rather than offering it and failing afterwards.
                        'requires_moderator' => $s->requiresModerator(),
                    ],
                    $o->status->allowedTransitions(),
                ),
            ]);

        return Inertia::render('Admin/Offers/Index', [
            'offers' => $offers,
            'filters' => [
                'q' => $request->string('q')->toString(),
                'status' => $request->string('status')->toString(),
                'queue' => $request->boolean('queue'),
            ],
            'statuses' => array_map(
                static fn (OfferStatus $s): array => ['value' => $s->value, 'label' => __('marketplace.statuses.'.$s->value)],
                OfferStatus::cases(),
            ),
            'counts' => [
                // Scoped like the rows themselves: a company inferring
                // platform-wide inventory from a badge is the same leak by a
                // slower route.
                'pending' => OfferScope::restrict($request, Offer::query())->whereIn('status', [
                    OfferStatus::Submitted->value, OfferStatus::UnderReview->value,
                ])->count(),
                'published' => OfferScope::restrict($request, Offer::query())
                    ->where('status', OfferStatus::Published->value)->count(),
            ],
            'can' => [
                // Same two-permission model as the FormRequest and the routes;
                // the old capability named a permission that did not exist, so
                // the button was hidden from every real seller.
                'create' => OfferScope::isModerator($request)
                    || ($request->user()?->hasPermission('marketplace.offers.manage_own') ?? false),
                'moderate' => $request->user()?->hasPermission('marketplace.offers.moderate') ?? false,
            ],
        ]);
    }

    public function create(Request $request): Response
    {
        return Inertia::render('Admin/Offers/Form', $this->payload($request, null));
    }

    public function store(OfferRequest $request): RedirectResponse
    {
        /*
         * company_id comes from the SESSION, never from input. A seller who
         * can post company_id can post somebody else's, and the offer would
         * then appear under a company they do not represent.
         */
        $companyId = OfferScope::companyIdForCreation($request);

        // A project belonging to another company must not be attachable, and
        // the UI not offering it is not a control — a direct POST bypasses it.
        OfferScope::assertProjectIsEligible($request, $companyId, $request->validated()['project_id'] ?? null);

        /*
         * forceFill for company_id: it is not mass-assignable, precisely so
         * that no ordinary payload can set it. This is the one place that may.
         */
        $offer = new Offer;

        /*
         * `company_id` IS STRIPPED BEFORE fill(), not merely overwritten after.
         *
         * The intent above is right — the company comes from the session — but
         * the validated payload still CONTAINED `company_id` when a client sent
         * one, and `fill()` on a non-fillable attribute throws
         * MassAssignmentException under `preventSilentlyDiscardingAttributes()`.
         * So a forged `company_id` did not get ignored: it produced a 500. The
         * guard that exists to make the field unforgeable turned an attempted
         * forgery into a crash, and legitimate clients echoing the field back
         * could not create an offer at all.
         *
         * Dropping the key first means a forged value is genuinely ignored, and
         * the forceFill below remains the single place company_id is set.
         */
        $attributes = $request->validated();
        unset($attributes['company_id']);

        $offer->fill($attributes + ['status' => OfferStatus::Draft]);
        $offer->forceFill(['company_id' => $companyId]);
        $offer->save();

        $this->audit->record('offer.created', $offer, $request->validated());

        return redirect()->route('admin.offers.edit', $offer)->with('success', __('app.states.saved'));
    }

    public function edit(Request $request, Offer $offer): Response
    {
        OfferScope::authorise($request, $offer);

        return Inertia::render('Admin/Offers/Form', $this->payload($request, $offer));
    }

    public function update(OfferRequest $request, Offer $offer): RedirectResponse
    {
        OfferScope::authorise($request, $offer);

        $values = $request->validated();

        OfferScope::assertProjectIsEligible($request, (int) $offer->company_id, $values['project_id'] ?? null);

        /*
         * OWNERSHIP IS NOT EDITABLE HERE.
         *
         * The ownership check above proves the visitor may edit THIS offer —
         * and then filling the whole payload let them write a different
         * company_id, handing the offer to somebody else immediately after
         * passing the check. The check and the write disagreed about what was
         * being protected.
         *
         * A platform moderator does not transfer ownership through this route
         * either: that is a separate, audited action, not a side effect of an
         * ordinary edit.
         */
        unset($values['company_id']);

        try {
            $offer->fill($values);
            $offer->save();
        } catch (RuntimeException $e) {
            return back()->withErrors(['disclosure_label' => $e->getMessage()]);
        }

        $this->audit->recordModelChange('offer.updated', $offer);

        return back()->with('success', __('app.states.saved'));
    }

    /**
     * Move through the workflow.
     *
     * The moderator flag comes from the permission, never from the request
     * body — a company posting `actor_is_moderator=true` must not be able to
     * approve its own listing.
     */
    public function transition(Request $request, Offer $offer): RedirectResponse
    {
        OfferScope::authorise($request, $offer);

        $validated = $request->validate([
            'status' => ['required', 'string', 'in:'.implode(',', array_map(
                static fn (OfferStatus $s): string => $s->value,
                OfferStatus::cases(),
            ))],
            'reason' => ['nullable', 'string', 'max:2000'],
        ]);

        $target = OfferStatus::from($validated['status']);
        $isModerator = $request->user()?->hasPermission('marketplace.offers.moderate') ?? false;
        $from = $offer->status;

        try {
            $offer->transitionTo($target, actorIsModerator: $isModerator);
            $offer->reviewed_by = $target->requiresModerator() ? $request->user()?->id : $offer->reviewed_by;
            $offer->moderation_notes = $validated['reason'] ?? $offer->moderation_notes;
            $offer->save();
        } catch (RuntimeException $e) {
            $this->audit->security('offer.transition.refused', [
                'offer_id' => $offer->id,
                'from' => $from->value,
                'to' => $target->value,
            ], result: 'refused');

            return back()->withErrors(['status' => $e->getMessage()]);
        }

        // Append-only, so the moderation trail survives even if the offer is
        // later archived or its notes overwritten.
        OfferStatusHistory::query()->create([
            'offer_id' => $offer->id,
            'from_status' => $from->value,
            'to_status' => $target->value,
            'actor_id' => $request->user()?->id,
            'actor_label' => $request->user()?->name,
            'actor_was_moderator' => $isModerator,
            'reason' => $validated['reason'] ?? null,
        ]);

        $this->audit->record('offer.transitioned', $offer, [], [
            'from' => $from->value,
            'to' => $target->value,
        ], severity: $target === OfferStatus::Published ? 'warning' : 'info');

        // The company is told the outcome. Until now a rejection was recorded
        // in the moderation trail and nowhere the seller could see, so a
        // listing vanished and they were left to guess why.
        $this->notifyModerationOutcome($offer, $target, $validated['reason'] ?? null);

        return back()->with('success', __('projects.transitioned', ['status' => __('marketplace.statuses.'.$target->value)]));
    }

    /**
     * Notify the seller of a moderation decision (spec 22.3, 30.2).
     *
     * `moderation_outcome`, which `ConsentGate` exempts from the consent gate.
     * That exemption is the point: requiring marketing consent before telling
     * someone why their listing was rejected would mean a company that declined
     * Telegram never finds out, which is the difference between an accountable
     * platform and a silent one.
     *
     * Only the three decisions a seller is owed an explanation for. Publishing,
     * pausing and archiving are the company's own actions on its own listing;
     * telling someone what they just did is noise, and noise is what trains
     * people to ignore the channel that later carries a rejection.
     */
    private function notifyModerationOutcome(Offer $offer, OfferStatus $target, ?string $reason): void
    {
        $event = match ($target) {
            OfferStatus::Approved => 'offer_approved',
            OfferStatus::Rejected => 'offer_rejected',
            OfferStatus::ChangesRequested => 'offer_changes_requested',
            default => null,
        };

        if ($event === null) {
            return;
        }

        $recipientId = $offer->owner_user_id ?? $offer->agent_user_id;

        if ($recipientId === null) {
            return;
        }

        $this->notifier->send(
            event: $event,
            recipient: (int) $recipientId,
            replacements: [
                'title' => (string) $offer->title_ckb,
                // A rejection with no stated reason is the complaint this
                // whole module exists to answer, so the placeholder is never
                // left empty in the rendered message.
                'reason' => trim((string) $reason) !== ''
                    ? (string) $reason
                    : __('marketplace.statuses.'.$target->value),
            ],
            consentPurpose: 'moderation_outcome',
            actionUrl: route('admin.offers.edit', $offer),
            priority: $target === OfferStatus::Rejected ? 'high' : 'normal',
        );
    }

    /** @return array<string, mixed> */
    private function payload(Request $request, ?Offer $offer): array
    {
        return [
            'offer' => $offer === null ? null : [
                'id' => $offer->id,
                ...$offer->only([
                    'title_ckb', 'title_ar', 'title_en', 'description_ckb',
                    'offer_type', 'property_type', 'unit_type',
                    'company_id', 'project_id', 'area_id',
                    'location_precision', 'latitude', 'longitude',
                    'size_sqm', 'rooms', 'bathrooms', 'floor',
                    'price', 'currency', 'availability', 'contact_method',
                    'source', 'is_sponsored', 'disclosure_label', 'terms',
                ]),
                'status' => $offer->status->value,
                'expires_at' => $offer->expires_at?->toDateString(),
                'allowed_transitions' => array_map(
                    static fn (OfferStatus $s): array => [
                        'value' => $s->value,
                        'requires_moderator' => $s->requiresModerator(),
                    ],
                    $offer->status->allowedTransitions(),
                ),
                'history' => $offer->statusHistory()->limit(20)->get()
                    ->map(static fn (OfferStatusHistory $h): array => [
                        'from' => $h->from_status,
                        'to' => $h->to_status,
                        'actor' => $h->actor_label,
                        'was_moderator' => $h->actor_was_moderator,
                        'reason' => $h->reason,
                        'at' => $h->created_at->toIso8601String(),
                    ])->all(),
            ],
            'options' => [
                /*
                 * SELECTORS ARE SCOPED.
                 *
                 * A dropdown listing every company and five hundred projects
                 * is a directory of competitors and their pipelines, handed to
                 * anybody who can open the offer form. A moderator needs the
                 * global list; a seller needs their own company and the
                 * projects they may actually attach.
                 */
                'companies' => OfferScope::isModerator($request)
                    ? Company::query()->orderBy('name_ckb')->limit(500)
                        ->get(['id', 'name_ckb', 'name_ar', 'name_en', 'verification_status'])
                        ->map(fn (Company $c): array => [
                            'value' => $c->id,
                            'label' => $c->name().($c->verification_status === 'verified' ? '' : ' ⚠'),
                        ])->all()
                    : Company::query()
                        ->whereKey(OfferScope::actingCompanyId($request) ?? 0)
                        ->get(['id', 'name_ckb', 'name_ar', 'name_en'])
                        ->map(fn (Company $c): array => ['value' => $c->id, 'label' => $c->name()])
                        ->all(),
                'projects' => OfferScope::eligibleProjects($request)
                    ->orderBy('name_ckb')
                    ->limit(500)
                    ->get(['id', 'name_ckb', 'name_ar', 'name_en'])
                    ->map(fn (Project $p): array => ['value' => $p->id, 'label' => $p->name()])->all(),
                'areas' => Area::query()->orderBy('path')->get(['id', 'name_ckb', 'name_ar', 'name_en', 'depth'])
                    ->map(fn (Area $a): array => ['value' => $a->id, 'label' => $a->name(), 'depth' => $a->depth])->all(),
                'property_types' => array_map(static fn (PropertyType $t): array => [
                    'value' => $t->value, 'label' => __('market.property_types.'.$t->value),
                ], PropertyType::cases()),
            ],
        ];
    }
}
