<?php

declare(strict_types=1);

use App\Modules\Portfolio\Console\CleanupPortfolioMedia;
use Illuminate\Support\Facades\Schedule;

/*
 * Correction v4, BLOCKER 9 — the cleanup loop that v3 was missing.
 *
 * Every ten minutes rather than daily, and the frequency is the point. The
 * two things this drains are both states a PERSON is waiting on: a file
 * they asked to delete that is still on the disk, and a slot against their
 * twelve-file cap that a crashed upload is holding. A daily sweep would
 * mean somebody who hit a transient disk error at 09:00 cannot re-upload
 * until the following morning.
 *
 * `withoutOverlapping` matters more here than on the nightly jobs: on
 * Hostinger the scheduler is driven by a single shared cron entry, and a
 * pass that runs long against a slow disk would otherwise be re-entered
 * while still working. The command is idempotent regardless — every row it
 * touches is re-checked under a lock — so overlap would be survivable, but
 * two passes fighting over the same failing disk is not useful work.
 *
 * The five-minute expiry on the overlap lock is deliberately shorter than
 * the default: if a pass dies without releasing it, the sweep must resume
 * on its own rather than waiting out an hour-long stale lock.
 */
Schedule::command(CleanupPortfolioMedia::class)
    ->everyTenMinutes()
    ->withoutOverlapping(5)
    ->onOneServer()
    ->runInBackground();
