<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * `orphaned_files.job_key` becomes NOT NULL (spec 26.1 cleanup identity).
 *
 * 001700 introduced the identity, backfilled it and made it unique — but left
 * the column nullable. A unique index does NOT close the hole: on every engine
 * this project supports, NULLs are exempt from uniqueness, so any number of
 * rows carrying a NULL identity can coexist. The application always writes a
 * key, but an import, a direct SQL fix during an incident, or a future code
 * path can create rows outside the contract, and the cleanup sweep would then
 * be unable to tell those jobs apart.
 *
 * A FORWARD MIGRATION, not an edit to 001700: that migration has shipped, and
 * a database which already ran it must be repaired rather than have its history
 * rewritten.
 *
 * REMEDIATION IS DETERMINISTIC. Nothing here invents a random identity — a
 * random key would be unique, silently wrong, and impossible to correlate with
 * the file it describes. Legacy rows are keyed exactly the way the model keys
 * them, and a collision is resolved by keeping the earliest row on the natural
 * key and suffixing later ones with their own primary key, which is stable
 * across reruns.
 */
return new class extends Migration
{
    private const TABLE = 'orphaned_files';

    private const UNIQUE_INDEX = 'orphaned_files_job_key_unique';

    public function up(): void
    {
        if (! Schema::hasTable(self::TABLE) || ! Schema::hasColumn(self::TABLE, 'job_key')) {
            return;   // 001700 has not run; nothing to tighten
        }

        // ---- 1. Audit and remediate before touching the column definition.
        $this->remediateMissingKeys();
        $this->remediateDuplicateKeys();

        // ---- 2. Refuse to proceed if anything is still outside the contract.
        $offenders = DB::table(self::TABLE)
            ->whereNull('job_key')
            ->orWhere('job_key', '')
            ->count();

        if ($offenders > 0) {
            throw new RuntimeException(
                "Refusing to enforce NOT NULL: {$offenders} row(s) still carry an empty cleanup "
                .'identity after remediation. The schema has not been modified.'
            );
        }

        // ---- 3. Tighten the column. Resumable: already-NOT NULL is a no-op.
        if ($this->columnIsNullable()) {
            Schema::table(self::TABLE, function (Blueprint $table): void {
                $table->string('job_key', 255)->nullable(false)->change();
            });
        }

        /*
         * ---- 4. Uniqueness, under WHICHEVER identity contract is in force.
         *
         * v6 merge resolution. This migration was written when `job_key`
         * was the unique identity. The strict cleanup chain (001900) then
         * moved uniqueness to `active_key` ON PURPOSE: a resolved incident
         * releases its `active_key` and KEEPS its `job_key`, so the
         * evidence of a past incident can coexist with a new incident at
         * the same path. Re-adding a `job_key` unique index on top of that
         * design makes the second incident impossible — the exact
         * regression this project fixed.
         *
         * So: enforce NOT NULL always (that is this migration's real
         * contribution and the strict chain never provided it), and
         * enforce uniqueness only where the strict contract is ABSENT.
         * Where it is present, `active_key` already guarantees one live
         * identity, which is the invariant that matters.
         */
        if (! $this->strictActiveKeyContractHolds() && ! $this->uniqueIndexExists()) {
            Schema::table(self::TABLE, function (Blueprint $table): void {
                $table->unique('job_key', self::UNIQUE_INDEX);
            });
        }

        // ---- 5. Prove the final contract rather than assume it.
        if ($this->columnIsNullable()) {
            throw new RuntimeException('job_key is still nullable after migration.');
        }

        if ($this->strictActiveKeyContractHolds()) {
            // The live-identity guarantee must exist in SOME form; if the
            // strict index vanished, fail closed rather than leave the
            // table with no uniqueness at all.
            if (! $this->activeKeyUniqueExists()) {
                throw new RuntimeException(
                    'Neither the job_key nor the active_key unique contract is in force. '
                    .'Refusing to leave cleanup identity unguarded.'
                );
            }
        } elseif (! $this->uniqueIndexExists()) {
            throw new RuntimeException('The job_key unique index did not survive the column change.');
        }
    }

    /**
     * Is the strict identity contract (001900) in force?
     *
     * `active_key` present AND uniquely indexed means live-identity
     * uniqueness has moved there, and `job_key` is deliberately repeatable
     * so resolved history survives.
     */
    private function strictActiveKeyContractHolds(): bool
    {
        return Schema::hasColumn(self::TABLE, 'active_key') && $this->activeKeyUniqueExists();
    }

    private function activeKeyUniqueExists(): bool
    {
        foreach ($this->uniqueIndexColumns() as $columns) {
            if ($columns === ['active_key']) {
                return true;
            }
        }

        return false;
    }

    /** @return list<list<string>> */
    private function uniqueIndexColumns(): array
    {
        $connection = Schema::getConnection();
        $found = [];

        if ($connection->getDriverName() === 'sqlite') {
            foreach ($connection->select('PRAGMA index_list('.self::TABLE.')') as $index) {
                if ((int) $index->unique !== 1) {
                    continue;
                }

                $columns = array_map(
                    static fn (object $c): string => (string) $c->name,
                    $connection->select('PRAGMA index_info('.$index->name.')'),
                );
                $found[] = $columns;
            }

            return $found;
        }

        $rows = $connection->select(
            'SELECT INDEX_NAME, COLUMN_NAME FROM information_schema.STATISTICS
             WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND NON_UNIQUE = 0
             ORDER BY INDEX_NAME, SEQ_IN_INDEX',
            [self::TABLE],
        );

        $grouped = [];
        foreach ($rows as $row) {
            $grouped[$row->INDEX_NAME][] = (string) $row->COLUMN_NAME;
        }

        return array_values($grouped);
    }

    /**
     * Key every row the way `OrphanedFile::jobKey()` does.
     *
     * Source-linked work keeps its `src:` identity so the sweep can still find
     * the row it belongs to; path-only work gets the same bounded hash the
     * model produces, so a legacy row and a freshly recorded one for the same
     * file collapse onto one job instead of racing each other.
     */
    private function remediateMissingKeys(): void
    {
        $rows = DB::table(self::TABLE)
            ->whereNull('job_key')
            ->orWhere('job_key', '')
            ->select('id', 'disk', 'path', 'source_type', 'source_id')
            ->get();

        foreach ($rows as $row) {
            $key = $row->source_type !== null && $row->source_id !== null
                ? 'src:'.$row->source_type.':'.$row->source_id
                : 'path:'.hash('sha256', (string) $row->disk."\0".(string) $row->path);

            /*
             * COLLISIONS ARE RESOLVED HERE, NOT IN A LATER PASS.
             *
             * The unique index from 001700 is already in force, so two legacy
             * rows deriving the same natural key make the SECOND update fail
             * immediately — a deferred duplicate sweep never gets to run. The
             * earliest row therefore keeps the natural key and any later row
             * carries its own primary key as a suffix: unique, deterministic,
             * and identical on a rerun.
             */
            $taken = DB::table(self::TABLE)
                ->where('job_key', $key)
                ->where('id', '!=', $row->id)
                ->exists();

            if ($taken) {
                $key = mb_substr($key.':dup:'.$row->id, 0, 255);
            }

            DB::table(self::TABLE)->where('id', $row->id)->update(['job_key' => $key]);
        }
    }

    /**
     * Collapse duplicates the backfill may have produced.
     *
     * Two legacy rows describing the same source genuinely are the same job, so
     * the EARLIEST row keeps the natural key — it holds the original evidence —
     * and later rows are suffixed with their own id. That is stable on a rerun
     * and never loses a row, which matters because a resolved job is the only
     * record that a file once existed.
     */
    private function remediateDuplicateKeys(): void
    {
        /*
         * v6 merge resolution: under the strict identity contract a
         * repeated `job_key` is LEGITIMATE — a resolved incident keeps its
         * key as history while a new incident at the same path takes a
         * fresh row. Renaming those to `:dup:` would corrupt exactly the
         * evidence 001900 exists to preserve, and would break the sweep's
         * ability to correlate an incident with its source.
         *
         * Duplicates are therefore only a defect among LIVE rows, which is
         * what the `active_key` unique index already guarantees. Where the
         * strict contract is absent, the original remediation applies
         * unchanged.
         */
        if ($this->strictActiveKeyContractHolds()) {
            return;
        }

        $duplicates = DB::table(self::TABLE)
            ->select('job_key')
            ->whereNotNull('job_key')
            ->groupBy('job_key')
            ->havingRaw('COUNT(*) > 1')
            ->pluck('job_key');

        foreach ($duplicates as $key) {
            $ids = DB::table(self::TABLE)
                ->where('job_key', $key)
                ->orderBy('id')
                ->pluck('id');

            foreach ($ids->slice(1) as $id) {
                DB::table(self::TABLE)
                    ->where('id', $id)
                    ->update(['job_key' => mb_substr($key.':dup:'.$id, 0, 255)]);
            }
        }
    }

    private function columnIsNullable(): bool
    {
        $connection = DB::connection();
        $driver = $connection->getDriverName();

        return match ($driver) {
            'sqlite' => (bool) collect(DB::select('PRAGMA table_info("'.self::TABLE.'")'))
                ->firstWhere('name', 'job_key')?->notnull === false,
            'mysql', 'mariadb' => DB::table('information_schema.COLUMNS')
                ->where('TABLE_SCHEMA', $connection->getDatabaseName())
                ->where('TABLE_NAME', self::TABLE)
                ->where('COLUMN_NAME', 'job_key')
                ->value('IS_NULLABLE') === 'YES',
            'pgsql' => DB::table('information_schema.columns')
                ->where('table_name', self::TABLE)
                ->where('column_name', 'job_key')
                ->value('is_nullable') === 'YES',
            default => throw new RuntimeException(
                "Cannot inspect column nullability on driver [{$driver}]."
            ),
        };
    }

    private function uniqueIndexExists(): bool
    {
        $connection = DB::connection();
        $driver = $connection->getDriverName();

        return match ($driver) {
            'sqlite' => collect(DB::select('PRAGMA index_list("'.self::TABLE.'")'))
                ->contains(static fn ($i): bool => (int) $i->unique === 1
                    && collect(DB::select('PRAGMA index_info("'.$i->name.'")'))
                        ->pluck('name')->all() === ['job_key']),
            'mysql', 'mariadb' => DB::table('information_schema.STATISTICS')
                ->where('TABLE_SCHEMA', $connection->getDatabaseName())
                ->where('TABLE_NAME', self::TABLE)
                ->where('COLUMN_NAME', 'job_key')
                ->where('NON_UNIQUE', 0)
                ->exists(),
            'pgsql' => DB::table('pg_indexes')
                ->where('tablename', self::TABLE)
                ->where('indexdef', 'like', '%UNIQUE%job_key%')
                ->exists(),
            default => throw new RuntimeException(
                "Cannot inspect indexes on driver [{$driver}]."
            ),
        };
    }

    /**
     * Relax the column again.
     *
     * Safe and genuinely reversible: widening a NOT NULL column to nullable
     * cannot fail on existing data, and 001700's own `down()` remains
     * responsible for removing the column entirely.
     */
    public function down(): void
    {
        if (! Schema::hasTable(self::TABLE) || ! Schema::hasColumn(self::TABLE, 'job_key')) {
            return;
        }

        if (! $this->columnIsNullable()) {
            Schema::table(self::TABLE, function (Blueprint $table): void {
                $table->string('job_key', 255)->nullable()->change();
            });
        }

        if (! $this->uniqueIndexExists()) {
            Schema::table(self::TABLE, function (Blueprint $table): void {
                $table->unique('job_key', self::UNIQUE_INDEX);
            });
        }
    }
};
