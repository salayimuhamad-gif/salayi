<?php

declare(strict_types=1);

namespace App\Modules\Portfolio\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;
use RuntimeException;

/**
 * One question inside a valuation rule set (Wave 6).
 *
 * A question carries NO percentage itself — its options do, server-side.
 * The `key` is the semantic identity ('renovation_state') that survives
 * versioning: every version's questions have fresh row ids, but the key is
 * what valuation snapshots record so history reads coherently across
 * versions.
 *
 * ---- generated model properties (scripts/generate-model-annotations.php)
 *
 * @property int $id
 * @property int $valuation_rule_set_id
 * @property string $key
 * @property string $question_type
 * @property string $label_ckb
 * @property string $label_ar
 * @property string $label_en
 * @property string|null $help_ckb
 * @property string|null $help_ar
 * @property string|null $help_en
 * @property int $sort_order
 * @property bool $is_active
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 *
 * ---- end generated model properties
 */
final class ValuationQuestion extends Model
{
    /** The only question type Wave 6 ships. */
    public const TYPE_SINGLE_SELECT = 'single_select';

    protected $table = 'valuation_questions';

    protected $fillable = [
        'valuation_rule_set_id', 'key', 'question_type',
        'label_ckb', 'label_ar', 'label_en',
        'help_ckb', 'help_ar', 'help_en',
        'sort_order', 'is_active',
    ];

    protected function casts(): array
    {
        return [
            'sort_order' => 'integer',
            'is_active' => 'boolean',
        ];
    }

    /** @return BelongsTo<ValuationRuleSet, $this> */
    public function ruleSet(): BelongsTo
    {
        return $this->belongsTo(ValuationRuleSet::class, 'valuation_rule_set_id');
    }

    /**
     * Every option, in display order.
     *
     * @return HasMany<ValuationQuestionOption, $this>
     */
    public function options(): HasMany
    {
        return $this->hasMany(ValuationQuestionOption::class, 'valuation_question_id')
            ->orderBy('sort_order')
            ->orderBy('id');
    }

    protected static function booted(): void
    {
        /*
         * The set's freeze extends to its content: questions are writable
         * and deletable only while the set is a DRAFT. Once published, the
         * rows are what persisted answers point at.
         */
        $assertDraft = static function (self $question): void {
            $status = ValuationRuleSet::query()
                ->whereKey($question->valuation_rule_set_id)
                ->value('status');

            if ($status !== ValuationRuleSet::STATUS_DRAFT) {
                throw new RuntimeException(
                    'Questions of a published valuation rule set are frozen — corrections are a new draft version.',
                );
            }
        };

        self::saving($assertDraft);
        self::deleting($assertDraft);
    }
}
