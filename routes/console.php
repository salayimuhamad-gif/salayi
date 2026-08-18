<?php

declare(strict_types=1);

use App\Modules\Operations\Models\SchedulerHeartbeat;
use Illuminate\Support\Facades\Schedule;

/*
 * Scheduled work (spec 34.2). One cron entry drives everything:
 *
 *   * * * * * php /home/ACCOUNT/application/artisan schedule:run >> /dev/null 2>&1
 *
 * Shared hosting has no long-running worker, so the queue is drained by a
 * short, bounded artisan run rather than a daemon. `--max-time` keeps every
 * invocation inside the host's execution limit; `withoutOverlapping` stops two
 * cron ticks from processing the same job.
 */

Schedule::command('queue:work --stop-when-empty --max-time=50 --tries=3 --queue=critical,notifications,default,imports,ai,maintenance')
    ->everyMinute()
    ->withoutOverlapping(2)
    ->runInBackground();

Schedule::command('queue:prune-failed --hours=336')->weekly();
Schedule::command('queue:prune-batches --hours=168')->daily();
Schedule::command('cache:prune-stale-tags')->hourly();
Schedule::command('auth:clear-resets')->daily();

// Heartbeat so the admin overview can show "scheduler last ran at ..."
// (spec 24.3). A silent cron is the most common shared-hosting failure and
// the one that is hardest to notice.
Schedule::call(static function (): void {
    if (! app()->runningUnitTests()) {
        SchedulerHeartbeat::query()->updateOrCreate(
            ['key' => 'scheduler'],
            ['last_run_at' => now(), 'last_success_at' => now(), 'consecutive_failures' => 0],
        );
    }
})->everyMinute()->name('scheduler-heartbeat');
