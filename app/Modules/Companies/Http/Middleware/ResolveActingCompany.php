<?php

declare(strict_types=1);

namespace App\Modules\Companies\Http\Middleware;

use App\Modules\Companies\Models\CompanyStaff;
use App\Modules\Operations\Services\AuditLogger;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Resolves the acting company for the portal (File one §8.2, §8.4).
 *
 * §8.2 asks for a company dashboard SEPARATE from the internal Super Admin
 * workspace, and §8.4 requires every query to be scoped to the company that
 * owns it. Those two are the same requirement seen from different ends: the
 * separation is only real if the scoping is automatic.
 *
 * So the acting company is resolved HERE, from the signed-in user's active
 * membership, and bound into the container. A controller cannot accidentally
 * read a company id from the request, because it never has to ask for one —
 * and the failure mode that protects against is the worst one this module has:
 * one company reading another's leads.
 *
 * Membership is per-company, not per-user. The schema was built that way on
 * purpose (`company_staff` is unique on company+user, with its own permission
 * flags), because the same broker legitimately works for two agencies and must
 * not carry one agency's rights into the other's dashboard.
 */
final class ResolveActingCompany
{
    /** Where the resolved membership is bound for controllers to read. */
    public const BINDING = 'company.acting_membership';

    public function __construct(private readonly AuditLogger $audit) {}

    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if ($user === null) {
            return redirect()->route('login');
        }

        $memberships = CompanyStaff::query()
            ->where('user_id', $user->id)
            ->where('is_active', true)
            ->with('company')
            ->get()
            // A membership whose company has been suspended or deleted is not
            // a way in; filtering here means no downstream query has to know.
            ->filter(fn (CompanyStaff $staff): bool => $staff->company !== null)
            ->values();

        if ($memberships->isEmpty()) {
            $this->audit->security('company.portal_access_without_membership', [
                'actor_id' => $user->id,
                'path' => $request->path(),
            ]);

            // 404 rather than 403: the portal's existence is not something a
            // non-member needs confirmed.
            abort(404);
        }

        /*
         * With several memberships the person chooses, and the choice is held
         * in the session rather than the URL. A company id in the URL is a
         * company id somebody can edit — and the whole point of §8.4 is that
         * they cannot.
         */
        $selectedId = $request->session()->get('company.acting_id');

        $membership = $memberships->firstWhere('company_id', $selectedId) ?? $memberships->first();

        $request->session()->put('company.acting_id', $membership->company_id);

        app()->instance(self::BINDING, $membership);

        // Shared with Inertia so the portal shell can show which company is
        // being acted for — an ambiguous dashboard is how somebody publishes an
        // offer under the wrong brand.
        $request->attributes->set('acting_membership', $membership);
        $request->attributes->set('available_memberships', $memberships);

        return $next($request);
    }
}
