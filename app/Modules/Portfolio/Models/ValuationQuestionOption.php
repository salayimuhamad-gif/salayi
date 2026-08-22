<?php

declare(strict_types=1);

namespace App\Modules\Portfolio\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;
use RuntimeException;

/**
 * One selectable answer to a valuation question (Wave 6).
 *
 * The option is where the AUTHORITATIVE signed percentage lives, and it
 * lives only here: an owner submits option IDS, the server reads
 * `adjustment_percent` from this row, and nothing a client sends is ever
 * interpreted as a percentage. The ±25 authoring bound is validated at the
 * admin boundary; the column stores whatever an admin legitimately authored.
 *
 * ---- generated model properties (scripts/generate-model-annotations.php)
 *
 * @property int $id
 * @property int $valuation_question_id
 * @property string $key
 * @property string $label_ckb
 * @property string $label_ar
 * @property string $label_en
 * @property string $adjustment_percent
 * @property int $sort_order
 * @property bool $is_active
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 *
 * ---- end generated model properties
 */
final class ValuationQuestionOption extends Model
{
    protected $table = 'valuation_question_options';

    protected $fillable = [
        'valuation_question_id', 'key',
        'label_ckb', 'label_ar', 'label_en',
        'adjustment_percent', 'sort_order', 'is_active',
    ];

    protected function casts(): array
    {
        return [
            'adjustment_percent' => 'decimal:3',
            'sort_order' => 'integer',
            'is_active' => 'boolean',
        ];
    }

    /** @return BelongsTo<ValuationQuestion, $this> */
    public function question(): BelongsTo
    {
        return $this->belongsTo(ValuationQuestion::class, 'valuation_question_id');
    }

    protected static function booted(): void
    {
        /*
         * Same freeze as the question: an option of a published set is what
         * a persisted answer points at and what its percentage MEANS.
         * Editable and deletable only while the owning set is a draft.
         */
        $assertDraft = static function (self $option): void {
            $status = ValuationRuleSet::query()
                ->whereKey(
                    ValuationQuestion::query()
                        ->whereKey($option->valuation_question_id)
                        ->value('valuation_rule_set_id'),
                )
                ->value('status');

            if ($status !== ValuationRuleSet::STATUS_DRAFT) {
                throw new RuntimeException(
                    'Options of a published valuation rule set are frozen — corrections are a new draft version.',
                );
            }
        };

        self::saving($assertDraft);
        self::deleting($assertDraft);
    }
}
