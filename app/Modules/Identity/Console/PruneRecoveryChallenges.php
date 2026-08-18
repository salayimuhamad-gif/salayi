<?php

declare(strict_types=1);

namespace App\Modules\Identity\Console;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Remove expired password-recovery challenges.
 *
 * Same retention rules as the return-handoff sweep: a row is eligible only
 * once it is past expiry AND past a grace period, so nothing disappears
 * while it could still be redeemed or while an operator is investigating a
 * refused reset. A live unconsumed row is never touched — expiry is the only
 * clock. Idempotent by construction.
 */
final class PruneRecoveryChallenges extends Command
{
    protected $signature = 'mulkihawler:recovery:prune
        {--grace-hours=24 : Keep expired rows this long before removing them}
        {--dry-run : Report what would be removed without changing anything}';

    protected $description = 'Remove password-recovery challenges that expired beyond the grace period';

    public function handle(): int
    {
        $graceHours = max(1, (int) $this->option('grace-hours'));
        $cutoff = now()->subHours($graceHours);
        $dryRun = (bool) $this->option('dry-run');

        $base = DB::table('password_recovery_challenges')->where('expires_at', '<', $cutoff);

        $consumed = (clone $base)->whereNotNull('consumed_at')->count();
        $unconsumed = (clone $base)->whereNull('consumed_at')->count();
        $live = DB::table('password_recovery_challenges')->where('expires_at', '>=', $cutoff)->count();

        $removed = $dryRun ? 0 : (clone $base)->delete();

        $this->info(sprintf(
            '%s %d expired recovery challenge(s) older than %dh: %d consumed, %d never used. %d live row(s) kept.',
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
