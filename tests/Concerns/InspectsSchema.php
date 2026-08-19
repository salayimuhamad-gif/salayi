<?php

declare(strict_types=1);

namespace Tests\Concerns;

use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Engine-neutral schema introspection for migration tests.
 *
 * The migration tests originally asked SQLite directly, via PRAGMA. That was
 * fine while SQLite was the only engine anybody ran them on, but it made every
 * one of them unrunnable against MySQL — the engine this platform actually
 * deploys to — so the tests could not verify the very portability problems
 * MySQL later turned out to have. Reading through the same driver switch the
 * migrations use keeps the assertions honest on both.
 */
trait InspectsSchema
{
    protected function columnIsNullable(string $table, string $column): bool
    {
        $connection = DB::connection();

        return match ($connection->getDriverName()) {
            'sqlite' => (int) collect(DB::select('PRAGMA table_info("'.$table.'")'))
                ->firstWhere('name', $column)->notnull === 0,

            'mysql', 'mariadb' => DB::table('information_schema.COLUMNS')
                ->where('TABLE_SCHEMA', $connection->getDatabaseName())
                ->where('TABLE_NAME', $table)
                ->where('COLUMN_NAME', $column)
                ->value('IS_NULLABLE') === 'YES',

            'pgsql' => DB::table('information_schema.columns')
                ->where('table_name', $table)
                ->where('column_name', $column)
                ->value('is_nullable') === 'YES',

            default => throw new RuntimeException('Unsupported driver for schema inspection.'),
        };
    }

    /**
     * Every unique index on the table, as name => ordered column list.
     *
     * @return array<string, list<string>>
     */
    protected function uniqueIndexesFor(string $table): array
    {
        $connection = DB::connection();

        if ($connection->getDriverName() === 'sqlite') {
            $indexes = [];

            foreach (DB::select('PRAGMA index_list("'.$table.'")') as $index) {
                if ((int) $index->unique !== 1) {
                    continue;
                }

                $columns = [];

                foreach (DB::select('PRAGMA index_info("'.$index->name.'")') as $column) {
                    $columns[(int) $column->seqno] = (string) $column->name;
                }

                ksort($columns);
                $indexes[(string) $index->name] = array_values($columns);
            }

            return $indexes;
        }

        if (in_array($connection->getDriverName(), ['mysql', 'mariadb'], true)) {
            $rows = DB::table('information_schema.STATISTICS')
                ->where('TABLE_SCHEMA', $connection->getDatabaseName())
                ->where('TABLE_NAME', $table)
                ->where('NON_UNIQUE', 0)
                ->orderBy('INDEX_NAME')->orderBy('SEQ_IN_INDEX')
                ->get(['INDEX_NAME', 'COLUMN_NAME']);

            $indexes = [];

            foreach ($rows as $row) {
                $indexes[(string) $row->INDEX_NAME][] = (string) $row->COLUMN_NAME;
            }

            return $indexes;
        }

        throw new RuntimeException('Unsupported driver for index inspection.');
    }

    protected function hasNamedIndex(string $table, string $indexName): bool
    {
        $connection = DB::connection();

        return match ($connection->getDriverName()) {
            'sqlite' => DB::table('sqlite_master')->where('type', 'index')
                ->where('tbl_name', $table)->where('name', $indexName)->exists(),

            'mysql', 'mariadb' => DB::table('information_schema.STATISTICS')
                ->where('TABLE_SCHEMA', $connection->getDatabaseName())
                ->where('TABLE_NAME', $table)->where('INDEX_NAME', $indexName)->exists(),

            default => throw new RuntimeException('Unsupported driver for index inspection.'),
        };
    }
}
