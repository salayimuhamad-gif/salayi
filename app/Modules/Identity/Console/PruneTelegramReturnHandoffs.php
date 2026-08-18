<?php

declare(strict_types=1);

namespace App\Modules\Identity\Console;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Remove expired Telegram return handoffs.
 *
 * `TelegramReturnHandoff::pruneExpired()` existed but nothing ever called it,
 * so every consumed or expired handoff row stayed in the table forever — a
 * growing list of `user_id` references that the code itself describes as
 * short-lived. An unused static method is not a retention policy.
 *
 * What is removed, and what is deliberately not:
 *
 *   - a row is eligible only once it is past expiry AND past the grace period
 *     on top of that, so nothing is deleted while it could still be redeemed
 *     or while an operator might want to see why a redemption failed;
 *   - a LIVE unconsumed row is never touched, whatever its age looks like at
 *     first glance — expiry is the only clock that matters;
 *   - an expired but unconsumed row IS removed, because a handoff that was
 *     never used is worth nothing after expiry and holding a `user_id`
 *     reference for it serves no purpose. It is counted separately so the
 *     output distinguishes "spent and cleaned up" from "never used".
 *
 * Idempotent by construction: a second run finds nothing left to match.
 */
final class PruneTelegramReturnHandoffs extends Command
{
    protected $signature = 'mulkihawler:telegram:prune-return-handoffs
        {--grace-hours=24 : Keep expired rows this long before removing them}
        {--dry-run : Report what would be removed without changing anything}';

    protected $description = 'Remove Telegram return handoffs that expired beyond the grace period';

    public function handle(): int
    {
        $graceHours = max(1, (int) $this->option('grace-hours'));
        $cutoff = now()->subHours($graceHours);
        $dryRun = (bool) $this->option('dry-run');

        $base = DB::table('telegram_return_handoffs')->where('expires_at', '<', $cutoff);

        $consumed = (clone $base)->whereNotNull('consumed_at')->count();
        $unconsumed = (clone $base)->whereNull('consumed_at')->count();
        $live = DB::table('telegram_return_handoffs')->where('expires_at', '>=', $cutoff)->count();

        $removed = $dryRun ? 0 : (clone $base)->delete();

        $this->info(sprintf(
            '%s %d expired return handoff(s) older than %dh: %d consumed, %d never used. %d live row(s) kept.',
            $dryRun ? 'Would remove' : 'Removed',
            $dryRun ? $consumed + $unconsumed : $removed,
            $graceHours,
            $consumed,
            $unconsumed,
            $live,
        ));

        return self::SUCCESS;
    }
}
