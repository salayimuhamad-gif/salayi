<?php

declare(strict_types=1);

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use PHPUnit\Framework\Attributes\Group;
use Tests\TestCase;

/**
 * The strict cleanup lifecycle's index contract, verified INDEPENDENTLY.
 *
 * The migrations prove their own work through
 * `SchemaContract::indexContractHolds()`. A test that asked the same helper
 * whether the helper's work succeeded would be circular: a defect in the
 * helper would define both the behaviour and the expectation. This class
 * therefore reads the schema through the driver's own introspection —
 * `PRAGMA index_list`/`index_info` on SQLite, `information_schema.STATISTICS`
 * on MySQL/MariaDB — and never calls the production helper.
 *
 * What it pins, on whichever engine the suite is configured for:
 *
 *   - TABLE OWNERSHIP: the index belongs to `orphaned_files`, not merely to
 *     some table with a matching name.
 *   - ORDERED COLUMNS: the exact column list, in order.
 *   - UNIQUENESS: unique where the lifecycle requires it.
 *   - FULL-COLUMN: MySQL prefix indexes are REJECTED, because a prefix
 *     index over a truncated key does not enforce identity.
 *   - FULL, NOT PARTIAL: SQLite partial indexes are REJECTED, because a
 *     `WHERE` clause exempts exactly the rows uniqueness must cover.
 *   - THE LIFECYCLE SPLIT: `job_key` is repeatable so resolved incidents
 *     keep their history; `active_key` is unique so only one live incident
 *     can exist.
 */
#[Group('schema-contract')]
final class CleanupIndexContractTest extends TestCase
{
    use RefreshDatabase;

    private const TABLE = 'orphaned_files';

    public function test_active_key_carries_a_full_unique_index_owned_by_this_table(): void
    {
        $index = $this->uniqueIndexes()['active_key'] ?? null;

        $this->assertNotNull($index, 'no unique index covers active_key');
        $this->assertSame(self::TABLE, $index['table'], 'the index belongs to another table');
        $this->assertSame(['active_key'], $index['columns'], 'the column list is wrong or out of order');
        $this->assertTrue($index['unique'], 'the active-identity index is not unique');
        $this->assertFalse($index['partial'], 'a partial index exempts the rows uniqueness must cover');
        $this->assertNull($index['prefix_length'], 'a prefix index does not enforce full-column identity');
    }

    public function test_job_key_is_repeatable_so_resolved_history_survives(): void
    {
        $this->assertArrayNotHasKey(
            'job_key',
            $this->uniqueIndexes(),
            'job_key is still uniquely indexed; a resolved incident could not keep its key '
            .'while a new incident took the same path',
        );

        $this->assertTrue(Schema::hasColumn(self::TABLE, 'job_key'));
        $this->assertTrue(Schema::hasColumn(self::TABLE, 'active_key'));
    }

    public function test_incident_uuid_is_unique_and_not_null(): void
    {
        $index = $this->uniqueIndexes()['incident_uuid'] ?? null;

        $this->assertNotNull($index, 'incident identity is not uniquely indexed');
        $this->assertSame(['incident_uuid'], $index['columns']);
        $this->assertFalse($index['partial']);
        $this->assertNull($index['prefix_length']);
        $this->assertFalse($this->columnIsNullable('incident_uuid'), 'incident_uuid must be NOT NULL');
    }

    public function test_the_disk_path_unique_is_gone(): void
    {
        foreach ($this->uniqueIndexes() as $key => $index) {
            $this->assertNotSame(
                ['disk', 'path'],
                $index['columns'],
                'a disk+path unique forbids a second incident at the same path: '.$key,
            );
        }
    }

    /* ------------------------------------------------------------------ */
    /* Raw introspection — no production helper is consulted */
    /* ------------------------------------------------------------------ */

    /**
     * @return array<string, array{table: string, columns: list<string>, unique: bool, partial: bool, prefix_length: int|null}>
     */
    private function uniqueIndexes(): array
    {
        $connection = DB::connection();

        return $connection->getDriverName() === 'sqlite'
            ? $this->sqliteUniqueIndexes()
            : $this->mysqlUniqueIndexes();
    }

    /** @return array<string, array{table: string, columns: list<string>, unique: bool, partial: bool, prefix_length: int|null}> */
    private function sqliteUniqueIndexes(): array
    {
        $found = [];

        foreach (DB::select('PRAGMA index_list('.self::TABLE.')') as $index) {
            if ((int) $index->unique !== 1) {
                continue;
            }

            $columns = array_map(
                static fn (object $c): string => (string) $c->name,
                DB::select('PRAGMA index_info('.$index->name.')'),
            );

            /*
             * `partial` is reported by PRAGMA on modern SQLite; where it is
             * absent, the index's own DDL is the authority — a `WHERE`
             * clause in the CREATE INDEX statement is what makes it partial.
             */
            $ddl = (string) (DB::selectOne(
                "SELECT sql FROM sqlite_master WHERE type = 'index' AND name = ?",
                [$index->name],
            )->sql ?? '');

            $partial = isset($index->partial)
                ? (int) $index->partial === 1
                : (stripos($ddl, ' where ') !== false);

            $found[implode(',', $columns)] = [
                'table' => self::TABLE,
                'columns' => $columns,
                'unique' => true,
                'partial' => $partial,
                // SQLite has no prefix indexes at all.
                'prefix_length' => null,
            ];
        }

        return $found;
    }

    /** @return array<string, array{table: string, columns: list<string>, unique: bool, partial: bool, prefix_length: int|null}> */
    private function mysqlUniqueIndexes(): array
    {
        $rows = DB::select(
            'SELECT INDEX_NAME, TABLE_NAME, COLUMN_NAME, SEQ_IN_INDEX, SUB_PART
             FROM information_schema.STATISTICS
             WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND NON_UNIQUE = 0
             ORDER BY INDEX_NAME, SEQ_IN_INDEX',
            [self::TABLE],
        );

        $byIndex = [];

        foreach ($rows as $row) {
            $byIndex[$row->INDEX_NAME]['table'] = (string) $row->TABLE_NAME;
            $byIndex[$row->INDEX_NAME]['columns'][] = (string) $row->COLUMN_NAME;

            if ($row->SUB_PART !== null) {
                // A prefix index over part of the value.
                $byIndex[$row->INDEX_NAME]['prefix_length'] = (int) $row->SUB_PART;
            }
        }

        $found = [];

        foreach ($byIndex as $index) {
            $found[implode(',', $index['columns'])] = [
                'table' => $index['table'],
                'columns' => $index['columns'],
                'unique' => true,
                // MySQL has no partial indexes.
                'partial' => false,
                'prefix_length' => $index['prefix_length'] ?? null,
            ];
        }

        return $found;
    }

    private function columnIsNullable(string $column): bool
    {
        if (DB::connection()->getDriverName() === 'sqlite') {
            foreach (DB::select('PRAGMA table_info("'.self::TABLE.'")') as $info) {
                if ($info->name === $column) {
                    return (int) $info->notnull === 0;
                }
            }

            return true;
        }

        return DB::selectOne(
            'SELECT IS_NULLABLE AS n FROM information_schema.COLUMNS
             WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ?',
            [self::TABLE, $column],
        )->n === 'YES';
    }
}
