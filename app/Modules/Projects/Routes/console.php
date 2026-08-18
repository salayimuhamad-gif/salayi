<?php

declare(strict_types=1);

use App\Modules\Projects\Console\PruneProjectDrafts;
use App\Modules\Projects\Console\ReplayCleanupJournal;
use App\Modules\Projects\Console\RetryMediaCleanupAll;
use App\Modules\Projects\Console\SweepOrphanedFiles;
use Illuminate\Support\Facades\Schedule;

/*
 * Abandoned wizard drafts are swept nightly. Off-peak because the delete can
 * touch a large number of rows on a busy dataset, and Hostinger's shared
 * database is least contended at night.
 */
Schedule::command(PruneProjectDrafts::class)->dailyAt('03:20');

/*
 * Media whose files could not be deleted. Hourly rather than nightly: a
 * transient storage error should resolve within the hour, and the bounded
 * attempt count means a genuine failure surfaces quickly instead of being
 * retried once a day for a week.
 */
/*
 * ONE retry command for all three media domains. The two single-domain
 * commands remain invokable for targeted operator work, but the schedule runs
 * the unified one so offer media is not left without a retry path.
 */
Schedule::command(RetryMediaCleanupAll::class)
    ->hourly()
    /*
     * Two instances finalising the same row would produce duplicate audit
     * evidence — the same deletion recorded twice, which makes the audit trail
     * describe events that did not happen.
     */
    ->withoutOverlapping(10);

/*
 * Draft uploads whose removal failed, plus originals left behind after a
 * successful promotion. Hourly, for the same reason: a transient storage error
 * should resolve within the hour, and a real one should surface quickly.
 */

/*
 * Files whose compensation failed. Hourly, because an orphan is wasted disk on
 * a shared host and the backlog only grows.
 */
/*
 * Drain the emergency journal before the sweep runs, so entries written while
 * the database was down become real jobs the same hour.
 */
Schedule::command(ReplayCleanupJournal::class)->hourly()->withoutOverlapping(10);

Schedule::command(SweepOrphanedFiles::class)
    ->hourly()
    // The sweep claims rows under a lock, but overlapping runs still waste a
    // shared host's limited execution budget fighting for the same work.
    ->withoutOverlapping(10);
