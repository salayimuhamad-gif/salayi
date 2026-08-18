<?php

declare(strict_types=1);

namespace App\Modules\Projects\Support;

use App\Modules\Companies\Enums\AssociationManagementStatus;
use App\Modules\Companies\Models\Company;
use App\Modules\Companies\Models\CompanyDeveloperAssociation;
use App\Modules\Companies\Models\CompanyProjectAssociation;
use App\Modules\Identity\Models\User;
use App\Modules\Projects\Models\Project;
use App\Modules\Projects\Models\ProjectDraft;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;

/**
 * The single authority on which projects a user may see or change.
 *
 * A company account manager was granted `projects.view` and `projects.update`
 * so they could enter their own company's projects — and every project
 * controller queries globally. The result was that any company portal user
 * could list, edit, transition, rate and delete media from EVERY project on
 * the platform, including their competitors'. That is a commercial disclosure,
 * not merely a permissions oversight, and it was introduced by the same change
 * that made the Wizard usable for them.
 *
 * Route-model binding is not authorisation. `Project $project` resolves any id
 * in the table; the check has to be a query constraint or an explicit
 * assertion, applied everywhere, from one place.
 *
 * PLATFORM OPERATORS are unconstrained — but only those holding
 * `projects.create_unscoped`, never those who merely lack a membership.
 */
final class ProjectScope
{
    /**
     * Constrain a project query to what this user may reach.
     *
     * @param  Builder<Project>  $query
     * @return Builder<Project>
     */
    public static function apply(Builder $query, Request $request): Builder
    {
        $user = $request->user();

        if ($user === null) {
            return $query->whereRaw('1 = 0');
        }

        if (self::inPlatformMode($request)) {
            return $query;
        }

        $companyId = self::actingCompanyId($request, $user);

        if ($companyId === null) {
            // Neither a platform operator nor acting for a company: nothing.
            // Failing closed is the only safe default here.
            return $query->whereRaw('1 = 0');
        }

        return $query->whereIn(
            'projects.id',
            self::manageable(
                CompanyProjectAssociation::query()
                    ->select('project_id')
                    ->where('company_id', $companyId),
                $companyId,
            ),
        );
    }

    /** Whether this user may reach one specific project. */
    public static function permits(Request $request, Project $project): bool
    {
        $user = $request->user();

        if ($user === null) {
            return false;
        }

        if (self::inPlatformMode($request)) {
            return true;
        }

        $companyId = self::actingCompanyId($request, $user);

        if ($companyId === null) {
            return false;
        }

        return self::manageable(
            CompanyProjectAssociation::query()
                ->where('company_id', $companyId)
                ->where('project_id', $project->id),
            $companyId,
        )->exists();
    }

    /**
     * Abort with 404 when a project is out of scope.
     *
     * 404 rather than 403: confirming that a project id exists tells a
     * competitor how many projects the platform holds and lets them probe for
     * specific ones. "Not found" discloses nothing.
     */
    public static function authorise(Request $request, Project $project): void
    {
        abort_unless(self::permits($request, $project), 404);
    }

    /**
     * A platform operator holds the UNSCOPED permission explicitly.
     *
     * Deliberately not "has no company membership" — that inversion promoted
     * every unlinked user, which is the bug this whole area keeps producing.
     */
    public static function isPlatform(User $user): bool
    {
        return $user->hasPermission(ActingCompany::PLATFORM_PERMISSION);
    }

    /**
     * Whether THIS REQUEST is operating in platform mode.
     *
     * Holding the unscoped permission permits platform mode; it does not force
     * every request into it. A platform administrator who also belongs to a
     * company and selects that company must see company scope — otherwise the
     * switcher appears to do nothing, and they review their own company's work
     * against the whole platform's data.
     *
     * Company mode wins whenever a company is selected. That is the narrower,
     * safer reading, and leaving it requires clearing the selection.
     */
    public static function inPlatformMode(Request $request): bool
    {
        $user = $request->user();

        if ($user === null || ! self::isPlatform($user)) {
            return false;
        }

        // The EXPLICIT mode, not the absence of a company id. A dual-role user
        // who has selected a company must stay scoped to it even though they
        // hold the unscoped permission.
        return ActingCompanyContext::isPlatformMode($request);
    }

    /**
     * The session's acting company.
     *
     * NOT ActingCompany::resolve(), which answers "which company could this
     * user act for" from a single request. An ordinary index request carries
     * no acting_company_id, so a multi-company user resolved to null and this
     * scope returned nothing — a blank project index and 404 everywhere, for
     * exactly the users the scoping exists to serve.
     */
    /**
     * Developer ids a company-scoped editor may assign, or null for unlimited.
     *
     * Null means "no restriction" — a platform operator. An empty ARRAY means
     * "none permitted", which is a different and equally real answer, so the
     * two are not conflated.
     *
     * The permitted set is derived from the acting company's own manageable
     * associations: a developer already linked to a project this company
     * represents. Anything else is another company's reference data, and
     * assigning it would attribute their developer to this company's project.
     *
     * @return list<int>|null
     */
    public static function permittedDeveloperIds(Request $request): ?array
    {
        $user = $request->user();

        if ($user === null) {
            return [];
        }

        if (self::inPlatformMode($request)) {
            return null;
        }

        $companyId = self::actingCompanyId($request, $user);

        if ($companyId === null) {
            return [];
        }

        /*
         * The DECLARED relationship, not one inferred from existing projects.
         *
         * Deriving permitted developers from the company's current projects was
         * circular: a company entering its first project had none, which is
         * exactly when the field matters. `company_developer_associations`
         * states the link directly, with its own lifecycle, so a new company
         * can be granted the developers it represents before it has entered
         * anything.
         */
        return CompanyDeveloperAssociation::query()
            ->live()
            ->where('company_id', $companyId)
            ->pluck('developer_id')
            ->map(static fn ($id): int => (int) $id)
            ->unique()
            ->values()
            ->all();
    }

    private static function actingCompanyId(Request $request, User $user): ?int
    {
        return ActingCompanyContext::current($request);
    }

    /**
     * Associations that confer management rights, and why.
     *
     * `management_status IN (pending, approved)` was the whole test, and it
     * was not enough: it granted every pending row to whichever company it
     * named, whatever created it. Pending is the state the Wizard writes, so
     * anything able to write a pending row could claim any project.
     *
     * PENDING now requires PROVENANCE — the association must point at a
     * ProjectDraft belonging to this same company, created by a user who held
     * `may_manage_projects` there. Only the Wizard can produce that, and only
     * for the company the draft was scoped to.
     *
     * APPROVED requires the approval to be real and current: is_approved true,
     * status approved, and inside its date window.
     *
     * Both require the COMPANY to still be eligible — a suspended company's
     * approved association confers nothing.
     *
     * @param  Builder<CompanyProjectAssociation>  $query
     * @param  int  $companyId  the acting company
     * @return Builder<CompanyProjectAssociation>
     */
    private static function manageable(Builder $query, int $companyId): Builder
    {
        $today = now()->toDateString();

        return $query
            // The company itself must be verified and published.
            ->whereIn('company_id', Company::query()->published()->select('id'))
            // Date window, for every status.
            ->where(function ($dates) use ($today): void {
                $dates->whereNull('starts_on')->orWhere('starts_on', '<=', $today);
            })
            ->where(function ($dates) use ($today): void {
                $dates->whereNull('ends_on')->orWhere('ends_on', '>=', $today);
            })
            ->where(function ($status) use ($companyId): void {
                $status
                    // APPROVED: reviewed, and consistent with is_approved.
                    ->where(function ($approved): void {
                        $approved
                            ->where('management_status', AssociationManagementStatus::Approved->value)
                            ->where('is_approved', true);
                    })
                    /*
                     * PENDING: one CORRELATED draft must tie together the
                     * association, the project, the creator and the company.
                     *
                     * The previous version used two independent subqueries —
                     * "some draft of this company exists" AND "the creator is
                     * some manager of this company". Those can both be true of
                     * completely unrelated rows, so any pending association
                     * naming a company that had ever used the Wizard was
                     * accepted. whereExists correlates them on the same row.
                     */
                    ->orWhere(function ($pending) use ($companyId): void {
                        $pending
                            ->where('management_status', AssociationManagementStatus::Pending->value)
                            ->whereNotNull('created_via_project_draft_id')
                            ->whereNotNull('created_by')
                            ->whereExists(function ($exists) use ($companyId): void {
                                $exists->selectRaw('1')
                                    ->from('project_drafts')
                                    ->whereColumn(
                                        'project_drafts.id',
                                        'company_project_associations.created_via_project_draft_id',
                                    )
                                    // The draft produced THIS project.
                                    ->whereColumn(
                                        'project_drafts.project_id',
                                        'company_project_associations.project_id',
                                    )
                                    // ...was written by the recorded creator.
                                    ->whereColumn(
                                        'project_drafts.user_id',
                                        'company_project_associations.created_by',
                                    )
                                    // ...and was scoped to this company.
                                    ->where(function ($scope) use ($companyId): void {
                                        $scope->where('project_drafts.company_id', $companyId)
                                            ->orWhere('project_drafts.acting_company_id', $companyId);
                                    })
                                    // A draft that was never submitted did not
                                    // create anything, so it cannot vouch for
                                    // an association.
                                    ->whereNotNull('project_drafts.submitted_at')
                                    ->whereNotNull('project_drafts.project_id');
                            })
                            /*
                             * The EXACT membership record that authorised
                             * this, correlated on every field.
                             *
                             * Checking only that `creator_manage_projects_
                             * confirmed_at` is non-null accepted any timestamp
                             * whatsoever — a forged value, or one copied from
                             * another row, was proof of nothing. The stored
                             * staff id, company, user and role must all agree
                             * with the association they claim to authorise.
                             */
                            ->whereNotNull('created_by_company_staff_id')
                            ->whereNotNull('creator_membership_company_id')
                            ->whereNotNull('creator_manage_projects_confirmed_at')
                            /*
                             * Evidence must be CONTEMPORANEOUS with the row it
                             * describes. A confirmation written long after the
                             * association was created is a later assertion
                             * about an earlier event, which is the forgery
                             * shape this excludes.
                             *
                             * Expressed with whereColumn rather than a
                             * date-arithmetic whereRaw: DATETIME(), DATE_ADD()
                             * and INTERVAL differ across SQLite and MySQL, and
                             * a driver-specific string here would silently
                             * evaluate differently on the two engines the
                             * suite and production actually use.
                             */
                            ->whereColumn(
                                'creator_manage_projects_confirmed_at',
                                '>=',
                                'company_project_associations.created_at',
                            )
                            ->whereExists(function ($exists) use ($companyId): void {
                                $exists->selectRaw('1')
                                    ->from('company_staff')
                                    ->whereColumn(
                                        'company_staff.id',
                                        'company_project_associations.created_by_company_staff_id',
                                    )
                                    ->whereColumn(
                                        'company_staff.user_id',
                                        'company_project_associations.created_by',
                                    )
                                    ->whereColumn(
                                        'company_staff.company_id',
                                        'company_project_associations.company_id',
                                    )
                                    // The snapshot must agree with the live
                                    // row; a mismatch means the evidence was
                                    // written for a different company.
                                    ->whereColumn(
                                        'company_staff.company_id',
                                        'company_project_associations.creator_membership_company_id',
                                    )
                                    /*
                                     * The role is deliberately NOT compared.
                                     *
                                     * `creator_membership_role` records what
                                     * was true at creation; `company_staff.role`
                                     * is mutable. Requiring them to match made
                                     * an ordinary promotion invalidate history
                                     * — the evidence became "false" because a
                                     * label changed, which is not how evidence
                                     * works. The snapshot stands on its own;
                                     * present access is the separate check
                                     * below.
                                     */
                                    ->where('company_staff.company_id', $companyId)
                                    ->where('company_staff.may_manage_projects', true);
                            })
                            /*
                             * CURRENT membership, separately. Historical
                             * evidence says the right existed at creation;
                             * this says it still does. Losing project rights
                             * removes pending access going forward without
                             * rewriting what was true then.
                             */
                            ->whereExists(function ($exists) use ($companyId): void {
                                $exists->selectRaw('1')
                                    ->from('company_staff')
                                    ->whereColumn(
                                        'company_staff.user_id',
                                        'company_project_associations.created_by',
                                    )
                                    ->where('company_staff.company_id', $companyId)
                                    ->where('company_staff.is_active', true)
                                    ->where('company_staff.may_manage_projects', true);
                            });
                    });
            });
    }
}
