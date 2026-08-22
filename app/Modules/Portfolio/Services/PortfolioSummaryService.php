<?php

declare(strict_types=1);

namespace App\Modules\Portfolio\Services;

use App\Modules\Core\ValueObjects\Decimal;
use App\Modules\Portfolio\Models\PortfolioProperty;
use App\Modules\Portfolio\Models\PortfolioValuation;

/**
 * The portfolio's honest summary (redesign Wave 5 §6/§13).
 *
 * Derived on every read from the rows that already exist — the properties and
 * their append-only valuation history. Nothing here is stored, cached or
 * projected, so the summary can never disagree with the pages beneath it.
 *
 * Two rules carry the whole class:
 *
 *   CURRENT VALUE is the property's `latestValuation` — the same
 *   MAX(calculated_at) relation the index and show pages already render, so
 *   "the total" and "the cards" are one fact. A latest row that records a
 *   refusal (`midpoint` null) contributes honesty, not a number: the property
 *   counts as awaiting, never as zero.
 *
 *   CURRENCIES NEVER MIX. A valuation carries its own currency, and IQD and
 *   USD are not addable quantities. Totals are grouped per currency, exactly,
 *   with the platform's Decimal — never floats — and when more than one group
 *   exists the interface says so instead of inventing an exchange rate.
 */
final class PortfolioSummaryService
{
    /**
     * @return array{
     *     property_count: int,
     *     valued_count: int,
     *     awaiting_count: int,
     *     totals: list<array{currency: string, total: string, count: int}>,
     *     multi_currency: bool,
     *     latest_valued_at: string|null,
     *     composition: list<array{property_type: string, count: int}>,
     *     state: string,
     * }
     */
    public function summarise(int $userId): array
    {
        $properties = PortfolioProperty::query()
            ->ownedBy($userId)
            ->with('latestValuation')
            ->get();

        $propertyCount = $properties->count();

        /*
         * The rows that honestly carry a current figure: a latest valuation
         * exists AND it produced a midpoint. A refusal row is the latest
         * word on that property — it keeps the property out of the totals,
         * it does not become a zero in them.
         */
        $valuedCount = 0;
        $latestValuedAt = null;

        /** @var array<string, array{currency: string, total: Decimal, count: int}> $totals */
        $totals = [];

        foreach ($properties as $property) {
            $valuation = $property->latestValuation;

            if (! $valuation instanceof PortfolioValuation || $valuation->midpoint === null) {
                continue;
            }

            $valuedCount++;

            $date = $valuation->calculated_at->toDateString();

            if ($latestValuedAt === null || $date > $latestValuedAt) {
                $latestValuedAt = $date;
            }

            $currency = $valuation->currency;
            $running = isset($totals[$currency]) ? $totals[$currency]['total'] : Decimal::zero(4);

            $totals[$currency] = [
                'currency' => $currency,
                'total' => $running->add(Decimal::of((string) $valuation->midpoint, 4)),
                'count' => isset($totals[$currency]) ? $totals[$currency]['count'] + 1 : 1,
            ];
        }

        ksort($totals, SORT_STRING);

        $composition = $properties
            ->countBy(static fn (PortfolioProperty $property): string => $property->property_type)
            ->map(static fn (int $count, string $type): array => [
                'property_type' => $type,
                'count' => $count,
            ])
            ->sortBy([['count', 'desc'], ['property_type', 'asc']])
            ->values()
            ->all();

        return [
            'property_count' => $propertyCount,
            'valued_count' => $valuedCount,
            'awaiting_count' => $propertyCount - $valuedCount,
            'totals' => array_values(array_map(static fn (array $group): array => [
                'currency' => $group['currency'],
                'total' => $group['total']->toString(),
                'count' => $group['count'],
            ], $totals)),
            'multi_currency' => count($totals) > 1,
            'latest_valued_at' => $latestValuedAt,
            'composition' => $composition,
            'state' => $propertyCount === 0
                ? 'no_assets'
                : ($valuedCount === 0 ? 'no_valuations' : 'ready'),
        ];
    }
}
