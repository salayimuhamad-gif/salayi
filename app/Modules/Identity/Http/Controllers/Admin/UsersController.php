<?php

declare(strict_types=1);

namespace App\Modules\Identity\Http\Controllers\Admin;

use App\Modules\Identity\Http\Middleware\TouchLastSeen;
use App\Modules\Identity\Models\Consent;
use App\Modules\Identity\Models\User;
use App\Modules\Identity\Services\TelegramPasswordRecovery;
use App\Modules\Leads\Enums\RevealReason;
use App\Modules\Leads\Services\PhoneRevealService;
use App\Modules\Operations\Services\AuditLogger;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

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

    public function index(Request $request): Response
    {
        $validated = $request->validate([
            'q' => ['nullable', 'string', 'max:80'],
            'status' => ['nullable', Rule::in(['active', 'suspended', 'unlinked'])],
            'locale' => ['nullable', Rule::in(['ckb', 'ar', 'en'])],
            'registered_from' => ['nullable', 'date'],
            'registered_to' => ['nullable', 'date'],
            // Presence windows, defined by last_seen_at (see TouchLastSeen):
            // "online" is the throttle interval, the rest are calendar-ish.
            'active' => ['nullable', Rule::in(['online', 'today', 'week', 'month'])],
            'sort' => ['nullable', Rule::in(['newest', 'oldest', 'recent_activity'])],
        ]);

        $users = User::query()
            ->whereDoesntHave('roles')
            ->withCount([
                'demandProfiles as advisor_request_count' => fn ($q) => $q->where('source', 'advisor'),
                'portfolioProperties as portfolio_count',
            ])
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
            ->when(
                ($validated['sort'] ?? 'newest') === 'recent_activity',
                fn ($q) => $q->orderByDesc('last_seen_at'),
                fn ($q) => ($validated['sort'] ?? 'newest') === 'oldest' ? $q->oldest('id') : $q->latest('id'),
            )
            ->paginate(25)
            ->withQueryString()
            ->through(fn (User $user): array => $this->row($user));

        return Inertia::render('Admin/Users/Index', [
            'users' => $users,
            'filters' => $validated,
        ]);
    }

    public function show(Request $request, User $user): Response
    {
        abort_if($user->roles()->exists(), 404);

        $user->loadCount([
            'demandProfiles as advisor_request_count' => fn ($q) => $q->where('source', 'advisor'),
            'portfolioProperties as portfolio_count',
        ]);

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
        ];
    }
}
