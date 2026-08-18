<?php

declare(strict_types=1);

namespace App\Modules\Projects\Console;

use Illuminate\Console\Command;

/**
 * DEPRECATED — superseded by `mulkihawler:retry-media-cleanup-all`.
 *
 * This command used to retry draft media cleanup with its own selection, its own
 * attempt accounting and its own deletion. Two commands claiming the same rows
 * by different mechanisms produce duplicate finalisation and duplicate audit
 * evidence — an audit trail describing events that happened once.
 *
 * It is retained only so an operator following an older runbook is redirected
 * rather than met with "command not defined". The body DELEGATES; the previous
 * implementation is gone rather than left unreachable, because dead code in a
 * cleanup path is exactly what a later reader will mistake for the real one.
 */
final class PruneDraftMedia extends Command
{
    protected $signature = 'mulkihawler:prune-draft-media
                            {--dry-run : Report the backlog and stop}';

    protected $description = 'Deprecated. Use mulkihawler:retry-media-cleanup-all.';

    public function handle(): int
    {
        $this->warn(
            'Deprecated: this command now delegates to '
            .'mulkihawler:retry-media-cleanup-all, which claims its rows, '
            .'repairs owed handoffs and covers all three media domains.'
        );

        return $this->call('mulkihawler:retry-media-cleanup-all', [
            '--dry-run' => (bool) $this->option('dry-run'),
        ]);
    }
}
