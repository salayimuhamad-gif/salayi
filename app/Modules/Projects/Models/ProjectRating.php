<?php

declare(strict_types=1);

namespace App\Modules\Projects\Models;

use App\Modules\Projects\Enums\RatingCategory;
use App\Modules\Projects\Enums\RatingType;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * One rating of one category from one provenance type (spec 13).
 *
 * The (project, category, type) unique key is what enforces spec 13.2's "these
 * types must remain separate" at the storage layer — an expert rating and a
 * public rating of the same category are different rows and can never
 * overwrite each other.
 *
 * ---- generated model properties (scripts/generate-model-annotations.php)
 *
 * @property int $id
 * @property int $project_id
 * @property RatingCategory $category
 * @property RatingType $type
 * @property string $value
 * @property int $sample_size
 * @property string $confidence
 * @property string|null $reason
 * @property string|null $source
 * @property Carbon|null $effective_date
 * @property string $review_status
 * @property int|null $author_id
 * @property int|null $reviewed_by
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 *
 * ---- end generated model properties
 */
final class ProjectRating extends Model
{
    protected $table = 'project_ratings';

    protected $fillable = [
        'project_id', 'category', 'type', 'value', 'sample_size',
        'confidence', 'reason', 'source', 'effective_date',
        'review_status', 'author_id', 'reviewed_by',
    ];

    protected function casts(): array
    {
        return [
            'category' => RatingCategory::class,
            'type' => RatingType::class,
            'value' => 'decimal:2',
            'sample_size' => 'integer',
            'effective_date' => 'date',
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
     * @return HasMany<ProjectRatingHistory, $this>
     */
    public function history(): HasMany
    {
        return $this->hasMany(ProjectRatingHistory::class)->orderByDesc('effective_date');
    }

    protected static function booted(): void
    {
        // Spec 13.3: every rating change stores its previous value, author,
        // source, reason, confidence and review status. Written here rather
        // than in a controller so a change made by a job, a seeder or an
        // import is recorded identically to one made through the admin.
        self::updated(function (self $rating): void {
            if (! $rating->wasChanged('value')) {
                return;
            }

            ProjectRatingHistory::query()->create([
                'project_rating_id' => $rating->id,
                'previous_value' => $rating->getOriginal('value'),
                'new_value' => $rating->value,
                'effective_date' => $rating->effective_date ?? now()->toDateString(),
                'author_id' => $rating->author_id,
                /*
                 * `auth()->user()` is null in console, queue and seeder
                 * contexts, where ratings are recalculated — so the fallback
                 * is real, not defensive padding. Written explicitly because
                 * reading a property off null inside `??` would warn on every
                 * scheduled recalculation.
                 */
                'author_label' => ($author = auth()->user()) === null ? 'system' : $author->name,
                'source' => $rating->source,
                'reason' => $rating->reason,
                'confidence' => $rating->confidence,
                'review_status' => $rating->review_status,
            ]);
        });
    }
}
