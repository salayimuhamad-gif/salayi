<?php

declare(strict_types=1);

use App\Modules\Notifications\Console\SendNotificationDigests;
use Illuminate\Support\Facades\Schedule;

/*
 * Hourly rather than daily, because the send hour is a per-user preference:
 * the command selects only the recipients whose `digest_hour` is the current
 * hour, so running once a day would serve exactly one of the twenty-four
 * possible choices and silently ignore the rest.
 *
 * withoutOverlapping so a slow run is not stacked on by the next cron tick —
 * the same protection the expiry sweep needs, and for the same reason: on
 * shared hosting the worker is killed at roughly fifty seconds and a second
 * copy would re-send what the first had not yet marked.
 */
Schedule::command(SendNotificationDigests::class, ['--limit' => 100])
    ->hourly()
    ->withoutOverlapping(15)
    ->onOneServer();
