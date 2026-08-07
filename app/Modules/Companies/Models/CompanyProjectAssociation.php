<?php

declare(strict_types=1);

namespace App\Modules\Companies\Models;

use App\Modules\Companies\Enums\AssociationManagementStatus;
use App\Modules\Companies\Enums\AssociationRole;
use App\Modules\Companies\Support\AssociationLifecycle;
use App\Modules\Identity\Models\User;
use App\Modules\Projects\Models\Project;
use App\Modules\Projects\Models\ProjectDraft;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;
use RuntimeException;

/**
 * An admin-granted company/project relationship (spec 18.3, 37.4).
 *
 * ---- generated model properties (scripts/generate-model-annotations.php)
 *
 * @property int $id
 * @property int $company_id
 * @property int $project_id
 * @property int|null $company_branch_id
 * @property AssociationRole $role
 * @property Carbon|null $starts_on
 * @property Carbon|null $ends_on
 * @property bool $is_approved
 * @property int|null $approved_by
 * @property Carbon|null $approved_at
 * @property string|null $notes
 * @property int $display_priority
 * @property bool $is_sponsored
 * @property string|null $disclosure_label
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property AssociationManagementStatus $management_status
 * @property int|null $created_by
 * @property Carbon|null $status_changed_at
 * @property int|null $created_via_project_draft_id
 * @property int|null $rejected_by
 * @property Carbon|null $rejected_at
 * @property int|null $revoked_by
 * @property Carbon|null $revoked_at
 * @property int|null $created_by_company_staff_id
 * @property string|null $creator_membership_role
 * @property int|null $creator_membership_company_id
 * @property Carbon|null $creator_manage_projects_confirmed_at
 *
 * ---- end generated model properties
 */
final class CompanyProjectAssociation extends Model
{
    protected $table = 'company_project_associations';

    protected $fillable = [
        'company_id', 'project_id', 'company_branch_id', 'role',
        'starts_on', 'ends_on', 'is_approved', 'approved_by', 'approved_at',
        // approved_by / approved_at already appear above, from the base
        // schema. Listing them twice is legal PHP and silently misleading.
        'management_status', 'created_by', 'status_changed_at',
        'created_via_project_draft_id',
        'rejected_by', 'rejected_at', 'revoked_by', 'revoked_at',
        // Creation evidence is DELIBERATELY ABSENT from $fillable — see
        // recordCreationEvidence(). Mass assignment is exactly the path a
        // crafted request would use to forge it.
        'notes', 'display_priority', 'is_sponsored', 'disclosure_label',
    ];

    protected function casts(): array
    {
        return [
            'role' => AssociationRole::class,
            'is_approved' => 'boolean',
            'status_changed_at' => 'datetime',
            // Enum-cast so an unknown value cannot reach the column through
            // the application, whatever the database supports.
            'management_status' => AssociationManagementStatus::class,
            'rejected_at' => 'datetime',
            'revoked_at' => 'datetime',
            'creator_manage_projects_confirmed_at' => 'datetime',
            'is_sponsored' => 'boolean',
            'display_priority' => 'integer',
            'starts_on' => 'date',
            'ends_on' => 'date',
            'approved_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<Company, $this>
     */
    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    /**
     * @return BelongsTo<Project, $this>
     */
    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }

    /**
     * Approved, within its date window, and on a verified company.
     *
     * @param  Builder<CompanyProjectAssociation>  $query
     * @return Builder<CompanyProjectAssociation>
     */
    public function scopeActive(Builder $query): Builder
    {
        return $query->where('is_approved', true)
            ->where(function (Builder $q): void {
                $q->whereNull('starts_on')->orWhere('starts_on', '<=', now()->toDateString());
            })
            ->where(function (Builder $q): void {
                $q->whereNull('ends_on')->orWhere('ends_on', '>=', now()->toDateString());
            })
            ->whereHas('company', fn (Builder $q) => $q->where('verification_status', 'verified'));
    }

    /**
     * Reject writes whose lifecycle columns disagree.
     *
     * Delegated to AssociationLifecycle so the model, the services and the
     * database CHECK cannot drift apart — this guard used to demand
     * approved_at while ignoring rejected_at and revoked_at, which the
     * database required, so Eloquent accepted rows the database refused.
     */
    private function assertLifecycleConsistent(): void
    {
        $violation = AssociationLifecycle::violationFor($this);

        if ($violation !== null) {
            throw new RuntimeException($violation);
        }
    }

    /**
     * @return BelongsTo<ProjectDraft, $this>
     */
    public function createdViaDraft(): BelongsTo
    {
        return $this->belongsTo(
            ProjectDraft::class,
            'created_via_project_draft_id',
        );
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    /**
     * Write the creation evidence. Once, at creation, and never again.
     *
     * These fields are not fillable: a request that can mass-assign them can
     * forge the proof that authorises itself, which defeats the entire point
     * of recording it. The only writer is the Wizard's submission path, and it
     * calls this.
     *
     * Refuses to overwrite. Evidence that can be rewritten later is not
     * evidence of anything that happened earlier.
     */
    public function recordCreationEvidence(CompanyStaff $membership): void
    {
        if ($this->created_by_company_staff_id !== null) {
            throw new RuntimeException('Creation evidence is immutable and has already been recorded.');
        }

        if (! (bool) $membership->may_manage_projects || ! (bool) $membership->is_active) {
            // Evidence records a right that HELD. Writing it for a membership
            // that does not hold it manufactures the proof rather than
            // recording it.
            throw new RuntimeException('Cannot record creation evidence for a membership without project rights.');
        }

        if ((int) $membership->company_id !== (int) $this->company_id) {
            throw new RuntimeException('Creation evidence must come from a membership of the same company.');
        }

        if ((int) $membership->user_id !== (int) $this->created_by) {
            // The membership must belong to the person recorded as creator.
            // Otherwise the evidence describes somebody else's authority.
            throw new RuntimeException('Creation evidence must belong to the recorded creator.');
        }

        /*
         * The DRAFT correlation is validated here, not left to ProjectScope to
         * notice later. Writing evidence that does not hold together produces
         * a row that looks recorded and grants nothing — the worst of both.
         */
        $draft = $this->createdViaDraft;

        if ($draft === null) {
            throw new RuntimeException('Creation evidence requires the originating draft.');
        }

        if ((int) $draft->user_id !== (int) $this->created_by) {
            throw new RuntimeException('The draft must belong to the recorded creator.');
        }

        if ((int) ($draft->acting_company_id ?? $draft->company_id) !== (int) $this->company_id) {
            throw new RuntimeException('The draft must be scoped to the same company.');
        }

        if ($draft->submitted_at === null) {
            // An unsubmitted draft created nothing, so it vouches for nothing.
            throw new RuntimeException('The draft must be submitted before it can vouch for an association.');
        }

        if ((int) $draft->project_id !== (int) $this->project_id) {
            throw new RuntimeException('The draft must have created this exact project.');
        }

        // `company_staff.role` is NOT NULL, so the reachable failure is an
        // EMPTY role, not a missing one.
        if ($membership->role === '') {
            // A role is part of the snapshot; without it the evidence cannot
            // be correlated against the live membership later.
            throw new RuntimeException('Creation evidence requires a membership role.');
        }

        $this->forceFill([
            'created_by_company_staff_id' => $membership->id,
            'creator_membership_role' => $membership->role,
            // Snapshot: survives deletion of the staff row it points at.
            'creator_membership_company_id' => $membership->company_id,
            'creator_manage_projects_confirmed_at' => now(),
        ])->saveQuietly();
    }

    protected static function booted(): void
    {
        /*
         * An ordinary update must never touch creation evidence. Approving,
         * rejecting or revoking an association changes its lifecycle, not the
         * history of who created it.
         */
        /*
         * PARTIAL evidence is refused.
         *
         * The four fields are one fact. A row carrying a confirmation
         * timestamp but no staff id, or a staff id but no role, passes every
         * individual null check while proving nothing — and ProjectScope
         * correlates on all of them, so a partial set silently grants no
         * access while looking recorded.
         */
        self::saving(static function (self $association): void {
            $fields = [
                'created_by_company_staff_id',
                'creator_membership_role',
                'creator_membership_company_id',
                'creator_manage_projects_confirmed_at',
            ];

            $present = array_filter(
                $fields,
                static fn (string $f): bool => ($association->{$f} ?? null) !== null,
            );

            if ($present !== [] && count($present) !== count($fields)) {
                $missing = implode(', ', array_diff($fields, $present));

                throw new RuntimeException("Creation evidence is incomplete; missing: {$missing}.");
            }
        });

        self::updating(static function (self $association): void {
            $protected = [
                'created_by_company_staff_id',
                'creator_membership_role',
                'creator_manage_projects_confirmed_at',
                'creator_membership_company_id',
                'created_via_project_draft_id',
                'created_by',
            ];

            foreach ($protected as $field) {
                if ($association->isDirty($field) && $association->getOriginal($field) !== null) {
                    throw new RuntimeException("Creation evidence field '{$field}' is immutable.");
                }
            }
        });

        self::saving(static function (self $association): void {
            $association->assertLifecycleConsistent();
        });

        self::saving(function (self $association): void {
            $role = $association->role;

            // A commercial relationship must always be labelled where it
            // appears (spec 18.3, 18.4).
            if (($association->is_sponsored || $role->requiresDisclosure())
                && trim((string) $association->disclosure_label) === '') {
                throw new RuntimeException(
                    'A sponsored or advertising association must carry a disclosure label (spec 18.3).',
                );
            }

            // NOT NULL DEFAULT 0, so zero — not null — is what "not yet
            // ordered" looks like in this column.
            if ($association->display_priority === 0) {
                $association->display_priority = $role->defaultPriority();
            }
        });
    }
}
