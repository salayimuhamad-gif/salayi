<?php

declare(strict_types=1);

namespace App\Modules\Companies\Models;

use App\Modules\Companies\Enums\AssociationManagementStatus;
use App\Modules\Companies\Support\AssociationLifecycle;
use App\Modules\Projects\Models\Developer;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;
use RuntimeException;

/**
 * A company's right to attribute projects to a developer (spec 11.2).
 *
 * Stated directly rather than inferred from existing projects, which was
 * circular and locked out a company's first project.
 *
 * ---- generated model properties (scripts/generate-model-annotations.php)
 *
 * @property int $id
 * @property int $company_id
 * @property int $developer_id
 * @property AssociationManagementStatus $management_status
 * @property bool $is_approved
 * @property Carbon|null $starts_on
 * @property Carbon|null $ends_on
 * @property int|null $created_by
 * @property int|null $approved_by
 * @property Carbon|null $approved_at
 * @property Carbon|null $status_changed_at
 * @property string|null $notes
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property int|null $rejected_by
 * @property Carbon|null $rejected_at
 * @property int|null $revoked_by
 * @property Carbon|null $revoked_at
 *
 * ---- end generated model properties
 */
final class CompanyDeveloperAssociation extends Model
{
    protected $table = 'company_developer_associations';

    protected $fillable = [
        'company_id', 'developer_id', 'management_status', 'is_approved',
        'starts_on', 'ends_on', 'created_by', 'approved_by', 'approved_at',
        'status_changed_at', 'notes',
        'rejected_by', 'rejected_at', 'revoked_by', 'revoked_at',
    ];

    protected function casts(): array
    {
        return [
            'management_status' => AssociationManagementStatus::class,
            'is_approved' => 'boolean',
            'starts_on' => 'date',
            'ends_on' => 'date',
            'approved_at' => 'datetime',
            'rejected_at' => 'datetime',
            'revoked_at' => 'datetime',
            'status_changed_at' => 'datetime',
        ];
    }

    /**
     * Same lifecycle discipline as company↔project associations.
     *
     * A new domain that accepts inconsistent states is a new source of the
     * problems the other one just had.
     */
    protected static function booted(): void
    {
        self::saving(static function (self $association): void {
            $violation = AssociationLifecycle::violationFor($association);

            if ($violation !== null) {
                throw new RuntimeException($violation);
            }
        });
    }

    /**
     * @return BelongsTo<Company, $this>
     */
    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    /**
     * @return BelongsTo<Developer, $this>
     */
    public function developer(): BelongsTo
    {
        return $this->belongsTo(Developer::class);
    }

    /**
     * Links that currently permit attribution.
     *
     * Approved AND consistent AND in date. The same three conditions
     * ProjectScope applies to project associations, so the two cannot drift
     * into disagreeing about what "live" means.
     *
     * @param  Builder<CompanyDeveloperAssociation>  $query
     * @return Builder<CompanyDeveloperAssociation>
     */
    public function scopeLive(Builder $query): Builder
    {
        $today = now()->toDateString();

        return $query
            ->where('management_status', AssociationManagementStatus::Approved->value)
            ->where('is_approved', true)
            ->where(function (Builder $dates) use ($today): void {
                $dates->whereNull('starts_on')->orWhere('starts_on', '<=', $today);
            })
            ->where(function (Builder $dates) use ($today): void {
                $dates->whereNull('ends_on')->orWhere('ends_on', '>=', $today);
            });
    }
}
