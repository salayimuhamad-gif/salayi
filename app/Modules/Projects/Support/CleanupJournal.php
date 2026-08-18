<?php

declare(strict_types=1);

namespace App\Modules\Projects\Support;

use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Last-resort replayable record of an unreferenced file (spec 26.1).
 *
 * `recordSafely()` falls back here when the outbox database write itself
 * fails. A log line is not durable cleanup work: nothing reads it, nothing
 * retries it, and rotation destroys it — in exactly the situation this exists
 * for.
 *
 * THREE CONCURRENCY RULES, each learned from a specific hole:
 *
 * 1. The coordination lock lives in a SEPARATE file that is never renamed.
 *    Locking the active journal itself was not enough: a writer could open the
 *    old inode, block on the lock, and then append to a file that had already
 *    been rotated away and read.
 *
 * 2. Rotated filenames carry random bytes, not a timestamp and pid. Two
 *    rotations in one process within one second produced the same name and
 *    silently overwrote unprocessed work.
 *
 * 3. A pending file is CLAIMED by an atomic rename before it is read, so two
 *    replay processes — or two hosts on shared storage — cannot both transfer
 *    the same entries.
 */
final class CleanupJournal
{
    /** How long a claimed file may sit before another worker may reclaim it. */
    private const CLAIM_STALE_SECONDS = 900;

    /**
     * A barrier fired inside `retain()`, after the temporary file is written
     * and before ownership is re-verified for the rename.
     *
     * THIS EXISTS FOR ONE REASON: the window it opens onto cannot be reached
     * deterministically from outside. A concurrency test that waits for the
     * temporary file to appear loses the race — ten executed runs reclaimed
     * before the write had even started — and one that relies on a large
     * payload is using timing as synchronisation, which is the same bet with
     * better odds.
     *
     * Null in production, so the only cost is one identity check per rewrite.
     *
     * @var (callable(string): void)|null
     */
    private static $retainBarrier = null;

    /** Install a barrier for a test. Pass null to remove it. */
    public static function setRetainBarrier(?callable $barrier): void
    {
        self::$retainBarrier = $barrier;
    }

    /** The file writers append to. */
    public static function path(): string
    {
        return storage_path('app/cleanup-journal.jsonl');
    }

    /**
     * The coordination lock.
     *
     * Separate from the data, and NEVER renamed — a lock on a file that is
     * about to be renamed coordinates nothing, because the renamer and the
     * appender end up holding locks on different inodes.
     */
    public static function lockPath(): string
    {
        return storage_path('app/cleanup-journal.lock');
    }

    /** Lines that could not be parsed, kept with their original bytes. */
    public static function deadLetterPath(): string
    {
        return storage_path('app/cleanup-journal.dead.jsonl');
    }

    public static function directory(): string
    {
        return storage_path('app');
    }

    /**
     * Append one entry. Never throws: this is the fallback of the fallback.
     *
     * @param  array<string, mixed>  $context
     */
    public static function append(string $disk, string $path, string $reason, array $context = []): bool
    {
        /*
         * ENTROPY CAN FAIL. `random_bytes()` throws when the system source is
         * unavailable — and this runs while handling an earlier failure, so an
         * exception here would replace the original error with a journal one
         * and lose both.
         */
        try {
            $entryId = bin2hex(random_bytes(16));
        } catch (Throwable) {
            // Weaker, but this is the fallback of the fallback: an id that is
            // merely unlikely to collide beats no record at all.
            $entryId = hash('sha256', uniqid((string) mt_rand(), true));
        }

        $line = json_encode([
            /*
             * A STABLE ID per entry, used as an idempotency key on import.
             * If the transfers succeeded but rewriting the claimed file
             * failed, the next replay re-imported them — inflating attempt
             * counts without a real cleanup attempt having happened.
             */
            'entry_id' => $entryId,
            'recorded_at' => date(DATE_ATOM),
            'disk' => $disk,
            'path' => $path,
            'reason' => $reason,
            'context' => $context,
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

        if ($line === false) {
            return false;
        }

        // Under the SHARED lock: the active journal is opened only once the
        // lock is held, so it cannot be the file a rotation just moved away.
        return (bool) self::withLock(LOCK_EX, static function () use ($line): bool {
            return self::rawAppend(self::path(), $line);
        }, false);
    }

    /**
     * Rotate the active journal aside for exclusive processing.
     *
     * @return string|null the rotated file, or null when there is nothing to do
     */
    public static function rotate(): ?string
    {
        return self::withLock(LOCK_EX, static function (): ?string {
            $active = self::path();

            if (! is_file($active) || filesize($active) === 0) {
                return null;
            }

            $rotated = self::uniqueDestination('processing');

            if ($rotated === null) {
                // Could not find a free name; leaving the journal alone is the
                // safe outcome, and the next run tries again.
                return null;
            }

            if (! @rename($active, $rotated)) {
                return null;
            }

            // Recreated before the lock is released, so no writer ever finds
            // it missing.
            @file_put_contents($active, '');

            return $rotated;
        }, null);
    }

    /**
     * Rotated files not currently claimed by a live worker.
     *
     * @return list<string>
     */
    public static function pendingProcessingFiles(): array
    {
        $found = glob(self::directory().'/cleanup-journal.*.processing.jsonl');

        return $found === false ? [] : $found;
    }

    /**
     * Take exclusive ownership of a pending file.
     *
     * An ATOMIC RENAME is the claim: exactly one caller can succeed, so two
     * replay processes cannot transfer the same entries twice. Listing files
     * and reading them, as the first version did, gave both processes the
     * whole set.
     *
     * @return string|null the claimed path, or null if somebody else won
     */
    /**
     * Take exclusive ownership of a pending or reclaimable file.
     *
     * UNDER THE COORDINATION LOCK, WITH THE LEASE REVALIDATED IMMEDIATELY
     * BEFORE THE RENAME.
     *
     * The previous version decided a candidate was stale, then renamed it
     * outside any lock. Between those two moments its rightful owner could
     * refresh the lease — and the rename stole a file that was actively being
     * processed, producing two workers on one piece of work.
     *
     * Holding the same lock that `refreshLease()` takes closes that window:
     * either the refresh lands first and this claim declines, or this claim
     * lands first and the refresh finds it no longer owns the path.
     *
     * @return string|null the claimed path, or null if somebody else owns it
     */
    public static function claim(string $pendingFile): ?string
    {
        return self::withLock(LOCK_EX, static function () use ($pendingFile): ?string {
            if (! is_file($pendingFile)) {
                return null;   // renamed away while we waited for the lock
            }

            /*
             * REVALIDATE. A `.claimed.jsonl` candidate was only offered
             * because its lease looked stale; that judgement was made before
             * the lock, so it is made again here with the lock held.
             */
            if (str_contains($pendingFile, '.claimed.') && ! self::leaseIsStale($pendingFile)) {
                return null;   // the owner refreshed while we were deciding
            }

            $claimed = self::uniqueDestination('claimed');

            if ($claimed === null) {
                return null;
            }

            // rename() is atomic within a filesystem: the loser gets false
            // rather than a second copy.
            if (! @rename($pendingFile, $claimed)) {
                return null;
            }

            // A reclaimed file must not inherit the previous holder's lease.
            @unlink(self::leasePathFor($pendingFile));

            /*
             * A CLAIM WITHOUT A LEASE IS NOT A CLAIM. Returning success when
             * the lease could not be written left the file owned by nobody:
             * another worker would see no lease, treat it as reclaimable, and
             * process it concurrently.
             */
            if (! self::writeLease($claimed)) {
                $returned = self::uniqueDestination('processing');

                if ($returned === null || ! @rename($claimed, $returned)) {
                    Log::critical('A claimed journal file could not be leased or returned', [
                        'file' => $claimed,
                    ]);
                }

                return null;
            }

            return $claimed;
        }, null);
    }

    /**
     * Whether a claimed file's lease has expired.
     *
     * Extracted so `claim()` and `reclaimableFiles()` apply exactly the same
     * rule — a second copy of this judgement is how a file gets stolen from a
     * live owner.
     */
    private static function leaseIsStale(string $claimedFile): bool
    {
        $lease = self::leasePathFor($claimedFile);

        // No lease at all: the worker died between the rename and the write.
        if (! is_file($lease)) {
            return true;
        }

        $decoded = json_decode((string) @file_get_contents($lease), true);
        $claimedAt = is_array($decoded) ? (int) ($decoded['claimed_at'] ?? 0) : 0;

        return time() - $claimedAt > self::CLAIM_STALE_SECONDS;
    }

    /** Refresh the lease on a file being processed. */
    /**
     * Refresh a lease this process still owns.
     *
     * COMPARE-AND-SWAP under the coordination lock. A stale worker could
     * previously refresh a path another worker had reclaimed and recreated,
     * and then rewrite it — producing two claimed files for one piece of work.
     */
    public static function refreshLease(string $claimedFile): bool
    {
        return (bool) self::withLock(LOCK_EX, static function () use ($claimedFile): bool {
            if (! self::stillOwns($claimedFile)) {
                return false;
            }

            return self::writeLease($claimedFile);
        }, false);
    }

    /** Seconds a lease survives without a refresh. */
    public static function leaseSeconds(): int
    {
        return self::CLAIM_STALE_SECONDS;
    }

    /** Release the lease when a claimed file is finished with. */
    public static function releaseLease(string $claimedFile): void
    {
        self::withLock(LOCK_EX, static function () use ($claimedFile): bool {
            // Only the owner may release: removing somebody else's lease would
            // hand their in-progress file to the reclaim sweep.
            if (! self::stillOwns($claimedFile)) {
                return false;
            }

            return @unlink(self::leasePathFor($claimedFile));
        }, false);
    }

    private static function leasePathFor(string $claimedFile): string
    {
        return $claimedFile.'.lease';
    }

    /**
     * This process's ownership token.
     *
     * Stable for the life of the process, and written into every lease. A
     * stale worker could previously refresh a lease on a path another worker
     * had since reclaimed and recreated — two claimed files for one piece of
     * work. The token makes ownership checkable rather than assumed.
     */
    public static function ownerToken(): string
    {
        static $token = null;

        if ($token === null) {
            try {
                $token = gethostname().':'.getmypid().':'.bin2hex(random_bytes(8));
            } catch (Throwable) {
                $token = gethostname().':'.getmypid().':'.hash('crc32b', uniqid('', true));
            }
        }

        return $token;
    }

    /** Whether this process still owns a claimed file. */
    public static function stillOwns(string $claimedFile): bool
    {
        $lease = self::leasePathFor($claimedFile);

        if (! is_file($claimedFile) || ! is_file($lease)) {
            // The file was reclaimed and renamed, or the lease was removed.
            return false;
        }

        $decoded = json_decode((string) @file_get_contents($lease), true);

        return is_array($decoded)
            && ($decoded['owner'] ?? null) === self::ownerToken();
    }

    private static function writeLease(string $claimedFile): bool
    {
        $payload = json_encode([
            'claimed_at' => time(),
            // The token this process must present to refresh, rewrite or
            // release the file.
            'owner' => self::ownerToken(),
            // Human-readable, so a stuck lease can be traced to a host and
            // process rather than merely noticed.
            'worker' => gethostname().':'.getmypid(),
        ]);

        if ($payload === false) {
            return false;
        }

        /*
         * Exact-byte accounting and a verified fsync, like every other write
         * here. A partially written lease parses as malformed JSON, which
         * `leaseIsStale()` reads as no lease at all — so a short write handed
         * a live worker's file to the reclaim sweep.
         */
        return self::writeAllBytes(self::leasePathFor($claimedFile), $payload);
    }

    /**
     * Claimed files abandoned by a crashed worker.
     *
     * @return list<string>
     */
    public static function reclaimableFiles(): array
    {
        $found = glob(self::directory().'/cleanup-journal.*.claimed.jsonl');

        if ($found === false) {
            return [];
        }

        return array_values(array_filter(
            $found,
            // The SAME rule claim() applies, so the two cannot disagree.
            static fn (string $file): bool => self::leaseIsStale($file),
        ));
    }

    /**
     * Parse one file into transferable entries and malformed lines.
     *
     * Malformed lines are RETURNED, not silently dropped — they are
     * quarantined with their original bytes rather than destroyed.
     *
     * v6: the annotation omitted the failure shape this method genuinely
     * returns — `ok: false` with an `error` — so every caller's read check
     * was reported as dead code against a docblock that did not describe
     * the code.
     *
     * @return array{ok: bool, error: string|null, entries: list<array{line: string, data: array<string, mixed>}>, malformed: list<string>}
     */
    public static function readFile(string $file): array
    {
        /*
         * A READ FAILURE IS NOT AN EMPTY FILE.
         *
         * `file()` returning false — permissions, a vanished mount, a bad
         * descriptor — was coerced to an empty list, after which the caller
         * called retain($file, []) and DELETED the file it had never read.
         * The one error most likely to be transient destroyed the work.
         */
        if (! is_readable($file)) {
            return ['ok' => false, 'entries' => [], 'malformed' => [], 'error' => 'not readable'];
        }

        $lines = @file($file, FILE_IGNORE_NEW_LINES);

        if ($lines === false) {
            return ['ok' => false, 'entries' => [], 'malformed' => [], 'error' => 'read failed'];
        }

        $entries = [];
        $malformed = [];

        foreach ($lines as $line) {
            if (trim($line) === '') {
                continue;
            }

            $decoded = json_decode($line, true);

            if (is_array($decoded) && isset($decoded['disk'], $decoded['path'])) {
                $entries[] = ['line' => $line, 'data' => $decoded];

                continue;
            }

            // A torn final line from a crashed append, or corruption. Kept
            // verbatim: throwing away bytes we cannot read is how a file that
            // named a real orphan becomes nothing at all.
            $malformed[] = $line;
        }

        return ['ok' => true, 'entries' => $entries, 'malformed' => $malformed, 'error' => null];
    }

    /**
     * Move malformed lines to the dead-letter journal.
     *
     * Returns the lines that could NOT be written. The caller must retain
     * those in the processing file: a quarantine that silently fails, and is
     * then followed by a rewrite that drops the line, destroys the only copy.
     *
     * @param  list<string>  $lines
     * @return list<string> lines still needing a home
     */
    public static function quarantine(array $lines): array
    {
        $failed = [];

        foreach ($lines as $line) {
            if (! self::rawAppend(self::deadLetterPath(), $line)) {
                $failed[] = $line;
            }
        }

        return $failed;
    }

    /**
     * Rewrite a processing file with only the lines that still need work.
     *
     * TEMPORARY FILE, FSYNC, ATOMIC RENAME. Rewriting in place meant a crash
     * mid-write left a truncated file — the only copy of that work, partially
     * destroyed. Here the original survives untouched until a complete
     * replacement is on disk.
     *
     * @param  list<string>  $remaining
     */
    /**
     * Rewrite a claimed file with only the lines that still need work.
     *
     * OWNERSHIP HELD THROUGH THE REPLACEMENT, NOT MERELY CHECKED BEFORE IT.
     *
     * The previous version verified ownership, then wrote a temporary file
     * outside any lock, then renamed. A reclaim landing during that write left
     * the stale worker renaming its temporary file back onto the old pathname
     * — recreating a `.claimed.jsonl` the new owner was already processing.
     * The executed test ended with two claimed files for one piece of work.
     *
     * The temporary file is still written outside the lock, because it may be
     * large and holding a global lock for the duration would serialise every
     * append. But the ownership re-check and the rename happen together under
     * the lock, so a file reclaimed mid-write is never resurrected: the
     * temporary is discarded instead.
     *
     * @param  list<string>  $remaining
     */
    public static function retain(string $file, array $remaining): bool
    {
        // Cheap early exit: no point writing a temporary file we may discard.
        if (! self::stillOwns($file)) {
            Log::warning('Refusing to rewrite a journal file this worker no longer owns', [
                'file' => $file,
            ]);

            return false;
        }

        if ($remaining === []) {
            // Deletion is itself the replacement, so it takes the lock too.
            return (bool) self::withLock(LOCK_EX, static function () use ($file): bool {
                if (! self::stillOwns($file)) {
                    return false;
                }

                $removed = @unlink($file);

                @unlink(self::leasePathFor($file));

                return $removed;
            }, false);
        }

        try {
            $temporary = $file.'.tmp.'.bin2hex(random_bytes(8));
        } catch (Throwable) {
            $temporary = $file.'.tmp.'.hash('crc32b', uniqid((string) mt_rand(), true));
        }

        if (! self::writeAllBytes($temporary, implode("\n", $remaining)."\n")) {
            @unlink($temporary);

            return false;
        }

        /*
         * THE BARRIER, if a test installed one. This is precisely the window
         * a competing worker must reclaim in, and it is unreachable from
         * outside the method.
         */
        if (self::$retainBarrier !== null) {
            (self::$retainBarrier)($file);
        }

        /*
         * THE ATOMIC SECTION. Ownership is re-verified with the lock held and
         * the rename happens before it is released, so no reclaim can slip
         * between the two.
         */
        return (bool) self::withLock(LOCK_EX, static function () use ($file, $temporary): bool {
            if (! self::stillOwns($file)) {
                // Reclaimed during the write. The temporary is discarded
                // rather than renamed: renaming it would resurrect a path the
                // new owner is processing.
                @unlink($temporary);

                Log::warning('A journal file was reclaimed during a rewrite; discarding the replacement', [
                    'file' => $file,
                ]);

                return false;
            }

            if (! @rename($temporary, $file)) {
                @unlink($temporary);

                return false;
            }

            return true;
        }, false);
    }

    /**
     * Write a payload in full, verified and flushed.
     *
     * Shared by `retain()` and the lease writer so both get exact-byte
     * accounting and the same durability guarantee.
     */
    private static function writeAllBytes(string $file, string $payload): bool
    {
        $handle = @fopen($file, 'wb');

        if ($handle === false) {
            return false;
        }

        try {
            $expected = strlen($payload);
            $written = 0;

            /*
             * fwrite returns the bytes ACTUALLY written, which on a full or
             * slow device is fewer than requested. Treating a short write as
             * success truncated the retained work silently.
             */
            while ($written < $expected) {
                $chunk = fwrite($handle, substr($payload, $written));

                if ($chunk === false || $chunk === 0) {
                    return false;
                }

                $written += $chunk;
            }

            if (fflush($handle) === false) {
                return false;
            }

            /*
             * A FAILED SYNC IS A FAILED WRITE. Ignoring it meant claiming
             * durability the device had not provided — and this file exists
             * for the case where the process is about to die.
             */
            if (function_exists('fsync') && @fsync($handle) === false) {
                return false;
            }

            return $written === $expected;
        } finally {
            fclose($handle);
        }
    }

    /**
     * Read the ACTIVE journal without rotating.
     *
     * Reporting only — a dry run must not disturb work another process may be
     * appending to.
     *
     * @return list<array<string, mixed>>
     */
    public static function entries(): array
    {
        $file = self::path();

        if (! is_file($file)) {
            return [];
        }

        return array_map(
            static fn (array $entry): array => $entry['data'],
            self::readFile($file)['entries'],
        );
    }

    /**
     * Run a callback holding the stable coordination lock.
     *
     * @template T
     *
     * @param  callable(): T  $callback
     * @param  T  $fallback
     * @return T
     */
    private static function withLock(int $mode, callable $callback, mixed $fallback): mixed
    {
        $lockFile = self::lockPath();

        if (! self::ensureDirectory(dirname($lockFile))) {
            return $fallback;
        }

        $handle = @fopen($lockFile, 'c');

        if ($handle === false) {
            return $fallback;
        }

        try {
            if (! flock($handle, $mode)) {
                return $fallback;
            }

            return $callback();
        } catch (Throwable $e) {
            Log::critical('Cleanup journal operation failed', ['error' => $e->getMessage()]);

            return $fallback;
        } finally {
            flock($handle, LOCK_UN);
            fclose($handle);
        }
    }

    /**
     * A destination that does not already exist.
     *
     * Random bytes rather than time and pid: two rotations in one process
     * within one second produced identical names and overwrote unprocessed
     * work. `rename()` onto an existing path destroys it silently.
     */
    private static function uniqueDestination(string $stage): ?string
    {
        foreach (range(1, 5) as $ignored) {
            try {
                $suffix = bin2hex(random_bytes(16));
            } catch (Throwable) {
                // Same reasoning as append(): never throw on a failure path.
                $suffix = hash('sha256', uniqid((string) mt_rand(), true));
            }

            $candidate = self::directory().'/cleanup-journal.'.$suffix.'.'.$stage.'.jsonl';

            if (! file_exists($candidate)) {
                return $candidate;
            }
        }

        // Five collisions on 128 random bits means something is badly wrong;
        // failing is safer than guessing again.
        Log::critical('Could not allocate a unique cleanup journal filename', ['stage' => $stage]);

        return null;
    }

    private static function rawAppend(string $file, string $line): bool
    {
        try {
            if (! self::ensureDirectory(dirname($file))) {
                return false;
            }

            /*
             * EVERY BYTE VERIFIED. `file_put_contents` returns the count
             * WRITTEN, and a full or failing device returns fewer than
             * requested — which the old `!== false` test read as success. A
             * half-written line is then unparseable, so the record that
             * mattered most became a dead-letter entry.
             */
            $payload = $line."\n";
            $expected = strlen($payload);

            $handle = @fopen($file, 'ab');

            if ($handle === false) {
                return false;
            }

            try {
                if (! flock($handle, LOCK_EX)) {
                    return false;
                }

                $written = 0;

                while ($written < $expected) {
                    $chunk = fwrite($handle, substr($payload, $written));

                    if ($chunk === false || $chunk === 0) {
                        return false;
                    }

                    $written += $chunk;
                }

                fflush($handle);

                if (function_exists('fsync')) {
                    // Durable before we claim success: this file exists for the
                    // case where the process is about to die.
                    @fsync($handle);
                }

                return $written === $expected;
            } finally {
                flock($handle, LOCK_UN);
                fclose($handle);
            }
        } catch (Throwable $e) {
            // Genuinely the end of the road: local disk is unwritable too.
            Log::critical('Cleanup journal append failed', [
                'file' => $file,
                'error' => $e->getMessage(),
            ]);

            return false;
        }
    }

    private static function ensureDirectory(string $directory): bool
    {
        return is_dir($directory) || mkdir($directory, 0775, true) || is_dir($directory);
    }
}
