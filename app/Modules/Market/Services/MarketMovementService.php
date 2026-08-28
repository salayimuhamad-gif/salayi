<?php

declare(strict_types=1);

namespace App\Modules\Market\Services;

use App\Modules\Core\ValueObjects\Decimal;
use App\Modules\Geography\Models\Area;
use App\Modules\Market\Enums\PropertyType;
use App\Modules\Market\Enums\ScopeType;
use App\Modules\Market\Models\MarketIndex;
use App\Modules\Market\Models\MarketIndexValue;
use App\Modules\Projects\Models\Project;
use DateInterval;
use DateTimeImmutable;
use Illuminate\Support\Collection;

/**
 * Market movement — gainers and losers DERIVED from the published index
 * series (Wave 4, spec 15.2/15.3).
 *
 * This service stores nothing and invents nothing. Every mover is a pair of
 * values that already exist in `market_index_values`, selected under the
 * same reliability contract the map and the Wave 3 location card answer
 * from (published, non-null, not limited — LatestReliableIndexValues'
 * rule applied to a whole series), and compared through
 * IndexCalculator::change(), which is the ONLY authority on whether two
 * figures are comparable. When change() refuses — methodology changed,
 * value missing, previous zero — the pair produces no mover, never a 0%.
 *
 * WHAT MAY MOVE. Only indices whose scope resolves to a live, published
 * area or project appear: those are the two scope types with an internal
 * identity and a public trilingual name. City-level indices have no entity
 * row to present (the product is single-city and city indices declare
 * scope_id NULL); project_phase and unit_type scopes have no addressable
 * entity at all. None of the three can honestly headline a mover card, so
 * they are excluded rather than shown as raw ids.
 *
 * SALE AND RENT NEVER MIX. An index is eligible only when its price type
 * declares the requested transaction basis outright. OfficialSnapshot
 * declares 'either' — a snapshot pins sale-or-rent per RECORD, and the
 * index series does not carry that pin — so official-snapshot indices are
 * excluded from movement entirely: under either mode they could smuggle
 * the other basis in. Asking and verified stay separate the way they
 * always have: a pair is always within ONE index, and one index declares
 * ONE price type, so a movement can never straddle families; the family
 * label still travels on every mover, exactly as it does on every index
 * card.
 *
 * WINDOWS ARE DATA-DRIVEN. The stored evidence is a monthly series
 * ('YYYY-MM' periods, spec Appendix B), so calendar windows pair the
 * latest reliable observation with the observation EXACTLY n months
 * before it — a missing month means no pair, never a substitute. 7D/30D
 * are dated windows: they need a strictly older observation whose
 * effective date falls inside the window, and with monthly aggregates
 * dated on the first of their month such a pair essentially cannot exist
 * — so those options surface as honestly unsupported instead of
 * fabricating short-window movement from monthly aggregates. 'all'
 * compares the earliest reliable observation against the latest.
 */
final class MarketMovementService
{
    /** Every window the product offers, in display order. */
    public const WINDOWS = ['7d', '30d', '1m', '3m', '6m', '1y', 'all'];

    public const TRANSACTIONS = ['sale', 'rent'];

    /** Bounded payload, the same discipline as the map layers. */
    private const MAX_PER_BUCKET = 8;

    private const DATED_WINDOW_DAYS = ['7d' => 7, '30d' => 30];

    private const CALENDAR_WINDOW_MONTHS = ['1m' => 1, '3m' => 3, '6m' => 6, '1y' => 12];

    public function __construct(private readonly IndexCalculator $calculator) {}

    /**
     * Movement for one transaction basis, one window, an optional set of
     * property categories.
     *
     * @param  list<string>  $propertyTypes  PropertyType values; [] means all
     * @return array{
     *     available: bool, reason: string|null,
     *     transaction: string, window: string,
     *     windows: array<string, bool>,
     *     property_types: list<string>,
     *     gainers: list<array<string, mixed>>,
     *     losers: list<array<string, mixed>>,
     *     flat: list<array<string, mixed>>
     * }
     */
    public function movement(string $transaction, string $window, array $propertyTypes = []): array
    {
        $indices = $this->eligibleIndices($transaction, $propertyTypes);

        $empty = fn (string $reason): array => [
            'available' => false,
            'reason' => $reason,
            'transaction' => $transaction,
            'window' => $window,
            'windows' => array_fill_keys(self::WINDOWS, false),
            'property_types' => $this->propertyTypeValues(),
            'gainers' => [],
            'losers' => [],
            'flat' => [],
        ];

        if ($indices->isEmpty()) {
            // The distinction matters to the reader: "your filter matched
            // nothing" invites loosening the filter; "there is no history
            // yet" does not.
            $anyForTransaction = $propertyTypes !== []
                && $this->eligibleIndices($transaction, [])->isNotEmpty();

            return $empty($anyForTransaction ? 'no_data_for_filters' : 'insufficient_history');
        }

        $seriesByIndex = $this->reliableSeries($indices->pluck('id')->all());
        $entities = $this->entities($indices);

        $movers = [];
        $windowsAvailable = array_fill_keys(self::WINDOWS, false);
        $sawSeries = false;

        foreach ($indices as $index) {
            /** @var list<MarketIndexValue> $series */
            $series = $seriesByIndex->get($index->id, collect())->values()->all();

            if (count($series) < 2) {
                continue;
            }

            $sawSeries = true;
            $entity = $entities[$index->scope_type->value][$index->scope_id] ?? null;

            if ($entity === null) {
                // The index is real but its entity is gone or unpublished:
                // nothing public to name, so nothing to rank.
                continue;
            }

            foreach (self::WINDOWS as $candidate) {
                if ($windowsAvailable[$candidate] && $candidate !== $window) {
                    continue;
                }

                $pair = $this->pairFor($candidate, $series);

                if ($pair === null) {
                    continue;
                }

                $change = $this->change($index, $pair['previous'], $pair['current']);

                if (! $change['comparable']) {
                    continue;
                }

                $windowsAvailable[$candidate] = true;

                if ($candidate === $window) {
                    $movers[] = $this->mover($index, $entity, $pair['previous'], $pair['current'], $change, $series);
                }
            }
        }

        if ($movers === []) {
            if (! $sawSeries) {
                return array_merge($empty('insufficient_history'), ['windows' => $windowsAvailable]);
            }

            $reason = isset(self::DATED_WINDOW_DAYS[$window])
                ? 'unsupported_short_window'
                : 'no_compatible_pair';

            return array_merge($empty($reason), ['windows' => $windowsAvailable]);
        }

        [$gainers, $losers, $flat] = $this->rank($movers);

        return [
            'available' => true,
            'reason' => null,
            'transaction' => $transaction,
            'window' => $window,
            'windows' => $windowsAvailable,
            'property_types' => $this->propertyTypeValues(),
            'gainers' => array_slice($gainers, 0, self::MAX_PER_BUCKET),
            'losers' => array_slice($losers, 0, self::MAX_PER_BUCKET),
            'flat' => array_slice($flat, 0, self::MAX_PER_BUCKET),
        ];
    }

    /**
     * Movement for a SET OF AREAS — the map heatmap's question (Map Phase 4):
     * one honest claim per area, under exactly the rules movement() already
     * enforces. Same eligibility, same reliable series, same window pairing,
     * same calculator verdict; the ONLY new decisions are scoping and
     * selection, both stated here:
     *
     *   - AREAS ONLY, from the caller's id list (the visible, paintable
     *     polygons) — project indices never tint an area;
     *   - the CATEGORY filter is single-valued and strict: a named category
     *     matches indices declaring exactly that category; NULL means the
     *     spanning all-categories index ONLY. That is the product's existing
     *     honest "all": an index with property_type NULL spans every
     *     category, and a typed index never stands in for it — averaging
     *     incomparable typed indices is exactly what this refuses to do;
     *   - ONE row per area: its indices are walked in key order (the same
     *     deterministic order movement() queries in) and the FIRST whose
     *     pair the calculator accepts for the requested window makes the
     *     claim. The row carries that index's full identity — currency,
     *     basis, price type, family, category — so the claim is always
     *     scoped and stated, never an anonymous blend;
     *   - an area with no acceptable pair simply has NO row. Absence is the
     *     wire form of "unknown": the polygon stays untinted, and nothing
     *     here ever turns missing into flat or zero.
     *
     * @param  list<int>  $areaIds
     * @return array{
     *     available: bool, reason: string|null,
     *     transaction: string, window: string, property_type: string|null,
     *     windows: array<string, bool>,
     *     property_types: list<string>,
     *     rows: list<array<string, mixed>>
     * }
     */
    public function areaMovement(string $transaction, string $window, ?string $propertyType, array $areaIds): array
    {
        $empty = fn (string $reason, array $windows = []): array => [
            'available' => false,
            'reason' => $reason,
            'transaction' => $transaction,
            'window' => $window,
            'property_type' => $propertyType,
            'windows' => $windows === [] ? array_fill_keys(self::WINDOWS, false) : $windows,
            'property_types' => $this->propertyTypeValues(),
            'rows' => [],
        ];

        if ($areaIds === []) {
            return $empty('insufficient_history');
        }

        $indices = $this->areaEligibleIndices($transaction, $propertyType, $areaIds);

        if ($indices->isEmpty()) {
            // The movement() distinction, kept: "your category filter matched
            // nothing" invites picking another category; "no history" does not.
            $anyForTransaction = $propertyType !== null
                && $this->areaEligibleIndices($transaction, null, $areaIds)->isNotEmpty();

            return $empty($anyForTransaction ? 'no_data_for_filters' : 'insufficient_history');
        }

        $seriesByIndex = $this->reliableSeries($indices->pluck('id')->all());

        // Published areas only, exactly like entities(): an index whose area
        // vanished or lost publication paints nothing.
        $slugsById = Area::query()
            ->whereIn('id', $indices->pluck('scope_id')->unique()->values()->all())
            ->where('publication_status', 'published')
            ->pluck('slug', 'id');

        $rows = [];
        $windowsAvailable = array_fill_keys(self::WINDOWS, false);
        $sawSeries = false;

        foreach ($indices as $index) {
            /** @var list<MarketIndexValue> $series */
            $series = $seriesByIndex->get($index->id, collect())->values()->all();

            if (count($series) < 2) {
                continue;
            }

            $slug = $slugsById->get($index->scope_id);

            if ($slug === null) {
                continue;
            }

            $sawSeries = true;

            foreach (self::WINDOWS as $candidate) {
                $wanted = $candidate === $window && ! isset($rows[$slug]);

                if ($windowsAvailable[$candidate] && ! $wanted) {
                    continue;
                }

                $pair = $this->pairFor($candidate, $series);

                if ($pair === null) {
                    continue;
                }

                $change = $this->change($index, $pair['previous'], $pair['current']);

                if (! $change['comparable']) {
                    continue;
                }

                $windowsAvailable[$candidate] = true;

                if ($wanted) {
                    $rows[$slug] = $this->areaRow($slug, $index, $pair['previous'], $pair['current'], $change);
                }
            }
        }

        if ($rows === []) {
            if (! $sawSeries) {
                return $empty('insufficient_history', $windowsAvailable);
            }

            $reason = isset(self::DATED_WINDOW_DAYS[$window])
                ? 'unsupported_short_window'
                : 'no_compatible_pair';

            return $empty($reason, $windowsAvailable);
        }

        ksort($rows);

        return [
            'available' => true,
            'reason' => null,
            'transaction' => $transaction,
            'window' => $window,
            'property_type' => $propertyType,
            'windows' => $windowsAvailable,
            'property_types' => $this->propertyTypeValues(),
            'rows' => array_values($rows),
        ];
    }

    /**
     * The heatmap's index eligibility: movement()'s rule, narrowed to area
     * scope, the caller's areas, and the single-category semantics the
     * method docblock states (NULL = the spanning index only).
     *
     * @param  list<int>  $areaIds
     * @return Collection<int, MarketIndex>
     */
    private function areaEligibleIndices(string $transaction, ?string $propertyType, array $areaIds): Collection
    {
        return MarketIndex::query()
            ->where('publication_status', 'published')
            ->where('scope_type', ScopeType::Area->value)
            ->whereIn('scope_id', $areaIds)
            ->when(
                $propertyType === null,
                static fn ($q) => $q->whereNull('property_type'),
                static fn ($q) => $q->where('property_type', $propertyType),
            )
            ->orderBy('key')
            ->get()
            ->filter(static fn (MarketIndex $index): bool => $index->price_type->transaction() === $transaction)
            ->values();
    }

    /**
     * One heat row — the mover shape flattened to what a polygon needs,
     * every field measured, the claiming index's identity stated in full.
     *
     * @param  array{comparable: bool, reason: string|null, change_percent: string|null, direction: string|null}  $change
     * @return array<string, mixed>
     */
    private function areaRow(
        string $slug,
        MarketIndex $index,
        MarketIndexValue $previous,
        MarketIndexValue $current,
        array $change,
    ): array {
        return [
            'area_slug' => $slug,
            'direction' => $change['direction'],
            'change_percent' => $change['change_percent'],
            'current_value' => (string) $current->value,
            'previous_value' => (string) $previous->value,
            'currency' => $index->currency,
            'basis' => $index->basis,
            'transaction' => $index->price_type->transaction(),
            'price_type' => $index->price_type->value,
            'family' => $index->price_type->family(),
            'property_type' => $index->property_type?->value,
            'requires_qualifier' => $index->requiresPublicQualifier(),
            // Map Phase 6: the claim's methodology version rides along so a
            // cross-area comparison can apply the calculator's own refusal
            // rules; the pair itself was already same-version by change().
            'methodology_version' => $current->methodology_version,
            'period_current' => $current->period,
            'period_previous' => $previous->period,
            'sample_size' => $current->sample_size,
            'confidence' => $current->confidence,
        ];
    }

    /**
     * Indices that may honestly move: published, scoped to an area or a
     * project, declaring the requested transaction basis outright, and —
     * when categories are filtered — declaring exactly one of them.
     *
     * An index with property_type NULL spans every category; it appears in
     * the unfiltered view but never under a category filter, because it
     * cannot honestly claim to BE that category.
     *
     * @param  list<string>  $propertyTypes
     * @return Collection<int, MarketIndex>
     */
    private function eligibleIndices(string $transaction, array $propertyTypes): Collection
    {
        return MarketIndex::query()
            ->where('publication_status', 'published')
            ->whereIn('scope_type', [ScopeType::Area->value, ScopeType::Project->value])
            ->whereNotNull('scope_id')
            ->when(
                $propertyTypes !== [],
                static fn ($q) => $q->whereIn('property_type', $propertyTypes),
            )
            ->orderBy('key')
            ->get()
            ->filter(static fn (MarketIndex $index): bool => $index->price_type->transaction() === $transaction)
            ->values();
    }

    /**
     * The reliable series per index: published, non-null, not limited —
     * §15.3's reliability contract, the same rule LatestReliableIndexValues
     * pins for the map and the location card — with one row per period,
     * the highest published revision winning exactly as it does everywhere
     * else revisions exist.
     *
     * @param  list<int>  $indexIds
     * @return Collection<int, Collection<int, MarketIndexValue>> keyed by market_index_id
     */
    private function reliableSeries(array $indexIds): Collection
    {
        if ($indexIds === []) {
            return collect();
        }

        $values = MarketIndexValue::query()
            ->whereIn('market_index_id', $indexIds)
            ->where('publication_status', 'published')
            ->whereNotNull('value')
            ->where('is_limited', false)
            ->orderBy('period')
            ->orderBy('revision_number')
            ->orderBy('id')
            ->get();

        /*
         * Grouped in plain PHP: assigning to the same period key overwrites
         * in place, so the highest published revision wins its period while
         * the period keeps its ascending position — the same array
         * semantics groupBy()/keyBy() are built on, spelled out where the
         * generics stay exact.
         */
        /** @var array<int, array<string, MarketIndexValue>> $byIndex */
        $byIndex = [];

        foreach ($values as $value) {
            $byIndex[$value->market_index_id][$value->period] = $value;
        }

        return collect($byIndex)->map(
            static fn (array $periods): Collection => collect(array_values($periods)),
        );
    }

    /**
     * The published entity behind each index, bulk-loaded, never guessed.
     *
     * @param  Collection<int, MarketIndex>  $indices
     * @return array<string, array<int, Area|Project>>
     */
    private function entities(Collection $indices): array
    {
        $areaIds = $indices->where('scope_type', ScopeType::Area)->pluck('scope_id')->filter()->unique()->values()->all();
        $projectIds = $indices->where('scope_type', ScopeType::Project)->pluck('scope_id')->filter()->unique()->values()->all();

        return [
            ScopeType::Area->value => $areaIds === [] ? [] : Area::query()
                ->whereIn('id', $areaIds)
                ->where('publication_status', 'published')
                ->get()
                ->keyBy('id')
                ->all(),
            ScopeType::Project->value => $projectIds === [] ? [] : Project::query()
                ->whereIn('id', $projectIds)
                ->where('publication_status', 'published')
                ->get()
                ->keyBy('id')
                ->all(),
        ];
    }

    /**
     * The pair one window compares, or null when the stored evidence cannot
     * honestly support it.
     *
     * Rules, exactly:
     *
     *   - calendar windows (1m/3m/6m/1y): current = the latest reliable
     *     observation; previous = the observation whose period is EXACTLY n
     *     calendar months earlier. A gap in the series disables the window
     *     rather than sliding to a neighbour;
     *   - dated windows (7d/30d): previous = the OLDEST strictly-earlier
     *     observation whose effective date lies within n days before the
     *     current observation's effective date. Monthly aggregates dated on
     *     their month's first day essentially never satisfy this — the
     *     option then reads as honestly unsupported;
     *   - all: previous = the earliest reliable observation, when it is not
     *     also the latest.
     *
     * @param  list<MarketIndexValue>  $series  ascending by period, one row per period
     * @return array{previous: MarketIndexValue, current: MarketIndexValue}|null
     */
    private function pairFor(string $window, array $series): ?array
    {
        $current = $series[count($series) - 1];

        if ($window === 'all') {
            $earliest = $series[0];

            return $earliest->period === $current->period
                ? null
                : ['previous' => $earliest, 'current' => $current];
        }

        if (isset(self::CALENDAR_WINDOW_MONTHS[$window])) {
            $months = self::CALENDAR_WINDOW_MONTHS[$window];
            $anchor = DateTimeImmutable::createFromFormat('!Y-m', $current->period);

            if ($anchor === false) {
                return null;
            }

            $target = $anchor->sub(new DateInterval('P'.$months.'M'))->format('Y-m');

            foreach ($series as $value) {
                if ($value->period === $target) {
                    return ['previous' => $value, 'current' => $current];
                }
            }

            return null;
        }

        $days = self::DATED_WINDOW_DAYS[$window] ?? null;

        if ($days === null) {
            return null;
        }

        $floor = $current->effective_date->copy()->subDays($days);

        foreach ($series as $value) {
            if ($value->period === $current->period) {
                continue;
            }

            if ($value->effective_date->greaterThanOrEqualTo($floor)) {
                return ['previous' => $value, 'current' => $current];
            }
        }

        return null;
    }

    /**
     * The comparison itself, delegated wholesale to the calculator.
     *
     * Family, currency and basis are constant within one index by schema,
     * but they are still passed through change() rather than assumed: the
     * calculator is the single authority on comparability, and methodology
     * versions DO vary between stored values.
     *
     * @return array{comparable: bool, reason: string|null, change_percent: string|null, direction: string|null}
     */
    private function change(MarketIndex $index, MarketIndexValue $previous, MarketIndexValue $current): array
    {
        $side = static fn (MarketIndexValue $value): array => [
            'value' => $value->value,
            'price_type_family' => $index->price_type->family(),
            'methodology_version' => $value->methodology_version,
            'currency' => $index->currency,
            'basis' => $index->basis,
        ];

        return $this->calculator->change($side($previous), $side($current));
    }

    /**
     * One mover — every field measured, none invented.
     *
     * @param  array{comparable: bool, reason: string|null, change_percent: string|null, direction: string|null}  $change
     * @param  list<MarketIndexValue>  $series
     * @return array<string, mixed>
     */
    private function mover(
        MarketIndex $index,
        Area|Project $entity,
        MarketIndexValue $previous,
        MarketIndexValue $current,
        array $change,
        array $series,
    ): array {
        return [
            // The slug is the stable public identifier the profile routes
            // already use; ids stay internal (the location card's rule).
            'entity' => [
                'slug' => $entity->slug,
                'type' => $index->scope_type->value,
                'name' => $entity->name(),
            ],
            'index_key' => $index->key,
            'property_type' => $index->property_type?->value,
            'transaction' => $index->price_type->transaction(),
            'price_type' => $index->price_type->value,
            'family' => $index->price_type->family(),
            'requires_qualifier' => $index->requiresPublicQualifier(),
            'basis' => $index->basis,
            'currency' => $index->currency,
            'current' => [
                'period' => $current->period,
                'value' => (string) $current->value,
                'sample_size' => $current->sample_size,
                'confidence' => $current->confidence,
            ],
            'previous' => [
                'period' => $previous->period,
                'value' => (string) $previous->value,
            ],
            'change_percent' => $change['change_percent'],
            'direction' => $change['direction'],
            'sparkline' => $this->sparkline($series, $current),
        ];
    }

    /**
     * Real history only: the reliable series, restricted to points sharing
     * the current value's methodology version so every point on one line is
     * mutually comparable (entity, category, transaction, currency, basis
     * and family are already pinned by the index). Fewer than two points
     * means no line — the chart precedent draws nothing it cannot support.
     *
     * @param  list<MarketIndexValue>  $series
     * @return list<array{period: string, value: string, is_limited: bool}>|null
     */
    private function sparkline(array $series, MarketIndexValue $current): ?array
    {
        $points = [];

        foreach ($series as $value) {
            if ($value->methodology_version !== $current->methodology_version) {
                continue;
            }

            $points[] = [
                'period' => $value->period,
                'value' => (string) $value->value,
                // False by selection — the reliable series excludes limited
                // values — kept in the shape MarketTrendChart consumes.
                'is_limited' => false,
            ];
        }

        return count($points) >= 2 ? $points : null;
    }

    /**
     * Rank by the exact comparable change, deterministically.
     *
     * Gainers: strongest positive first. Losers: strongest negative first.
     * Flat: genuine 0.00% pairs — a real comparison, never a stand-in for
     * missing data. Ties break on entity slug, then index key: both are
     * stable ASCII identifiers, unlike localized names.
     *
     * @param  list<array<string, mixed>>  $movers
     * @return array{0: list<array<string, mixed>>, 1: list<array<string, mixed>>, 2: list<array<string, mixed>>}
     */
    private function rank(array $movers): array
    {
        $tie = static function (array $a, array $b): int {
            return [$a['entity']['slug'], $a['index_key']] <=> [$b['entity']['slug'], $b['index_key']];
        };

        $compare = static fn (array $a, array $b): int => Decimal::of((string) $a['change_percent'], 4)
            ->compareTo(Decimal::of((string) $b['change_percent'], 4));

        $gainers = array_values(array_filter($movers, static fn (array $m): bool => $m['direction'] === 'up'));
        $losers = array_values(array_filter($movers, static fn (array $m): bool => $m['direction'] === 'down'));
        $flat = array_values(array_filter($movers, static fn (array $m): bool => $m['direction'] === 'flat'));

        usort($gainers, static fn (array $a, array $b): int => $compare($b, $a) ?: $tie($a, $b));
        usort($losers, static fn (array $a, array $b): int => $compare($a, $b) ?: $tie($a, $b));
        usort($flat, $tie);

        return [$gainers, $losers, $flat];
    }

    /**
     * The category vocabulary, straight from the enum, so a case added to
     * PropertyType appears here without anyone editing a list.
     *
     * @return list<string>
     */
    private function propertyTypeValues(): array
    {
        return array_map(static fn (PropertyType $type): string => $type->value, PropertyType::cases());
    }
}
