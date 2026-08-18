<?php

declare(strict_types=1);

namespace App\Modules\Advisor\Services;

use App\Modules\Core\ValueObjects\Decimal;

/**
 * Deterministic lifestyle and suitability scoring (spec 16.3).
 *
 * Spec 16.3 draws the architectural line this class enforces:
 *
 *     "The score must be deterministic and explainable."
 *     "AI explains the calculated result but does not decide the raw score
 *      alone."
 *
 * So the number is computed here, in PHP, from the user's stated priorities and
 * retrieved facts. The model is handed the finished breakdown and asked to put
 * it into Sorani prose. It cannot move the score, and if the prose disagrees
 * with the breakdown the breakdown is what the interface shows.
 *
 * That ordering matters commercially as much as technically: a score a model
 * produced cannot be defended to a buyer who asks why one project outranked
 * another, and "the model felt it was a better fit" is not an answer this
 * product can give.
 *
 * Every component returns its own sub-score AND the reason for it, so the
 * explanation is assembled from the same arithmetic that produced the total
 * rather than reconstructed afterwards.
 *
 * @phpstan-type LifestyleProfileInput array{
 *     budget_max?: string|null, budget_min?: string|null,
 *     property_types?: list<string>,
 *     priorities?: list<array{kind: string, max_distance_m?: int, importance?: int, required?: bool}>
 * }
 * @phpstan-type LifestyleCandidate array{
 *     price?: string|null, property_type?: string|null,
 *     distances?: array<string, int>, amenity_score?: int|null,
 *     data_confidence?: string|null, risk_score?: int|null
 * }
 */
final class LifestyleMatcher
{
    /** Component weights out of 100. Visible here so they can be argued about. */
    public const WEIGHTS = [
        'budget_fit' => 25,
        'commute_fit' => 20,
        'school_fit' => 15,
        'service_coverage' => 12,
        'property_type_fit' => 10,
        'family_proximity' => 8,
        'lifestyle_fit' => 5,
        'risk_adjustment' => 5,
    ];

    /**
     * @param  array{
     *     budget_max?: string|null, budget_min?: string|null,
     *     property_types?: list<string>,
     *     priorities?: list<array{kind: string, max_distance_m?: int, importance?: int, required?: bool}>
     * }  $profile
     * @param  array{
     *     price?: string|null, property_type?: string|null,
     *     distances?: array<string, int>, amenity_score?: int|null,
     *     data_confidence?: string|null, risk_score?: int|null
     * }  $candidate
     * @return array{
     *     score: int, components: array<string, array{score: int, weight: int, weighted: string, reason: string}>,
     *     disqualified: bool, disqualification_reasons: list<string>,
     *     confidence: string, explainable: bool
     * }
     */
    public function score(array $profile, array $candidate): array
    {
        $disqualifiers = $this->disqualify($profile, $candidate);

        $components = [
            'budget_fit' => $this->budgetFit($profile, $candidate),
            'commute_fit' => $this->distanceFit($profile, $candidate, ['workplace', 'spouse_workplace']),
            'school_fit' => $this->distanceFit($profile, $candidate, ['child_one_school', 'child_two_school', 'university']),
            'service_coverage' => $this->serviceCoverage($candidate),
            'property_type_fit' => $this->propertyTypeFit($profile, $candidate),
            'family_proximity' => $this->distanceFit($profile, $candidate, ['parents', 'relatives']),
            'lifestyle_fit' => $this->serviceCoverage($candidate),
            'risk_adjustment' => $this->riskAdjustment($candidate),
        ];

        $total = Decimal::zero(4);
        $detailed = [];

        foreach ($components as $key => $component) {
            $weight = self::WEIGHTS[$key];
            $weighted = Decimal::of((string) $component['score'], 4)
                ->multiply($weight)
                ->divide(100);

            $total = $total->add($weighted);

            $detailed[$key] = [
                'score' => $component['score'],
                'weight' => $weight,
                'weighted' => $weighted->toScale(2)->toString(),
                'reason' => $component['reason'],
            ];
        }

        // A disqualified candidate scores zero rather than a low number. A
        // "42% match" on a property outside a stated hard requirement invites
        // the user to consider it anyway, which is not what "required" meant.
        $final = $disqualifiers === [] ? (int) round($total->toFloat()) : 0;

        return [
            'score' => max(0, min(100, $final)),
            'components' => $detailed,
            'disqualified' => $disqualifiers !== [],
            'disqualification_reasons' => $disqualifiers,
            'confidence' => $this->confidence($candidate),
            /*
             * The contract with the presentation layer: a score is only
             * publishable when every component carried its own reason.
             *
             * This compared two counts that are equal by construction — a
             * component is always appended for every weight — so it was
             * constant regardless of whether the reasons were actually there.
             * Checking the reasons themselves is what the comment always
             * claimed, and it can genuinely fail.
             */
            'explainable' => ! in_array(
                '',
                array_map(static fn (array $c): string => trim((string) $c['reason']), $detailed),
                true,
            ),
        ];
    }

    /**
     * @param  LifestyleProfileInput  $profile
     * @param  LifestyleCandidate  $candidate
     * @return list<string>
     */
    private function disqualify(array $profile, array $candidate): array
    {
        $reasons = [];

        $budgetMax = $profile['budget_max'] ?? null;
        $price = $candidate['price'] ?? null;

        if ($budgetMax !== null && $price !== null
            && Decimal::of((string) $price, 4)->greaterThan(Decimal::of((string) $budgetMax, 4))) {
            $reasons[] = 'above_budget_maximum';
        }

        $types = $profile['property_types'] ?? [];

        /*
         * `?? null` rather than direct access: a candidate with no property
         * type is a legitimate state — an unclassified project — and the
         * matcher treats it as unmeasured rather than emitting a warning and
         * then excluding it. Surfaced by running the packaging preflight with
         * warnings visible.
         */
        $candidateType = $candidate['property_type'] ?? null;

        if ($types !== [] && $candidateType !== null
            && ! in_array((string) $candidateType, $types, true)) {
            $reasons[] = 'property_type_excluded';
        }

        foreach ($profile['priorities'] ?? [] as $priority) {
            if (($priority['required'] ?? false) !== true) {
                continue;
            }

            $actual = $candidate['distances'][$priority['kind']] ?? null;
            $limit = $priority['max_distance_m'] ?? null;

            // A required priority with no measurement is a disqualifier, not a
            // pass. Absence of evidence about a hard requirement cannot be
            // treated as satisfaction of it.
            if ($actual === null) {
                $reasons[] = 'required_priority_unmeasured:'.$priority['kind'];

                continue;
            }

            if ($limit !== null && $actual > $limit) {
                $reasons[] = 'required_priority_exceeded:'.$priority['kind'];
            }
        }

        return $reasons;
    }

    /**
     * @param  LifestyleProfileInput  $profile
     * @param  LifestyleCandidate  $candidate
     * @return array{score: int, reason: string}
     */
    private function budgetFit(array $profile, array $candidate): array
    {
        $price = $candidate['price'] ?? null;
        $max = $profile['budget_max'] ?? null;

        if ($price === null) {
            return ['score' => 0, 'reason' => 'no_price_available'];
        }

        if ($max === null) {
            return ['score' => 50, 'reason' => 'no_budget_stated'];
        }

        $priceValue = Decimal::of((string) $price, 4);
        $maxValue = Decimal::of((string) $max, 4);

        if ($maxValue->isZero()) {
            return ['score' => 0, 'reason' => 'budget_is_zero'];
        }

        if ($priceValue->greaterThan($maxValue)) {
            return ['score' => 0, 'reason' => 'above_budget'];
        }

        $ratio = $priceValue->divide($maxValue)->toFloat();

        // Peak at 75-95% of budget. Well under budget is not automatically
        // better: it usually means fewer rooms or a worse location, and a
        // scoring curve that rewards cheapness would rank a bedsit above the
        // family apartment the user actually described.
        $score = match (true) {
            $ratio >= 0.75 && $ratio <= 0.95 => 100,
            $ratio > 0.95 => 85,
            $ratio >= 0.55 => 80,
            $ratio >= 0.35 => 60,
            default => 40,
        };

        return [
            'score' => $score,
            'reason' => sprintf('price_is_%d_percent_of_budget', (int) round($ratio * 100)),
        ];
    }

    /**
     * @param  list<string>  $kinds
     * @param  LifestyleProfileInput  $profile
     * @param  LifestyleCandidate  $candidate
     * @return array{score: int, reason: string}
     */
    private function distanceFit(array $profile, array $candidate, array $kinds): array
    {
        $relevant = array_values(array_filter(
            $profile['priorities'] ?? [],
            static fn (array $p): bool => in_array($p['kind'], $kinds, true),
        ));

        if ($relevant === []) {
            // Not stated as a priority, so it neither helps nor hurts.
            return ['score' => 50, 'reason' => 'not_a_stated_priority'];
        }

        $totalImportance = 0;
        $earned = 0;
        $unmeasured = 0;

        foreach ($relevant as $priority) {
            $importance = max(1, (int) ($priority['importance'] ?? 3));
            $totalImportance += $importance;

            $actual = $candidate['distances'][$priority['kind']] ?? null;
            $limit = $priority['max_distance_m'] ?? null;

            if ($actual === null || $limit === null || $limit <= 0) {
                $unmeasured++;

                continue;
            }

            $ratio = $actual / $limit;

            $componentScore = match (true) {
                $ratio <= 0.5 => 100,
                $ratio <= 1.0 => (int) round(100 - ($ratio - 0.5) * 80),
                $ratio <= 1.5 => (int) round(60 - ($ratio - 1.0) * 100),
                default => 0,
            };

            $earned += $componentScore * $importance;
        }

        /*
         * No `$totalImportance === 0` branch: `$relevant` is already proven
         * non-empty above, and each importance is `max(1, ...)`, so the total
         * is at least one. The case it claimed to handle — nothing stated as a
         * priority — returns earlier as `not_a_stated_priority`, which is the
         * accurate reason. Two returns for one situation, one of them
         * unreachable, only made the scoring harder to follow.
         */
        if ($unmeasured === count($relevant)) {
            // Nothing could be measured. Reported honestly as unknown rather
            // than scored as neutral, which would read as "acceptable".
            return ['score' => 0, 'reason' => 'distances_unavailable'];
        }

        return [
            'score' => (int) round($earned / $totalImportance),
            'reason' => $unmeasured > 0
                ? sprintf('%d_of_%d_priorities_unmeasured', $unmeasured, count($relevant))
                : 'all_priorities_measured',
        ];
    }

    /**
     * @param  LifestyleCandidate  $candidate
     * @return array{score: int, reason: string}
     */
    private function serviceCoverage(array $candidate): array
    {
        $amenity = $candidate['amenity_score'] ?? null;

        if ($amenity === null) {
            return ['score' => 0, 'reason' => 'no_amenity_data'];
        }

        return ['score' => max(0, min(100, (int) $amenity)), 'reason' => 'nearby_amenity_score'];
    }

    /**
     * @param  LifestyleProfileInput  $profile
     * @param  LifestyleCandidate  $candidate
     * @return array{score: int, reason: string}
     */
    private function propertyTypeFit(array $profile, array $candidate): array
    {
        $wanted = $profile['property_types'] ?? [];
        $actual = $candidate['property_type'] ?? null;

        if ($wanted === []) {
            return ['score' => 50, 'reason' => 'no_type_preference'];
        }

        if ($actual === null) {
            return ['score' => 0, 'reason' => 'property_type_unknown'];
        }

        return in_array($actual, $wanted, true)
            ? ['score' => 100, 'reason' => 'matches_preferred_type']
            : ['score' => 0, 'reason' => 'type_not_preferred'];
    }

    /**
     * @param  LifestyleCandidate  $candidate
     * @return array{score: int, reason: string}
     */
    private function riskAdjustment(array $candidate): array
    {
        $risk = $candidate['risk_score'] ?? null;

        if ($risk === null) {
            // Unknown risk is not low risk. Scoring it neutral would let a
            // project with no risk assessment outrank one honestly assessed as
            // slightly risky.
            return ['score' => 40, 'reason' => 'risk_not_assessed'];
        }

        return ['score' => max(0, min(100, 100 - (int) $risk)), 'reason' => 'inverse_of_assessed_risk'];
    }

    /**
     * @param  LifestyleCandidate  $candidate
     */
    private function confidence(array $candidate): string
    {
        $declared = $candidate['data_confidence'] ?? null;

        if (in_array($declared, ['high', 'moderate', 'low'], true)) {
            return (string) $declared;
        }

        $known = 0;

        foreach (['price', 'property_type', 'amenity_score', 'risk_score'] as $field) {
            if (($candidate[$field] ?? null) !== null) {
                $known++;
            }
        }

        return match (true) {
            $known >= 4 => 'moderate',
            $known >= 2 => 'low',
            default => 'insufficient',
        };
    }
}
