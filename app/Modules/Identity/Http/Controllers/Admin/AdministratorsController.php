<?php

declare(strict_types=1);

namespace App\Modules\Identity\Http\Controllers\Admin;

use App\Modules\Identity\Enums\RoleKey;
use App\Modules\Identity\Http\Middleware\TouchLastSeen;
use App\Modules\Identity\Models\Role;
use App\Modules\Identity\Models\User;
use App\Modules\Operations\Services\AuditLogger;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

/**
 * The roles machinery the accounts surface defers to.
 *
 * `UsersController` administers ordinary members and 404s every role-holding
 * target, with the comment that operators "are administered through the roles
 * machinery" — which did not exist. `identity.roles.view` and
 * `identity.roles.assign` sat in the registry authorising nothing, and a
 * Super Admin had no page anywhere that could list operators, show who holds
 * which role, or change an assignment. This controller is that machinery.
 *
 * The two surfaces partition the user base: members (no role rows) live on
 * the accounts surface with its phone-privacy ceremony; operators (any role
 * row) live here, where there is deliberately NO phone data at all — an
 * operator's reachable identity is their name and their roles.
 *
 * Everything here is authorised by the fine-grained permissions the registry
 * already declares, so Super Admin arrives through the same door as a System
 * Admin — `Gate::before` and `hasPermission()` stay the single source of
 * truth, and nothing checks a user id.
 */
final class AdministratorsController extends Controller
{
    public function __construct(private readonly AuditLogger $audit) {}

    public function index(Request $request): Response
    {
        $administrators = User::query()
            ->whereHas('roles')
            ->with('roles:id,key')
            ->orderBy('name')
            ->paginate(50)
            ->withQueryString()
            ->through(fn (User $user): array => $this->row($user));

        return Inertia::render('Admin/Administrators/Index', [
            'administrators' => $administrators,
            'assignable_roles' => self::assignableRoleKeys(),
            'active_super_admins' => self::activeSuperAdminCount(),
            'can' => [
                /*
                 * Role MUTATION is a rank, not a permission: a System Admin
                 * holds identity.roles.assign and must still never be offered
                 * the editor, because granting roles — to a colleague or to
                 * themselves — widens effective permissions one grant at a
                 * time. The server enforces the same rule with a 403.
                 */
                'assign_roles' => $request->user()->isSuperAdmin(),
                'suspend' => $request->user()->hasPermission('identity.users.suspend'),
                'revoke_sessions' => $request->user()->hasPermission('identity.sessions.revoke'),
                // Whether rows holding super_admin may be acted on AT ALL:
                // suspend, reactivate and force-logout are rank-guarded on
                // super-admin targets exactly like the roles editor.
                'act_on_super_admin' => $request->user()->isSuperAdmin(),
                'grant_super_admin' => $request->user()->isSuperAdmin(),
            ],
        ]);
    }

    /**
     * Replace the target's ADMINISTRATIVE roles with the submitted set.
     *
     * Public account roles (member/company_owner/company_staff/broker) are
     * deliberately out of scope on both sides: they cannot be submitted, and
     * any the target already holds survive the sync untouched — those are
     * assigned by the company-membership machinery, not by this surface.
     */
    public function updateRoles(Request $request, User $user): RedirectResponse
    {
        $validated = $request->validate([
            'roles' => ['present', 'array'],
            'roles.*' => ['string', Rule::in(self::assignableRoleKeys())],
        ]);

        /** @var list<string> $desired */
        $desired = array_values(array_unique($validated['roles']));
        $currentAdministrative = $user->roles()->pluck('key')
            ->filter(static fn (string $key): bool => RoleKey::tryFrom($key)?->isAdministrative() === true)
            ->sort()->values()->all();

        $actor = $request->user();

        /*
         * Escalation boundary: administrative role MUTATION requires a real
         * Super Admin, full stop. `identity.roles.assign` alone is not
         * enough — a System Admin holds it, and a System Admin who can hand
         * out Product Owner or Market Data Manager roles (to a colleague or
         * to THEMSELVES) can widen their own effective permissions one grant
         * at a time. Reading the role map stays a permission; changing it is
         * a rank.
         */
        if (! $actor->isSuperAdmin()) {
            $this->audit->security('identity.roles.escalation_denied', [
                'actor_id' => $actor->id,
                'target_id' => $user->id,
                'requested' => $desired,
            ]);

            abort(403);
        }

        /*
         * Lockout guard. Removing super_admin from the last active, unsuspended
         * holder would leave the installation with no account that can reach
         * this page — an administration lockout with no self-service recovery.
         */
        $losesSuperAdmin = in_array(RoleKey::SuperAdmin->value, $currentAdministrative, true)
            && ! in_array(RoleKey::SuperAdmin->value, $desired, true);

        if ($losesSuperAdmin && self::activeSuperAdminCount(except: $user->id) === 0) {
            return back()->withErrors(['roles' => __('identity.administrators.last_super_admin')]);
        }

        /*
         * The REGISTRY defines the roles; the table follows it. Looking rows
         * up with whereIn silently dropped any grant whose row the seeder had
         * not created yet — a fresh installation, or a role added after the
         * last deploy's seed, would "save" and change nothing.
         */
        $roleIds = array_map(
            static fn (string $key): int => Role::query()->firstOrCreate(
                ['key' => $key],
                ['name' => str_replace('_', ' ', ucwords($key, '_')), 'is_system' => true],
            )->id,
            $desired,
        );
        $retained = $user->roles()->get()
            ->filter(static fn (Role $role): bool => ! $role->isAdministrative())
            ->pluck('id')->all();

        $user->roles()->sync(array_merge($roleIds, $retained));

        $this->audit->record('identity.roles.updated', $user, [
            'roles' => ['from' => $currentAdministrative, 'to' => $desired],
        ], [
            'actor_id' => $actor->id,
        ], severity: 'warning');

        /*
         * To the index, not back(): a member promoted from their accounts
         * page now 404s there by the surface contract, and the operators
         * list is where the result of this action is visible either way.
         */
        return redirect()->route('admin.administrators.index')
            ->with('success', __('identity.administrators.roles_saved'));
    }

    public function suspend(Request $request, User $user): RedirectResponse
    {
        $validated = $request->validate([
            'reason' => ['required', 'string', 'min:3', 'max:500'],
        ]);

        // The mirror of the accounts surface: members 404 here exactly as
        // operators 404 there, so each surface only ever confirms its own
        // population.
        abort_unless($user->roles()->exists(), 404);
        abort_if($user->id === $request->user()->id, 422);

        $this->guardRank($request, $user, 'suspend');

        /*
         * Suspending the last active Super Admin is the same lockout as
         * removing the role, through a different door. With the rank guard
         * above, the acting Super Admin themselves keeps the count above
         * zero — this stays as belt-and-braces for a future where the
         * permission stops implying the rank.
         */
        if ($user->isSuperAdmin() && self::activeSuperAdminCount(except: $user->id) === 0) {
            return back()->withErrors(['reason' => __('identity.administrators.last_super_admin')]);
        }

        $user->forceFill([
            'suspended_at' => now(),
            'suspended_reason' => $validated['reason'],
        ])->save();

        $this->audit->record('identity.administrator_suspended', $user, [], [
            'actor_id' => $request->user()->id,
            'reason' => $validated['reason'],
        ], severity: 'warning');

        return back()->with('status', 'administrator-suspended');
    }

    public function reactivate(Request $request, User $user): RedirectResponse
    {
        abort_unless($user->roles()->exists(), 404);

        $this->guardRank($request, $user, 'reactivate');

        $user->forceFill([
            'suspended_at' => null,
            'suspended_reason' => null,
        ])->save();

        $this->audit->record('identity.administrator_reactivated', $user, [], [
            'actor_id' => $request->user()->id,
        ]);

        return back()->with('status', 'administrator-reactivated');
    }

    public function forceLogout(Request $request, User $user): RedirectResponse
    {
        abort_unless($user->roles()->exists(), 404);

        $this->guardRank($request, $user, 'force_logout');

        DB::table('sessions')->where('user_id', $user->getKey())->delete();
        $user->forceFill(['remember_token' => Str::random(60)])->save();

        $this->audit->record('identity.administrator_sessions_revoked', $user, [], [
            'actor_id' => $request->user()->id,
        ], severity: 'warning');

        return back()->with('success', __('identity.users.sessions_revoked'));
    }

    /**
     * One operator, with deliberately NO phone or contact data: this surface
     * answers "who can do what", never "how do I reach them".
     *
     * @return array<string, mixed>
     */
    private function row(User $user): array
    {
        $roles = $user->roles->pluck('key')->sort()->values()->all();

        return [
            'id' => $user->id,
            'name' => $user->name,
            'initials' => $user->initials(),
            'roles' => $roles,
            'is_super_admin' => in_array(RoleKey::SuperAdmin->value, $roles, true),
            'is_suspended' => $user->suspended_at !== null,
            'suspended_reason' => $user->suspended_reason,
            // Security posture, not a secret: whether enrolment happened,
            // never the secret or the recovery codes themselves.
            'mfa_enabled' => $user->hasMfaEnabled(),
            'telegram_linked' => $user->telegram_verified_at !== null,
            'registered_at' => $user->created_at?->toDateString(),
            'last_login_at' => $user->last_login_at?->toDateString(),
            'last_seen_at' => $user->last_seen_at?->diffForHumans(),
            'online' => $user->last_seen_at !== null
                && $user->last_seen_at->gte(now()->subSeconds(TouchLastSeen::INTERVAL_SECONDS)),
        ];
    }

    /**
     * Rank order, applied at every mutating door. Acting on an account that
     * holds super_admin — suspending it, reactivating it, ending its
     * sessions — is reserved to another Super Admin; a lesser administrator
     * whose permission reaches the route is refused here and the refusal is
     * a security event.
     */
    private function guardRank(Request $request, User $user, string $action): void
    {
        if ($user->isSuperAdmin() && ! $request->user()->isSuperAdmin()) {
            $this->audit->security('identity.administrators.escalation_denied', [
                'actor_id' => $request->user()->id,
                'target_id' => $user->id,
                'action' => $action,
            ]);

            abort(403);
        }
    }

    /** @return list<string> the administrative role keys this surface manages */
    public static function assignableRoleKeys(): array
    {
        return array_values(array_map(
            static fn (RoleKey $role): string => $role->value,
            array_filter(RoleKey::cases(), static fn (RoleKey $role): bool => $role->isAdministrative()),
        ));
    }

    private static function activeSuperAdminCount(?int $except = null): int
    {
        return User::query()
            ->whereHas('roles', static fn ($q) => $q->where('key', RoleKey::SuperAdmin->value))
            ->where('is_active', true)
            ->whereNull('suspended_at')
            ->when($except !== null, static fn ($q) => $q->whereKeyNot($except))
            ->count();
    }
}
