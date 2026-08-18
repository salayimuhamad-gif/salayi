<?php

declare(strict_types=1);

namespace App\Modules\Market\Jobs;

use App\Modules\Market\Models\MarketIndex;
use App\Modules\Market\Services\IndexBuilder;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

/**
 * Rebuild one index across every period it has data for.
 *
 * On the `maintenance` queue for the same reason as the nearby recalculation:
 * publishing four hundred price records must not delay a login notification on
 * a host with one cron-driven worker.
 *
 * Unique per index, because publishing a batch of prices fires one dispatch per
 * record and they all target the same handful of indices.
 */
final class RebuildMarketIndex implements ShouldBeUnique, ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public int $tries = 2;

    public int $timeout = 110;

    public int $uniqueFor = 300;

    public function __construct(public readonly int $indexId)
    {
        $this->onQueue('maintenance');
    }

    public function uniqueId(): string
    {
        return 'index:'.$this->indexId;
    }

    public function handle(IndexBuilder $builder): void
    {
        $index = MarketIndex::query()->find($this->indexId);

        if ($index === null) {
            return;
        }

        $builder->rebuild($index);
    }
}
