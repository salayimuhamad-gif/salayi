<?php

declare(strict_types=1);

namespace App\Modules\Projects\Support;

use App\Modules\Companies\Models\Company;
use App\Modules\Identity\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

/**
 * The acting company for an admin session, held server-side.
 *
 * ActingCompany::resolve() answers "which company COULD this user act for",
 * from one request. That is the right question when starting a draft and the
 * wrong one everywhere else: an ordinary project index request carries no
 * acting_company_id, so a user with two memberships resolved to "must choose"
 * — and ProjectScope, reading company_id as null, returned an empty result
 * set. The consequence was a blank project index and 404 on every edit,
 * media and ratings page, for exactly the users the scoping was written for.
 *
 * The choice therefore has to persist. It lives in the SESSION, not in a query
 * parameter: a parameter can be edited by anyone, is lost on every link that
 * forgets to carry it, and would have to be threaded through pagination,
 * filters and every redirect.
 *
 * Membership is REVALIDATED on read, not merely on switch. A session outlives
 * a deactivated membership, and a stale context must fail closed rather than
 * keep granting access to a company somebody has left.
 */
final class ActingCompanyContext
{
    private const SESSION_KEY = 'admin.acting_company_id';

    /**
     * The explicit mode. Stored separately from the company id because a null
     * id is ambiguous: it means "platform" for an authorised operator and
     * "has not chosen yet" for a multi-company user, and those need opposite
     * treatment.
     */
    private const MODE_KEY = 'admin.acting_mode';

    public const MODE_PLATFORM = 'platform';

    public const MODE_COMPANY = 'company';

    /**
     * The company this session is acting for, or null for platform mode.
     *
     * Resolution order, deliberately: a validated session choice, then a sole
     * membership, then nothing. A user with several memberships and no stored
     * choice returns null and `mustChoose()` is true — the caller sends them
     * to the switcher rather than guessing.
     */
    public static function current(Request $request): ?int
    {
        $user = $request->user();

        if ($user === null) {
            return null;
        }

        // A deliberate platform choice wins over every shortcut below.
        if ($request->session()->get(self::MODE_KEY) === self::MODE_PLATFORM
            && self::mayUsePlatformMode($user)) {
            return null;
        }

        $available = self::available($user);

        if ($available === []) {
            return null;
        }

        $stored = $request->session()->get(self::SESSION_KEY);

        // Revalidated every read. A membership deactivated after the choice
        // was made must stop working immediately.
        if ($stored !== null && in_array((int) $stored, $available, true)) {
            return (int) $stored;
        }

        if ($stored !== null) {
            /*
             * Stale: cleared immediately rather than left to fail validation
             * on every request. Recorded, because a context disappearing
             * mid-session is something the person will notice and support will
             * be asked about.
             */
            $request->session()->forget(self::SESSION_KEY);

            Log::info('Acting-company context cleared', [
                'user_id' => $user->id,
                'company_id' => (int) $stored,
                'reason' => 'membership or company no longer eligible',
            ]);
        }

        if (count($available) === 1) {
            // Unambiguous, so remembering it saves asking a question with one
            // possible answer.
            $request->session()->put(self::SESSION_KEY, $available[0]);

            return $available[0];
        }

        return null;
    }

    /**
     * The explicit mode for this session.
     *
     * Platform mode is a DELIBERATE choice, not the absence of one. A dual-role
     * user who selects it stays in it — the sole-membership shortcut in
     * current() must not quietly pull them back into company scope on the next
     * request.
     */
    public static function mode(Request $request): string
    {
        $user = $request->user();

        if ($user === null) {
            return self::MODE_COMPANY;
        }

        $stored = $request->session()->get(self::MODE_KEY);

        if ($stored === self::MODE_PLATFORM && self::mayUsePlatformMode($user)) {
            return self::MODE_PLATFORM;
        }

        if ($stored === self::MODE_PLATFORM) {
            // Permission lost since the choice: fail closed and clear it.
            $request->session()->forget(self::MODE_KEY);
        }

        return self::available($user) === [] && self::mayUsePlatformMode($user)
            ? self::MODE_PLATFORM
            : self::MODE_COMPANY;
    }

    public static function isPlatformMode(Request $request): bool
    {
        return self::mode($request) === self::MODE_PLATFORM;
    }

    /** Whether this user may operate without company scope at all. */
    public static function mayUsePlatformMode(User $user): bool
    {
        return $user->hasPermission(ActingCompany::PLATFORM_PERMISSION);
    }

    /** Switch deliberately into platform mode. */
    public static function switchToPlatform(Request $request): bool
    {
        $user = $request->user();

        if ($user === null || ! self::mayUsePlatformMode($user)) {
            return false;
        }

        $request->session()->put(self::MODE_KEY, self::MODE_PLATFORM);
        $request->session()->forget(self::SESSION_KEY);

        return true;
    }

    /** Whether this user must pick before company-scoped work can proceed. */
    public static function mustChoose(Request $request): bool
    {
        $user = $request->user();

        if ($user === null || self::isPlatformMode($request)) {
            return false;
        }

        return count(self::available($user)) > 1 && self::current($request) === null;
    }

    /**
     * Record a deliberate switch.
     *
     * Returns false for a company the user may not act for — the caller
     * reports it rather than silently ignoring the request, because silently
     * ignoring a switch leaves somebody acting as a company they believe they
     * have left.
     */
    public static function switchTo(Request $request, int $companyId): bool
    {
        $user = $request->user();

        if ($user === null || ! in_array($companyId, self::available($user), true)) {
            return false;
        }

        $request->session()->put(self::SESSION_KEY, $companyId);
        // Leaving platform mode is part of choosing a company.
        $request->session()->put(self::MODE_KEY, self::MODE_COMPANY);

        return true;
    }

    /** Drop the stored context. Called on logout and on session rotation. */
    public static function clear(Request $request): void
    {
        $request->session()->forget(self::SESSION_KEY);
        $request->session()->forget(self::MODE_KEY);
    }

    /**
     * Companies this user may manage projects for.
     *
     * Filtered by the MEMBERSHIP capability, not by the global role. A manager
     * at company A who is ordinary staff at company B may act for A only.
     *
     * @return list<int>
     */
    public static function available(User $user): array
    {
        if (! $user->hasPermission(ActingCompany::SCOPED_PERMISSION)) {
            return [];
        }

        $memberships = ActingCompany::manageableCompanyIds($user);

        if ($memberships === []) {
            return [];
        }

        /*
         * The COMPANY must also be eligible, not just the membership.
         *
         * Checking company_staff alone meant a soft-deleted or suspended
         * company remained a usable session context: the row was gone from
         * every listing while its staff carried on editing its projects.
         * A deleted company is not a context anybody may act as.
         */
        /*
         * ELIGIBILITY POLICY, stated once.
         *
         * A company may be acted for when it is verified AND published — the
         * same test `Company::published()` applies everywhere else, so the
         * portal and the public site agree about who is operating.
         *
         * My previous filter excluded `publication_status IN (suspended,
         * archived)`. `suspended` is a VERIFICATION status, not a publication
         * one, so that clause matched nothing: a suspended company remained a
         * fully usable acting context. Reusing the model's own scope removes
         * the chance of getting the column wrong again.
         *
         * Soft deletes are excluded by the model's global scope.
         */
        return Company::query()
            ->published()
            ->whereIn('id', $memberships)
            ->orderBy('id')
            ->pluck('id')
            ->map(static fn ($id): int => (int) $id)
            ->all();
    }
}
