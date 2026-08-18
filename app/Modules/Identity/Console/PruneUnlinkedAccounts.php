<?php

declare(strict_types=1);

namespace App\Modules\Identity\Console;

use App\Modules\Identity\Models\User;
use App\Modules\Identity\Services\AbandonedAccountPolicy;
use Illuminate\Console\Command;

/**
 * Reclaim abandoned unlinked accounts (v7 account-first).
 *
 * All judgement lives in {@see AbandonedAccountPolicy}: which accounts are
 * eligible, what is cleared, and what survives as a tombstone. This command
 * only iterates and reports, so the destructive rules have one home and one
 * set of tests rather than being restated wherever cleanup happens.
 *
 * Why this exists: `phone_index` is UNIQUE, so an account that never linked
 * holds a phone number away from the person who actually owns it — and it
 * costs one form submission to create. Without reclamation that lock is
 * permanent and trivially weaponisable.
 */
final class PruneUnlinkedAccounts extends Command
{
    protected $signature = 'mulkihawler:accounts:prune-unlinked
        {--hours= : Override the configured retention window}
        {--dry-run : Report what would be reclaimed without changing anything}';

    protected $description = 'Reclaim abandoned accounts that never completed Telegram linking';

    public function handle(AbandonedAccountPolicy $policy): int
    {
        $hours = $this->option('hours') === null
            ? AbandonedAccountPolicy::retentionHours()
            : max(1, (int) $this->option('hours'));

        $dryRun = (bool) $this->option('dry-run');

        $reclaimed = 0;
        $kept = [];

        User::query()
            ->whereNull('telegram_verified_at')
            ->whereNull('telegram_id')
            ->where('created_at', '<', now()->subHours($hours))
            ->orderBy('id')
            ->chunkById(200, function ($users) use ($policy, $dryRun, $hours, &$reclaimed, &$kept): void {
                foreach ($users as $user) {
                    // The SAME window that selected this candidate.
                    $assessment = $policy->assess($user, $hours);

                    if (! $assessment['eligible']) {
                        $kept[$assessment['reason']] = ($kept[$assessment['reason']] ?? 0) + 1;

                        continue;
                    }

                    if ($dryRun) {
                        $reclaimed++;

                        continue;
                    }

                    if ($policy->release($user, 'scheduled_prune')) {
                        $reclaimed++;
                    } else {
                        // Released between assessment and write — linked, or
                        // already reclaimed. Not an error.
                        $kept['raced'] = ($kept['raced'] ?? 0) + 1;
                    }
                }
            });

        $this->info(sprintf(
            '%s %d abandoned unlinked account(s). Policy applied: retention %dh (%s)%s.',
            $dryRun ? 'Would reclaim' : 'Reclaimed',
            $reclaimed,
            $hours,
            $this->option('hours') === null ? 'configured default' : '--hours override',
            $dryRun ? ', dry run — nothing was changed' : '',
        ));

        foreach ($kept as $reason => $count) {
            $this->line(sprintf('  kept %d: %s', $count, $reason));
        }

        return self::SUCCESS;
    }
}
