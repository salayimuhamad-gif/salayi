<?php

declare(strict_types=1);

namespace App\Modules\Leads\Http\Controllers\Account;

use App\Modules\Leads\Models\DemandProfile;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Inertia\Inertia;
use Inertia\Response;

/**
 * "My requests" (spec §7.3): the account-side view of what was sent to the
 * MyHawler team.
 *
 * What the person sees is THEIR OWN submission — the localized summary the
 * advisor built from their answers, the stage name, and the dates. What they
 * do not see is any of the sales apparatus: owner, notes, tasks, scores.
 * Those describe how the team is working the lead, not what the person asked
 * for, and exposing them here would leak one side's workspace into the
 * other's status page.
 */
final class AccountRequestsController extends Controller
{
    public function index(Request $request): Response
    {
        /*
         * H9: paginated for the same reason as the portfolio index — a
         * person who has asked twenty times deserves a page that loads,
         * and the size is fixed server-side.
         */
        $requests = DemandProfile::query()
            ->where('user_id', $request->user()->id)
            ->where('source', 'advisor')
            ->latest('updated_at')
            ->paginate(20)
            ->withQueryString()
            ->through(static fn (DemandProfile $lead): array => [
                'id' => $lead->id,
                'stage' => $lead->stage,
                'summary' => $lead->ai_summary,
                'summary_locale' => $lead->ai_summary_locale,
                'objective' => $lead->objective,
                'property_type' => $lead->property_type,
                'budget_max' => $lead->budget_max === null ? null : (float) $lead->budget_max,
                'currency' => $lead->currency,
                'currency_source' => $lead->currency_source,
                'submitted_at' => $lead->created_at?->toDateString(),
                'updated_at' => $lead->updated_at?->toDateString(),
            ]);

        return Inertia::render('Account/Requests', [
            'requests' => $requests,
        ]);
    }
}
