<?php

declare(strict_types=1);

namespace App\Modules\Projects\Models;

use App\Modules\Core\Support\SafeText;
use App\Modules\Projects\Support\CleanupJournal;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use RuntimeException;
use Throwable;

/**
 * A file on disk that no database row references (spec 26.1).
 *
 * Every path that writes bytes before creating its row can fail in between.
 * Compensation deletes the file; when compensation itself fails there was
 * previously nothing but a log line — which is not a work queue, because
 * nobody is watching it and nothing retries it.
 *
 * A row here IS the reference. The sweep drains it.
 *
 * v6 merge: the strict cleanup branch shipped this model WITHOUT property
 * annotations, so static analysis lost sight of every column and reported
 * 63 "undefined property" errors against correct code. The annotations
 * come from the production model, extended with the two columns the
 * strict identity contract adds.
 *
 * @property int $id
 * @property string $disk
 * @property string $path
 * @property string $reason
 * @property int|null $project_draft_id
 * @property int|null $project_id
 * @property int|null $user_id
 * @property int $attempts
 * @property string|null $last_error
 * @property Carbon|null $last_attempted_at
 * @property Carbon|null $resolved_at
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property string|null $source_type
 * @property int|null $source_id
 * @property Carbon|null $file_resolved_at
 * @property Carbon|null $source_finalised_at
 * @property Carbon|null $handed_off_at
 * @property string $job_key
 * @property string|null $active_key
 * @property string $incident_uuid
 *
 * ---- generated model properties (scripts/generate-model-annotations.php)
 * @property int $id
 * @property string $disk
 * @property string $path
 * @property string $reason
 * @property int|null $project_draft_id
 * @property int|null $project_id
 * @property int|null $user_id
 * @property int $attempts
 * @property string|null $last_error
 * @property Carbon|null $last_attempted_at
 * @property Carbon|null $resolved_at
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property string|null $source_type
 * @property int|null $source_id
 * @property Carbon|null $file_resolved_at
 * @property Carbon|null $source_finalised_at
 * @property Carbon|null $handed_off_at
 * @property string $job_key
 * @property string|null $journal_entry_id
 * @property string|null $active_key
 * @property string $incident_uuid
 *
 * ---- end generated model properties
 */
final class OrphanedFile extends Model
{
    protected $table = 'orphaned_files';

    protected $fillable = [
        'disk', 'path', 'reason',
        'project_draft_id', 'project_id', 'user_id',
        'attempts', 'last_error', 'last_attempted_at', 'resolved_at',
        // Where the file came from, so the sweep can finish the job: delete
        // the cleanup-pending row it belonged to once the bytes are gone.
        'source_type', 'source_id',
        'file_resolved_at', 'source_finalised_at', 'handed_off_at',
        'job_key', 'active_key', 'incident_uuid',
    ];

    protected function casts(): array
    {
        return [
            'attempts' => 'integer',
            'last_attempted_at' => 'datetime',
            'resolved_at' => 'datetime',
            'file_resolved_at' => 'datetime',
            'source_finalised_at' => 'datetime',
            'handed_off_at' => 'datetime',
        ];
    }

    /**
     * Record a file that needs removing, or note another failed attempt.
     *
     * `updateOrCreate` on (disk, path) keeps the backlog one row per file: a
     * repeated failure should raise the attempt count, not lengthen the queue.
     *
     * @param  array<string, mixed>  $context
     */
    /**
     * Create or update the cleanup job for one source lifecycle.
     *
     * IDENTITY IS THE JOB, NOT THE PATH. Keying on disk and path let a new
     * source overwrite an UNRESOLVED job belonging to an older one, after
     * which the old source row pointed at an outbox row describing somebody
     * else's file — both records lying at once.
     *
     * `job_key` is `source_type:source_id` for linked work, or
     * `path:disk:path` for work with no surviving row. Two sources in
     * different domains may share a numeric id and remain distinct.
     *
     * @param  array<string, mixed>  $context
     */
    /**
     * Context keys that may reach the database.
     *
     * EVERYTHING ELSE IS DROPPED. Journal lines carry diagnostic context
     * written by whatever failed — `recordSafely()` adds `outbox_error` — and
     * merging that straight into the upsert payload produced
     * "no column named outbox_error" on replay. The line was then retained and
     * could never recover its cleanup job: the fallback that exists for the
     * database being unavailable was permanently unreplayable.
     */
    private const PERSISTED_CONTEXT_KEYS = [
        'source_type', 'source_id',
        'project_id', 'project_draft_id', 'user_id',
        'last_error', 'journal_entry_id',
    ];

    /**
     * Keep only keys that are real columns, folding the rest into last_error.
     *
     * The diagnostic is not discarded — losing why the first write failed
     * would make the journal harder to act on, not safer — it is moved to the
     * field that exists for exactly that.
     *
     * @param  array<string, mixed>  $context
     * @return array<string, mixed>
     */
    private static function persistableContext(array $context): array
    {
        $persisted = array_intersect_key($context, array_flip(self::PERSISTED_CONTEXT_KEYS));

        $rejected = array_diff_key($context, array_flip(self::PERSISTED_CONTEXT_KEYS));

        if ($rejected !== []) {
            $notes = [];

            foreach ($rejected as $key => $value) {
                if (is_scalar($value) || $value === null) {
                    $notes[] = $key.'='.(string) $value;
                }
            }

            if ($notes !== []) {
                $combined = trim(($persisted['last_error'] ?? '').' '.implode('; ', $notes));

                $persisted['last_error'] = SafeText::truncate($combined, 255);
            }
        }

        return $persisted;
    }

    /**
     * THE authoritative incident identity.
     *
     * `001900` makes `incident_uuid` NOT NULL and unique because an
     * incident that cannot be named cannot be referenced by the Journal,
     * the import ledger or an Outbox job. Every creation route — this
     * model, the Journal replay, the sweeper, the importer and the
     * `001900` backfill — mints identity HERE so there is exactly one
     * definition of what an incident is called.
     *
     * The value is random per incident and IMMUTABLE thereafter: it is
     * assigned once, on creation, and never recomputed. It is deliberately
     * NOT derived from `job_key` or `disk|path`, because a resolved
     * incident releases its key and a later incident at the same path is a
     * DIFFERENT incident — deriving identity from the path would collide
     * the two and merge their evidence.
     */
    public static function mintIncidentUuid(): string
    {
        return (string) Str::uuid();
    }

    /**
     * Identity is applied at the model boundary, not at each call site.
     *
     * Before this, only `record()` set `incident_uuid`; any other create
     * path — importer, replay, sweeper, a factory, a fixture — produced a
     * row the strict schema rejects. A `creating` hook makes the guarantee
     * structural: nothing can create an OrphanedFile without an identity,
     * and an identity already supplied (a replayed incident carrying its
     * original uuid) is never overwritten.
     */
    protected static function booted(): void
    {
        self::creating(static function (self $model): void {
            if (blank($model->incident_uuid)) {
                $model->incident_uuid = self::mintIncidentUuid();
            }
        });

        self::updating(static function (self $model): void {
            // Immutability is the whole point of the identity: anything
            // recorded elsewhere referring to it must keep matching.
            if ($model->isDirty('incident_uuid') && filled($model->getOriginal('incident_uuid'))) {
                throw new RuntimeException(
                    'incident_uuid is immutable; incident '
                    .$model->getOriginal('incident_uuid').' cannot be renamed.'
                );
            }
        });
    }

    /** @param  array<string, mixed>  $context */
    public static function record(string $disk, string $path, string $reason, array $context = []): self
    {
        $activeKey = self::jobKey($disk, $path, $context);

        // Identity is computed from the RAW context above; only whitelisted
        // keys go on to touch SQL.
        $context = self::persistableContext($context);

        /*
         * ONE ATOMIC STATEMENT for both cases.
         *
         * The previous shape read the row, incremented in PHP and saved. The
         * lock made that safe only while an outer transaction was open — and
         * the journal replay calls this without one, so the read-modify-write
         * raced itself and increments were lost.
         *
         * `where(active_key)->increment()` is evaluated by the database, so it
         * is correct with or without a surrounding transaction.
         */
        /*
         * WHICH FIELDS MEAN WHAT.
         *
         * HISTORICAL — never rewritten once set:
         *   incident_uuid, created_at, source_type, source_id
         *   (identity and origin: what this incident IS)
         *
         * LATEST ATTEMPT — deliberately updated on every call:
         *   attempts, reason, last_error, disk, path, updated_at
         *   and the operational references below
         *   (diagnostics: what is happening to it NOW)
         *
         * A resolved row is frozen entirely — it holds no active_key, so this
         * update cannot reach it.
         */
        $latest = [
            'attempts' => DB::raw(self::attemptsExpression()),
            'reason' => $reason,
            'disk' => $disk,
            'path' => $path,
            'updated_at' => now(),
        ];

        // Diagnostics from the newest failure, when the caller supplied them.
        foreach (['last_error', 'project_id', 'project_draft_id', 'user_id'] as $operational) {
            if (array_key_exists($operational, $context)) {
                $latest[$operational] = $context[$operational];
            }
        }

        $updated = self::query()
            ->where('active_key', $activeKey)
            ->update($latest);

        if ($updated > 0) {
            return self::query()->where('active_key', $activeKey)->firstOrFail();
        }

        /*
         * No OUTSTANDING job holds this key, so this is a NEW INCIDENT.
         *
         * A resolved row keeps its history and releases `active_key` to null,
         * which is why a later incident at the same path creates a new row
         * rather than overwriting evidence of the earlier one.
         *
         * The insert can still lose a race; `upsert` on the unique active_key
         * makes that harmless without raising a violation that would poison a
         * surrounding transaction.
         */
        $now = now();

        self::query()->upsert(
            [array_merge($context, [
                'incident_uuid' => self::mintIncidentUuid(),
                'active_key' => $activeKey,
                'job_key' => $activeKey,
                'disk' => $disk,
                'path' => $path,
                'reason' => $reason,
                'attempts' => 1,
                'resolved_at' => null,
                'created_at' => $now,
                'updated_at' => $now,
            ])],
            ['active_key'],
            // The loser of the race still counts: its attempt is real.
            [
                'reason' => $reason,
                'updated_at' => $now,
                'attempts' => DB::raw(self::attemptsExpression()),
            ],
        );

        return self::query()->where('active_key', $activeKey)->firstOrFail();
    }

    /**
     * Mark a job resolved and release its identity.
     *
     * The row becomes immutable evidence from this point: its reason, attempt
     * count and timestamps describe one real past incident, and the next
     * incident at the same path gets its own row.
     */
    public function markResolved(): void
    {
        $this->forceFill([
            'resolved_at' => now(),
            'last_error' => null,
            // Released, so `active_key` is free for the next incident.
            'active_key' => null,
        ])->save();
    }

    /**
     * How this engine refers to the existing row inside a conflict update.
     *
     * MySQL reads the column directly; SQLite and PostgreSQL need it
     * qualified, because an unqualified name resolves to the proposed row and
     * the count would stay at one forever.
     */
    private static function attemptsExpression(): string
    {
        return match (DB::connection()->getDriverName()) {
            'mysql', 'mariadb' => '`attempts` + 1',
            default => '"orphaned_files"."attempts" + 1',
        };
    }

    /**
     * The immutable identity of one cleanup job, always within 255 characters.
     *
     * `path:{disk}:{path}` overflowed: `path` allows 512 characters and
     * `job_key` allows 255, so a long but perfectly valid path could not be
     * persisted at all — and therefore could not be replayed from the
     * emergency journal either.
     *
     * Truncating the path would be worse than the overflow: two different
     * deep paths sharing a prefix would collapse into one job and one of the
     * files would never be cleaned. A hash cannot collide by prefix.
     *
     * @param  array<string, mixed>  $context
     */
    public static function jobKey(string $disk, string $path, array $context = []): string
    {
        if (isset($context['source_type'], $context['source_id'])) {
            // Readable on sight, and bounded: source types are short constants
            // and ids are integers.
            return 'src:'.$context['source_type'].':'.$context['source_id'];
        }

        return 'path:'.hash('sha256', $disk."\0".$path);
    }

    /**
     * Remove a file that has no database row, recording it if that fails.
     *
     * ONE helper, because the three call sites each got it slightly wrong: one
     * only caught exceptions, one only checked the boolean, one ignored the
     * result entirely. A missing file counts as resolved — an earlier
     * interrupted run may have taken it, and treating that as failure keeps
     * the backlog forever.
     *
     * @param  array<string, mixed>  $context
     * @return bool true when the bytes are confirmed gone
     */
    public static function removeOrRecord(string $disk, string $path, string $reason, array $context = []): bool
    {
        if ($path === '') {
            return true;
        }

        try {
            $storage = Storage::disk($disk);

            if (! $storage->exists($path)) {
                return true;   // already gone
            }

            // BOTH failure shapes. delete() returns false on a permissions
            // problem and throws on an unreachable disk.
            if ($storage->delete($path)) {
                return true;
            }

            self::recordSafely($disk, $path, $reason, $context + [
                'last_error' => 'delete() returned false',
            ]);
        } catch (Throwable $e) {
            /*
             * `Storage::disk()` throws for an unconfigured disk name, and that
             * is caught here — the call is inside the try deliberately. What
             * was NOT protected is the recording itself.
             */
            self::recordSafely($disk, $path, $reason, $context + [
                'last_error' => SafeText::truncate($e->getMessage(), 255),
            ]);
        }

        return false;
    }

    /**
     * Record without ever throwing.
     *
     * This runs on failure paths — including inside `afterRollback`, where an
     * exception is unrecoverable and would replace the original error with a
     * database one. If the outbox write itself fails there is nothing further
     * to try, so the last resort is a log line naming the exact file, which is
     * at least something a person can act on.
     *
     * @param  array<string, mixed>  $context
     */
    public static function recordSafely(string $disk, string $path, string $reason, array $context = []): void
    {
        try {
            self::record($disk, $path, $reason, $context);
        } catch (Throwable $e) {
            /*
             * The database refused. A log line here was NOT durable cleanup
             * work — nothing reads it, nothing retries it, and rotation
             * eventually destroys it, in exactly the situation this fallback
             * exists for.
             *
             * The journal is an append-only local file, replayed into this
             * table by `mulkihawler:replay-cleanup-journal` once the database
             * is available again.
             */
            $journalled = CleanupJournal::append(
                $disk,
                $path,
                $reason,
                /*
                 * `last_error` is a real column; `outbox_error` was not, and
                 * the replay died on it. The diagnostic is the same, in a
                 * field that exists.
                 */
                $context + ['last_error' => SafeText::truncate($e->getMessage(), 255)],
            );

            Log::error('Could not record an orphaned file', [
                'disk' => $disk,
                'path' => $path,
                'reason' => $reason,
                'error' => $e->getMessage(),
                'journalled' => $journalled,
            ]);
        }
    }

    /**
     * Whether a journal entry has already been imported.
     *
     * The replay uses this rather than re-recording: a transfer that succeeded
     * before a failed file rewrite must not be counted a second time.
     */
    public static function alreadyImported(string $entryId): bool
    {
        return CleanupJournalImport::query()->where('entry_id', $entryId)->exists();
    }

    /**
     * @param  Builder<OrphanedFile>  $query
     * @return Builder<OrphanedFile>
     */
    public function scopeOutstanding(Builder $query): Builder
    {
        return $query->whereNull('resolved_at');
    }
}
