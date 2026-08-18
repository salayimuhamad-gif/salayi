<?php

declare(strict_types=1);

use App\Modules\Operations\Console\ExpireLapsedRecords;
use Illuminate\Support\Facades\Schedule;

/*
 * Hourly. Expiry is a date boundary rather than a moment, so a listing that
 * lapsed at midnight need not disappear at 00:00:01 — but it should not still
 * be live at lunchtime either.
 *
 * withoutOverlapping because a slow run on a large table must not have a second
 * copy started on top of it by the next cron tick.
 */
Schedule::command(ExpireLapsedRecords::class, ['--limit' => 200])
    ->hourly()
    ->withoutOverlapping(15)
    ->onOneServer();
