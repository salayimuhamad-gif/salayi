<?php

declare(strict_types=1);

namespace App\Modules\Projects\Console;

use App\Modules\Projects\Models\CleanupJournalImport;
use App\Modules\Projects\Models\OrphanedFile;
use App\Modules\Projects\Support\CleanupJournal;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * Move emergency journal entries into the cleanup outbox (spec 26.1).
 *
 * The journal exists for the window where the database could not be written.
 * This closes that window: every entry becomes a real, retryable cleanup job.
 *
 * ROTATE, THEN PROCESS. Reading the active file and truncating it afterwards
 * erased anything appended in between — silent data loss in the one mechanism
 * whose entire purpose is not losing things. The file is renamed under an
 * exclusive lock and a fresh empty one put in its place, so the file being
 * processed is one nothing can append to.
 *
 * Files left by a crashed run are adopted on the next pass.
 */
final class ReplayCleanupJournal extends Command
{
    protected $signature = 'mulkihawler:replay-cleanup-journal
                            {--dry-run : Report the active journal and stop}';

    protected $description = 'Transfer emergency cleanup journal entries into the orphaned-files outbox.';

    public function handle(): int
    {
        if ($this->option('dry-run')) {
            $entries = CleanupJournal::entries();
            $pending = CleanupJournal::pendingProcessingFiles();

            $this->info(count($entries).' active entr(y/ies), '.count($pending).' rotated file(s) awaiting replay.');

            foreach ($entries as $entry) {
                $this->line("  {$entry['disk']}:{$entry['path']} ({$entry['reason']})");
            }

            // A dry run must not rotate: another process may be appending, and
            // a report that changes what it reports is worse than none.
            return self::SUCCESS;
        }

        /*
         * CLAIM before reading. Listing pending files and processing them gave
         * two concurrent replays the whole set, so the same entries were
         * transferred twice. An atomic rename means exactly one worker wins.
         *
         * Reclaimable files — claimed by a run that then died — are adopted
         * first, so nothing is stranded.
         */
        $candidates = array_merge(
            CleanupJournal::reclaimableFiles(),
            CleanupJournal::pendingProcessingFiles(),
        );

        $claimed = [];

        foreach ($candidates as $candidate) {
            $owned = CleanupJournal::claim($candidate);

            if ($owned !== null) {
                $claimed[] = $owned;
            }
        }

        // Our own rotation last, so older work drains first.
        $rotated = CleanupJournal::rotate();

        if ($rotated !== null) {
            $owned = CleanupJournal::claim($rotated);

            if ($owned !== null) {
                $claimed[] = $owned;
            }
        }

        if ($claimed === []) {
            $this->info('The cleanup journal is empty.');

            return self::SUCCESS;
        }

        $transferred = 0;
        $failed = 0;
        $quarantined = 0;

        foreach ($claimed as $file) {
            $parsed = CleanupJournal::readFile($file);

            /*
             * A READ FAILURE IS NOT AN EMPTY FILE. Rewriting or unlinking here
             * would destroy work never read — and the errors that cause it,
             * permissions and transient mounts, are exactly the recoverable
             * kind. The claim is kept and the lease refreshed so this worker
             * retains ownership for one more cycle, then released so another
             * can try.
             */
            if ($parsed['ok'] !== true) {
                $this->error("Could not read {$file}: ".($parsed['error'] ?? 'unknown').'.');

                CleanupJournal::releaseLease($file);
                $failed++;

                continue;
            }

            $remaining = [];

            if ($parsed['malformed'] !== []) {
                /*
                 * A quarantine that fails must NOT be followed by a rewrite
                 * that drops the line — that destroys the only copy. Lines the
                 * dead-letter journal would not accept stay in this file.
                 */
                $unquarantined = CleanupJournal::quarantine($parsed['malformed']);

                $quarantined += count($parsed['malformed']) - count($unquarantined);
                $remaining = array_merge($remaining, $unquarantined);
                $failed += count($unquarantined);
            }

            /*
             * Lease refreshed on ELAPSED TIME, not entry count. A file of one
             * enormous entry needs the lease kept alive as much as a file of
             * ten thousand small ones, and a count-based refresh would let the
             * first expire mid-processing.
             */
            $lastRefresh = time();
            $refreshEvery = max(30, (int) (CleanupJournal::leaseSeconds() / 3));
            $lostLease = false;

            /*
             * Lines this pass has not yet handled. Maintained as we go so an
             * early stop knows exactly what it never reached.
             */
            $untouched = array_map(
                static fn (array $entry): string => $entry['line'],
                $parsed['entries'],
            );

            foreach ($parsed['entries'] as $index => $entry) {
                // No longer untouched: this pass is about to handle it.
                unset($untouched[$index]);

                if (time() - $lastRefresh >= $refreshEvery) {
                    /*
                     * A LEASE THAT CANNOT BE REFRESHED IS OWNERSHIP LOST.
                     *
                     * Ignoring the result meant this worker kept transferring
                     * entries while another, seeing an expired lease, could
                     * reclaim the same file and transfer them again. Stopping
                     * here retains every unprocessed line and leaves the file
                     * claimed, so nothing is lost and nothing is doubled.
                     */
                    if (! CleanupJournal::refreshLease($file)) {
                        $this->error("Lost the lease on {$file}; stopping to avoid concurrent processing.");

                        // Put it back: this pass will not handle it after all.
                        $untouched[$index] = $entry['line'];
                        $lostLease = true;

                        break;
                    }

                    $lastRefresh = time();
                }

                try {
                    $context = is_array($entry['data']['context'] ?? null)
                        ? $entry['data']['context']
                        : [];

                    /*
                     * LEGACY LINES have no entry_id: they were written before
                     * the field existed, and a retained one would inflate its
                     * job's attempts on every replay. The key is derived from
                     * the exact original bytes, so the same line always
                     * resolves to the same ledger entry.
                     */
                    $entryId = (string) ($entry['data']['entry_id'] ?? '');

                    if ($entryId === '') {
                        /*
                         * 64 CHARACTERS EXACTLY, because that is the column.
                         *
                         * `'legacy:' . sha256` is 71 and overflowed — silently
                         * on SQLite, which does not enforce VARCHAR length, and
                         * as a truncation or error on MySQL. Two different
                         * legacy lines truncated to the same 64 characters
                         * would then collide into one ledger entry.
                         *
                         * The prefix is kept for readability and paid for out
                         * of the hash, which stays collision-free at 57 hex
                         * characters (228 bits) for this purpose.
                         */
                        $entryId = 'legacy:'.substr(hash('sha256', $entry['line']), 0, 57);
                    }

                    /*
                     * ONE TRANSACTION: reserve the entry id, create or
                     * increment the job, link the ledger.
                     *
                     * The previous shape asked `alreadyImported()` and then
                     * recorded — a time-of-check/time-of-use gap two workers
                     * could both pass, importing the same entry twice and
                     * inflating the job's attempts without a real cleanup
                     * attempt. The ledger's unique index now decides, inside
                     * the same transaction as the work it guards.
                     */
                    $payloadHash = hash('sha256', $entry['line']);

                    $imported = DB::transaction(function () use ($entry, $entryId, $context, $payloadHash): string {
                        $existing = CleanupJournalImport::query()
                            ->where('entry_id', $entryId)
                            ->lockForUpdate()
                            ->first();

                        if ($existing !== null) {
                            /*
                             * SAME ID, DIFFERENT BYTES is not a duplicate — it
                             * is a conflict. Consuming it would discard a line
                             * describing work nobody has done, and recording
                             * it would corrupt the ledger's meaning. The line
                             * is kept and reported.
                             */
                            /*
                             * A NULL STORED HASH IS ITSELF A CONFLICT.
                             *
                             * Skipping the check when the hash was missing
                             * meant rows with NO integrity evidence were the
                             * ones treated as trustworthy — precisely
                             * backwards. The column is now NOT NULL, so a null
                             * here is legacy data or corruption, and either
                             * needs a person rather than a silent pass.
                             */
                            /*
                             * Read the RAW attribute: the column is NOT NULL,
                             * so the generated annotation types it `string`
                             * and a `=== null` test on the typed accessor
                             * reads as dead code — while legacy or corrupt
                             * rows can still carry null in the database. The
                             * raw value is what actually needs judging.
                             */
                            $storedHash = $existing->getAttributes()['payload_hash'] ?? null;

                            if ($storedHash === null || $storedHash !== $payloadHash) {
                                return 'conflict';
                            }

                            return 'duplicate';   // consumed by an earlier run
                        }

                        $job = OrphanedFile::record(
                            (string) $entry['data']['disk'],
                            (string) $entry['data']['path'],
                            (string) ($entry['data']['reason'] ?? 'journal_replay'),
                            $context,
                        );

                        CleanupJournalImport::query()->create([
                            'entry_id' => $entryId,
                            'orphaned_file_id' => $job->id,
                            // Detects a journal line edited between runs.
                            'payload_hash' => $payloadHash,
                            'imported_at' => now(),
                        ]);

                        return 'imported';
                    });

                    if ($imported === 'conflict') {
                        $this->error(
                            "Integrity conflict on entry {$entryId}: the ledger records different "
                            .'bytes for this id. The line has been retained for review.'
                        );

                        // Retained, not consumed: the caller decides.
                        $remaining[] = $entry['line'];
                        $failed++;

                        continue;
                    }

                    // A recognised duplicate still counts as handled: the work
                    // is safely in the database and the line may be dropped.
                    $transferred++;
                } catch (Throwable $e) {
                    $failed++;

                    /*
                     * Only the FAILED line is retained. Keeping the whole file
                     * would re-record every success next run, inflating
                     * attempt counts without a real cleanup attempt.
                     */
                    $remaining[] = $entry['line'];

                    $this->error(
                        "Could not replay {$entry['data']['disk']}:{$entry['data']['path']}: "
                        .$e->getMessage()
                    );
                }
            }

            if ($lostLease) {
                /*
                 * Every line NOT yet transferred is retained — including the
                 * ones this pass never reached. Keeping only the failures
                 * would silently drop the untouched tail of the file.
                 *
                 * The claim is deliberately NOT released: doing so while this
                 * process may still be mid-write is exactly what would let two
                 * workers run at once. The stale-lease sweep reclaims it once
                 * this worker is genuinely gone.
                 */
                $remaining = array_merge($remaining, $untouched);

                $failed++;

                if (! CleanupJournal::retain($file, $remaining)) {
                    // Ownership is already gone, so another worker owns this
                    // file now; saying so beats a silent no-op.
                    $this->error("Could not retain {$file}: this worker no longer owns it.");
                }

                continue;
            }

            if (! CleanupJournal::retain($file, $remaining)) {
                // The claimed file survives untouched, so the work is not
                // lost; the next run reclaims it once the claim goes stale.
                $this->error("Could not rewrite {$file}; it will be reclaimed later.");
                $failed++;

                continue;
            }

            // Finished with: the lease goes so the file is not held.
            CleanupJournal::releaseLease($file);
        }

        $this->info("Transferred {$transferred}; {$failed} retained for retry; {$quarantined} quarantined.");

        if ($quarantined > 0) {
            $this->warn(
                $quarantined.' malformed line(s) moved to '.CleanupJournal::deadLetterPath()
                .' — these need a person.'
            );
        }

        return ($failed > 0 || $quarantined > 0) ? self::FAILURE : self::SUCCESS;
    }
}
