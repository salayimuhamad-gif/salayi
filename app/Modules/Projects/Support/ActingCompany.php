<?php

declare(strict_types=1);

namespace App\Modules\Projects\Support;

use App\Modules\Companies\Models\CompanyStaff;
use App\Modules\Identity\Models\User;
use Illuminate\Http\Request;

/**
 * Which company a user is acting for, and whether they may act without one.
 *
 * Two defects this replaces, both of which silently removed company scoping:
 *
 *   1. `CompanyStaff::first()` picked a company NONDETERMINISTICALLY for a
 *      user who belongs to more than one. Which company a draft was scoped to
 *      then depended on row order — and a user could be denied their own
 *      draft on the next request because a different row came back first.
 *
 *   2. ABSENCE of a company_staff row was treated as "platform administrator".
 *      That inverts the safe default: a user whose staff row is deactivated,
 *      or who was never linked to a company at all, was promoted to unscoped
 *      access. Platform operation now requires an explicit permission.
 */
final class ActingCompany
{
    /**
     * Creating a project for the company you act for.
     *
     * A company portal user holds this and nothing more.
     */
    public const SCOPED_PERMISSION = 'projects.create_scoped';

    /**
     * Creating a project with no company scope at all.
     *
     * A SEPARATE permission, deliberately. Using `projects.create` for both
     * meant a company user who kept the role but lost their membership became
     * a platform operator — the very inversion this class exists to prevent,
     * reintroduced through the permission name. Unscoped creation is now
     * something a role must be granted explicitly and no company role is.
     */
    public const PLATFORM_PERMISSION = 'projects.create_unscoped';

    /**
     * Active memberships, regardless of what they permit.
     *
     * @return list<int>
     */
    public static function availableTo(User $user): array
    {
        return CompanyStaff::query()
            ->where('user_id', $user->id)
            ->where('is_active', true)
            ->orderBy('company_id')
            ->pluck('company_id')
            ->map(static fn ($id): int => (int) $id)
            ->all();
    }

    /**
     * Memberships that may actually MANAGE PROJECTS.
     *
     * The distinction matters and was missing. Treating every active
     * membership as project-manageable let a global CompanyAccountManager
     * role carry authority across companies: a manager at A who is ordinary
     * staff at B could edit B's projects, because the role was checked and the
     * membership was not.
     *
     * `may_manage_projects` is the per-membership grant, defaulting to false,
     * exactly as `may_manage_offers` already works.
     *
     * @return list<int>
     */
    public static function manageableCompanyIds(User $user): array
    {
        return CompanyStaff::query()
            ->where('user_id', $user->id)
            ->where('is_active', true)
            ->where('may_manage_projects', true)
            ->orderBy('company_id')
            ->pluck('company_id')
            ->map(static fn ($id): int => (int) $id)
            ->all();
    }

    /**
     * Resolve the acting company for this request.
     *
     * Explicit choice first: a `company_id` on the request, validated against
     * the user's memberships. Then a single unambiguous membership. A user
     * with several memberships and no stated choice is NOT guessed at — the
     * caller is told to ask.
     *
     * @return array{company_id: ?int, is_platform: bool, must_choose: bool, available: list<int>}
     */
    public static function resolve(Request $request, ?User $user = null): array
    {
        $user ??= $request->user();

        if ($user === null) {
            return ['company_id' => null, 'is_platform' => false, 'must_choose' => false, 'available' => []];
        }

        $available = self::availableTo($user);
        // Platform mode requires the unscoped permission AND no membership.
        // Losing a membership does not promote anybody.
        $isPlatform = $available === [] && $user->hasPermission(self::PLATFORM_PERMISSION);

        // A company member is scoped even when they also hold the platform
        // permission: acting for a company is the narrower, safer reading, and
        // an explicit switch is required to leave it.
        $requested = $request->integer('acting_company_id') ?: null;

        if ($requested !== null && in_array($requested, $available, true)) {
            return ['company_id' => $requested, 'is_platform' => false, 'must_choose' => false, 'available' => $available];
        }

        if (count($available) === 1) {
            return ['company_id' => $available[0], 'is_platform' => false, 'must_choose' => false, 'available' => $available];
        }

        if (count($available) > 1) {
            // Ambiguous on purpose. Choosing for them is how a draft ends up
            // scoped to the wrong company.
            return ['company_id' => null, 'is_platform' => false, 'must_choose' => true, 'available' => $available];
        }

        return ['company_id' => null, 'is_platform' => $isPlatform, 'must_choose' => false, 'available' => []];
    }

    /**
     * Whether a draft's stored scope is still valid for this user.
     *
     * Re-checked on every request rather than trusted from creation time. A
     * membership can be deactivated between one request and the next, and a
     * scoped draft must NOT quietly become an unscoped platform draft when
     * that happens — it must become inaccessible.
     */
    public static function stillPermits(User $user, ?int $draftCompanyId): bool
    {
        if ($draftCompanyId === null) {
            return $user->hasPermission(self::PLATFORM_PERMISSION)
                && self::availableTo($user) === [];
        }

        return $user->hasPermission(self::SCOPED_PERMISSION)
            && in_array($draftCompanyId, self::manageableCompanyIds($user), true);
    }
}
