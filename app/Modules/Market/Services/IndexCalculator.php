<?php

declare(strict_types=1);

namespace App\Modules\Market\Services;

use App\Modules\Core\ValueObjects\Decimal;
use App\Modules\Market\Enums\PriceType;
use App\Modules\Market\Support\Statistics;
use InvalidArgumentException;

/**
 * The index engine (spec 15.2) and its explainability contract (spec 15.3).
 *
 * Two commitments are enforced structurally rather than by convention:
 *
 *   1. NO MIXED PROVENANCE. Every observation fed to one index must share a
 *      PriceType family and transaction basis. A mixed batch is refused
 *      outright rather than silently filtered, because silently dropping half
 *      an input set produces a number that looks fine and is not what the
 *      caller asked for. Spec 14.1.
 *
 *   2. NO BARE NUMBERS. calculate() cannot return a value without its period,
 *      sample size, confidence, methodology version, source summary, outlier
 *      count and — when the sample is thin — an explicit limitation warning.
 *      Spec 15.3 requires all of these on every public index value, so they
 *      are fields of the return type rather than something a caller might
 *      forget to attach.
 *
 * Storage-free, so the methodology can be tested exhaustively and so a change
 * to it cannot diverge between the recomputation job, the admin preview and
 * the public page.
 */
final class IndexCalculator
{
    /** Below this the index is computed but flagged as limited (spec 15.3). */
    public const DEFAULT_MINIMUM_SAMPLE = 5;

    /** Below this no value is published at all. */
    public const ABSOLUTE_FLOOR = 3;

    public function __construct(
        private readonly OutlierDetector $outliers = new OutlierDetector,
    ) {}

    /**
     * @param  list<array{value: Decimal, price_type: PriceType, weight?: int|float, source?: string}>  $observations
     * @param  array{
     *     minimum_sample?: int, methodology_version?: string,
     *     exclude_outliers?: bool, basis?: string, currency?: string
     * }  $options
     * @return array{
     *     value: string|null, basis: string, period: string, currency: string,
     *     sample_size: int, excluded_outliers: int, contributing_sources: list<string>,
     *     price_type_family: string|null, confidence: string, is_limited: bool,
     *     warning: string|null, methodology_version: string, revision_status: string,
     *     minimum_sample: int, median: string|null, min: string|null, max: string|null
     * }
     *
     * @throws InvalidArgumentException when provenance is mixed
     */
    public function calculate(string $period, array $observations, array $options = []): array
    {
        $minimumSample = $options['minimum_sample'] ?? self::DEFAULT_MINIMUM_SAMPLE;
        $methodologyVersion = $options['methodology_version'] ?? 'v1';
        $excludeOutliers = $options['exclude_outliers'] ?? true;
        $basis = $options['basis'] ?? 'median';
        $currency = $options['currency'] ?? 'USD';

        $this->assertSingleProvenance($observations);

        $family = $observations === [] ? null : $observations[0]['price_type']->family();

        $empty = $this->emptyResult($period, $basis, $currency, $methodologyVersion, $minimumSample, $family);

        if ($observations === []) {
            return $empty;
        }

        $values = array_map(
            static fn (array $o): Decimal => $o['value'],
            $observations,
        );

        $excluded = 0;
        $kept = array_keys($observations);

        if ($excludeOutliers) {
            $detection = $this->outliers->detect($values);
            $kept = $detection['kept_indexes'];
            $excluded = count($detection['outlier_indexes']);
        }

        // `array_map` over a list returns a list; the keys never gap.
        $keptObservations = array_map(
            static fn (int $i): array => $observations[$i],
            $kept,
        );

        $sampleSize = count($keptObservations);

        // Below the absolute floor nothing is published. An index value
        // computed from two observations is not an index, and presenting one
        // with a "low confidence" label still puts a number on the page that
        // people will quote.
        if ($sampleSize < self::ABSOLUTE_FLOOR) {
            return array_merge($empty, [
                'sample_size' => $sampleSize,
                'excluded_outliers' => $excluded,
                'confidence' => 'insufficient',
                'is_limited' => true,
                'warning' => 'sample_below_publication_floor',
                'contributing_sources' => $this->sources($keptObservations),
            ]);
        }

        $keptValues = array_map(static fn (array $o): Decimal => $o['value'], $keptObservations);

        $value = match ($basis) {
            'weighted_mean' => Statistics::weightedMean(array_map(
                static fn (array $o): array => [
                    'value' => $o['value'],
                    // Absent an explicit weight, fall back to the price type's
                    // evidence strength so a verified transaction counts for
                    // more than an asking price within its own family.
                    'weight' => $o['weight'] ?? $o['price_type']->evidenceWeight(),
                ],
                $keptObservations,
            ), 4),
            'mean' => Statistics::mean($keptValues, 4),
            // Median is the default because property distributions are
            // right-skewed: a handful of premium units drag a mean upward and
            // produce an index nobody recognises as their own market.
            default => Statistics::median($keptValues, 4),
        };

        $isLimited = $sampleSize < $minimumSample;

        return [
            'value' => $value?->toString(),
            'basis' => $basis,
            'period' => $period,
            'currency' => $currency,
            'sample_size' => $sampleSize,
            'excluded_outliers' => $excluded,
            'contributing_sources' => $this->sources($keptObservations),
            'price_type_family' => $family,
            'confidence' => $this->confidence($sampleSize, $excluded, $family, $minimumSample),
            'is_limited' => $isLimited,
            'warning' => $isLimited ? 'sample_below_minimum' : null,
            'methodology_version' => $methodologyVersion,
            'revision_status' => 'initial',
            'minimum_sample' => $minimumSample,
            'median' => Statistics::median($keptValues, 4)?->toString(),
            'min' => Statistics::min($keptValues)?->toString(),
            'max' => Statistics::max($keptValues)?->toString(),
        ];
    }

    /**
     * Period-over-period change between two calculated results.
     *
     * Refuses to compare across methodology versions or provenance families.
     * A change figure computed between a v1 asking index and a v2 verified
     * index would be meaningless and would look exactly like a real movement.
     *
     * @param  array<string, mixed>  $previous
     * @param  array<string, mixed>  $current
     * @return array{comparable: bool, reason: string|null, change_percent: string|null, direction: string|null}
     */
    public function change(array $previous, array $current): array
    {
        $incomparable = static fn (string $reason): array => [
            'comparable' => false, 'reason' => $reason,
            'change_percent' => null, 'direction' => null,
        ];

        if ($previous['value'] === null || $current['value'] === null) {
            return $incomparable('missing_value');
        }

        if ($previous['price_type_family'] !== $current['price_type_family']) {
            return $incomparable('provenance_family_changed');
        }

        if ($previous['methodology_version'] !== $current['methodology_version']) {
            return $incomparable('methodology_version_changed');
        }

        if ($previous['currency'] !== $current['currency']) {
            return $incomparable('currency_changed');
        }

        if ($previous['basis'] !== $current['basis']) {
            return $incomparable('basis_changed');
        }

        $from = Decimal::of((string) $previous['value'], 4);
        $change = $from->percentageChangeTo((string) $current['value'], 2);

        if ($change === null) {
            return $incomparable('previous_value_zero');
        }

        return [
            'comparable' => true,
            'reason' => null,
            'change_percent' => $change->toString(),
            'direction' => $change->isZero() ? 'flat' : ($change->isNegative() ? 'down' : 'up'),
        ];
    }

    /**
     * Confidence for the public display (spec 15.3).
     *
     * Sample size AND provenance both matter. A hundred asking prices are not
     * high confidence about what the market actually transacted at; twenty
     * verified transactions are.
     */
    public function confidence(int $sampleSize, int $excludedOutliers, ?string $family, int $minimumSample): string
    {
        if ($sampleSize < self::ABSOLUTE_FLOOR) {
            return 'insufficient';
        }

        if ($sampleSize < $minimumSample) {
            return 'low';
        }

        // A batch where a large share was discarded is not trustworthy even
        // when what survived is numerous — it signals a source problem rather
        // than a clean sample.
        //
        // The boundary is INCLUSIVE at one quarter: if one row in four had to
        // be thrown away, the remaining three are not obviously sound either.
        // Sitting the comparison on the exclusive side made a batch with
        // exactly 25% discarded report moderate confidence, which the test
        // suite caught.
        $total = $sampleSize + $excludedOutliers;

        if ($total > 0 && $excludedOutliers / $total >= 0.25) {
            return 'low';
        }

        return match ($family) {
            'verified' => $sampleSize >= $minimumSample * 4 ? 'high' : 'moderate',
            'official' => $sampleSize >= $minimumSample * 4 ? 'moderate' : 'low',
            // Asking prices never reach high confidence about market value,
            // regardless of volume. They are evidence of intent, not of price.
            'asking' => $sampleSize >= $minimumSample * 4 ? 'moderate' : 'low',
            default => 'low',
        };
    }

    /**
     * @param  list<array{price_type: PriceType, ...}>  $observations
     *
     * @throws InvalidArgumentException
     */
    private function assertSingleProvenance(array $observations): void
    {
        if (count($observations) < 2) {
            return;
        }

        $first = $observations[0]['price_type'];

        foreach ($observations as $index => $observation) {
            if (! $first->mayAggregateWith($observation['price_type'])) {
                throw new InvalidArgumentException(sprintf(
                    'Refusing to aggregate "%s" with "%s" at position %d. '.
                    'Spec 14.1: official market snapshots and asking prices must never be mixed, '.
                    'and sale and rent figures are not summable.',
                    $first->value,
                    $observation['price_type']->value,
                    $index,
                ));
            }
        }
    }

    /**
     * @param  list<array{source?: string, ...}>  $observations
     * @return list<string>
     */
    private function sources(array $observations): array
    {
        $sources = [];

        foreach ($observations as $observation) {
            $source = $observation['source'] ?? null;

            if (is_string($source) && $source !== '') {
                $sources[] = $source;
            }
        }

        $unique = array_values(array_unique($sources));
        sort($unique);

        return $unique;
    }

    /** @return array<string, mixed> */
    private function emptyResult(
        string $period,
        string $basis,
        string $currency,
        string $methodologyVersion,
        int $minimumSample,
        ?string $family,
    ): array {
        return [
            'value' => null,
            'basis' => $basis,
            'period' => $period,
            'currency' => $currency,
            'sample_size' => 0,
            'excluded_outliers' => 0,
            'contributing_sources' => [],
            'price_type_family' => $family,
            'confidence' => 'insufficient',
            'is_limited' => true,
            'warning' => 'no_observations',
            'methodology_version' => $methodologyVersion,
            'revision_status' => 'initial',
            'minimum_sample' => $minimumSample,
            'median' => null,
            'min' => null,
            'max' => null,
        ];
    }
}
