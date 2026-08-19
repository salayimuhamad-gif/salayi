<?php

declare(strict_types=1);

namespace App\Modules\Projects\Support;

use Illuminate\Support\Facades\DB;
use RuntimeException;
use Throwable;

/**
 * Verify that an index really is what a migration believes it to be.
 *
 * WHY THIS EXISTS. Three migrations each carried their own `indexExists()`
 * that asked only "is there an index with this name?". On SQLite that question
 * is GLOBAL — `sqlite_master` lists every index in the database — so an index
 * of the same name on an unrelated table answered yes.
 *
 * The consequence was not cosmetic. Both identity migrations concluded that
 * their replacement index already existed, skipped creating the real one, and
 * then dropped the old constraint. `orphaned_files` was left with NO identity
 * index at all and duplicate keys were accepted.
 *
 * A name is not a contract. The contract is: this table, these columns, in
 * this order, with this uniqueness. All four are checked here, once, so the
 * migrations cannot drift apart again.
 */
final class SchemaContract
{
    /**
     * Whether an index on `$table` named `$name` covers exactly
     * `$orderedColumns` with the given uniqueness.
     *
     * FAILS CLOSED: a driver that cannot be inspected throws rather than
     * guessing, because both possible guesses are harmful — "present" makes a
     * migration skip creating protection, "absent" makes it try to create a
     * duplicate.
     *
     * @param  list<string>  $orderedColumns
     *
     * @throws RuntimeException when the index state cannot be determined
     *
     * IMPURE by nature: it reads the LIVE schema, and the migrations that
     * call it change that schema between calls. Without this marker,
     * static analysis folds a re-verification into an earlier identical
     * call and reports a fail-closed check as unreachable — the check is
     * correct; the assumption of purity is not.
     *
     * @phpstan-impure
     */
    public static function indexContractHolds(
        string $table,
        string $name,
        array $orderedColumns,
        bool $unique,
    ): bool {
        $connection = DB::connection();
        $driver = $connection->getDriverName();

        try {
            return match ($driver) {
                'sqlite' => self::sqliteContract($table, $name, $orderedColumns, $unique),
                'mysql', 'mariadb' => self::mysqlContract(
                    $connection->getDatabaseName(),
                    $table,
                    $name,
                    $orderedColumns,
                    $unique,
                ),
                'pgsql' => self::postgresContract($table, $name, $orderedColumns, $unique),
                default => throw new RuntimeException(
                    "Cannot verify index contracts on driver [{$driver}]."
                ),
            };
        } catch (RuntimeException $e) {
            throw $e;
        } catch (Throwable $e) {
            throw new RuntimeException(
                "Could not verify index [{$name}] on [{$table}]: ".$e->getMessage(),
                previous: $e,
            );
        }
    }

    /**
     * @param  list<string>  $orderedColumns
     */
    private static function sqliteContract(
        string $table,
        string $name,
        array $orderedColumns,
        bool $unique,
    ): bool {
        /*
         * PRAGMA index_list is scoped TO THE TABLE, unlike sqlite_master. An
         * index of this name owned by something else simply does not appear
         * here, which is the whole point.
         */
        $index = collect(DB::select("PRAGMA index_list('{$table}')"))
            ->first(static fn ($row): bool => $row->name === $name);

        if ($index === null) {
            return false;
        }

        if ((bool) $index->unique !== $unique) {
            return false;
        }

        /*
         * A PARTIAL INDEX IS NOT A FULL CONTRACT.
         *
         * `CREATE UNIQUE INDEX ... WHERE job_key <> 'bypass'` enforces
         * uniqueness for every value EXCEPT the excluded ones. The helper
         * reported it as satisfying the identity contract, so a migration
         * could drop the old protection and then accept two rows carrying the
         * excluded value.
         *
         * `partial` is absent on older SQLite builds, where partial indexes do
         * not exist at all — treated as 0 there, which is correct rather than
         * lenient.
         */
        if ((int) ($index->partial ?? 0) !== 0) {
            return false;
        }

        $columns = collect(DB::select("PRAGMA index_info('{$name}')"))
            ->sortBy('seqno')
            ->pluck('name')
            ->values()
            ->all();

        return $columns === $orderedColumns;
    }

    /**
     * @param  list<string>  $orderedColumns
     */
    private static function mysqlContract(
        string $schema,
        string $table,
        string $name,
        array $orderedColumns,
        bool $unique,
    ): bool {
        /*
         * MySQL has no partial indexes, so there is nothing equivalent to
         * exclude here — the column set and uniqueness are the whole contract.
         */
        $rows = DB::table('information_schema.STATISTICS')
            ->where('TABLE_SCHEMA', $schema)
            ->where('TABLE_NAME', $table)
            ->where('INDEX_NAME', $name)
            ->orderBy('SEQ_IN_INDEX')
            ->get(['COLUMN_NAME', 'NON_UNIQUE']);

        if ($rows->isEmpty()) {
            return false;
        }

        // NON_UNIQUE is 0 for a unique index.
        if (((int) $rows->first()->NON_UNIQUE === 0) !== $unique) {
            return false;
        }

        return $rows->pluck('COLUMN_NAME')->values()->all() === $orderedColumns;
    }

    /**
     * @param  list<string>  $orderedColumns
     */
    private static function postgresContract(
        string $table,
        string $name,
        array $orderedColumns,
        bool $unique,
    ): bool {
        $definition = DB::table('pg_indexes')
            ->where('tablename', $table)
            ->where('indexname', $name)
            ->value('indexdef');

        if ($definition === null) {
            return false;
        }

        $definition = (string) $definition;

        if (str_contains($definition, 'UNIQUE INDEX') !== $unique) {
            return false;
        }

        // PostgreSQL partial indexes carry a WHERE clause, and enforce the
        // constraint only for the rows it selects.
        if (str_contains($definition, ' WHERE ')) {
            return false;
        }

        /*
         * The column list is between the outermost parentheses. Parsing it
         * beats a substring search, which would accept an index over
         * (other_column, job_key) as an index over (job_key).
         */
        if (preg_match('/\((.*)\)$/', $definition, $matches) !== 1) {
            return false;
        }

        $columns = array_map(
            static fn (string $column): string => trim($column, " \t\"'"),
            explode(',', $matches[1]),
        );

        return $columns === $orderedColumns;
    }
}
