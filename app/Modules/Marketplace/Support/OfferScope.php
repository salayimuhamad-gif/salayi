<?php

declare(strict_types=1);

namespace App\Modules\Marketplace\Support;

use App\Modules\Companies\Models\CompanyProjectAssociation;
use App\Modules\Marketplace\Models\Offer;
use App\Modules\Projects\Models\Project;
use App\Modules\Projects\Support\ActingCompanyContext;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

/**
 * The ownership boundary for marketplace offers (spec 19.1).
 *
 * A route group can prove somebody may manage OFFERS. It cannot prove they may
 * manage THIS offer — that depends on which company the offer belongs to and
 * which company the visitor is currently acting for, and only a per-request
 * check knows both.
 *
 * FAIL CLOSED. A scoped user with no valid acting company gets nothing, not
 * everything: treating a null context as "unscoped" turns a missing session
 * into platform-wide access, which is the most dangerous default available.
 */
final class OfferScope
{
    /** Platform moderation sees every company's offers. */
    public static function isModerator(Request $request): bool
    {
        return $request->user()?->hasPermission('marketplace.offers.moderate') === true;
    }

    /**
     * The company this request may act for, or null.
     *
     * Null from a scoped user means "no valid context", which callers must
     * treat as access to nothing.
     */
    public static function actingCompanyId(Request $request): ?int
    {
        $companyId = ActingCompanyContext::current($request);

        return $companyId === null ? null : (int) $companyId;
    }

    /**
     * Restrict a query to what this visitor may see.
     *
     * @param  Builder<Offer>  $query
     * @return Builder<Offer>
     */
    public static function restrict(Request $request, Builder $query): Builder
    {
        if (self::isModerator($request)) {
            return $query;
        }

        $companyId = self::actingCompanyId($request);

        if ($companyId === null) {
            /*
             * A contradiction rather than an early return: the caller still
             * receives a Builder it can paginate, and an empty page is a safe,
             * explicable state. Returning everything would be the alternative.
             */
            return $query->whereRaw('1 = 0');
        }

        return $query->where('company_id', $companyId);
    }

    /**
     * Refuse an offer this visitor does not own.
     *
     * 404 rather than 403: confirming that an offer id exists tells a
     * competitor how many listings the platform holds and lets them probe for
     * a rival's unpublished stock.
     */
    public static function authorise(Request $request, Offer $offer): void
    {
        if (self::isModerator($request)) {
            return;
        }

        $companyId = self::actingCompanyId($request);

        abort_if($companyId === null, 404);
        abort_unless((int) $offer->company_id === $companyId, 404);
    }

    /**
     * Projects this visitor may attach an offer to.
     *
     * A moderator may attach any. A seller may attach only projects their
     * acting company actually manages — approved association, not merely a
     * project they can see. `ProjectScope` already owns that judgement, so it
     * is asked rather than reimplemented here.
     *
     * @return Builder<Project>
     */
    public static function eligibleProjects(Request $request): Builder
    {
        $query = Project::query();

        if (self::isModerator($request)) {
            return $query;
        }

        $companyId = self::actingCompanyId($request);

        if ($companyId === null) {
            return $query->whereRaw('1 = 0');
        }

        return $query->whereIn(
            'id',
            CompanyProjectAssociation::query()
                ->select('project_id')
                ->where('company_id', $companyId)
                ->where('is_approved', true),
        );
    }

    /**
     * Refuse a project this visitor may not attach.
     *
     * The form not OFFERING a project is not a control — a direct POST does
     * not read the form. A field error rather than a 404 here: the offer
     * itself is theirs, and the problem is one value in it.
     */
    public static function assertProjectIsEligible(Request $request, int $companyId, mixed $projectId): void
    {
        if ($projectId === null || $projectId === '') {
            return;
        }

        if (self::isModerator($request)) {
            return;
        }

        $eligible = self::eligibleProjects($request)->whereKey($projectId)->exists();

        if (! $eligible) {
            throw ValidationException::withMessages([
                'project_id' => __('marketplace.errors.project_not_eligible'),
            ]);
        }
    }

    /**
     * The company a new offer belongs to.
     *
     * Taken from the validated session context, never from request input — a
     * seller who can post `company_id` can post somebody else's.
     */
    public static function companyIdForCreation(Request $request): int
    {
        $companyId = self::actingCompanyId($request);

        if (self::isModerator($request)) {
            $companyId ??= (int) $request->integer('company_id');

            abort_if($companyId === 0, 422);

            return $companyId;
        }

        abort_if($companyId === null, 403);

        return $companyId;
    }
}
