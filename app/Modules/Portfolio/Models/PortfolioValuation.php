<?php

declare(strict_types=1);

namespace App\Modules\Portfolio\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;
use RuntimeException;

/**
 * One valuation snapshot (spec 20.2).
 *
 * Append-only. Step 6's exit criterion is "valuation history is preserved", and
 * a history that can be edited is not one — an owner needs to see that the
 * estimate moved, not a single figure that silently became a different figure.
 *
 * ---- generated model properties (scripts/generate-model-annotations.php)
 *
 * @property int $id
 * @property int $portfolio_property_id
 * @property string|null $midpoint
 * @property string|null $low
 * @property string|null $high
 * @property string $currency
 * @property string $confidence
 * @property int|null $match_level
 * @property string $match_label
 * @property string $methodology
 * @property int $comparison_count
 * @property Carbon|null $data_period_from
 * @property Carbon|null $data_period_to
 * @property int $excluded_asking_count
 * @property string $excluded_asking_note
 * @property string|null $no_valuation_reason
 * @property Carbon $calculated_at
 *
 * ---- end generated model properties
 */
final class PortfolioValuation extends Model
{
    public const UPDATED_AT = null;

    public const CREATED_AT = null;

    protected $table = 'portfolio_valuations';

    protected $fillable = [
        'portfolio_property_id', 'midpoint', 'low', 'high', 'currency',
        'confidence', 'match_level', 'match_label', 'methodology',
        'comparison_count', 'data_period_from', 'data_period_to',
        'excluded_asking_count', 'excluded_asking_note', 'no_valuation_reason',
        'calculated_at',
    ];

    protected function casts(): array
    {
        return [
            'midpoint' => 'decimal:4',
            'low' => 'decimal:4',
            'high' => 'decimal:4',
            'match_level' => 'integer',
            'comparison_count' => 'integer',
            'excluded_asking_count' => 'integer',
            'data_period_from' => 'date',
            'data_period_to' => 'date',
            'calculated_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<PortfolioProperty, $this>
     */
    public function property(): BelongsTo
    {
        return $this->belongsTo(PortfolioProperty::class, 'portfolio_property_id');
    }

    /** A row with no midpoint records that no valuation was possible, and why. */
    public function hasValuation(): bool
    {
        return $this->midpoint !== null;
    }

    protected static function booted(): void
    {
        self::saving(function (self $valuation): void {
            // Spec 20.2 makes the excluded-asking note a required result field.
            // A valuation stored without it could be shown to an owner with no
            // explanation of why it sits below the listings they have seen.
            if (trim((string) $valuation->excluded_asking_note) === '') {
                throw new RuntimeException(
                    'A valuation must record its asking-price exclusion note (spec 20.2).',
                );
            }
        });

        self::updating(static function (): bool {
            throw new RuntimeException('Valuation history is append-only (spec 36 Step 6).');
        });
    }
}
