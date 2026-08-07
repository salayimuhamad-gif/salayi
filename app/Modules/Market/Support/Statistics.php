<?php

declare(strict_types=1);

namespace App\Modules\Market\Support;

use App\Modules\Core\ValueObjects\Decimal;

/**
 * Exact descriptive statistics over money (spec 14.1 minimum, maximum, median).
 *
 * Everything is Decimal end to end. Spec 26.1 bans floats for financial values,
 * and a median is a published figure like any other — a market index that
 * drifts by a fraction of a cent between recomputations invites exactly the
 * "your numbers changed" complaint the platform cannot afford.
 *
 * The median of an even-sized set is the mean of the two central values, which
 * is a division and therefore rounded half-up at the working scale like every
 * other Decimal operation.
 */
final class Statistics
{
    /**
     * @param  list<Decimal>  $values
     * @return list<Decimal> ascending
     */
    public static function sorted(array $values): array
    {
        usort($values, static fn (Decimal $a, Decimal $b): int => $a->compareTo($b));

        // `usort` reindexes in place, so the result is already a list.
        return $values;
    }

    /** @param list<Decimal> $values */
    public static function median(array $values, int $scale = 4): ?Decimal
    {
        if ($values === []) {
            return null;
        }

        $sorted = self::sorted($values);
        $count = count($sorted);
        $middle = intdiv($count, 2);

        if ($count % 2 === 1) {
            return $sorted[$middle]->toScale($scale);
        }

        return $sorted[$middle - 1]->add($sorted[$middle])->divide(2)->toScale($scale);
    }

    /** @param list<Decimal> $values */
    public static function mean(array $values, int $scale = 4): ?Decimal
    {
        if ($values === []) {
            return null;
        }

        $sum = Decimal::zero(8);

        foreach ($values as $value) {
            $sum = $sum->add($value);
        }

        return $sum->divide(count($values))->toScale($scale);
    }

    /** @param list<Decimal> $values */
    public static function min(array $values): ?Decimal
    {
        return $values === [] ? null : self::sorted($values)[0];
    }

    /** @param list<Decimal> $values */
    public static function max(array $values): ?Decimal
    {
        if ($values === []) {
            return null;
        }

        $sorted = self::sorted($values);

        return $sorted[count($sorted) - 1];
    }

    /**
     * Median absolute deviation.
     *
     * The robust scale estimate. Chosen over standard deviation because
     * property price data arrives with data-entry errors — an extra zero turns
     * 240,000 into 2,400,000 — and a standard deviation is inflated by exactly
     * the outlier it is being used to detect, which masks it. The MAD is
     * unmoved by up to half the sample being wrong.
     *
     * @param  list<Decimal>  $values
     */
    public static function medianAbsoluteDeviation(array $values, int $scale = 4): ?Decimal
    {
        $median = self::median($values, 8);

        if ($median === null) {
            return null;
        }

        $deviations = array_map(
            static fn (Decimal $v): Decimal => $v->subtract($median)->abs(),
            $values,
        );

        return self::median($deviations, $scale);
    }

    /**
     * Weighted mean, exact.
     *
     * @param  list<array{value: Decimal, weight: int|float|string}>  $weighted
     */
    public static function weightedMean(array $weighted, int $scale = 4): ?Decimal
    {
        if ($weighted === []) {
            return null;
        }

        $numerator = Decimal::zero(8);
        $denominator = Decimal::zero(8);

        foreach ($weighted as $entry) {
            $weight = Decimal::of((string) $entry['weight'], 8);
            $numerator = $numerator->add($entry['value']->multiply($weight));
            $denominator = $denominator->add($weight);
        }

        if ($denominator->isZero()) {
            return null;
        }

        return $numerator->divide($denominator)->toScale($scale);
    }

    /**
     * Percentile by nearest-rank, which never interpolates between two
     * observed prices to invent one that nobody paid.
     *
     * @param  list<Decimal>  $values
     */
    public static function percentile(array $values, float $percentile, int $scale = 4): ?Decimal
    {
        if ($values === [] || $percentile < 0.0 || $percentile > 100.0) {
            return null;
        }

        $sorted = self::sorted($values);
        $rank = (int) ceil($percentile / 100.0 * count($sorted));
        $index = max(0, min(count($sorted) - 1, $rank - 1));

        return $sorted[$index]->toScale($scale);
    }
}
