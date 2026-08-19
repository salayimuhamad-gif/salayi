<?php

declare(strict_types=1);

namespace App\Modules\Projects\Services;

use App\Modules\Core\ValueObjects\Decimal;
use App\Modules\Projects\Enums\RatingCategory;
use App\Modules\Projects\Enums\RatingType;

/**
 * Turns individual ratings into the figures shown on a project (spec 13.2, 13.4).
 *
 * Storage-free by design so the two rules that matter can be tested
 * exhaustively without a database:
 *
 *   1. "These types must remain separate" (13.2). Each RatingType gets its own
 *      aggregate. There is no code path that averages an internal expert
 *      assessment together with an anonymous public rating.
 *
 *   2. "No single anonymous rating may create an official project score" (13.4).
 *      Only three types contribute to the official score at all, and each has a
 *      minimum sample size below which it is not even displayed as an aggregate.
 *
 * Every returned figure carries its sample size, its provenance and its
 * confidence, because spec 13.4 requires the public display to show all three.
 * A bare number with no provenance cannot be produced by this class.
 */
final class RatingAggregator
{
    /** Ratings are on a 0..5 scale. */
    public const SCALE_MAX = 5.0;

    /**
     * Aggregate one category across every rating type, keeping types separate.
     *
     * `value` is the DECIMAL STRING the model stores, not a float: ratings are
     * summed through `Decimal` precisely so repeated averaging cannot drift,
     * and declaring float here invited a caller to hand over a value that had
     * already lost precision on the way in.
     *
     * @param  list<array{type: RatingType, value: string, weight?: float}>  $ratings
     * @return array{
     *     by_type: array<string, array{
     *         type: string, mean: string, count: int, displayable: bool,
     *         contributes_to_official: bool, minimum_sample: int, ai_generated: bool
     *     }>,
     *     official: array{score: string|null, confidence: string, contributing_types: list<string>, sample_size: int},
     *     inverted: bool
     * }
     */
    public function aggregateCategory(RatingCategory $category, array $ratings): array
    {
        $grouped = [];

        foreach ($ratings as $rating) {
            $grouped[$rating['type']->value][] = $rating;
        }

        $byType = [];

        foreach (RatingType::cases() as $type) {
            $entries = $grouped[$type->value] ?? [];
            $count = count($entries);

            if ($count === 0) {
                continue;
            }

            $sum = Decimal::zero(4);

            foreach ($entries as $entry) {
                $sum = $sum->add(Decimal::of((string) $entry['value'], 4));
            }

            $mean = $sum->divide($count)->toScale(2);

            $byType[$type->value] = [
                'type' => $type->value,
                'mean' => $mean->toString(),
                'count' => $count,
                // Below the minimum sample this type is NOT shown as an
                // aggregate. Spec 13.4 — one anonymous rating is not a score.
                'displayable' => $count >= $type->minimumSampleSize(),
                'contributes_to_official' => $type->contributesToOfficialScore(),
                'minimum_sample' => $type->minimumSampleSize(),
                'ai_generated' => $type->isAiGenerated(),
            ];
        }

        return [
            'by_type' => $byType,
            'official' => $this->officialScore($byType),
            'inverted' => $category->isInverted(),
        ];
    }

    /**
     * The official score: a weighted mean over only the contributing types
     * that also cleared their minimum sample size.
     *
     * Weights are renormalised across whichever types are actually present, so
     * a project with an expert rating but no resident survey still produces a
     * meaningful figure rather than one silently scaled down by the missing
     * 30%.
     *
     * @param  array<string, array{mean: string, count: int, displayable: bool, contributes_to_official: bool}>  $byType
     * @return array{score: string|null, confidence: string, contributing_types: list<string>, sample_size: int}
     */
    public function officialScore(array $byType): array
    {
        $weightedSum = Decimal::zero(6);
        $totalWeight = 0;
        $contributing = [];
        $sampleSize = 0;

        foreach ($byType as $key => $entry) {
            $type = RatingType::from($key);

            if (! $type->contributesToOfficialScore() || ! $entry['displayable']) {
                continue;
            }

            $weight = $type->officialWeight();

            if ($weight === 0) {
                continue;
            }

            $weightedSum = $weightedSum->add(Decimal::of($entry['mean'], 6)->multiply($weight));
            $totalWeight += $weight;
            $contributing[] = $type->value;
            $sampleSize += $entry['count'];
        }

        if ($totalWeight === 0) {
            return [
                'score' => null,
                'confidence' => 'insufficient',
                'contributing_types' => [],
                'sample_size' => 0,
            ];
        }

        return [
            'score' => $weightedSum->divide($totalWeight)->toScale(2)->toString(),
            'confidence' => $this->confidence($contributing, $sampleSize),
            'contributing_types' => $contributing,
            'sample_size' => $sampleSize,
        ];
    }

    /**
     * Confidence label for the public display (spec 13.4).
     *
     * Driven by how MANY independent kinds of evidence agree, not by how many
     * individual ratings exist. Fifty public ratings and no expert assessment
     * is not high confidence; one expert plus one resident survey is better
     * evidence than either alone.
     *
     * There is deliberately NO global sample-size threshold here. Volume is
     * already enforced per type by RatingType::minimumSampleSize() — a resident
     * survey needs five responses and a public aggregate needs ten before
     * either can contribute at all. Applying a second, global floor on top of
     * that double-counts volume and, worse, made `moderate` unreachable for the
     * commonest sound pairing: one signed expert assessment plus one calculated
     * market score, which is two ratings in total and genuinely moderate
     * evidence. That defect was caught by the test suite.
     *
     * $sampleSize is accepted but intentionally unused: it is kept in the
     * signature because the confidence rule is a product judgement likely to be
     * revisited, and callers already have the figure to hand. Removing it would
     * make reinstating a volume term a breaking change across every call site.
     *
     * @param  list<string>  $contributingTypes
     * @param  int  $sampleSize  total contributing ratings; see note above
     */
    public function confidence(array $contributingTypes, int $sampleSize = 0): string
    {
        $distinctTypes = count(array_unique($contributingTypes));

        return match (true) {
            $distinctTypes === 0 => 'insufficient',
            $distinctTypes >= 3 => 'high',
            $distinctTypes === 2 => 'moderate',
            default => 'low',
        };
    }

    /**
     * Normalise an inverted category for display as "higher is better".
     *
     * Noise, traffic and investment risk are stored as recorded — a 4.5 noise
     * rating means it is noisy — and flipped only at the presentation edge, so
     * the stored value always means what the rater meant.
     */
    public function displayValue(RatingCategory $category, ?string $raw): ?string
    {
        if ($raw === null) {
            return null;
        }

        if (! $category->isInverted()) {
            return $raw;
        }

        return Decimal::of((string) self::SCALE_MAX, 2)->subtract(Decimal::of($raw, 2))->toString();
    }
}
