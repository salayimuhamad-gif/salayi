<?php

declare(strict_types=1);

namespace App\Modules\Geography\Services;

use App\Modules\Core\ValueObjects\Decimal;
use App\Modules\Geography\Models\Area;
use App\Modules\Market\Enums\PropertyType;
use App\Modules\Market\Models\MarketIndex;
use App\Modules\Market\Models\MarketIndexValue;
use App\Modules\Market\Services\AreaPriceIntelligence;
use App\Modules\Market\Services\IndexCalculator;
use App\Modules\Market\Services\MarketMovementService;

/**
 * Side-by-side comparison of 2–3 published areas (Map Phase 6) — a
 * COMPOSITION of the existing authorities, never a second engine:
 *
 *   - identity + publication: the Area profile / Phase 5 search rule
 *     (published WITH fully published ancestry; one bulk ancestor query
 *     doubling as the breadcrumbs);
 *   - services: AreaServiceSummary verbatim, so a count here always equals
 *     the Area profile and the Phase 3 card;
 *   - current prices: AreaPriceIntelligence (the extracted Wave 3 lookup)
 *     — separate indices, most-specific area first, absence never zero;
 *   - movement: ONE bulk MarketMovementService::areaMovement() call — the
 *     heatmap's exact per-area claims, absence meaning unknown;
 *   - comparability: IndexCalculator::change() is the ONLY arithmetic and
 *     refusal authority. Two figures are directly compared only when their
 *     evidence identity matches exactly — transaction, property type,
 *     exact price type (asking never meets verified), family, currency
 *     (no FX, ever), basis, methodology version — and every computed
 *     difference is the calculator's own Decimal verdict, server-side.
 *
 * NO SCORE. NO WINNER. NO WEIGHTS. Facts are dimensional observations
 * ("a larger recorded increase", "N more recorded places") emitted as
 * localization keys + parameters; anything incompatible is stated as
 * incompatible with its reason, never ranked.
 */
final class AreaComparisonService
{
    public function __construct(
        private readonly AreaServiceSummary $services,
        private readonly AreaPriceIntelligence $prices,
        private readonly MarketMovementService $movement,
        private readonly IndexCalculator $calculator,
    ) {}

    /**
     * Compare the named areas, preserving the submitted order.
     *
     * Returns null when ANY slug fails the public rule — missing,
     * unpublished, or under unpublished ancestry. One answer for all three
     * failures, because "not found" and "exists but withheld" are different
     * disclosures and only one is safe to make (the profile's rule).
     *
     * @param  list<string>  $slugs  2–3 distinct public slugs
     * @return array<string, mixed>|null
     */
    public function compare(array $slugs, string $transaction, string $window, ?string $propertyType): ?array
    {
        $areasBySlug = Area::query()
            ->published()
            ->whereIn('slug', $slugs)
            ->get()
            ->keyBy('slug');

        if ($areasBySlug->count() !== count($slugs)) {
            return null;
        }

        /*
         * Published-ancestry gate, bulk (Phase 5's shape): every ancestor
         * any compared area references, in one query; the same rows then
         * serve every breadcrumb.
         */
        $ancestorIds = $areasBySlug
            ->flatMap(static fn (Area $area): array => $area->ancestorIds())
            ->unique()
            ->values()
            ->all();

        $publishedAncestors = $ancestorIds === []
            ? collect()
            : Area::query()->published()->whereIn('id', $ancestorIds)->get()->keyBy('id');

        foreach ($areasBySlug as $area) {
            foreach ($area->ancestorIds() as $ancestorId) {
                if (! $publishedAncestors->has($ancestorId)) {
                    return null;
                }
            }
        }

        $placesEnabled = (bool) feature('places.database');
        $movementEnabled = (bool) feature('market.intelligence');

        $movement = $movementEnabled
            ? $this->movement->areaMovement(
                $transaction,
                $window,
                $propertyType,
                $areasBySlug->map(static fn (Area $area): int => (int) $area->id)->values()->all(),
            )
            : $this->disabledMovementEnvelope($transaction, $window, $propertyType);

        $movementRows = collect($movement['rows'])->keyBy('area_slug');

        /** @var array<string, array{available: bool, reason: string|null, area: Area|null, matches: list<array{index: MarketIndex, latest: MarketIndexValue}>}> $resolvedPrices */
        $resolvedPrices = [];
        $areas = [];

        foreach ($slugs as $slug) {
            /** @var Area $area */
            $area = $areasBySlug->get($slug);

            $resolvedPrices[$slug] = $this->prices->resolve($area);

            $bounds = $area->bbox_min_lat !== null
                && $area->bbox_max_lat !== null
                && $area->bbox_min_lng !== null
                && $area->bbox_max_lng !== null
                ? [
                    'north' => (float) $area->bbox_max_lat,
                    'south' => (float) $area->bbox_min_lat,
                    'east' => (float) $area->bbox_max_lng,
                    'west' => (float) $area->bbox_min_lng,
                ]
                : null;

            $areas[] = [
                // The Phase 5 area-row vocabulary — navigation-safe fields
                // only, cached bbox, never boundary WKT (§12).
                'slug' => $area->slug,
                'name' => $area->name(),
                'type' => $area->type->value,
                'type_label' => __('geography.public.type.'.$area->type->value),
                'breadcrumb' => array_map(
                    static fn (int $id): array => ['name' => $publishedAncestors->get($id)?->name() ?? ''],
                    $area->ancestorIds(),
                ),
                'lat' => $area->latitude !== null ? (float) $area->latitude : null,
                'lng' => $area->longitude !== null ? (float) $area->longitude : null,
                'bounds' => $bounds,
                /*
                 * §51's distinction, kept structural: a disabled places
                 * feature is NULL with a reason — never an empty list that
                 * would read as "zero services".
                 */
                'services' => $placesEnabled ? $this->services->summarize($area) : null,
                'services_reason' => $placesEnabled ? null : 'feature_disabled',
                'prices' => $this->prices->publicPayload($resolvedPrices[$slug]),
                // The heatmap's one deterministic claim for THIS area, or
                // null — absence is the wire form of "unknown" (§19).
                'movement' => $movementRows->get($slug),
            ];
        }

        $comparison = $this->movementComparison($movementRows->values()->all());

        return [
            'filters' => [
                'transaction' => $transaction,
                'window' => $window,
                'property_type' => $propertyType,
            ],
            'windows' => $movement['windows'],
            'property_types' => $movement['property_types'],
            'movement' => [
                'available' => $movement['available'],
                'reason' => $movement['reason'],
            ],
            'areas' => $areas,
            'market_comparison' => $comparison,
            'facts' => $this->facts($slugs, $resolvedPrices, $movementRows->all(), $comparison),
        ];
    }

    /**
     * The movement envelope when market.intelligence is off: unavailable
     * BECAUSE THE FEATURE IS OFF — never "insufficient history", which
     * would be the wrong honesty (§51).
     *
     * @return array{available: bool, reason: string, transaction: string, window: string, property_type: string|null, windows: array<string, bool>, property_types: list<string>, rows: list<array<string, mixed>>}
     */
    private function disabledMovementEnvelope(string $transaction, string $window, ?string $propertyType): array
    {
        return [
            'available' => false,
            'reason' => 'feature_disabled',
            'transaction' => $transaction,
            'window' => $window,
            'property_type' => $propertyType,
            'windows' => array_fill_keys(MarketMovementService::WINDOWS, false),
            'property_types' => array_map(
                static fn (PropertyType $type): string => $type->value,
                PropertyType::cases(),
            ),
            'rows' => [],
        ];
    }

    /**
     * May the present movement claims be DIRECTLY compared (§18/§44)?
     *
     * Within one areaMovement() response, transaction and property type are
     * uniform by construction; direct magnitude comparison additionally
     * requires every present row to share exact price type, family,
     * currency, basis and methodology version — the calculator's refusal
     * dimensions, checked as equality because each row's own pair already
     * passed change(). Fewer than two claims compares nothing.
     *
     * @param  list<array<string, mixed>>  $rows
     * @return array{comparable: bool, reason: string|null, signature: array<string, mixed>|null}
     */
    private function movementComparison(array $rows): array
    {
        if (count($rows) < 2) {
            return ['comparable' => false, 'reason' => 'insufficient_claims', 'signature' => null];
        }

        $signature = static fn (array $row): array => [
            'transaction' => $row['transaction'],
            'property_type' => $row['property_type'],
            'price_type' => $row['price_type'],
            'family' => $row['family'],
            'currency' => $row['currency'],
            'basis' => $row['basis'],
            'methodology_version' => $row['methodology_version'],
        ];

        $first = $signature($rows[0]);

        foreach ($rows as $row) {
            $mismatch = $this->firstMismatch($first, $signature($row));

            if ($mismatch !== null) {
                return ['comparable' => false, 'reason' => $mismatch, 'signature' => null];
            }
        }

        return ['comparable' => true, 'reason' => null, 'signature' => $first];
    }

    /**
     * The first differing identity dimension, named — so "not directly
     * comparable" always says why.
     *
     * @param  array<string, mixed>  $a
     * @param  array<string, mixed>  $b
     */
    private function firstMismatch(array $a, array $b): ?string
    {
        foreach ($a as $dimension => $value) {
            if ($b[$dimension] !== $value) {
                return $dimension;
            }
        }

        return null;
    }

    /**
     * Deterministic, strictly factual key-differences (§45/§46): every
     * numeric value is computed HERE with the calculator's own Decimal
     * arithmetic (§20/§47) and shipped as a localization key + parameters —
     * the frontend formats, it never calculates. No winner, no score.
     *
     * @param  list<string>  $slugs
     * @param  array<string, array{available: bool, reason: string|null, area: Area|null, matches: list<array{index: MarketIndex, latest: MarketIndexValue}>}>  $resolvedPrices
     * @param  array<string, array<string, mixed>>  $movementRows  keyed by area slug
     * @param  array{comparable: bool, reason: string|null, signature: array<string, mixed>|null}  $comparison
     * @return list<array{key: string, params: array<string, string|null>}>
     */
    private function facts(array $slugs, array $resolvedPrices, array $movementRows, array $comparison): array
    {
        $facts = [];

        foreach ($this->pairs($slugs) as [$a, $b]) {
            foreach ($this->priceFacts($a, $b, $resolvedPrices[$a], $resolvedPrices[$b]) as $fact) {
                $facts[] = $fact;
            }
        }

        $priced = array_values(array_filter(
            $slugs,
            static fn (string $slug): bool => $resolvedPrices[$slug]['available'],
        ));

        // ≥2 areas carry real current prices, yet not one pair produced a
        // compatible difference: state the incompatibility once, with the
        // most specific reason a shared-but-diverging identity offers.
        if (count($priced) >= 2 && ! $this->hasPriceFact($facts)) {
            $facts[] = [
                'key' => 'price_not_comparable',
                'params' => ['reason' => $this->priceIncompatibilityReason($priced, $resolvedPrices)],
            ];
        }

        foreach ($this->movementFacts($slugs, $movementRows, $comparison) as $fact) {
            $facts[] = $fact;
        }

        return $facts;
    }

    /**
     * Unordered pairs of the submitted slugs, submission order first.
     *
     * @param  list<string>  $slugs
     * @return list<array{0: string, 1: string}>
     */
    private function pairs(array $slugs): array
    {
        $pairs = [];
        $count = count($slugs);

        for ($i = 0; $i < $count; $i++) {
            for ($j = $i + 1; $j < $count; $j++) {
                $pairs[] = [$slugs[$i], $slugs[$j]];
            }
        }

        return $pairs;
    }

    /**
     * Compatible current-price differences for one area pair.
     *
     * A pair of figures is compared ONLY when the full evidence identity
     * matches (§14) — and only when the two answers come from DIFFERENT
     * priced areas: two areas both answered by the same published ancestor
     * share one figure, and "0% difference" between them would be a claim
     * about the areas that the evidence does not make.
     *
     * @param  array{available: bool, reason: string|null, area: Area|null, matches: list<array{index: MarketIndex, latest: MarketIndexValue}>}  $resolvedA
     * @param  array{available: bool, reason: string|null, area: Area|null, matches: list<array{index: MarketIndex, latest: MarketIndexValue}>}  $resolvedB
     * @return list<array{key: string, params: array<string, string|null>}>
     */
    private function priceFacts(string $a, string $b, array $resolvedA, array $resolvedB): array
    {
        if (! $resolvedA['available'] || ! $resolvedB['available']) {
            return [];
        }

        if ($resolvedA['area'] !== null && $resolvedB['area'] !== null
            && (int) $resolvedA['area']->id === (int) $resolvedB['area']->id) {
            return [];
        }

        $facts = [];

        foreach ($resolvedA['matches'] as $matchA) {
            foreach ($resolvedB['matches'] as $matchB) {
                if (! $this->priceIdentityMatches($matchA, $matchB)) {
                    continue;
                }

                /*
                 * The calculator is the arbiter AND the arithmetic: feeding
                 * A as "previous" and B as "current" makes change_percent
                 * the exact percentage by which B differs from A, under the
                 * same refusal rules every movement figure obeys.
                 */
                $change = $this->calculator->change(
                    $this->calculatorSide($matchA),
                    $this->calculatorSide($matchB),
                );

                if (! $change['comparable'] || $change['change_percent'] === null) {
                    continue;
                }

                $percent = Decimal::of($change['change_percent'], 2);

                if ($percent->isZero()) {
                    $facts[] = [
                        'key' => 'price_equal',
                        'params' => [
                            'a' => $a,
                            'b' => $b,
                            'price_type' => $matchA['index']->price_type->value,
                            'currency' => $matchA['index']->currency,
                        ],
                    ];

                    continue;
                }

                // change A→B positive means B's figure is the higher one.
                [$higher, $lower] = $percent->isPositive() ? [$b, $a] : [$a, $b];

                $facts[] = [
                    'key' => 'price_higher',
                    'params' => [
                        'higher' => $higher,
                        'lower' => $lower,
                        'percent' => $percent->abs()->toString(),
                        'amount' => Decimal::of((string) $matchB['latest']->value, 4)
                            ->subtract((string) $matchA['latest']->value)
                            ->abs()
                            ->toString(),
                        'price_type' => $matchA['index']->price_type->value,
                        'currency' => $matchA['index']->currency,
                        'basis' => $matchA['index']->basis,
                    ],
                ];
            }
        }

        return $facts;
    }

    /**
     * §14's minimum identity, pre-gated on the dimensions the calculator
     * does not see (exact price type — asking never meets verified even
     * inside one family — and property type); the calculator re-verifies
     * family, methodology, currency and basis itself.
     *
     * @param  array{index: MarketIndex, latest: MarketIndexValue}  $a
     * @param  array{index: MarketIndex, latest: MarketIndexValue}  $b
     */
    private function priceIdentityMatches(array $a, array $b): bool
    {
        return $a['index']->price_type === $b['index']->price_type
            && $a['index']->property_type === $b['index']->property_type;
    }

    /**
     * @param  array{index: MarketIndex, latest: MarketIndexValue}  $match
     * @return array{value: mixed, price_type_family: string, methodology_version: mixed, currency: string, basis: string}
     */
    private function calculatorSide(array $match): array
    {
        return [
            'value' => $match['latest']->value,
            'price_type_family' => $match['index']->price_type->family(),
            'methodology_version' => $match['latest']->methodology_version,
            'currency' => $match['index']->currency,
            'basis' => $match['index']->basis,
        ];
    }

    /** @param  list<array{key: string, params: array<string, string|null>}>  $facts */
    private function hasPriceFact(array $facts): bool
    {
        foreach ($facts as $fact) {
            if ($fact['key'] === 'price_higher' || $fact['key'] === 'price_equal') {
                return true;
            }
        }

        return false;
    }

    /**
     * Why no price pair compared: a shared (price type, property type)
     * identity that diverges names its first differing dimension; no shared
     * identity at all is its own honest answer.
     *
     * @param  list<string>  $priced
     * @param  array<string, array{available: bool, reason: string|null, area: Area|null, matches: list<array{index: MarketIndex, latest: MarketIndexValue}>}>  $resolvedPrices
     */
    private function priceIncompatibilityReason(array $priced, array $resolvedPrices): string
    {
        $sharedSource = false;

        foreach ($this->pairs($priced) as [$a, $b]) {
            /*
             * Both areas answered by the SAME published ancestor: the
             * figures describe one area, so "no difference" is a claim
             * about the shared source, not about the compared areas — its
             * own reason, never dressed up as missing evidence.
             */
            if ($resolvedPrices[$a]['area'] !== null && $resolvedPrices[$b]['area'] !== null
                && (int) $resolvedPrices[$a]['area']->id === (int) $resolvedPrices[$b]['area']->id) {
                $sharedSource = true;

                continue;
            }

            foreach ($resolvedPrices[$a]['matches'] as $matchA) {
                foreach ($resolvedPrices[$b]['matches'] as $matchB) {
                    if (! $this->priceIdentityMatches($matchA, $matchB)) {
                        continue;
                    }

                    if ($matchA['index']->currency !== $matchB['index']->currency) {
                        return 'currency';
                    }

                    if ($matchA['index']->basis !== $matchB['index']->basis) {
                        return 'basis';
                    }

                    if ($matchA['latest']->methodology_version !== $matchB['latest']->methodology_version) {
                        return 'methodology_version';
                    }
                }
            }
        }

        return $sharedSource ? 'shared_source' : 'no_shared_evidence';
    }

    /**
     * Movement facts — emitted ONLY under a comparable signature (§18);
     * incompatible claims are stated as such, never ranked.
     *
     * @param  list<string>  $slugs
     * @param  array<string, array<string, mixed>>  $movementRows
     * @param  array{comparable: bool, reason: string|null, signature: array<string, mixed>|null}  $comparison
     * @return list<array{key: string, params: array<string, string|null>}>
     */
    private function movementFacts(array $slugs, array $movementRows, array $comparison): array
    {
        $present = array_values(array_filter($slugs, static fn (string $slug): bool => isset($movementRows[$slug])));

        if (count($present) < 2) {
            return [];
        }

        if (! $comparison['comparable']) {
            return [[
                'key' => 'movement_not_comparable',
                'params' => ['reason' => $comparison['reason']],
            ]];
        }

        $facts = [];

        foreach ($this->pairs($present) as [$a, $b]) {
            $rowA = $movementRows[$a];
            $rowB = $movementRows[$b];

            $percentA = Decimal::of((string) $rowA['change_percent'], 2);
            $percentB = Decimal::of((string) $rowB['change_percent'], 2);
            $directionA = (string) $rowA['direction'];
            $directionB = (string) $rowB['direction'];

            $params = [
                'a' => $a,
                'b' => $b,
                'a_percent' => $percentA->toString(),
                'b_percent' => $percentB->toString(),
            ];

            if ($percentA->equals($percentB)) {
                $facts[] = ['key' => 'movement_equal', 'params' => $params];

                continue;
            }

            if ($directionA === 'up' && $directionB === 'down') {
                $facts[] = ['key' => 'movement_diverged', 'params' => [...$params, 'rising' => $a, 'falling' => $b]];

                continue;
            }

            if ($directionA === 'down' && $directionB === 'up') {
                $facts[] = ['key' => 'movement_diverged', 'params' => [...$params, 'rising' => $b, 'falling' => $a]];

                continue;
            }

            if ($directionA === 'down' || $directionB === 'down') {
                // Both non-rising with at least one decline: the lower
                // percentage is the stronger recorded decrease.
                $stronger = $percentA->lessThan($percentB) ? $a : $b;

                $facts[] = ['key' => 'movement_larger_decrease', 'params' => [...$params, 'stronger' => $stronger]];

                continue;
            }

            // Both rising (or one genuinely flat): the higher percentage is
            // the larger recorded increase.
            $stronger = $percentA->greaterThan($percentB) ? $a : $b;

            $facts[] = ['key' => 'movement_larger_increase', 'params' => [...$params, 'stronger' => $stronger]];
        }

        return $facts;
    }
}
