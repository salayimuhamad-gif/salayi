<?php

declare(strict_types=1);

use App\Modules\Identity\Console\PruneRecoveryChallenges;
use App\Modules\Identity\Console\PruneTelegramIntents;
use App\Modules\Identity\Console\PruneTelegramReturnHandoffs;
use App\Modules\Identity\Console\PruneUnlinkedAccounts;
use Illuminate\Support\Facades\Schedule;

/*
 * Daily. Login material is short-lived by design, so nothing here is urgent —
 * but leaving it unbounded turns the login path into the slowest query on the
 * system eventually, and that is the worst place for one.
 */
Schedule::command(PruneTelegramIntents::class)
    ->dailyAt('03:20')
    ->withoutOverlapping(30)
    ->onOneServer();

/*
 * v7 account-first: an account can now exist without ever being linked, and
 * `phone_index` is UNIQUE — so an abandoned registration holds a phone number
 * hostage from the person who actually owns it. Daily, and an hour after the
 * intent sweep so the two never contend for the same rows.
 */
Schedule::command(PruneUnlinkedAccounts::class)
    ->dailyAt('04:20')
    ->withoutOverlapping(30)
    ->onOneServer();

/*
 * Expired return handoffs. The table holds a `user_id` per row and the design
 * calls those rows short-lived, so something has to make that true — a prune
 * method nobody calls is not a retention policy. Half past three, clear of both
 * the intent sweep and the unlinked-account sweep.
 */
Schedule::command(PruneTelegramReturnHandoffs::class)
    ->dailyAt('03:50')
    ->withoutOverlapping(30)
    ->onOneServer();

/*
 * Expired password-recovery challenges. Fifteen-minute credentials must not
 * accumulate as rows; five past four keeps it clear of the other sweeps.
 */
Schedule::command(PruneRecoveryChallenges::class)
    ->dailyAt('04:05')
    ->withoutOverlapping(30)
    ->onOneServer();
