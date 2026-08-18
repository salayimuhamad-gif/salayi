<?php

declare(strict_types=1);

namespace App\Modules\Advisor\Models;

use App\Modules\Projects\Models\Project;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;
use RuntimeException;

/**
 * A deterministic match result with its stored breakdown (spec 16.3).
 *
 * `score` and `components` are computed by LifestyleMatcher. `narrative` is
 * what the model wrote about them. The score is authoritative: if the two ever
 * disagree, the interface shows the breakdown.
 *
 * ---- generated model properties (scripts/generate-model-annotations.php)
 *
 * @property int $id
 * @property int $lifestyle_profile_id
 * @property int $project_id
 * @property int|null $advisor_conversation_id
 * @property int $score
 * @property array<string, mixed>|null $components
 * @property bool $is_disqualified
 * @property array<string, mixed>|null $disqualification_reasons
 * @property string $confidence
 * @property string|null $narrative
 * @property string|null $narrative_locale
 * @property int|null $narrative_message_id
 * @property string $matcher_version
 * @property Carbon|null $calculated_at
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 *
 * ---- end generated model properties
 */
final class AdvisorMatch extends Model
{
    protected $table = 'advisor_matches';

    protected $fillable = [
        'lifestyle_profile_id', 'project_id', 'advisor_conversation_id',
        'score', 'components', 'is_disqualified', 'disqualification_reasons',
        'confidence', 'narrative', 'narrative_locale', 'narrative_message_id',
        'matcher_version', 'calculated_at',
    ];

    protected function casts(): array
    {
        return [
            'score' => 'integer',
            'components' => 'array',
            'disqualification_reasons' => 'array',
            'is_disqualified' => 'boolean',
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

    protected static function booted(): void
    {
        self::saving(function (self $match): void {
            // A score with no breakdown cannot be explained, and spec 16.3
            // requires it to be explainable. Refusing at the model layer means
            // no code path can store a bare number.
            if (! is_array($match->components) || $match->components === []) {
                throw new RuntimeException(
                    'A match score cannot be stored without its component breakdown (spec 16.3).',
                );
            }
        });
    }
}
