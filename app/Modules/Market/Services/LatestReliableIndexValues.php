<?php

declare(strict_types=1);

namespace App\Modules\Market\Services;

use App\Modules\Market\Models\MarketIndexValue;
use Illuminate\Support\Collection;

/**
 * The latest RELIABLE published value for each index, in two queries total.
 *
 * Extracted verbatim from MapExplorerController so the map's price layer and
 * the Wave 3 location-resolve endpoint answer from ONE selection rule instead
 * of two copies that drift. The semantics are pinned by MapExplorerTest and
 * are exactly §15.3's reliability contract:
 *
 *   - published values only;
 *   - a null value never wins;
 *   - a limited value is excluded rather than shown with a caveat nobody
 *     reads at a glance;
 *   - "latest" is MAX(period), not max id, because a revision can be inserted
 *     after a later period was published and an id-based latest would silently
 *     prefer the row that happened to be written last.
 *
 * The reliability rules are applied in BOTH halves of the grouped-MAX shape.
 * Applying them only to the outer query would let a limited or unpublished
 * row win MAX(period) and then be filtered out, leaving the index with no
 * value at all — silently dropping an area that does have a reliable earlier
 * figure.
 */
final class LatestReliableIndexValues
{
    /**
     * @param  list<int>  $indexIds
     * @return Collection<int, MarketIndexValue> keyed by market_index_id
     */
    public function for(array $indexIds): Collection
    {
        if ($indexIds === []) {
            return collect();
        }

        /*
         * EVERY COLUMN IS QUALIFIED in the outer query, because it joins a
         * derived table that exposes the same column names — SQLite and MySQL
         * both reject the bare form as ambiguous. The inner query keeps bare
         * names deliberately: it has no join and its own grouping refers to
         * its own table.
         */
        $reliableInner = static fn ($query) => $query
            ->where('publication_status', 'published')
            ->whereNotNull('value')
            ->where('is_limited', false);

        $reliableOuter = static fn ($query) => $query
            ->where('market_index_values.publication_status', 'published')
            ->whereNotNull('market_index_values.value')
            ->where('market_index_values.is_limited', false);

        $latestPeriods = MarketIndexValue::query()
            ->select('market_index_id')
            ->selectRaw('MAX(period) as latest_period')
            ->whereIn('market_index_id', $indexIds)
            ->tap($reliableInner)
            ->groupBy('market_index_id');

        return MarketIndexValue::query()
            ->whereIn('market_index_values.market_index_id', $indexIds)
            ->tap($reliableOuter)
            ->joinSub(
                $latestPeriods,
                'latest',
                static function ($join): void {
                    $join->on('market_index_values.market_index_id', '=', 'latest.market_index_id')
                        ->on('market_index_values.period', '=', 'latest.latest_period');
                },
            )
            ->select('market_index_values.*')
            ->get()
            ->keyBy('market_index_id');
    }
}
