<?php

declare(strict_types=1);

namespace App\Modules\Market\Observers;

use App\Modules\Market\Jobs\RebuildMarketIndex;
use App\Modules\Market\Models\MarketIndex;
use App\Modules\Market\Models\PriceRecord;

/**
 * A price record entering or leaving publication changes what an index sees.
 *
 * Only publication status and the outlier flag matter. Correcting a note or a
 * source does not change the arithmetic, and rebuilding on every save would
 * make reviewing four hundred rows queue four hundred rebuilds.
 *
 * The dispatch is scoped to indices that could actually include this record —
 * matching price type, scope type and property type. An apartment price cannot
 * affect a land index, and queueing every index would make publishing one row
 * O(indices).
 */
final class PriceRecordObserver
{
    public function saved(PriceRecord $record): void
    {
        if (! $record->wasChanged(['publication_status', 'is_outlier'])) {
            return;
        }

        $this->queueAffectedIndices($record);
    }

    public function deleted(PriceRecord $record): void
    {
        $this->queueAffectedIndices($record);
    }

    private function queueAffectedIndices(PriceRecord $record): void
    {
        MarketIndex::query()
            ->where('price_type', $record->price_type->value)
            ->where('scope_type', $record->scope_type->value)
            ->where(function ($q) use ($record): void {
                $q->whereNull('property_type')
                    ->orWhere('property_type', $record->property_type->value);
            })
            ->select('id')
            ->each(static function (MarketIndex $index): void {
                RebuildMarketIndex::dispatch($index->id);
            });
    }
}
