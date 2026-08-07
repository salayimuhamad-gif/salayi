<?php

declare(strict_types=1);

namespace App\Modules\Portfolio\Services;

use App\Modules\Core\ValueObjects\Decimal;
use App\Modules\Market\Enums\PriceType;
use App\Modules\Market\Support\Statistics;

/**
 * Portfolio valuation (spec 20.2).
 *
 * Two commitments drive the whole design, both taken directly from the
 * specification rather than inferred:
 *
 *   1. "No valuation if insufficient reliable data" — the fifth and last item
 *      in the priority list. A valuation is a number a person may borrow
 *      against, sell on, or decline an offer because of. Producing a weak one
 *      with a "low confidence" label is worse than producing none, because the
 *      number is what gets remembered and the label is what gets forgotten.
 *      This engine returns null rather than guess.
 *
 *   2. "Excluded asking-price note" — a required field of the result. Asking
 *      prices are excluded from valuation entirely (they are what sellers hope
 *      for, not what buyers paid), and the exclusion is DISCLOSED rather than
 *      silent, so an owner can see why their valuation is lower than the
 *      listings they have been reading.
 *
 * Comparable selection follows the spec 20.2 priority ladder exactly, and the
 * match level reached is reported, because "same project, same unit type" and
 * "wider area fallback" are very different claims about the same figure.
 *
 * @phpstan-type ValuationProperty array{
 *     project_id?: int|null, area_id?: int|null, area_path?: string|null,
 *     property_type?: string|null, unit_type?: string|null, size_sqm?: string|float|null
 * }
 * @phpstan-type ValuationComparable array{
 *     price: string, price_type: PriceType, project_id?: int|null, area_id?: int|null,
 *     area_path?: string|null, property_type?: string|null, unit_type?: string|null,
 *     size_sqm?: string|float|null, effective_date?: string|null
 * }
 */
final class ValuationEngine
{
    /** Below this many comparables no valuation is produced at any tier. */
    public const MINIMUM_COMPARABLES = 3;

    /** Tier 1 and 2 size tolerance. */
    private const SIMILAR_SIZE_TOLERANCE = 0.15;

    /**
     * @param  array{
     *     project_id?: int|null, area_id?: int|null, area_path?: string|null,
     *     property_type?: string|null, unit_type?: string|null, size_sqm?: string|float|null
     * }  $property
     * @param  list<array{
     *     price: string, price_type: PriceType, project_id?: int|null, area_id?: int|null,
     *     area_path?: string|null, property_type?: string|null, unit_type?: string|null,
     *     size_sqm?: string|float|null, effective_date?: string|null
     * }>  $comparables
     * @return array{
     *     midpoint: string|null, low: string|null, high: string|null,
     *     confidence: string, match_level: int|null, match_label: string,
     *     methodology: string, comparison_count: int, data_period: array{from: string|null, to: string|null},
     *     excluded_asking_count: int, excluded_asking_note: string,
     *     currency: string, reason: string|null
     * }
     */
    public function value(array $property, array $comparables, string $currency = 'USD'): array
    {
        // Step one, before any matching: asking prices are removed. They never
        // participate in a valuation at any tier.
        $asking = [];
        $usable = [];

        foreach ($comparables as $comparable) {
            if ($comparable['price_type']->family() === 'asking') {
                $asking[] = $comparable;

                continue;
            }

            $usable[] = $comparable;
        }

        $excludedNote = $asking === []
            ? 'no_asking_prices_present'
            : 'asking_prices_excluded_from_valuation';

        $empty = [
            'midpoint' => null, 'low' => null, 'high' => null,
            'confidence' => 'insufficient', 'match_level' => null,
            'match_label' => 'none', 'methodology' => 'comparable_median_v1',
            'comparison_count' => 0,
            'data_period' => ['from' => null, 'to' => null],
            'excluded_asking_count' => count($asking),
            'excluded_asking_note' => $excludedNote,
            'currency' => $currency,
            'reason' => null,
        ];

        foreach ($this->tiers() as $level => $tier) {
            $matched = array_values(array_filter(
                $usable,
                fn (array $c): bool => $tier['matcher']($property, $c),
            ));

            if (count($matched) < self::MINIMUM_COMPARABLES) {
                continue;
            }

            return $this->summarise($matched, $level + 1, $tier['label'], $currency, count($asking), $excludedNote);
        }

        // Every tier exhausted. Spec 20.2 item 5.
        return array_merge($empty, [
            'reason' => $usable === []
                ? 'no_transaction_evidence_available'
                : 'insufficient_comparables_at_every_match_level',
        ]);
    }

    /**
     * The spec 20.2 priority ladder, coarsening one step at a time.
     *
     * @return list<array{label: string, matcher: callable}>
     */
    private function tiers(): array
    {
        return [
            [
                'label' => 'same_project_unit_type_and_size',
                'matcher' => fn (array $p, array $c): bool => $this->sameProject($p, $c)
                    && $this->sameUnitType($p, $c)
                    && $this->similarSize($p, $c),
            ],
            [
                'label' => 'same_project_similar_size',
                'matcher' => fn (array $p, array $c): bool => $this->sameProject($p, $c)
                    && $this->similarSize($p, $c, 0.30),
            ],
            [
                'label' => 'same_area_property_type_and_size',
                'matcher' => fn (array $p, array $c): bool => $this->sameArea($p, $c)
                    && $this->samePropertyType($p, $c)
                    && $this->similarSize($p, $c, 0.30),
            ],
            [
                'label' => 'wider_area_fallback',
                'matcher' => fn (array $p, array $c): bool => $this->withinWiderArea($p, $c)
                    && $this->samePropertyType($p, $c),
            ],
        ];
    }

    /**
     * @param  list<array<string, mixed>>  $matched
     * @return array<string, mixed>
     */
    private function summarise(
        array $matched,
        int $level,
        string $label,
        string $currency,
        int $excludedAsking,
        string $excludedNote,
    ): array {
        $prices = array_map(
            static fn (array $c): Decimal => Decimal::of((string) $c['price'], 4),
            $matched,
        );

        // Median, not mean. A single premium unit in a small comparable set
        // would drag a mean upward and hand the owner a figure no buyer will
        // match.
        $midpoint = Statistics::median($prices, 4);

        // The published range is the interquartile spread, not min-to-max. One
        // distressed sale should not widen an owner's stated range by 40%.
        $low = Statistics::percentile($prices, 25, 4);
        $high = Statistics::percentile($prices, 75, 4);

        $dates = array_values(array_filter(array_map(
            static fn (array $c): ?string => isset($c['effective_date']) ? (string) $c['effective_date'] : null,
            $matched,
        )));
        sort($dates);

        return [
            'midpoint' => $midpoint?->toString(),
            'low' => $low?->toString(),
            'high' => $high?->toString(),
            'confidence' => $this->confidence($level, count($matched)),
            'match_level' => $level,
            'match_label' => $label,
            'methodology' => 'comparable_median_v1',
            'comparison_count' => count($matched),
            'data_period' => [
                'from' => $dates[0] ?? null,
                'to' => $dates === [] ? null : $dates[count($dates) - 1],
            ],
            'excluded_asking_count' => $excludedAsking,
            'excluded_asking_note' => $excludedNote,
            'currency' => $currency,
            'reason' => null,
        ];
    }

    /**
     * Confidence falls as the match coarsens, regardless of sample size.
     *
     * Forty comparables from a "wider area fallback" is not high confidence
     * about one specific apartment — it is a lot of weakly relevant evidence,
     * and treating volume as a substitute for relevance is how a valuation
     * ends up confidently wrong.
     */
    public function confidence(int $matchLevel, int $count): string
    {
        return match (true) {
            $matchLevel === 1 && $count >= 8 => 'high',
            $matchLevel === 1 => 'moderate',
            $matchLevel === 2 && $count >= 8 => 'moderate',
            $matchLevel === 2 => 'low',
            $matchLevel === 3 && $count >= 12 => 'moderate',
            default => 'low',
        };
    }

    /**
     * @param  ValuationProperty  $p
     * @param  ValuationComparable  $c
     */
    private function sameProject(array $p, array $c): bool
    {
        // Read once: the second lookup repeated a key the first has
        // already established, and the fallback there could never fire.
        $propertyValue = $p['project_id'] ?? null;

        return $propertyValue !== null && $propertyValue === ($c['project_id'] ?? null);
    }

    /**
     * @param  ValuationProperty  $p
     * @param  ValuationComparable  $c
     */
    private function sameArea(array $p, array $c): bool
    {
        // Read once: the second lookup repeated a key the first has
        // already established, and the fallback there could never fire.
        $propertyValue = $p['area_id'] ?? null;

        return $propertyValue !== null && $propertyValue === ($c['area_id'] ?? null);
    }

    /**
     * @param  ValuationProperty  $p
     * @param  ValuationComparable  $c
     */
    private function samePropertyType(array $p, array $c): bool
    {
        // Read once, as above.
        $propertyValue = $p['property_type'] ?? null;

        return $propertyValue !== null && $propertyValue === ($c['property_type'] ?? null);
    }

    /**
     * @param  ValuationProperty  $p
     * @param  ValuationComparable  $c
     */
    private function sameUnitType(array $p, array $c): bool
    {
        // Read once: the second lookup repeated a key the first has
        // already established, and the fallback there could never fire.
        $propertyValue = $p['unit_type'] ?? null;

        return $propertyValue !== null && $propertyValue === ($c['unit_type'] ?? null);
    }

    /**
     * Wider-area fallback via the materialised path from Step 2.
     *
     * A comparable in a sibling neighbourhood of the same district qualifies;
     * one from another district does not. Without the path check this tier
     * would silently mean "anywhere in Erbil".
     */
    /**
     * @param  ValuationProperty  $p
     * @param  ValuationComparable  $c
     */
    private function withinWiderArea(array $p, array $c): bool
    {
        $propertyPath = $p['area_path'] ?? null;
        $comparablePath = $c['area_path'] ?? null;

        if (! is_string($propertyPath) || ! is_string($comparablePath)) {
            return false;
        }

        $parent = $this->parentPath($propertyPath);

        return $parent !== null && str_starts_with($comparablePath, $parent);
    }

    private function parentPath(string $path): ?string
    {
        $segments = array_values(array_filter(explode('/', trim($path, '/'))));

        if (count($segments) < 2) {
            return null;
        }

        array_pop($segments);

        return '/'.implode('/', $segments).'/';
    }

    /**
     * @param  ValuationProperty  $p
     * @param  ValuationComparable  $c
     */
    private function similarSize(array $p, array $c, float $tolerance = self::SIMILAR_SIZE_TOLERANCE): bool
    {
        $propertySize = $p['size_sqm'] ?? null;
        $comparableSize = $c['size_sqm'] ?? null;

        if ($propertySize === null || $comparableSize === null) {
            return false;
        }

        $a = (float) $propertySize;
        $b = (float) $comparableSize;

        if ($a <= 0.0 || $b <= 0.0) {
            return false;
        }

        return abs($a - $b) / $a <= $tolerance;
    }
}
