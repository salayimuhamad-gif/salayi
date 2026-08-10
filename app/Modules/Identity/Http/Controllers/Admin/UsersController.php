<?php

declare(strict_types=1);

namespace App\Modules\Identity\Http\Controllers\Admin;

use App\Modules\Identity\Http\Middleware\TouchLastSeen;
use App\Modules\Identity\Models\Consent;
use App\Modules\Identity\Models\User;
use App\Modules\Identity\Services\TelegramPasswordRecovery;
use App\Modules\Leads\Enums\RevealReason;
use App\Modules\Leads\Models\DemandProfile;
use App\Modules\Leads\Services\PhoneRevealService;
use App\Modules\Operations\Services\AuditLogger;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Administrator user accounts (spec §8).
 *
 * The honesty rule from §0 is structural in every payload this controller
 * builds: a phone is PRESENT or ABSENT, and when present its status is the
 * string `user_provided` — there is no field and no code path that could
 * render "verified phone", so no template can claim one. The number itself
 * never appears in a list, a detail page, or an Inertia prop; the ONLY way
 * out is the audited reveal, which demands its own capability, a reason,
 * consent, and a rate.
 *
 * Ordinary members only. Accounts holding platform roles are administered
 * through the roles machinery, and mixing them into this list would put
 * suspend buttons next to the operators' own accounts.
 */
final class UsersController extends Controller
{
    public function __construct(
        private readonly PhoneRevealService $reveals,
        private readonly AuditLogger $audit,
    ) {}

    /**
     * The one filter vocabulary, shared verbatim by the list and the export
     * so "Export Sheet" can never mean anything other than "what I am
     * looking at".
     *
     * @return array<string, mixed>
     */
    private function validateFilters(Request $request): array
    {
        return $request->validate([
            'q' => ['nullable', 'string', 'max:80'],
            'status' => ['nullable', Rule::in(['active', 'suspended', 'unlinked'])],
            'locale' => ['nullable', Rule::in(['ckb', 'ar', 'en'])],
            'registered_from' => ['nullable', 'date'],
            'registered_to' => ['nullable', 'date'],
            // Presence windows, defined by last_seen_at (see TouchLastSeen):
            // "online" is the throttle interval, the rest are calendar-ish.
            'active' => ['nullable', Rule::in(['online', 'today', 'week', 'month'])],
            'sort' => ['nullable', Rule::in(['newest', 'oldest', 'recent_activity'])],
            /*
             * The request-side filters. Objective, property type and stage
             * match against the LATEST advisor request — the one the row
             * displays — so a filtered screen never shows a value the filter
             * did not select.
             */
            'has_request' => ['nullable', Rule::in(['1', '0'])],
            'objective' => ['nullable', Rule::in(['investment', 'residence'])],
            'property_type' => ['nullable', Rule::in(['apartment', 'villa', 'house'])],
            'stage' => ['nullable', Rule::in(DemandProfile::STAGES)],
        ]);
    }

    /**
     * @param  array<string, mixed>  $validated
     * @return Builder<User>
     */
    private function workspaceQuery(array $validated)
    {
        return User::query()
            ->whereDoesntHave('roles')
            ->withCount([
                'demandProfiles as advisor_request_count' => fn ($q) => $q->where('source', 'advisor'),
                'portfolioProperties as portfolio_count',
            ])
            // The newest advisor request and the consent bit, both as single
            // set-based loads — a page of users costs a constant number of
            // queries however long it gets.
            ->with('latestAdvisorRequest')
            ->withExists(['consents as contact_consent' => fn ($q) => $q
                ->where('type', 'company_contact')
                ->where('granted', true)
                ->whereNull('withdrawn_at')])
            /*
             * Search matches names only. The phone is deliberately not
             * searchable: a number box on this screen would be a lookup tool
             * for exactly the data the reveal ceremony exists to protect —
             * and matching against a blind index would require normalising
             * attacker-typed input into probe queries.
             */
            ->when($validated['q'] ?? null, fn ($q, $term) => $q->where(function ($inner) use ($term): void {
                $inner->where('name', 'like', '%'.$term.'%')
                    ->orWhere('display_name', 'like', '%'.$term.'%');
            }))
            ->when(($validated['status'] ?? null) === 'active', fn ($q) => $q->whereNull('suspended_at'))
            ->when(($validated['status'] ?? null) === 'suspended', fn ($q) => $q->whereNotNull('suspended_at'))
            ->when(($validated['status'] ?? null) === 'unlinked', fn ($q) => $q->whereNull('telegram_verified_at'))
            ->when($validated['locale'] ?? null, fn ($q, $locale) => $q->where('preferred_locale', $locale))
            ->when($validated['registered_from'] ?? null, fn ($q, $from) => $q->whereDate('created_at', '>=', $from))
            ->when($validated['registered_to'] ?? null, fn ($q, $to) => $q->whereDate('created_at', '<=', $to))
            ->when($validated['active'] ?? null, fn ($q, $window) => $q->where(
                'last_seen_at',
                '>=',
                match ($window) {
                    'online' => now()->subSeconds(TouchLastSeen::INTERVAL_SECONDS),
                    'today' => now()->startOfDay(),
                    'week' => now()->subDays(7),
                    default => now()->subDays(30),
                },
            ))
            ->when(($validated['has_request'] ?? null) === '1', fn ($q) => $q
                ->whereHas('demandProfiles', fn ($r) => $r->where('source', 'advisor')))
            ->when(($validated['has_request'] ?? null) === '0', fn ($q) => $q
                ->whereDoesntHave('demandProfiles', fn ($r) => $r->where('source', 'advisor')))
            ->when($validated['objective'] ?? null, fn ($q, $objective) => $q
                ->whereHas('latestAdvisorRequest', fn ($r) => $r->where('objective', $objective)))
            ->when($validated['property_type'] ?? null, fn ($q, $type) => $q
                ->whereHas('latestAdvisorRequest', fn ($r) => $r->where('property_type', $type)))
            ->when($validated['stage'] ?? null, fn ($q, $stage) => $q
                ->whereHas('latestAdvisorRequest', fn ($r) => $r->where('stage', $stage)))
            ->when(
                ($validated['sort'] ?? 'newest') === 'recent_activity',
                fn ($q) => $q->orderByDesc('last_seen_at'),
                fn ($q) => ($validated['sort'] ?? 'newest') === 'oldest' ? $q->oldest('id') : $q->latest('id'),
            );
    }

    public function index(Request $request): Response
    {
        $validated = $this->validateFilters($request);

        $users = $this->workspaceQuery($validated)
            ->paginate(25)
            ->withQueryString()
            ->through(fn (User $user): array => $this->row($user));

        return Inertia::render('Admin/Users/Index', [
            'users' => $users,
            'filters' => $validated,
            'stages' => DemandProfile::STAGES,
            // The actor's own doors, so the page offers only what the server
            // will honour. The reveal stays target-scoped: the button renders
            // per row only where consent exists, and the endpoint re-checks
            // everything regardless.
            'can_reveal' => $request->user()->hasPermission('identity.users.contact'),
            'can_export' => $request->user()->hasPermission('identity.users.export'),
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
     * Export Sheet: the CURRENT filtered result set, as CSV.
     *
     * Behind its own permission — walking away with the whole population is
     * a different power from paging through it — and audited with the exact
     * filters used. The sheet carries NO phone numbers and no security
     * fields: the phone column is availability status only, because bulk
     * export would turn the per-target, consent-bound, reasoned, rate-limited
     * reveal ceremony into a loophole. Excel-friendly: UTF-8 BOM so Kurdish
     * and Arabic render, CRLF rows, and every cell defused against formula
     * injection.
     */
    public function export(Request $request): StreamedResponse
    {
        $validated = $this->validateFilters($request);
        $query = $this->workspaceQuery($validated);

        $this->audit->record('identity.users.exported', null, [], [
            'actor_id' => $request->user()->id,
            'filters' => array_filter($validated, static fn ($v): bool => $v !== null && $v !== ''),
            'rows' => (clone $query)->reorder()->count(),
        ], severity: 'warning');

        $filename = 'myhawler-users-'.now()->format('Ymd-His').'.csv';

        return response()->streamDownload(function () use ($query): void {
            $out = fopen('php://output', 'w');

            // The BOM is what makes Excel read the sheet as UTF-8 instead of
            // mojibake-ing every Kurdish and Arabic name.
            fwrite($out, "\xEF\xBB\xBF");

            $this->csvRow($out, [
                'name', 'preferred_language', 'account_status', 'registered_at',
                'last_login_at', 'last_seen_at', 'online', 'telegram_linked',
                'telegram_linked_at', 'phone_status', 'request_count',
                'latest_request_objective', 'latest_request_property_type',
                'latest_request_stage', 'latest_request_updated_at',
                'portfolio_count',
            ]);

            // chunkById for constant memory; it orders by id, so the sheet is
            // the filtered SET — Excel re-sorts columns better than we do.
            (clone $query)->reorder()->chunkById(500, function ($users) use ($out): void {
                foreach ($users as $user) {
                    $latest = $user->latestAdvisorRequest;

                    $this->csvRow($out, [
                        $user->display_name ?? $user->name,
                        $user->preferred_locale,
                        $user->suspended_at !== null ? 'suspended' : 'active',
                        $user->created_at?->toDateString(),
                        $user->last_login_at?->toDateTimeString(),
                        $user->last_seen_at?->toDateTimeString(),
                        $user->last_seen_at !== null
                            && $user->last_seen_at->gte(now()->subSeconds(TouchLastSeen::INTERVAL_SECONDS))
                            ? 'online' : 'offline',
                        $user->telegram_verified_at !== null ? 'linked' : 'not_linked',
                        $user->telegram_verified_at?->toDateString(),
                        // Availability, never a number. The reveal ceremony is
                        // the single door and it opens one account at a time.
                        $user->phone_index === null
                            ? 'none'
                            : ($user->contact_consent ? 'available_reveal_permitted' : 'available_reveal_not_permitted'),
                        (int) $user->advisor_request_count,
                        $latest?->objective,
                        $latest?->property_type,
                        $latest?->stage,
                        $latest?->updated_at?->toDateTimeString(),
                        (int) $user->portfolio_count,
                    ]);
                }
            });

            fclose($out);
        }, $filename, [
            'Content-Type' => 'text/csv; charset=UTF-8',
            'Cache-Control' => 'no-store, no-cache, must-revalidate',
        ]);
    }

    /**
     * One CSV row, CRLF-terminated, every cell defused.
     *
     * A cell beginning with = + - @ or a control character is executed as a
     * formula by Excel and LibreOffice; a user whose display name is
     * `=HYPERLINK(...)` must land in a spreadsheet as text. The apostrophe
     * prefix is the standard neutralisation both suites honour.
     *
     * @param  resource  $out
     * @param  list<string|int|float|null>  $cells
     */
    private function csvRow($out, array $cells): void
    {
        fputcsv($out, array_map(static function ($cell): string {
            $value = (string) ($cell ?? '');

            if ($value !== '' && (str_contains('=+-@', $value[0]) || $value[0] === "\t" || $value[0] === "\r")) {
                $value = "'".$value;
            }

            return $value;
        }, $cells), eol: "\r\n");
    }

    public function show(Request $request, User $user): Response
    {
        abort_if($user->roles()->exists(), 404);

        $user->loadCount([
            'demandProfiles as advisor_request_count' => fn ($q) => $q->where('source', 'advisor'),
            'portfolioProperties as portfolio_count',
        ])->load('latestAdvisorRequest');

        $consent = Consent::query()
            ->where('user_id', $user->id)
            ->where('type', 'company_contact')
            ->where('granted', true)
            ->whereNull('withdrawn_at')
            ->latest('granted_at')
            ->first();

        return Inertia::render('Admin/Users/Show', [
            'account' => $this->row($user) + [
                'profile_bio' => $user->profile_bio,
                'contact_preference' => $user->contact_preference,
                'primary_purpose' => $user->primary_purpose,
                'suspended_reason' => $user->suspended_reason,
                'onboarding_completed_at' => $user->onboarding_completed_at?->toDateTimeString(),
            ],
            'contact_consent' => [
                'granted' => $consent !== null,
                'granted_at' => $consent?->granted_at?->toDateTimeString(),
            ],
            /*
             * The §8 timeline from the columns that actually record it —
             * registered, linked, onboarded, last seen — in event order.
             */
            'timeline' => array_values(array_filter([
                ['event' => 'registered', 'at' => $user->created_at?->toDateTimeString()],
                ['event' => 'telegram_linked', 'at' => $user->telegram_verified_at?->toDateTimeString()],
                ['event' => 'onboarded', 'at' => $user->onboarding_completed_at?->toDateTimeString()],
                ['event' => 'last_login', 'at' => $user->last_login_at?->toDateTimeString()],
                $user->suspended_at === null ? null
                    : ['event' => 'suspended', 'at' => $user->suspended_at->toDateTimeString()],
            ], static fn (?array $row): bool => $row !== null && $row['at'] !== null)),
            'requests' => $user->demandProfiles()
                ->where('source', 'advisor')
                ->latest('updated_at')
                ->limit(10)
                ->get()
                ->map(static fn ($lead): array => [
                    'id' => $lead->id,
                    'stage' => $lead->stage,
                    'objective' => $lead->objective,
                    'property_type' => $lead->property_type,
                    'updated_at' => $lead->updated_at?->toDateString(),
                ])
                ->all(),
            'can_manage' => $request->user()->hasPermission('identity.users.suspend'),
            'can_reveal' => $request->user()->hasPermission('identity.users.contact'),
            'can_revoke_sessions' => $request->user()->hasPermission('identity.sessions.revoke'),
            'can_trigger_recovery' => $request->user()->hasPermission('identity.users.update'),
            // Promotion to operator is a RANK, not a permission: granting
            // roles widens effective permissions, so only a real Super Admin
            // is offered the door. The server enforces the same rule with a
            // 403 and a security audit record.
            'can_assign_roles' => $request->user()->isSuperAdmin(),
            'assignable_roles' => AdministratorsController::assignableRoleKeys(),
            'reveal_reasons' => array_map(
                static fn (RevealReason $reason): array => [
                    'value' => $reason->value,
                    'requires_note' => $reason->requiresNote(),
                ],
                RevealReason::cases(),
            ),
        ]);
    }

    public function suspend(Request $request, User $user): RedirectResponse
    {
        $validated = $request->validate([
            'reason' => ['required', 'string', 'min:3', 'max:500'],
        ]);

        /*
         * Two refusals, ORDER DELIBERATE. Operators are not on this surface
         * at all — a role-holding target 404s exactly like any other absent
         * id, which in this build also covers every actor aiming at themself
         * (reaching this route requires a role). The 422 self-guard behind it
         * is belt-and-braces for a future where permissions stop implying a
         * role row; it must never be the FIRST check, because answering 422
         * for an operator id would confirm the account exists here.
         */
        abort_if($user->roles()->exists(), 404);
        abort_if($user->id === $request->user()->id, 422);

        $user->forceFill([
            'suspended_at' => now(),
            'suspended_reason' => $validated['reason'],
        ])->save();

        $this->audit->record('identity.user_suspended', $user, [], [
            'actor_id' => $request->user()->id,
            'reason' => $validated['reason'],
        ], severity: 'warning');

        return back()->with('status', 'user-suspended');
    }

    public function reactivate(Request $request, User $user): RedirectResponse
    {
        abort_if($user->roles()->exists(), 404);

        $user->forceFill([
            'suspended_at' => null,
            'suspended_reason' => null,
        ])->save();

        $this->audit->record('identity.user_reactivated', $user, [], [
            'actor_id' => $request->user()->id,
        ]);

        return back()->with('status', 'user-reactivated');
    }

    /**
     * The reveal, from the accounts surface: the same audited ceremony as the
     * sales workspace, behind this surface's own capability.
     */
    /**
     * End every session the account holds, now.
     *
     * The remember token is rotated in the same breath: a forced logout that
     * leaves remember-me cookies alive is a logout in name only. Behind its
     * own permission — `identity.sessions.revoke` sat in the registry with no
     * route enforcing it until this action gave it one.
     */
    public function forceLogout(Request $request, User $user): RedirectResponse
    {
        abort_if($user->roles()->exists(), 404);

        DB::table('sessions')->where('user_id', $user->getKey())->delete();
        $user->forceFill(['remember_token' => Str::random(60)])->save();

        $this->audit->record('identity.user_sessions_revoked', $user, [], [], severity: 'warning');

        return back()->with('success', __('identity.users.sessions_revoked'));
    }

    /**
     * Send the account a password-recovery link — to ITS OWN Telegram chat.
     *
     * The admin triggers the process and never sees the credential: the
     * challenge goes to the chat the account verified with, carries the same
     * TTL, single use and identity binding as a self-service request, and
     * ends in a password only the account holder chooses.
     */
    public function sendRecovery(Request $request, User $user, TelegramPasswordRecovery $recovery): RedirectResponse
    {
        abort_if($user->roles()->exists(), 404);

        $sent = $recovery->requestForUser($user, $user->preferred_locale ?? 'ckb');

        if (! $sent) {
            return back()->withErrors(['recovery' => __('identity.users.recovery_unavailable')]);
        }

        $this->audit->record('identity.password_recovery_triggered_by_admin', $user, [], [], severity: 'warning');

        return back()->with('success', __('identity.users.recovery_sent'));
    }

    public function revealPhone(Request $request, User $user): JsonResponse
    {
        abort_if($user->roles()->exists(), 404);

        $validated = $request->validate([
            'reason' => ['required', Rule::in(array_map(static fn (RevealReason $r): string => $r->value, RevealReason::cases()))],
            'note' => ['nullable', 'string', 'max:500'],
        ]);

        $result = $this->reveals->reveal(
            $request->user(),
            $user,
            RevealReason::from($validated['reason']),
            $validated['note'] ?? null,
            null,
            $request->ip() === null ? null : hash('sha256', $request->ip()),
            permission: 'identity.users.contact',
        );

        return response()->json($result, $result['ok'] ? 200 : 422, [
            'Cache-Control' => 'no-store, no-cache, must-revalidate',
            'Pragma' => 'no-cache',
        ]);
    }

    /**
     * One account, at the honesty level the spec demands.
     *
     * @return array<string, mixed>
     */
    private function row(User $user): array
    {
        return [
            'id' => $user->id,
            'name' => $user->name,
            'display_name' => $user->display_name,
            'thumb' => $user->profilePhotoUrl(64),
            'photo' => $user->profilePhotoUrl(256),
            'initials' => $user->initials(),
            'preferred_locale' => $user->preferred_locale,
            'is_suspended' => $user->suspended_at !== null,
            'telegram_linked' => $user->telegram_verified_at !== null,
            'telegram_linked_at' => $user->telegram_verified_at?->toDateString(),
            'phone_present' => $user->phone_index !== null,
            'phone_status' => 'user_provided',
            'registered_at' => $user->created_at?->toDateString(),
            'last_login_at' => $user->last_login_at?->toDateString(),
            'last_seen_at' => $user->last_seen_at?->diffForHumans(),
            'online' => $user->last_seen_at !== null
                && $user->last_seen_at->gte(now()->subSeconds(TouchLastSeen::INTERVAL_SECONDS)),
            'advisor_request_count' => (int) ($user->advisor_request_count ?? 0),
            'portfolio_count' => (int) ($user->portfolio_count ?? 0),
            /*
             * The consent BIT, never the consent record: with the actor's
             * identity.users.contact permission it decides whether the row
             * offers the reveal ceremony. The server re-checks permission,
             * consent, reason and rate on every reveal regardless.
             */
            'contact_consent' => (bool) ($user->contact_consent ?? false),
            'latest_request' => $user->latestAdvisorRequest === null ? null : [
                'objective' => $user->latestAdvisorRequest->objective,
                'property_type' => $user->latestAdvisorRequest->property_type,
                'stage' => $user->latestAdvisorRequest->stage,
                'updated_at' => $user->latestAdvisorRequest->updated_at?->toDateString(),
            ],
        ];
    }
}
