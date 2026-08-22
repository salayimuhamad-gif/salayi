<?php

declare(strict_types=1);

namespace App\Modules\Portfolio\Models;

use App\Modules\Identity\Models\User;
use App\Modules\Projects\Models\Project;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;
use RuntimeException;

/**
 * A versioned set of valuation adjustment questions (Wave 6).
 *
 * The lifecycle is the whole design: a DRAFT is editable and invisible to
 * valuations; publishing freezes it as the single ACTIVE version for its
 * scope; a correction is a NEW draft version with fresh rows, never an edit.
 * The freeze is enforced HERE, not by admin-UI discipline, because an active
 * set is what persisted owner answers point at — editing one in place would
 * silently change what thousands of stored answers mean.
 *
 * ---- generated model properties (scripts/generate-model-annotations.php)
 *
 * @property int $id
 * @property string $name
 * @property string $scope_transaction
 * @property int|null $project_id
 * @property list<string>|null $property_types
 * @property int $version
 * @property string $status
 * @property Carbon|null $published_at
 * @property Carbon|null $retired_at
 * @property int|null $created_by
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 *
 * ---- end generated model properties
 */
final class ValuationRuleSet extends Model
{
    public const STATUS_DRAFT = 'draft';

    public const STATUS_ACTIVE = 'active';

    public const STATUS_RETIRED = 'retired';

    public const STATUSES = [self::STATUS_DRAFT, self::STATUS_ACTIVE, self::STATUS_RETIRED];

    /**
     * The only transaction basis the portfolio values today — the same
     * 'sale' the PortfolioValuer states as its evidence basis. Stored per
     * set so the claim is explicit on every row.
     */
    public const SCOPE_TRANSACTION_SALE = 'sale';

    protected $table = 'valuation_rule_sets';

    protected $fillable = [
        'name', 'scope_transaction', 'project_id', 'property_types',
        'version', 'status', 'published_at', 'retired_at', 'created_by',
    ];

    protected function casts(): array
    {
        return [
            'property_types' => 'array',
            'version' => 'integer',
            'published_at' => 'datetime',
            'retired_at' => 'datetime',
        ];
    }

    /**
     * Every question, in the order the owner form and the admin builder
     * both render them.
     *
     * @return HasMany<ValuationQuestion, $this>
     */
    public function questions(): HasMany
    {
        return $this->hasMany(ValuationQuestion::class, 'valuation_rule_set_id')
            ->orderBy('sort_order')
            ->orderBy('id');
    }

    /** @return BelongsTo<Project, $this> */
    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }

    /** @return BelongsTo<User, $this> */
    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function isDraft(): bool
    {
        return $this->status === self::STATUS_DRAFT;
    }

    public function isActive(): bool
    {
        return $this->status === self::STATUS_ACTIVE;
    }

    /**
     * Whether this set's questions apply to the given property facts. Scope
     * is deliberately coarse and deterministic: the basis must match, a
     * project-scoped set matches only its project, and a property-type list
     * matches only listed types. Anything finer belongs in the questions
     * themselves, where the owner answers it explicitly.
     */
    public function appliesTo(?int $projectId, string $propertyType): bool
    {
        if ($this->project_id !== null && $this->project_id !== $projectId) {
            return false;
        }

        $types = $this->property_types;

        return $types === null || $types === [] || in_array($propertyType, $types, true);
    }

    protected static function booted(): void
    {
        self::updating(static function (self $set): void {
            $original = (string) $set->getOriginal('status');

            if ($original === self::STATUS_RETIRED) {
                // Retired sets are the read-only record of what WAS active.
                throw new RuntimeException('A retired valuation rule set is read-only.');
            }

            if ($original !== self::STATUS_ACTIVE) {
                return;
            }

            /*
             * Active content is frozen: the only legal transition is
             * retirement (status + retired_at together). Any other dirty
             * attribute is an in-place edit of rules that persisted answers
             * and live valuations depend on.
             */
            $illegal = array_diff(array_keys($set->getDirty()), ['status', 'retired_at', 'updated_at']);

            if ($illegal !== [] || ($set->isDirty('status') && $set->status !== self::STATUS_RETIRED)) {
                throw new RuntimeException(
                    'An active valuation rule set is frozen — corrections are a new draft version.',
                );
            }
        });

        self::deleting(static function (self $set): void {
            // Draft and retired sets may be deleted (history lives in the
            // valuation snapshots, not here); the active set must be retired
            // first so there is never a moment with a half-deleted live set.
            if ((string) $set->getOriginal('status') === self::STATUS_ACTIVE) {
                throw new RuntimeException('Retire an active valuation rule set before deleting it.');
            }
        });
    }
}
