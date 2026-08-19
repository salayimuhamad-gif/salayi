<?php

declare(strict_types=1);

namespace App\Modules\Projects\Models;

use App\Modules\Geography\Models\Place;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * A frozen project-to-place relationship (spec 10.5 step 4).
 *
 * The distance is a SNAPSHOT, not a live computation. If a place is moved or
 * corrected, existing rows keep the distance that was published and are marked
 * stale (step 7) for recalculation — so a public profile never silently changes
 * a figure a user may have made a decision on.
 *
 * ---- generated model properties (scripts/generate-model-annotations.php)
 *
 * @property int $id
 * @property int $project_id
 * @property int $place_id
 * @property int $straight_line_m
 * @property int|null $travel_distance_m
 * @property int|null $travel_time_s
 * @property string|null $travel_provider
 * @property string|null $compass_point
 * @property int|null $rank
 * @property float|null $relevance
 * @property bool $is_manual
 * @property bool $is_hidden
 * @property string|null $override_reason
 * @property bool $is_stale
 * @property Carbon|null $calculated_at
 * @property int|null $overridden_by
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 *
 * ---- end generated model properties
 */
final class ProjectNearbyPlace extends Model
{
    protected $table = 'project_nearby_places';

    protected $fillable = [
        'project_id', 'place_id', 'straight_line_m', 'travel_distance_m',
        'travel_time_s', 'travel_provider', 'compass_point', 'rank', 'relevance',
        'is_manual', 'is_hidden', 'override_reason', 'is_stale',
        'calculated_at', 'overridden_by',
    ];

    protected function casts(): array
    {
        return [
            'straight_line_m' => 'integer',
            'travel_distance_m' => 'integer',
            'travel_time_s' => 'integer',
            'rank' => 'integer',
            'relevance' => 'float',
            'is_manual' => 'boolean',
            'is_hidden' => 'boolean',
            'is_stale' => 'boolean',
            'calculated_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<Project, $this>
     */
    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }

    /**
     * @return BelongsTo<Place, $this>
     */
    public function place(): BelongsTo
    {
        return $this->belongsTo(Place::class);
    }

    /**
     * Travel figures are only shown when a routing provider actually supplied
     * them. Spec 10.5 step 3 makes them conditional, and presenting a
     * straight-line distance as a travel distance would be a fabrication.
     */
    public function hasTravelData(): bool
    {
        return $this->travel_distance_m !== null && $this->travel_provider !== null;
    }
}
