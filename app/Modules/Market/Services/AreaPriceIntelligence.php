<?php

declare(strict_types=1);

namespace App\Modules\Market\Services;

use App\Modules\Geography\Models\Area;
use App\Modules\Market\Models\MarketIndex;
use App\Modules\Market\Models\MarketIndexValue;

/**
 * Current published price intelligence for one area — the Wave 3 lookup
 * EXTRACTED VERBATIM from LocationResolveController::priceIntelligence()
 * (Map Phase 6), so the location card and the area comparison answer from
 * ONE selection rule instead of two copies that drift. The methodology is
 * unchanged; only its address moved.
 *
 * The source is exactly the map price layer's: published area-scoped
 * MarketIndex definitions, each carrying ONE price_type, basis and
 * currency, valued by the shared latest-reliable selection (published,
 * non-null, not limited, MAX(period) with reliability in both halves).
 *
 * The lookup walks the area's own containment chain — the area first, then
 * its published ancestors nearest-first — and answers from the MOST
 * SPECIFIC area that carries any reliable figure. Every area in that chain
 * genuinely contains the original one (hierarchy containment is enforced
 * on save), so this is never a nearest-area guess; the answer names the
 * area the figures describe.
 *
 * Indices are returned as SEPARATE rows, never combined: a sale index and
 * a rent index, or two currencies, stay apart exactly as §14.1 requires —
 * the one-price_type-per-index schema makes mixing unrepresentable, and
 * nothing here adds arithmetic. No figure, no zero, no average, no
 * fallback.
 *
 * TWO LAYERS, ONE IMPLEMENTATION: resolve() performs the selection and
 * keeps the models (the comparison needs identity fields — property_type,
 * methodology_version — that the public row deliberately omits);
 * publicPayload() renders the EXACT row shape /location/resolve has always
 * served, so extraction cannot change that contract by a single field.
 */
final class AreaPriceIntelligence
{
    public function __construct(private readonly LatestReliableIndexValues $latestValues) {}

    /**
     * The selection: which area in the containment chain answers, with
     * which (index, latest reliable value) pairs.
     *
     * @return array{
     *     available: bool,
     *     reason: string|null,
     *     area: Area|null,
     *     matches: list<array{index: MarketIndex, latest: MarketIndexValue}>
     * }
     */
    public function resolve(Area $area): array
    {
        // The same flag that gates the map's price layer: a switched-off
        // module gathers nothing, and saying "no data" would be the wrong
        // honesty.
        if (! feature('market.indices')) {
            return ['available' => false, 'reason' => 'feature_disabled', 'area' => null, 'matches' => []];
        }

        // Most specific first: the area itself, then ancestors inward-out.
        $chain = [$area->id, ...array_reverse($area->ancestorIds())];

        $indices = MarketIndex::query()
            ->where('publication_status', 'published')
            ->where('scope_type', 'area')
            ->whereIn('scope_id', $chain)
            ->orderBy('key')
            ->get();

        if ($indices->isEmpty()) {
            return ['available' => false, 'reason' => 'no_published_values', 'area' => null, 'matches' => []];
        }

        $latestByIndex = $this->latestValues->for($indices->pluck('id')->all());

        foreach ($chain as $areaId) {
            $matches = [];

            foreach ($indices as $index) {
                if ((int) $index->scope_id !== (int) $areaId) {
                    continue;
                }

                $latest = $latestByIndex->get($index->id);

                if ($latest === null) {
                    continue;
                }

                $matches[] = ['index' => $index, 'latest' => $latest];
            }

            if ($matches !== []) {
                $pricedArea = (int) $areaId === (int) $area->id
                    ? $area
                    : Area::query()->published()->find($areaId);

                if ($pricedArea === null) {
                    continue;
                }

                return [
                    'available' => true,
                    'reason' => null,
                    'area' => $pricedArea,
                    'matches' => $matches,
                ];
            }
        }

        return ['available' => false, 'reason' => 'no_published_values', 'area' => null, 'matches' => []];
    }

    /**
     * The public presentation — field for field the shape
     * LocationResolveController::priceIntelligence() always returned.
     *
     * @param  array{available: bool, reason: string|null, area: Area|null, matches: list<array{index: MarketIndex, latest: MarketIndexValue}>}  $resolved
     * @return array{available: bool, reason: string|null, area_name: string|null, indices: list<array<string, mixed>>}
     */
    public function publicPayload(array $resolved): array
    {
        if (! $resolved['available'] || $resolved['area'] === null) {
            return [
                'available' => false,
                'reason' => $resolved['reason'],
                'area_name' => null,
                'indices' => [],
            ];
        }

        $rows = [];

        foreach ($resolved['matches'] as $match) {
            $index = $match['index'];
            $latest = $match['latest'];

            $rows[] = [
                'key' => $index->key,
                'name' => $index->name(),
                'price_type' => $index->price_type->value,
                'basis' => $index->basis,
                'value' => (string) $latest->value,
                'change_percent' => $latest->change_percent === null ? null : (string) $latest->change_percent,
                'period' => $latest->period,
                'currency' => $index->currency,
                'sample_size' => $latest->sample_size,
                'confidence' => $latest->confidence,
                // Selection already excluded limited values; stated
                // explicitly so the card's warning logic reads real state.
                'is_limited' => (bool) $latest->is_limited,
                // §15.3: a non-verified figure must not travel unlabelled.
                'requires_qualifier' => $index->requiresPublicQualifier(),
            ];
        }

        return [
            'available' => true,
            'reason' => null,
            'area_name' => $resolved['area']->name(),
            'indices' => $rows,
        ];
    }

    /**
     * The one-call form both public consumers read.
     *
     * @return array{available: bool, reason: string|null, area_name: string|null, indices: list<array<string, mixed>>}
     */
    public function for(Area $area): array
    {
        return $this->publicPayload($this->resolve($area));
    }
}
