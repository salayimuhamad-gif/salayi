<?php

declare(strict_types=1);

namespace App\Modules\Portfolio\Services;

use App\Modules\Core\Contracts\FeatureFlagRepository;
use App\Modules\Core\ValueObjects\Decimal;
use App\Modules\Portfolio\Models\PortfolioProperty;
use App\Modules\Portfolio\Models\PortfolioPropertyAnswer;
use App\Modules\Portfolio\Models\PortfolioValuation;
use App\Modules\Portfolio\Models\ValuationQuestion;
use App\Modules\Portfolio\Models\ValuationQuestionOption;

/**
 * The adjustment side of a valuation (Wave 6): what an owner's answers do
 * to the evidence baseline, and nothing else.
 *
 * Two commitments, both structural:
 *
 *   1. SERVER AUTHORITY. The plan is built exclusively from persisted rows —
 *      the active set the resolver picked, its questions, its options, and
 *      the option each stored answer points at. Percentages come from the
 *      option rows and from nowhere else; nothing a client ever submitted
 *      is read as a number here.
 *
 *   2. INERT BY DEFAULT. Flag off, no applicable set, or zero valid answers
 *      all produce the empty plan, and the empty plan changes NOTHING: the
 *      valuation row the valuer writes is byte-identical to pre-Wave-6
 *      behaviour. A stale answer — its question or option no longer part of
 *      the resolved active set — is excluded and REPORTED, never silently
 *      applied and never silently dropped from the interface.
 *
 * The arithmetic is Decimal end to end: the total is an exact scale-3 sum
 * (uncapped — a large total WARNS, it does not silently clamp), the factor
 * is 1 + total/100 at scale 6, and a factor at or below zero refuses the
 * valuation rather than publishing a zero-or-negative "estimate".
 *
 * @phpstan-type AdjustmentRow array{
 *     question_id: int, option_id: int,
 *     question_key: string, option_key: string,
 *     question_ckb: string, question_ar: string, question_en: string,
 *     option_ckb: string, option_ar: string, option_en: string,
 *     adjustment_percent: string, position: int
 * }
 * @phpstan-type StaleAnswer array{answer_id: int, question_id: int, reason: string}
 * @phpstan-type AdjustmentPlan array{
 *     rule_set_id: int|null, applied: list<AdjustmentRow>, stale: list<StaleAnswer>,
 *     total_percent: string|null, warned: bool
 * }
 */
final class ValuationAdjustments
{
    /** The feature flag gating the whole rule-engine surface. */
    public const FLAG = 'portfolio.valuation_rules';

    /**
     * |total| at or above this WARNS (spec: no silent cap). The threshold
     * marks the point where the answers are claiming more than the
     * evidence, and the interface says so while still showing the number.
     */
    public const LARGE_ADJUSTMENT_WARNING = '30.000';

    /** The refusal recorded when the combined factor is zero or negative. */
    public const REASON_EXCEEDS_BASIS = 'adjustments_exceed_valuation_basis';

    /** Methodology stamped when at least one adjustment was applied. */
    public const METHODOLOGY_RULES = 'comparable_median_rules_v1';

    public function __construct(
        private readonly ValuationRuleResolver $resolver,
        private readonly FeatureFlagRepository $flags,
    ) {}

    /**
     * The plan for THIS property from its persisted answers: which
     * adjustments would apply, which stored answers are stale, and the
     * exact total. Pure read — nothing is persisted, which is also what
     * lets the admin preview and the owner form share it.
     *
     * @return AdjustmentPlan
     */
    public function planFor(PortfolioProperty $property): array
    {
        if (! $this->flags->enabled(self::FLAG)) {
            return $this->inertPlan();
        }

        $set = $this->resolver->activeSetFor($property->project_id, $property->property_type);

        if ($set === null) {
            return $this->inertPlan();
        }

        $answers = $property->answers()->with(['question', 'option'])->get();

        $applied = [];
        $stale = [];

        foreach ($answers as $answer) {
            $reason = $this->staleReason($answer, $set->id);

            if ($reason !== null) {
                $stale[] = [
                    'answer_id' => (int) $answer->id,
                    'question_id' => (int) $answer->valuation_question_id,
                    'reason' => $reason,
                ];

                continue;
            }

            /** @var ValuationQuestion $question */
            $question = $answer->question;
            /** @var ValuationQuestionOption $option */
            $option = $answer->option;

            $applied[] = [
                'question_id' => (int) $question->id,
                'option_id' => (int) $option->id,
                'question_key' => $question->key,
                'option_key' => $option->key,
                'question_ckb' => $question->label_ckb,
                'question_ar' => $question->label_ar,
                'question_en' => $question->label_en,
                'option_ckb' => $option->label_ckb,
                'option_ar' => $option->label_ar,
                'option_en' => $option->label_en,
                'adjustment_percent' => Decimal::of((string) $option->adjustment_percent, 3)->toString(),
                // Ordered below; the question's authored position decides.
                'position' => (int) $question->sort_order,
            ];
        }

        /*
         * Snapshot order is the authored question order, then id — the same
         * order the form showed the questions in, so the stored breakdown
         * re-reads exactly as it was answered.
         */
        usort($applied, static function (array $a, array $b): int {
            return [$a['position'], $a['question_id']] <=> [$b['position'], $b['question_id']];
        });

        foreach ($applied as $index => $row) {
            $applied[$index]['position'] = $index;
        }

        if ($applied === []) {
            return [
                'rule_set_id' => (int) $set->id,
                'applied' => [],
                'stale' => $stale,
                'total_percent' => null,
                'warned' => false,
            ];
        }

        $total = Decimal::zero(3);

        foreach ($applied as $row) {
            $total = $total->add($row['adjustment_percent']);
        }

        return [
            'rule_set_id' => (int) $set->id,
            'applied' => $applied,
            'stale' => $stale,
            'total_percent' => $total->toString(),
            'warned' => $total->abs()->compareTo(self::LARGE_ADJUSTMENT_WARNING) >= 0,
        ];
    }

    /**
     * Apply a non-empty plan to a SUCCESSFUL engine result, once.
     *
     * Returns only the attribute overrides for the valuation row: the
     * final figures, the preserved base figures, the exact total, and the
     * methodology that says rules participated. A factor at or below zero
     * turns the row into an explicit refusal — base preserved, final
     * figures null, reason stated — because a valuation of zero or less is
     * not an estimate, it is arithmetic escaping its meaning.
     *
     * @param  array{midpoint: string|null, low: string|null, high: string|null}  $result
     * @param  AdjustmentPlan  $plan
     * @return array<model-property<PortfolioValuation>, string|null>
     */
    public function apply(array $result, array $plan): array
    {
        $total = Decimal::of((string) $plan['total_percent'], 3);

        // factor = 1 + total/100, exact at scale 6.
        $factor = Decimal::of('1', 6)->add(Decimal::of($total, 6)->divide('100'));

        $base = [
            'base_midpoint' => $result['midpoint'],
            'base_low' => $result['low'],
            'base_high' => $result['high'],
            'adjustment_total_percent' => $total->toString(),
            'methodology' => self::METHODOLOGY_RULES,
        ];

        if ($factor->compareTo('0') <= 0) {
            return array_merge($base, [
                'midpoint' => null,
                'low' => null,
                'high' => null,
                'no_valuation_reason' => self::REASON_EXCEEDS_BASIS,
            ]);
        }

        $scale = static fn (?string $value): ?string => $value === null
            ? null
            : Decimal::of($value, 4)->multiply($factor)->toString();

        return array_merge($base, [
            'midpoint' => $scale($result['midpoint']),
            'low' => $scale($result['low']),
            'high' => $scale($result['high']),
        ]);
    }

    /**
     * Why a persisted answer no longer counts, or null when it is valid.
     *
     * The full chain is re-proven on every read: the question must exist,
     * be active, and belong to the RESOLVED active set; the option must
     * exist, be active, and belong to THAT question. Anything less and the
     * answer is stale — reported, never applied.
     */
    private function staleReason(PortfolioPropertyAnswer $answer, int $activeSetId): ?string
    {
        $question = $answer->question;
        $option = $answer->option;

        if ($question === null || $option === null) {
            return 'question_removed';
        }

        if ((int) $question->valuation_rule_set_id !== $activeSetId) {
            return 'question_not_in_active_set';
        }

        if (! $question->is_active) {
            return 'question_inactive';
        }

        if ((int) $option->valuation_question_id !== (int) $question->id) {
            return 'option_mismatch';
        }

        if (! $option->is_active) {
            return 'option_inactive';
        }

        return null;
    }

    /**
     * @return AdjustmentPlan
     */
    private function inertPlan(): array
    {
        return [
            'rule_set_id' => null,
            'applied' => [],
            'stale' => [],
            'total_percent' => null,
            'warned' => false,
        ];
    }
}
