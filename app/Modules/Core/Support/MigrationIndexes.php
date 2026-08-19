<?php

declare(strict_types=1);

namespace App\Modules\Core\Support;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use RuntimeException;

/**
 * Index introspection for reversible migrations.
 *
 * SEVEN migrations added a column with a chained `->index()` and then dropped
 * that column in `down()` without dropping the index first. Every one of them
 * was a guaranteed rollback failure on SQLite:
 *
 *   error in index offer_media_cleanup_pending_index after drop column:
 *   no such column: cleanup_pending
 *
 * Nothing caught it because `migrate:rollback` had never been executed. Each
 * migration could hard-code the conventional index name, but the convention is
 * not a guarantee — an index may have been created under an explicit name, or
 * cover several columns. Reading the real index list is both correct and
 * idempotent, so an interrupted rollback converges on a rerun.
 */
final class MigrationIndexes
{
    /**
     * Drop every index that would be invalidated by dropping these columns.
     *
     * ANY index that references at least one of `$columns` is dropped, not
     * only indexes made up entirely of them. A composite index such as
     * `notifications_digest_idx (user_id, digest_state)` cannot survive the
     * loss of `digest_state` — SQLite rejects the column drop outright — so
     * removing it is not a choice the migration gets to make. Indexes that
     * touch none of the dropped columns are left strictly alone.
     *
     * @param  list<string>  $columns  the columns about to be dropped
     */
    public static function dropIndexesOn(string $table, array $columns): void
    {
        if (! Schema::hasTable($table)) {
            return;
        }

        $dropping = array_values(array_filter(
            $columns,
            static fn (string $column): bool => Schema::hasColumn($table, $column),
        ));

        if ($dropping === []) {
            return;
        }

        foreach (self::indexes($table) as $name => $indexColumns) {
            if ($indexColumns === [] || array_intersect($indexColumns, $dropping) === []) {
                continue;   // touches nothing being dropped, or not inspectable
            }

            self::drop($table, $name);
        }
    }

    /**
     * Every droppable index on the table, as name => ordered column list.
     *
     * Primary keys and engine-generated indexes are excluded: they are not
     * separately droppable and are not what this helper is for.
     *
     * @return array<string, list<string>>
     */
    public static function indexes(string $table): array
    {
        $connection = DB::connection();
        $driver = $connection->getDriverName();

        if ($driver === 'sqlite') {
            $indexes = [];

            foreach (DB::select('PRAGMA index_list('.$table.')') as $index) {
                $name = (string) $index->name;

                if (str_starts_with($name, 'sqlite_autoindex')) {
                    continue;   // backs a table constraint; not droppable alone
                }

                $columns = [];

                foreach (DB::select('PRAGMA index_info('.$name.')') as $column) {
                    if ($column->name === null) {
                        continue;   // expression index; not column-addressable
                    }

                    $columns[(int) $column->seqno] = (string) $column->name;
                }

                ksort($columns);
                $indexes[$name] = array_values($columns);
            }

            return $indexes;
        }

        if ($driver === 'mysql' || $driver === 'mariadb') {
            $rows = DB::table('information_schema.STATISTICS')
                ->where('TABLE_SCHEMA', $connection->getDatabaseName())
                ->where('TABLE_NAME', $table)
                ->where('INDEX_NAME', '!=', 'PRIMARY')
                ->orderBy('INDEX_NAME')
                ->orderBy('SEQ_IN_INDEX')
                ->get(['INDEX_NAME', 'COLUMN_NAME']);

            $indexes = [];

            foreach ($rows as $row) {
                if ($row->COLUMN_NAME === null) {
                    continue;
                }

                $indexes[(string) $row->INDEX_NAME][] = (string) $row->COLUMN_NAME;
            }

            return $indexes;
        }

        if ($driver === 'pgsql') {
            $rows = DB::select(
                'select i.relname as index_name, a.attname as column_name, k.ordinality as position
                   from pg_class t
                   join pg_index ix on t.oid = ix.indrelid
                   join pg_class i on i.oid = ix.indexrelid
                   join lateral unnest(ix.indkey) with ordinality as k(attnum, ordinality) on true
                   join pg_attribute a on a.attrelid = t.oid and a.attnum = k.attnum
                  where t.relname = ? and not ix.indisprimary
                  order by i.relname, k.ordinality',
                [$table],
            );

            $indexes = [];

            foreach ($rows as $row) {
                $indexes[(string) $row->index_name][] = (string) $row->column_name;
            }

            return $indexes;
        }

        throw new RuntimeException(
            "Cannot enumerate indexes on driver [{$driver}]. Supported: sqlite, mysql, mariadb, pgsql."
        );
    }

    /**
     * Drop one index by name, in its OWN statement.
     *
     * Blueprint only queues statements, so batching an index drop and a column
     * drop into a single closure can still emit them in an order SQLite
     * rejects. Separate calls make the ordering explicit.
     */
    private static function drop(string $table, string $name): void
    {
        Schema::table($table, function ($blueprint) use ($name): void {
            // MIGRATION-GUARD: intentional-drop — this index covers only
            // columns the calling migration is reversing.
            $blueprint->dropIndex($name);
        });
    }
}
