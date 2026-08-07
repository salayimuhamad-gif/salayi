<?php

declare(strict_types=1);

use App\Modules\Projects\Support\SchemaContract;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * One cleanup job per source lifecycle (spec 26.1).
 *
 * `orphaned_files` was unique by disk and path, which directly contradicts the
 * exact source linkage added in 001600. A path can be reused by a later
 * upload, so a NEW source would overwrite an UNRESOLVED job belonging to an
 * older one — and the old source row went on pointing at an outbox id that now
 * described somebody else's file. Both records then lied.
 *
 * `job_key` replaces that identity:
 *
 *   - source-linked work  → "project_media:41"
 *   - unlinked work       → "path:public:projects/7/a.jpg"
 *
 * A generated column would be tidier, but is expressed differently on MySQL,
 * PostgreSQL and SQLite; a plain column written by the model works identically
 * on all three, which matters more here than elegance.
 *
 * Multiple historical jobs may now share a disk and path. That is the point:
 * a resolved job is immutable evidence of a file that once existed, and the
 * next file at that path is a different job.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('orphaned_files')) {
            return;
        }

        Schema::table('orphaned_files', function (Blueprint $table): void {
            if (! Schema::hasColumn('orphaned_files', 'job_key')) {
                $table->string('job_key', 255)->nullable();
            }
        });

        /*
         * THE WHOLE MAPPING IS COMPUTED BEFORE ANYTHING IS WRITTEN.
         *
         * Row-by-row updates hit TRANSIENT collisions: if row A must take the
         * key row B currently holds, writing A first violates the unique index
         * even though the final state is perfectly unique. The first row
         * updated, the second threw, and the rerun failed the same way —
         * leaving the data half-rewritten and the migration permanently stuck.
         *
         * `EVERY ROW IS RECOMPUTED`, not only null ones, because an operator
         * who corrects a colliding source_type/source_id must be able to
         * unblock the migration — the stale key computed from the old values
         * would otherwise survive and keep failing.
         */
        $mapping = [];

        foreach (
            DB::table('orphaned_files')
                ->select('id', 'disk', 'path', 'source_type', 'source_id', 'job_key')
                ->orderBy('id')
                ->cursor() as $row
        ) {
            $key = $row->source_type !== null && $row->source_id !== null
                ? 'src:'.$row->source_type.':'.$row->source_id
                : 'path:'.hash('sha256', $row->disk."\0".$row->path);

            if ((string) ($row->job_key ?? '') !== $key) {
                $mapping[(int) $row->id] = $key;
            }
        }

        if ($mapping !== []) {
            /*
             * TWO PASSES THROUGH TEMPORARY KEYS.
             *
             * Every row being changed first takes a key that cannot collide
             * with anything — its own id is unique by definition — and only
             * then takes its real value. That makes the order irrelevant, so
             * chains and swaps both work.
             *
             * Wrapped in a transaction where the driver supports transactional
             * DML, so an interruption rolls back rather than leaving the
             * half-rewritten state that got operators stuck.
             */
            DB::transaction(function () use ($mapping): void {
                foreach (array_keys($mapping) as $id) {
                    DB::table('orphaned_files')
                        ->where('id', $id)
                        ->update(['job_key' => 'tmp:'.$id.':'.bin2hex(random_bytes(8))]);
                }

                foreach ($mapping as $id => $key) {
                    DB::table('orphaned_files')->where('id', $id)->update(['job_key' => $key]);
                }
            });
        }

        /*
         * THE PARTIAL STATE LEFT BY AN EARLIER FAILED RUN.
         *
         * A previous version dropped the old unique index and then failed to
         * create the new one, leaving `orphaned_files` with NEITHER identity
         * constraint. This migration then threw on duplicates while claiming
         * "the old disk+path unique index is intact" — which was false, and
         * sent operators looking for a constraint that was not there.
         *
         * Both contracts are inspected first so the diagnosis is accurate, and
         * the old index is restored the moment the data allows it. An
         * unprotected table is the one state worth fixing before anything
         * else.
         */
        $oldIndex = SchemaContract::indexContractHolds(
            'orphaned_files', 'orphaned_files_disk_path_unique', ['disk', 'path'], true,
        );

        $newIndex = SchemaContract::indexContractHolds(
            'orphaned_files', 'orphaned_files_job_key_unique', ['job_key'], true,
        );

        if (! $oldIndex && ! $newIndex) {
            $pathDuplicates = DB::table('orphaned_files')
                ->select('disk', 'path')
                ->groupBy('disk', 'path')
                ->havingRaw('COUNT(*) > 1')
                ->get();

            if ($pathDuplicates->isEmpty()) {
                // Restorable: put the previous protection back at once.
                Schema::table('orphaned_files', function (Blueprint $table): void {
                    $table->unique(['disk', 'path'], 'orphaned_files_disk_path_unique');
                });

                $oldIndex = SchemaContract::indexContractHolds(
                    'orphaned_files', 'orphaned_files_disk_path_unique', ['disk', 'path'], true,
                );
            } else {
                /*
                 * Neither constraint can be created from the current data.
                 * Reported with the exact conflicting rows, and honestly: the
                 * table is unprotected right now.
                 */
                $conflicts = DB::table('orphaned_files')
                    ->select('id', 'disk', 'path')
                    ->whereIn('path', $pathDuplicates->pluck('path')->take(5)->all())
                    ->orderBy('path')
                    ->orderBy('id')
                    ->get()
                    ->map(static fn ($row): string => "id={$row->id} {$row->disk}:{$row->path}")
                    ->implode('; ');

                throw new RuntimeException(
                    'orphaned_files currently has NO identity index — an earlier run removed the '
                    .'disk+path unique before creating its replacement. It cannot be restored '
                    ."because these rows share a disk and path: {$conflicts}. Resolve them, then "
                    .'rerun; the job_key preflight will follow.'
                );
            }
        }

        /*
         * PREFLIGHT BEFORE ANY DESTRUCTIVE DDL.
         *
         * Two legacy rows with the same source_type and source_id backfill to
         * ONE job_key. The previous order dropped the old unique index first
         * and only then discovered the collision — and MySQL auto-commits DDL,
         * so the table was left with NEITHER identity constraint and no way
         * back.
         */
        $duplicateKeys = DB::table('orphaned_files')
            ->select('job_key')
            ->whereNotNull('job_key')
            ->groupBy('job_key')
            ->havingRaw('COUNT(*) > 1')
            ->pluck('job_key');

        if ($duplicateKeys->isNotEmpty()) {
            $detail = DB::table('orphaned_files')
                ->select('id', 'job_key', 'source_type', 'source_id', 'disk', 'path')
                ->whereIn('job_key', $duplicateKeys->take(5)->all())
                ->orderBy('job_key')
                ->orderBy('id')
                ->get()
                ->map(static fn ($row): string => sprintf(
                    'id=%d key=%s source=%s:%s path=%s',
                    $row->id,
                    (string) $row->job_key,
                    (string) ($row->source_type ?? 'null'),
                    (string) ($row->source_id ?? 'null'),
                    (string) $row->path,
                ))
                ->implode('; ');

            throw new RuntimeException(
                'Cannot create the job_key unique index: '.$duplicateKeys->count()
                .' computed key(s) collide. '.$detail
                .'. No schema change has been made. '
                .($oldIndex
                    ? 'The old disk+path unique index is intact. '
                    : 'NOTE: orphaned_files currently has no identity index. ')
                .'Two rows sharing a source_type/source_id are one cleanup lifecycle; resolve '
                .'them before rerunning.'
            );
        }

        $unkeyed = DB::table('orphaned_files')->whereNull('job_key')->count();

        if ($unkeyed > 0) {
            throw new RuntimeException(
                "Backfill incomplete: {$unkeyed} row(s) have no job_key. No schema change "
                .'has been made.'
            );
        }

        $overLong = DB::table('orphaned_files')->whereRaw('LENGTH(job_key) > 255')->count();

        if ($overLong > 0) {
            throw new RuntimeException(
                "Backfill produced {$overLong} job_key value(s) longer than the 255-character "
                .'column. No schema change has been made.'
            );
        }

        /*
         * THE NEW INDEX FIRST, WHILE THE OLD ONE STILL STANDS.
         *
         * The two constrain different columns, so they coexist happily. If
         * creation fails for any reason the table still enforces its previous
         * identity, which is the whole point of this ordering.
         */
        if (! SchemaContract::indexContractHolds('orphaned_files', 'orphaned_files_job_key_unique', ['job_key'], true)
            && Schema::hasColumn('orphaned_files', 'job_key')) {
            Schema::table('orphaned_files', function (Blueprint $table): void {
                $table->unique('job_key', 'orphaned_files_job_key_unique');
            });
        }

        if (! SchemaContract::indexContractHolds('orphaned_files', 'orphaned_files_job_key_unique', ['job_key'], true)) {
            throw new RuntimeException(
                'The job_key unique index on orphaned_files was not created. '
                .'The old disk+path unique index has been left in place.'
            );
        }

        /*
         * ONLY NOW is the old identity removed, with the replacement verified
         * present. Re-inspected afterwards so a partially applied run cannot
         * report success.
         */
        if (SchemaContract::indexContractHolds('orphaned_files', 'orphaned_files_disk_path_unique', ['disk', 'path'], true)) {
            Schema::table('orphaned_files', function (Blueprint $table): void {
                // MIGRATION-GUARD: intentional-drop — this index expresses the
                // identity being replaced; the replacement is verified above.
                $table->dropUnique('orphaned_files_disk_path_unique');
            });
        }

        if (SchemaContract::indexContractHolds('orphaned_files', 'orphaned_files_disk_path_unique', ['disk', 'path'], true)) {
            throw new RuntimeException(
                'The disk+path unique index on orphaned_files could not be removed. '
                .'Multiple cleanup jobs per path are required by the current lifecycle.'
            );
        }

        if (! SchemaContract::indexContractHolds('orphaned_files', 'orphaned_files_job_key_unique', ['job_key'], true)) {
            throw new RuntimeException(
                'The job_key unique index disappeared while removing the old one. '
                .'The table now enforces no identity contract.'
            );
        }
    }

    public function down(): void
    {
        if (! Schema::hasTable('orphaned_files')) {
            return;
        }

        /*
         * PREFLIGHT, THEN RESTORE, THEN DESTROY.
         *
         * Dropping `job_key` before recreating the disk/path unique meant a
         * failure in that final DDL — a MySQL lock timeout, a permissions
         * problem — left the table with neither identity constraint. The
         * preflight proves the old contract is satisfiable; adding it while
         * `job_key` still exists is safe, because the two constrain different
         * columns.
         */
        if (! SchemaContract::indexContractHolds('orphaned_files', 'orphaned_files_disk_path_unique', ['disk', 'path'], true)) {
            $duplicates = DB::table('orphaned_files')
                ->select('disk', 'path')
                ->groupBy('disk', 'path')
                ->havingRaw('COUNT(*) > 1')
                ->get()
                ->count();

            if ($duplicates > 0) {
                throw new RuntimeException(
                    "Refusing to reverse: {$duplicates} disk/path pair(s) carry several cleanup "
                    .'jobs, which the previous unique index forbids. Resolve or archive them '
                    .'first. No schema change has been made.'
                );
            }

            Schema::table('orphaned_files', function (Blueprint $table): void {
                $table->unique(['disk', 'path'], 'orphaned_files_disk_path_unique');
            });
        }

        if (! SchemaContract::indexContractHolds('orphaned_files', 'orphaned_files_disk_path_unique', ['disk', 'path'], true)) {
            throw new RuntimeException(
                'The previous disk/path unique index could not be restored. '
                .'No destructive change has been made.'
            );
        }

        // The old contract is in force. Now the new identity may go.
        if (SchemaContract::indexContractHolds('orphaned_files', 'orphaned_files_job_key_unique', ['job_key'], true)) {
            Schema::table('orphaned_files', function (Blueprint $table): void {
                // MIGRATION-GUARD: intentional-drop — reversing this
                // migration's own index.
                $table->dropUnique('orphaned_files_job_key_unique');
            });
        }

        if (SchemaContract::indexContractHolds('orphaned_files', 'orphaned_files_job_key_unique', ['job_key'], true)) {
            throw new RuntimeException('The job_key unique index could not be removed.');
        }

        if (Schema::hasColumn('orphaned_files', 'job_key')) {
            Schema::table('orphaned_files', function (Blueprint $table): void {
                // MIGRATION-GUARD: intentional-drop — reversing this
                // migration's own column.
                $table->dropColumn('job_key');
            });
        }

        if (Schema::hasColumn('orphaned_files', 'job_key')) {
            throw new RuntimeException('The job_key column could not be removed.');
        }
    }
};
