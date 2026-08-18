<?php

declare(strict_types=1);

use App\Modules\Geography\Console\RefreshStaleNearbyPlaces;
use Illuminate\Support\Facades\Schedule;

/*
 * Hourly rather than every minute. The observers handle the common cases in
 * near-real time; this is the safety net for what they miss, and a stale
 * distance is not urgent enough to spend a cron tick on every sixty seconds.
 */
Schedule::command(RefreshStaleNearbyPlaces::class, ['--limit' => 25])
    ->hourly()
    ->withoutOverlapping(10)
    ->onOneServer();
