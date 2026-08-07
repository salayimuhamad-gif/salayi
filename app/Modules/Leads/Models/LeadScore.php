<?php

declare(strict_types=1);

namespace App\Modules\Leads\Models;

use App\Modules\Identity\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * A lead's score and the reasons that produced it (File one §9 Leads 2).
 *
 * §9 requires "score reasons" in the workspace, not just a number. A salesperson
 * told a lead is 82 learns nothing actionable; told it is 82 because the budget
 * is stated, the timeframe is short and three projects were viewed twice, they
 * know what to open the call with.
 *
 * The override fields exist because a score is a heuristic and the person on
 * the phone knows things the heuristic cannot. An override is recorded WITH its
 * reason and its author, and the calculated value is kept alongside — so a
 * manager can always see what the system thought and what a human decided
 * instead.
 *
 * ---- generated model properties (scripts/generate-model-annotations.php)
 *
 * @property int $id
 * @property int $demand_profile_id
 * @property int $total
 * @property int $uncapped_total
 * @property string $rule_version
 * @property array<string, mixed>|null $reasons
 * @property int|null $calculated_total
 * @property bool $is_overridden
 * @property string|null $override_reason
 * @property int|null $overridden_by
 * @property Carbon $calculated_at
 *
 * ---- end generated model properties
 */
final class LeadScore extends Model
{
    protected $fillable = [
        'demand_profile_id', 'total', 'uncapped_total', 'rule_version', 'reasons',
        'calculated_total', 'is_overridden', 'override_reason', 'overridden_by', 'calculated_at',
    ];

    protected function casts(): array
    {
        return [
            'reasons' => 'array',
            'is_overridden' => 'boolean',
            'calculated_at' => 'datetime',
            'total' => 'integer',
            'uncapped_total' => 'integer',
            'calculated_total' => 'integer',
        ];
    }

    /**
     * @return BelongsTo<DemandProfile, $this>
     */
    public function profile(): BelongsTo
    {
        return $this->belongsTo(DemandProfile::class, 'demand_profile_id');
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function overriddenBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'overridden_by');
    }

    /**
     * The reasons, ready for display.
     *
     * Returns an empty list rather than null so a caller never has to decide
     * between "no reasons" and "reasons missing" — both mean there is nothing
     * to show, and branching on the difference produces two ways to render the
     * same empty state.
     *
     * @return list<array<string, mixed>>
     */
    public function reasonList(): array
    {
        $reasons = $this->reasons;

        return is_array($reasons) ? array_values($reasons) : [];
    }
}
