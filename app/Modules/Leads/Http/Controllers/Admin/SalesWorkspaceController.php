<?php

declare(strict_types=1);

namespace App\Modules\Leads\Http\Controllers\Admin;

use App\Modules\Advisor\Models\AdvisorMessage;
use App\Modules\Advisor\Services\AdvisorProjectMatcher;
use App\Modules\Advisor\Support\AdvisorCurrency;
use App\Modules\Companies\Models\CompanyStaff;
use App\Modules\Identity\Models\Consent;
use App\Modules\Leads\Enums\LeadOutcome;
use App\Modules\Leads\Enums\RevealReason;
use App\Modules\Leads\Models\DemandProfile;
use App\Modules\Leads\Models\LeadNote;
use App\Modules\Leads\Models\LeadTask;
use App\Modules\Leads\Services\PhoneRevealService;
use App\Modules\Leads\Support\LeadScope;
use App\Modules\Operations\Services\AuditLogger;
use App\Modules\Projects\Support\ActingCompanyContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

/**
 * The sales workspace (File one §9 Leads 2, 5).
 *
 * §9 lists filters, stages, notes, tasks, outcomes and score reasons. The one
 * that governs how this class is written is §9 Leads 5: **never expose contact
 * details without valid consent.**
 *
 * That is enforced by omission, not by a flag the interface is trusted to
 * respect. No phone number, email or encrypted contact field appears in any
 * payload this controller builds — not masked, not partial, not "available on
 * request". Revealing a contact is a separate, audited act performed by
 * `PhoneRevealService`, which checks permission, consent and a stated reason
 * before it decrypts anything.
 *
 * The reason for that split is practical rather than legalistic: a workspace
 * that ships a masked number to the browser has already put it in a response
 * body, a browser cache and possibly a screenshot. The only reliable way to not
 * leak a number is to not send it.
 */
final class SalesWorkspaceController extends Controller
{
    /** The pipeline, in order — canonical list on the model that owns the column. */
    private const STAGES = DemandProfile::STAGES;

    public function __construct(private readonly AuditLogger $audit) {}

    public function index(Request $request): Response
    {
        $validated = $request->validate([
            'stage' => ['nullable', Rule::in(self::STAGES)],
            'owner' => ['nullable', 'integer'],
            'company_id' => ['nullable', 'integer'],
            'min_score' => ['nullable', 'integer', 'between:0,100'],
            'q' => ['nullable', 'string', 'max:80'],
        ]);

        /*
         * The tenant boundary is applied to the QUERY, not tapped: tap()
         * discards the builder it is given, so the restriction would have
         * evaporated and every lead stayed visible.
         */
        $leads = LeadScope::restrict($request, DemandProfile::query())
            ->with(['owner:id,name', 'company:id,brand_name', 'user', 'area:id,name_ckb,name_ar,name_en', 'score' => fn ($q) => $q->latest('id')->limit(1)])
            ->when($validated['stage'] ?? null, fn ($q, $stage) => $q->inStage($stage))
            ->when($validated['owner'] ?? null, fn ($q, $owner) => $q->where('owner_user_id', $owner))
            /*
             * TENANT SCOPE FIRST, and it is not optional.
             *
             * The company filter was a convenience parameter, so a company
             * account manager holding leads.view saw every lead on the
             * platform — names, phone numbers and buying intent belonging to
             * competitors. The acting company now bounds the query, and the
             * filter can only narrow within it.
             */
            ->when($validated['company_id'] ?? null, fn ($q, $id) => $q->forCompany($id))
            ->when(
                $validated['min_score'] ?? null,
                fn ($q, $min) => $q->whereHas('score', fn ($s) => $s->where('total', '>=', $min))
            )
            /*
             * Search matches the objective and the AI summary, never a name or
             * a number. Allowing a phone-number search would turn this box into
             * a lookup tool for contact data the consent gate exists to protect.
             */
            ->when(
                $validated['q'] ?? null,
                fn ($q, $term) => $q->where(function ($inner) use ($term): void {
                    $inner->where('objective', 'like', '%'.$term.'%')
                        ->orWhere('ai_summary', 'like', '%'.$term.'%');
                })
            )
            ->latest('id')
            ->paginate(25)
            ->through(fn (DemandProfile $lead): array => $this->summarise($lead));

        return Inertia::render('Admin/Leads/Workspace', [
            'leads' => $leads,
            'stages' => self::STAGES,
            'outcomes' => LeadOutcome::values(),
            'filters' => $validated,
        ]);
    }

    public function show(Request $request, DemandProfile $lead): Response
    {
        LeadScope::authorise($request, $lead);

        $lead->load(['owner:id,name', 'company:id,brand_name', 'user', 'area:id,name_ckb,name_ar,name_en', 'notes.author:id,name', 'tasks.assignee:id,name']);

        return Inertia::render('Admin/Leads/Show', [
            'lead' => $this->summarise($lead),
            'notes' => $lead->notes
                ->sortByDesc('created_at')
                ->map(static fn (LeadNote $note): array => [
                    'id' => $note->id,
                    'body' => $note->body(),
                    'author' => $note->author?->name,
                    'stage_at_time' => $note->stage_at_time,
                    'created_at' => $note->created_at?->toDateTimeString(),
                ])
                ->values()
                ->all(),
            'tasks' => $lead->tasks
                ->sortBy('due_on')
                ->map(static fn (LeadTask $task): array => [
                    'id' => $task->id,
                    'title' => $task->title,
                    'kind' => $task->kind,
                    'due_on' => $task->due_on?->toDateString(),
                    'status' => $task->status,
                    'outcome' => $task->outcome?->value,
                    'assignee' => $task->assignee?->name,
                    'is_overdue' => $task->status === 'open'
                        && $task->due_on !== null
                        && $task->due_on->isPast(),
                ])
                ->values()
                ->all(),
            'stages' => self::STAGES,
            'outcomes' => LeadOutcome::values(),
            /*
             * The transcript, SANITIZED (spec §10): the words the person and
             * the assistant exchanged, and nothing of the machinery. Roles
             * other than those two never ship; prompt versions, providers,
             * models and validation fields exist on the row and are
             * deliberately not selected into this payload.
             */
            'transcript' => $lead->advisor_conversation_id === null ? [] : AdvisorMessage::query()
                ->where('advisor_conversation_id', $lead->advisor_conversation_id)
                ->whereIn('role', ['user', 'assistant'])
                ->orderBy('id')
                ->limit(200)
                ->get(['id', 'role', 'content', 'locale', 'created_at'])
                ->map(static fn ($message): array => [
                    'role' => $message->role,
                    'content' => $message->content,
                    'locale' => $message->locale,
                    'at' => $message->created_at?->toDateTimeString(),
                ])
                ->all(),
            'suggested_projects' => $this->suggestedProjects($lead),
            'suggested_projects_budget_basis' => $this->suggestedProjectsBudgetBasis($lead),
            'contact_consent' => $this->contactConsent($lead),
            'reveal_reasons' => array_map(
                static fn (RevealReason $reason): array => [
                    'value' => $reason->value,
                    'requires_note' => $reason->requiresNote(),
                ],
                RevealReason::cases(),
            ),
        ]);
    }

    /**
     * Reveal the user-provided phone number (spec §9).
     *
     * Everything that decides this lives in PhoneRevealService — permission,
     * mandatory reason, hourly rate, the consent gate, the ledger row and an
     * audit entry that NEVER contains the number. This action adds only the
     * transport concerns: tenant authorisation on the lead, and no-store
     * headers so the one legitimate copy of the number dies with the
     * response.
     */
    public function revealPhone(Request $request, DemandProfile $lead, PhoneRevealService $reveals): JsonResponse
    {
        LeadScope::authorise($request, $lead);

        $validated = $request->validate([
            'reason' => ['required', Rule::in(array_map(static fn (RevealReason $r): string => $r->value, RevealReason::cases()))],
            'note' => ['nullable', 'string', 'max:500'],
        ]);

        $result = $reveals->reveal(
            $request->user(),
            $lead,
            RevealReason::from($validated['reason']),
            $validated['note'] ?? null,
            $lead->company_id,
            $request->ip() === null ? null : hash('sha256', $request->ip()),
        );

        $status = $result['ok'] ? 200 : 422;

        return response()->json($result, $status, [
            'Cache-Control' => 'no-store, no-cache, must-revalidate',
            'Pragma' => 'no-cache',
        ]);
    }

    /**
     * Up to three published projects matching the STRUCTURED lead fields —
     * the same matcher the advisor itself uses, fed from the validated
     * columns rather than from anything the AI merely said.
     *
     * @return array<int, array<string, mixed>>
     */
    private function suggestedProjects(DemandProfile $lead): array
    {
        if ($lead->objective === null && $lead->property_type === null && $lead->budget_max === null) {
            return [];
        }

        /*
         * Correction v5, BLOCKER 10: the CURRENCY travels with the budget.
         *
         * The v4 matcher already refused to compare a budget whose
         * currency it did not know — and this call site then withheld the
         * currency the lead actually carried, so every Admin suggestion
         * for a valid USD or IQD lead silently ran budget-blind while
         * looking budget-aware. The lead's own structured `currency`
         * column (the one BLOCKER 3 made trustworthy) now rides along:
         * USD budgets compare only with USD prices, IQD only with IQD,
         * and a null or legacy currency performs no numeric comparison —
         * visibly, via `budget_basis` below, never silently.
         */
        $criteria = array_filter([
            'purpose' => $lead->objective,
            'budget' => $lead->budget_max === null ? null : (float) $lead->budget_max,
            'currency' => $lead->currency,
            'property_type' => $lead->property_type,
            'area' => $lead->area?->name_ckb,
        ], static fn ($value): bool => $value !== null);

        $matcher = app(AdvisorProjectMatcher::class);
        $result = $matcher->recommend($criteria, (string) ($lead->ai_summary_locale ?? 'ckb'));

        // The matcher exposes its project cards under `items` (the same key
        // every advisor consumer reads); `cards` never existed, so this list
        // was permanently empty (Phase 19).
        return array_slice(array_map(static fn (array $card): array => [
            'slug' => $card['slug'] ?? null,
            'name' => $card['name'] ?? null,
            'area' => $card['area'] ?? null,
            'price_label' => $card['price_label'] ?? null,
            'fit_label' => $card['fit_label'] ?? null,
            'budget_state' => $card['budget_state'] ?? 'not_set',
        ], $result['items'] ?? []), 0, 3);
    }

    /**
     * WHY the suggested-project budget comparison did or did not run —
     * shown beside the cards so a salesperson never mistakes a
     * budget-blind ranking for a budget-aware one (v5, BLOCKER 10).
     *
     *   compared          — a budget and a supported stated currency exist.
     *   skipped_legacy    — a budget exists but its 'USD' predates the
     *                       currency fix; an assumption, not an answer.
     *   skipped_unknown   — a budget exists with no usable currency.
     *   none              — the lead states no budget at all.
     */
    private function suggestedProjectsBudgetBasis(DemandProfile $lead): string
    {
        if ($lead->budget_max === null && $lead->budget_min === null) {
            return 'none';
        }

        if (AdvisorCurrency::storable($lead->currency) !== null && $lead->currency_source !== 'legacy') {
            return 'compared';
        }

        return $lead->currency_source === 'legacy' ? 'skipped_legacy' : 'skipped_unknown';
    }

    /** @return array{granted: bool, granted_at: string|null} */
    private function contactConsent(DemandProfile $lead): array
    {
        if ($lead->user_id === null) {
            return ['granted' => false, 'granted_at' => null];
        }

        $consent = Consent::query()
            ->where('user_id', $lead->user_id)
            ->where('type', 'company_contact')
            ->where('granted', true)
            ->whereNull('withdrawn_at')
            ->latest('granted_at')
            ->first();

        return [
            'granted' => $consent !== null,
            'granted_at' => $consent?->granted_at?->toDateTimeString(),
        ];
    }

    public function addNote(Request $request, DemandProfile $lead): RedirectResponse
    {
        LeadScope::authorise($request, $lead);

        $validated = $request->validate([
            'body' => ['required', 'string', 'max:4000'],
        ]);

        $note = new LeadNote;
        $note->fill([
            'demand_profile_id' => $lead->id,
            'author_user_id' => $request->user()?->id,
            'company_id' => $lead->company_id,
            // Recorded so a later reader knows where the lead stood when this
            // was written — the answer to "why did it move?".
            'stage_at_time' => $lead->stage,
        ]);
        $note->setBody($validated['body']);
        $note->save();

        // The note body is never in the audit context: it is the sensitive
        // part, and an audit log is read by more people than the workspace.
        $this->audit->record('lead.note_added', $lead, [], ['note_id' => $note->id]);

        return back()->with('success', __('leads.note_added'));
    }

    public function addTask(Request $request, DemandProfile $lead): RedirectResponse
    {
        LeadScope::authorise($request, $lead);

        /**
         * Declaring the key type makes the analyser check every validated key
         * against LeadTask's real columns, so a rule added for a field the model
         * does not have fails here rather than silently never persisting.
         *
         * @var array<model-property<LeadTask>, mixed> $validated
         */
        $validated = $request->validate([
            'title' => ['required', 'string', 'max:160'],
            'kind' => ['required', 'string', 'max:32'],
            'due_on' => ['nullable', 'date'],
            /*
             * `exists:users,id` accepted ANY user on the platform, so a
             * company could assign a task to a rival's staff — and the
             * assignee would then see the lead. The rule below restricts the
             * set; the closure after validation confirms it, because a rule
             * built from a request-time query is only as good as the context
             * it was built with.
             */
            'assigned_to_user_id' => [
                'nullable', 'integer',
                Rule::exists('users', 'id')->where(
                    fn ($query) => $this->assignableUserIds($request) === null
                        ? $query
                        : $query->whereIn('id', $this->assignableUserIds($request)),
                ),
            ],
        ]);

        $task = LeadTask::query()->create($validated + [
            'demand_profile_id' => $lead->id,
            'created_by_user_id' => $request->user()?->id,
            'company_id' => $lead->company_id,
            'status' => 'open',
        ]);

        $this->audit->record('lead.task_created', $lead, [], ['task_id' => $task->id]);

        return back()->with('success', __('leads.task_created'));
    }

    /**
     * Complete a task with its outcome.
     *
     * The outcome is required, because a task closed without one tells a
     * manager reviewing a lost deal precisely nothing — which is the moment the
     * record exists for.
     */
    public function completeTask(Request $request, DemandProfile $lead, LeadTask $task): RedirectResponse
    {
        LeadScope::authorise($request, $lead);

        abort_unless($task->demand_profile_id === $lead->id, 404);

        $validated = $request->validate([
            'outcome' => ['required', Rule::in(LeadOutcome::values())],
            'notes' => ['nullable', 'string', 'max:2000'],
        ]);

        $outcome = LeadOutcome::from($validated['outcome']);
        $task->complete($outcome, $validated['notes'] ?? null);

        /*
         * A terminal outcome closes the lead's remaining open tasks. Continuing
         * to chase somebody who said no is a poor experience, and where consent
         * was the basis for contacting them at all, a compliance problem.
         */
        if ($outcome->isTerminal()) {
            LeadTask::query()
                ->where('demand_profile_id', $lead->id)
                ->where('id', '!=', $task->id)
                ->open()
                ->update(['status' => 'cancelled']);
        }

        $this->audit->record('lead.task_completed', $lead, [], [
            'task_id' => $task->id,
            'outcome' => $outcome->value,
        ]);

        return back()->with('success', __('leads.task_completed'));
    }

    public function moveStage(Request $request, DemandProfile $lead): RedirectResponse
    {
        LeadScope::authorise($request, $lead);

        $validated = $request->validate([
            'stage' => ['required', Rule::in(self::STAGES)],
        ]);

        $from = $lead->stage;
        $lead->forceFill(['stage' => $validated['stage']])->save();

        $this->audit->record('lead.stage_changed', $lead, [], [
            'from' => $from,
            'to' => $validated['stage'],
        ]);

        return back()->with('success', __('leads.stage_changed'));
    }

    /**
     * The lead as the workspace sees it.
     *
     * §9 Leads 5 is enforced here by what is ABSENT. There is no phone, no
     * email, no masked contact and no "reveal available" hint — revealing is a
     * separate audited act, and a payload that carried even a masked number
     * would already have put it in a response body and a browser cache.
     *
     * @return array<string, mixed>
     */
    /**
     * Who this requester may assign work to.
     *
     * Null means "anybody" — a platform sales role coordinating across
     * companies. Otherwise: active staff of the acting company only.
     *
     * A rejected id fails as an ordinary validation error rather than "that
     * user belongs to another company", which would confirm the existence and
     * employer of somebody the requester cannot otherwise see.
     *
     * @return list<int>|null
     */
    private function assignableUserIds(Request $request): ?array
    {
        $user = $request->user();

        // Platform sales coordinates across companies by design.
        if (($user?->hasPermission('leads.assign') ?? false)
            && ActingCompanyContext::isPlatformMode($request)) {
            return null;
        }

        $companyId = ActingCompanyContext::current($request);

        if ($companyId === null) {
            // Fail closed: no valid context, no assignable colleagues.
            return [];
        }

        return CompanyStaff::query()
            ->where('company_id', $companyId)
            ->where('is_active', true)
            ->pluck('user_id')
            ->map(static fn ($id): int => (int) $id)
            ->all();
    }

    /**
     * @return array<string, mixed> one lead row for the workspace table
     */
    private function summarise(DemandProfile $lead): array
    {
        $score = $lead->score->first();

        return [
            'id' => $lead->id,
            'stage' => $lead->stage,
            'objective' => $lead->objective,
            'property_type' => $lead->property_type,
            'budget_min' => $lead->budget_min === null ? null : (string) $lead->budget_min,
            'budget_max' => $lead->budget_max === null ? null : (string) $lead->budget_max,
            /*
             * v4 BLOCKER 3: the REAL stored currency, plus how it got
             * there. A salesperson looking at a budget needs to know
             * whether "USD" is something the buyer said or something the
             * old code assumed — those two rows warrant very different
             * phone calls.
             */
            'currency' => $lead->currency,
            'currency_source' => $lead->currency_source,
            'timeframe' => $lead->timeframe,
            'source' => $lead->source,
            'owner' => $lead->owner?->name,
            'company' => $lead->company?->brand_name,
            'created_at' => $lead->created_at?->toDateString(),
            'updated_at' => $lead->updated_at?->toDateTimeString(),
            'area' => $lead->area?->name_ckb,

            /*
             * The account behind the lead, at the honesty level spec §0
             * demands: the Telegram link is a verified fact, the phone is
             * PRESENT or ABSENT and always `user_provided` — this payload has
             * no way to spell "verified phone", so no template can claim one.
             */
            'lead_user' => $lead->user === null ? null : [
                'name' => $lead->user->display_name ?? $lead->user->name,
                'thumb' => $lead->user->profilePhotoUrl(64),
                'initials' => $lead->user->initials(),
                'telegram_linked' => $lead->user->telegram_verified_at !== null,
                'phone_present' => $lead->user->phone_index !== null,
                'phone_status' => 'user_provided',
            ],
            'admin_summary' => $this->adminSummary($lead),

            'score' => $score === null ? null : [
                'total' => $score->total,
                // §9 requires the REASONS, not just the number. "82" is not
                // actionable; "budget stated, short timeframe, three repeat
                // views" tells a salesperson how to open the call.
                'reasons' => $score->reasonList(),
                'is_overridden' => (bool) $score->is_overridden,
                'calculated_total' => $score->calculated_total,
            ],
        ];
    }

    /**
     * The one-line administrator summary (spec §7.2), assembled ONLY from
     * validated columns and rendered through translations in the admin's own
     * locale. The phone fragment has exactly two spellings — "user-provided
     * phone number" and "no phone on record" — so this sentence cannot call
     * a phone verified, because no verified variant exists to reach.
     */
    private function adminSummary(DemandProfile $lead): string
    {
        $parts = [];

        $parts[] = $lead->user === null
            ? __('leads.summary.no_account')
            : ($lead->user->display_name ?? $lead->user->name);

        if ($lead->user !== null) {
            $parts[] = $lead->user->telegram_verified_at !== null
                ? __('leads.summary.telegram_linked')
                : __('leads.summary.telegram_not_linked');

            $parts[] = $lead->user->phone_index !== null
                ? __('leads.summary.phone_user_provided')
                : __('leads.summary.phone_absent');
        }

        $wants = [];

        if ($lead->property_type !== null) {
            $wants[] = __('leads.summary.type_'.$lead->property_type);
        }

        if ($lead->objective !== null) {
            $wants[] = __('leads.summary.objective_'.$lead->objective);
        }

        if ($lead->budget_max !== null) {
            /*
             * v4 BLOCKER 3: a budget with no known unit is rendered as
             * exactly that. Printing the amount beside an assumed 'USD'
             * is what produced two-hundred-million-dollar dinar buyers.
             */
            $wants[] = $lead->currency === null
                ? __('leads.summary.budget_unknown_currency', [
                    'amount' => number_format((float) $lead->budget_max),
                ])
                : __('leads.summary.budget', [
                    'currency' => $lead->currency,
                    'amount' => number_format((float) $lead->budget_max),
                ]);
        }

        if ($lead->area !== null) {
            $wants[] = __('leads.summary.area', ['area' => $lead->area->name_ckb]);
        }

        if ($wants !== []) {
            $parts[] = implode(__('leads.summary.joiner'), $wants);
        }

        return implode(' — ', $parts);
    }
}
