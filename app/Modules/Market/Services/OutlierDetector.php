<?php

declare(strict_types=1);

namespace App\Modules\Market\Services;

use App\Modules\Core\ValueObjects\Decimal;
use App\Modules\Market\Support\Statistics;

/**
 * Robust outlier detection for price series (spec 15.2 "outlier rules",
 * spec 14.3 "detect unrealistic jumps").
 *
 * Uses the modified z-score of Iglewicz and Hoaglin:
 *
 *     M_i = 0.6745 * (x_i - median) / MAD
 *
 * flagging |M_i| > 3.5. The 0.6745 constant makes the MAD a consistent
 * estimator of the standard deviation for normally distributed data.
 *
 * WHY NOT STANDARD DEVIATION. The classic 3-sigma rule fails on exactly the
 * data this product handles. A single mistyped price — 2,400,000 where
 * 240,000 was meant — inflates the standard deviation enough that the mistyped
 * value falls inside three sigma of the now-corrupted mean, so the test passes
 * the very error it exists to catch. The MAD is unaffected by up to half the
 * sample being wrong.
 *
 * The comparison is rearranged to avoid dividing by the MAD:
 *
 *     |x - median| * 6745  >  35000 * MAD
 *
 * which is exact Decimal multiplication, and which also behaves correctly when
 * the MAD is zero instead of dividing by it.
 */
final class OutlierDetector
{
    /** Iglewicz-Hoaglin threshold, expressed as an integer ratio. */
    private const NUMERATOR = '6745';   // 0.6745 * 10000

    private const THRESHOLD = '35000';  // 3.5 * 10000

    /**
     * @param  list<Decimal>  $values
     * @return array{
     *     median: string|null, mad: string|null,
     *     outlier_indexes: list<int>, kept_indexes: list<int>,
     *     method: string, degenerate: bool
     * }
     */
    public function detect(array $values): array
    {
        $count = count($values);

        // Below four observations there is no meaningful notion of an outlier:
        // with three values, one third of the sample cannot be "unusual".
        // Reporting none is honest; reporting one would be an artefact.
        if ($count < 4) {
            return [
                'median' => Statistics::median($values)?->toString(),
                'mad' => null,
                'outlier_indexes' => [],
                'kept_indexes' => array_keys($values),
                'method' => 'skipped_small_sample',
                'degenerate' => false,
            ];
        }

        $median = Statistics::median($values, 8);
        $mad = Statistics::medianAbsoluteDeviation($values, 8);

        if ($median === null || $mad === null) {
            return [
                'median' => null, 'mad' => null,
                'outlier_indexes' => [], 'kept_indexes' => array_keys($values),
                'method' => 'unavailable', 'degenerate' => true,
            ];
        }

        // A zero MAD means more than half the values are identical. The
        // modified z-score is undefined there, so fall back to flagging only
        // values that differ from the median at all — which is the correct
        // reading: in a series of 20 identical figures, the one that differs
        // IS the anomaly.
        if ($mad->isZero()) {
            $outliers = [];

            foreach ($values as $index => $value) {
                if (! $value->equals($median)) {
                    $outliers[] = $index;
                }
            }

            return [
                'median' => $median->toScale(4)->toString(),
                'mad' => '0.0000',
                'outlier_indexes' => $outliers,
                'kept_indexes' => array_values(array_diff(array_keys($values), $outliers)),
                'method' => 'zero_mad_exact_match',
                'degenerate' => true,
            ];
        }

        $limit = $mad->multiply(self::THRESHOLD);
        $outliers = [];

        foreach ($values as $index => $value) {
            $scaled = $value->subtract($median)->abs()->multiply(self::NUMERATOR);

            if ($scaled->greaterThan($limit)) {
                $outliers[] = $index;
            }
        }

        return [
            'median' => $median->toScale(4)->toString(),
            'mad' => $mad->toScale(4)->toString(),
            'outlier_indexes' => $outliers,
            'kept_indexes' => array_values(array_diff(array_keys($values), $outliers)),
            'method' => 'modified_z_score',
            'degenerate' => false,
        ];
    }

    /**
     * Period-over-period jump check (spec 14.3 "detect unrealistic jumps").
     *
     * Separate from the distribution test above and asking a different
     * question: not "is this value unusual among its peers" but "did this
     * series move more between two consecutive periods than a property market
     * plausibly can".
     *
     * The default threshold is 40% month-over-month. Erbil is a volatile
     * market and 15% would produce constant false positives; 40% is high
     * enough that a genuine move survives it and low enough to catch a
     * misplaced decimal or a currency mix-up, which are the actual failure
     * modes.
     *
     * @param  list<array{period: string, value: Decimal}>  $series  ascending by period
     * @return list<array{from_period: string, to_period: string, change_percent: string, direction: string}>
     */
    public function unrealisticJumps(array $series, float $thresholdPercent = 40.0): array
    {
        $flagged = [];
        $threshold = Decimal::of((string) $thresholdPercent, 4);

        for ($i = 1, $n = count($series); $i < $n; $i++) {
            $previous = $series[$i - 1]['value'];
            $current = $series[$i]['value'];

            $change = $previous->percentageChangeTo($current, 4);

            // A move away from zero is undefined, not infinite. It is a
            // separate data problem and is reported by the validator.
            if ($change === null) {
                continue;
            }

            if ($change->abs()->greaterThan($threshold)) {
                $flagged[] = [
                    'from_period' => $series[$i - 1]['period'],
                    'to_period' => $series[$i]['period'],
                    'change_percent' => $change->toScale(2)->toString(),
                    'direction' => $change->isNegative() ? 'down' : 'up',
                ];
            }
        }

        return $flagged;
    }
}
