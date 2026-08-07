<?php

declare(strict_types=1);

namespace App\Modules\Geography\Jobs;

use App\Modules\Core\Concerns\GuardedByFeature;
use App\Modules\Geography\Services\NearbyPlaceCalculator;
use App\Modules\Operations\Services\AuditLogger;
use App\Modules\Projects\Models\Project;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

/**
 * Recalculate one project's nearby places (spec 10.5 step 8, "recalculate when
 * a new place is added").
 *
 * Queued, and on the `maintenance` queue specifically. `routes/console.php`
 * orders the worker `critical,notifications,default,imports,ai,maintenance`, so
 * a bulk recalculation triggered by publishing fifty places can never delay a
 * login notification. On shared hosting with one cron-driven worker, queue
 * ordering is the only scheduling control available.
 *
 * ShouldBeUnique with a short window because the triggers overlap: saving a
 * project fires one, and publishing a nearby place fires another for the same
 * project. Without it a busy afternoon of editing queues the same expensive
 * calculation dozens of times.
 */
final class RecalculateNearbyPlaces implements ShouldBeUnique, ShouldQueue
{
    use Dispatchable;
    use GuardedByFeature;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public int $tries = 3;

    public int $timeout = 110;

    /** Unique for two minutes, which is longer than the cron tick. */
    public int $uniqueFor = 120;

    public function __construct(public readonly int $projectId)
    {
        $this->onQueue('maintenance');
    }

    public function uniqueId(): string
    {
        return 'nearby:'.$this->projectId;
    }

    public function handle(NearbyPlaceCalculator $calculator, AuditLogger $audit): void
    {
        /*
         * Repair prompt §3.5 / §17. Checked at run time, not dispatch time: a
         * job can sit in the queue while an administrator switches the places
         * database off, and the value that matters is the one at the moment
         * the distances would actually be rewritten.
         */
        if ($this->featureDisabled('places.database')) {
            return;
        }

        $project = Project::query()->find($this->projectId);

        if ($project === null) {
            return;
        }

        $result = $calculator->recalculate($project);

        // Recorded because an automatic rewrite of published distances should
        // be attributable after the fact, not invisible.
        $audit->record('nearby.recalculated', $project, [], [
            'written' => $result['written'],
            'candidates' => $result['candidates'],
            'manual_preserved' => $result['manual_preserved'],
            'hidden_preserved' => $result['hidden_preserved'],
        ]);
    }
}
