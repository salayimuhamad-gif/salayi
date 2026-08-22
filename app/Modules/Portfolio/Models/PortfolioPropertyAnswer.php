<?php

declare(strict_types=1);

namespace App\Modules\Portfolio\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * An owner's persisted answer to a valuation question (Wave 6).
 *
 * IDs only, by design: the row says WHICH option the owner chose, never what
 * that choice is worth — the percentage stays on the option row, server-side.
 * Answers are the mutable CURRENT state (one per question per property,
 * replaced on change); the immutable record of what a valuation actually
 * applied lives in portfolio_valuation_adjustments, not here. A stale answer
 * — one whose question or option is no longer part of the active set — stays
 * stored but is excluded from calculations and surfaced as stale, never
 * silently applied.
 *
 * ---- generated model properties (scripts/generate-model-annotations.php)
 *
 * @property int $id
 * @property int $portfolio_property_id
 * @property int $valuation_question_id
 * @property int $valuation_question_option_id
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 *
 * ---- end generated model properties
 */
final class PortfolioPropertyAnswer extends Model
{
    protected $table = 'portfolio_property_answers';

    protected $fillable = [
        'portfolio_property_id', 'valuation_question_id', 'valuation_question_option_id',
    ];

    /** @return BelongsTo<PortfolioProperty, $this> */
    public function property(): BelongsTo
    {
        return $this->belongsTo(PortfolioProperty::class, 'portfolio_property_id');
    }

    /** @return BelongsTo<ValuationQuestion, $this> */
    public function question(): BelongsTo
    {
        return $this->belongsTo(ValuationQuestion::class, 'valuation_question_id');
    }

    /** @return BelongsTo<ValuationQuestionOption, $this> */
    public function option(): BelongsTo
    {
        return $this->belongsTo(ValuationQuestionOption::class, 'valuation_question_option_id');
    }
}
