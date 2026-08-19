<?php

declare(strict_types=1);

namespace App\Modules\Geography\Console;

use App\Modules\Core\Concerns\GuardedByFeature;
use App\Modules\Geography\Jobs\RecalculateNearbyPlaces;
use App\Modules\Projects\Models\ProjectNearbyPlace;
use Illuminate\Console\Command;

/**
 * Sweep stale nearby-place snapshots (spec 10.5 step 7).
 *
 * The safety net beneath the observers. An import that writes places directly,
 * a job that failed its three attempts, or a manual database correction all
 * leave rows flagged stale with nothing queued to clear them. Without a sweep
 * those figures stay stale silently and forever.
 *
 * Batched because on shared hosting the worker gets fifty seconds per cron
 * tick. A bounded number of projects per run finishes; an unbounded one is
 * killed mid-flight every minute and never completes.
 */
final class RefreshStaleNearbyPlaces extends Command
{
    use GuardedByFeature;

    protected $signature = 'mulkihawler:nearby:refresh
                            {--limit=25 : Projects to queue this run}
                            {--dry-run : Report what would be queued}';

    protected $description = 'Queue a nearby-place recalculation for projects with stale snapshots';

    public function handle(): int
    {
        // Repair prompt §3.5 / §17: a cron tick must not burn its fifty-second
        // budget refreshing distances for a switched-off module.
        if ($this->featureDisabled('places.database')) {
            $this->info('places.database is disabled — nothing to refresh.');

            return self::SUCCESS;
        }

        $limit = max(1, (int) $this->option('limit'));

        $projectIds = ProjectNearbyPlace::query()
            ->where('is_stale', true)
            ->distinct()
            ->limit($limit)
            ->pluck('project_id');

        if ($projectIds->isEmpty()) {
            $this->info('No stale nearby-place snapshots.');

            return self::SUCCESS;
        }

        if ($this->option('dry-run')) {
            $this->line(sprintf('Would queue %d project(s): %s', $projectIds->count(), $projectIds->implode(', ')));

            return self::SUCCESS;
        }

        foreach ($projectIds as $projectId) {
            RecalculateNearbyPlaces::dispatch((int) $projectId);
        }

        $this->info(sprintf('Queued %d project(s) for recalculation.', $projectIds->count()));

        return self::SUCCESS;
    }
}
