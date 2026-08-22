<?php

declare(strict_types=1);

namespace App\Modules\Portfolio\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use RuntimeException;

/**
 * One adjustment a valuation actually applied (Wave 6).
 *
 * A SNAPSHOT with the same append-only discipline as the valuation it
 * belongs to: semantic keys, the trilingual labels as they read at
 * calculation time, and the signed percent that was applied. It never joins
 * back to the live rule tables — the whole point is that retiring, revising
 * or deleting a rule set changes NOTHING about what history says happened.
 *
 * ---- generated model properties (scripts/generate-model-annotations.php)
 *
 * @property int $id
 * @property int $portfolio_valuation_id
 * @property string $question_key
 * @property string $option_key
 * @property string $question_ckb
 * @property string $question_ar
 * @property string $question_en
 * @property string $option_ckb
 * @property string $option_ar
 * @property string $option_en
 * @property string $adjustment_percent
 * @property int $position
 *
 * ---- end generated model properties
 */
final class PortfolioValuationAdjustment extends Model
{
    public const UPDATED_AT = null;

    public const CREATED_AT = null;

    protected $table = 'portfolio_valuation_adjustments';

    protected $fillable = [
        'portfolio_valuation_id', 'question_key', 'option_key',
        'question_ckb', 'question_ar', 'question_en',
        'option_ckb', 'option_ar', 'option_en',
        'adjustment_percent', 'position',
    ];

    protected function casts(): array
    {
        return [
            'adjustment_percent' => 'decimal:3',
            'position' => 'integer',
        ];
    }

    /** @return BelongsTo<PortfolioValuation, $this> */
    public function valuation(): BelongsTo
    {
        return $this->belongsTo(PortfolioValuation::class, 'portfolio_valuation_id');
    }

    protected static function booted(): void
    {
        self::updating(static function (): bool {
            throw new RuntimeException('Valuation adjustment snapshots are append-only.');
        });
    }
}
