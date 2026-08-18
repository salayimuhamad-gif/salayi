<?php

declare(strict_types=1);

namespace App\Modules\Companies\Http\Controllers\Portal;

use App\Modules\Companies\Http\Middleware\ResolveActingCompany;
use App\Modules\Companies\Models\CompanyStaff;
use App\Modules\Marketplace\Models\Offer;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Inertia\Inertia;
use Inertia\Response;

/**
 * The company portal (File one §8.2, §8.4).
 *
 * Separate from the Super Admin workspace in the way that matters: it is not a
 * differently-styled admin screen, it is a surface where the acting company is
 * never a parameter. Every query below starts from
 * `$this->membership()->company_id`, which was resolved by middleware from the
 * signed-in user's own membership and cannot be influenced by the request.
 *
 * That is the whole of §8.4. A company id accepted from a route, a query string
 * or a form field is a company id somebody can change — and the thing on the
 * other side of that change is another agency's leads.
 */
final class PortalController extends Controller
{
    public function dashboard(Request $request): Response
    {
        $membership = $this->membership();
        $company = $membership->company;

        return Inertia::render('Portal/Dashboard', [
            'company' => [
                'id' => $company->id,
                'name' => $company->name(),
                'verification_status' => $company->verification_status,
                'subscription_plan' => $company->subscription_plan,
            ],
            'capabilities' => [
                'manage_offers' => $membership->may('manage_offers'),
                'view_leads' => $membership->may('view_leads'),
                'view_lead_contacts' => $membership->may('view_lead_contacts'),
            ],
            'counts' => [
                // Scoped, always. There is no unscoped count on this page.
                'offers' => Offer::query()->where('company_id', $company->id)->count(),
                'published_offers' => Offer::query()
                    ->where('company_id', $company->id)
                    ->where('status', 'published')
                    ->count(),
                'branches' => $company->branches()->count(),
                'staff' => CompanyStaff::query()->where('company_id', $company->id)->active()->count(),
            ],
            'memberships' => $this->membershipOptions($request),
        ]);
    }

    /**
     * The company's own offers.
     *
     * The lifecycle state is shown as-is. A company that cannot see that its
     * offer sits in `changes_requested` will resubmit the same offer, costing a
     * reviewer's time twice and teaching the advertiser nothing.
     */
    public function offers(Request $request): Response
    {
        $membership = $this->membership();

        abort_unless($membership->may('manage_offers'), 403);

        $offers = Offer::query()
            ->where('company_id', $membership->company_id)
            ->latest('id')
            ->paginate(20)
            ->through(static fn (Offer $offer): array => [
                'id' => $offer->id,
                'public_id' => $offer->public_id,
                // Sorani first, falling back through the locales the offer
                // actually has rather than to an empty string.
                'title' => $offer->title_ckb ?: ($offer->title_ar ?: $offer->title_en),
                'status' => $offer->status->value,
                'scheduled_for' => $offer->scheduled_for?->toDateString(),
                'expires_at' => $offer->expires_at?->toDateString(),
                'is_sponsored' => (bool) $offer->is_sponsored,
                'disclosure_label' => $offer->disclosure_label,
            ]);

        return Inertia::render('Portal/Offers', [
            'offers' => $offers,
            'can_manage' => true,
        ]);
    }

    /**
     * Switch which company the person is acting for.
     *
     * Validated against their OWN memberships rather than trusted. Without that
     * check this endpoint is precisely the hole the middleware exists to close.
     */
    public function switchCompany(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'company_id' => ['required', 'integer'],
        ]);

        $permitted = CompanyStaff::query()
            ->where('user_id', $request->user()?->id)
            ->where('company_id', $validated['company_id'])
            ->active()
            ->exists();

        if (! $permitted) {
            abort(404);
        }

        $request->session()->put('company.acting_id', $validated['company_id']);

        return redirect()->route('portal.dashboard');
    }

    /** @return list<array<string, mixed>> */
    private function membershipOptions(Request $request): array
    {
        $memberships = $request->attributes->get('available_memberships');

        if ($memberships === null) {
            return [];
        }

        /** @var list<CompanyStaff> $memberships */
        $staffCollection = collect($memberships);

        return $staffCollection
            ->map(static fn (CompanyStaff $staff): array => [
                'company_id' => $staff->company_id,
                'company_name' => $staff->company?->name(),
                'role' => $staff->role,
            ])
            ->values()
            ->all();
    }

    /**
     * The membership resolved by middleware.
     *
     * Read from the container rather than re-queried: re-querying would mean a
     * second place that decides which company is being acted for, and two
     * places that decide the same thing eventually disagree.
     */
    private function membership(): CompanyStaff
    {
        /** @var CompanyStaff $membership */
        $membership = app(ResolveActingCompany::BINDING);

        return $membership;
    }
}
