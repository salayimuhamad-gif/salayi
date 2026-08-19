<?php

declare(strict_types=1);

namespace App\Modules\Market\Models;

use App\Modules\Core\ValueObjects\Decimal;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;
use RuntimeException;

/**
 * One published index value with its full explanation (spec 15.3).
 *
 * Revisions are new rows, not updates. A published market figure that changes
 * in place is indistinguishable from one that was always different, and
 * `revision_number` plus `revision_status` are what let the public page say
 * "revised on 12 March" rather than quietly showing a new number.
 *
 * ---- generated model properties (scripts/generate-model-annotations.php)
 *
 * @property int $id
 * @property int $market_index_id
 * @property string $period
 * @property Carbon $effective_date
 * @property string|null $value
 * @property string|null $median
 * @property string|null $minimum
 * @property string|null $maximum
 * @property string|null $change_percent
 * @property int $sample_size
 * @property int $excluded_outliers
 * @property array<string, mixed>|null $contributing_sources
 * @property string $confidence
 * @property bool $is_limited
 * @property string|null $warning
 * @property string $methodology_version
 * @property string $revision_status
 * @property int $revision_number
 * @property string $publication_status
 * @property Carbon|null $published_at
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 *
 * ---- end generated model properties
 */
final class MarketIndexValue extends Model
{
    protected $table = 'market_index_values';

    protected $fillable = [
        'market_index_id', 'period', 'effective_date',
        'value', 'median', 'minimum', 'maximum', 'change_percent',
        'sample_size', 'excluded_outliers', 'contributing_sources',
        'confidence', 'is_limited', 'warning',
        'methodology_version', 'revision_status', 'revision_number',
        'publication_status', 'published_at',
    ];

    protected function casts(): array
    {
        return [
            'value' => 'decimal:4',
            'median' => 'decimal:4',
            'minimum' => 'decimal:4',
            'maximum' => 'decimal:4',
            'change_percent' => 'decimal:4',
            'contributing_sources' => 'array',
            'sample_size' => 'integer',
            'excluded_outliers' => 'integer',
            'revision_number' => 'integer',
            'is_limited' => 'boolean',
            'effective_date' => 'date',
            'published_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<MarketIndex, $this>
     */
    public function index(): BelongsTo
    {
        return $this->belongsTo(MarketIndex::class, 'market_index_id');
    }

    public function decimalValue(): ?Decimal
    {
        return $this->value === null ? null : Decimal::of((string) $this->value, 4);
    }

    /**
     * Everything spec 15.3 requires alongside a public figure.
     *
     * @return array<string, mixed>
     */
    public function explanation(): array
    {
        return [
            'period' => $this->period,
            'effective_date' => $this->effective_date->toDateString(),
            'sample_size' => $this->sample_size,
            'excluded_outliers' => $this->excluded_outliers,
            'sources' => $this->contributing_sources ?? [],
            'confidence' => $this->confidence,
            'revision_status' => $this->revision_status,
            'revision_number' => $this->revision_number,
            'methodology_version' => $this->methodology_version,
            'is_limited' => $this->is_limited,
            'warning' => $this->warning,
        ];
    }

    protected static function booted(): void
    {
        self::updating(function (self $value): bool {
            // A published figure is immutable. A correction is a new revision.
            if ($value->getOriginal('publication_status') === 'published' && $value->isDirty('value')) {
                throw new RuntimeException(
                    'A published index value cannot be edited in place. Publish a new revision instead.',
                );
            }

            return true;
        });
    }
}
