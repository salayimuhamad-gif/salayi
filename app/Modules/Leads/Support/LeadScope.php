<?php

declare(strict_types=1);

namespace App\Modules\Leads\Support;

use App\Modules\Leads\Models\DemandProfile;
use App\Modules\Projects\Support\ActingCompanyContext;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;

/**
 * The tenant boundary for leads (spec 21.1).
 *
 * A lead carries a person's name, phone number and stated buying intent. The
 * workspace was globally unscoped while `leads.view` was granted to a company
 * portal role, so a company account manager could read every enquiry on the
 * platform — including those belonging to their competitors.
 *
 * FAIL CLOSED, for the same reason as offers: a missing acting company must
 * mean nothing, never everything.
 */
final class LeadScope
{
    /** Platform sales roles work across every company. */
    public static function isPlatformSales(Request $request): bool
    {
        return $request->user()?->hasPermission('leads.assign') === true;
    }

    /**
     * Restrict a query to what this visitor may see.
     *
     * @param  Builder<DemandProfile>  $query
     * @return Builder<DemandProfile>
     */
    public static function restrict(Request $request, Builder $query): Builder
    {
        if (self::isPlatformSales($request)) {
            return $query;
        }

        $companyId = ActingCompanyContext::current($request);

        if ($companyId === null) {
            return $query->whereRaw('1 = 0');
        }

        return $query->where('company_id', $companyId);
    }

    /**
     * Refuse a lead this visitor does not own.
     *
     * 404 rather than 403: an enumerable id space plus a distinguishable
     * refusal tells a competitor how much demand the platform is handling.
     */
    public static function authorise(Request $request, DemandProfile $lead): void
    {
        if (self::isPlatformSales($request)) {
            return;
        }

        $companyId = ActingCompanyContext::current($request);

        abort_if($companyId === null, 404);
        abort_unless((int) $lead->company_id === (int) $companyId, 404);
    }
}
