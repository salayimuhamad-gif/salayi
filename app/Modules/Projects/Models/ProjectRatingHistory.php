<?php

declare(strict_types=1);

namespace App\Modules\Projects\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;
use RuntimeException;

/**
 * Append-only rating change log (spec 13.3).
 *
 * Same reasoning as AuditLog: a history row that can be edited is not a
 * history. There is no updated_at and no update path.
 *
 * ---- generated model properties (scripts/generate-model-annotations.php)
 *
 * @property int $id
 * @property int $project_rating_id
 * @property string|null $previous_value
 * @property string $new_value
 * @property Carbon $effective_date
 * @property string|null $author_label
 * @property int|null $author_id
 * @property string|null $source
 * @property string|null $reason
 * @property string $confidence
 * @property string $review_status
 * @property Carbon $created_at
 *
 * ---- end generated model properties
 */
final class ProjectRatingHistory extends Model
{
    public const UPDATED_AT = null;

    protected $table = 'project_rating_history';

    protected $fillable = [
        'project_rating_id', 'previous_value', 'new_value', 'effective_date',
        'author_id', 'author_label', 'source', 'reason', 'confidence', 'review_status',
    ];

    protected function casts(): array
    {
        return [
            'previous_value' => 'decimal:2',
            'new_value' => 'decimal:2',
            'effective_date' => 'date',
        ];
    }

    /**
     * @return BelongsTo<ProjectRating, $this>
     */
    public function rating(): BelongsTo
    {
        return $this->belongsTo(ProjectRating::class, 'project_rating_id');
    }

    protected static function booted(): void
    {
        self::updating(static function (): bool {
            throw new RuntimeException('Rating history is append-only and cannot be modified.');
        });

        self::deleting(static function (): bool {
            throw new RuntimeException('Rating history is append-only and cannot be deleted.');
        });
    }
}
