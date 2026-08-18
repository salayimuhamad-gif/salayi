<?php

declare(strict_types=1);

namespace App\Modules\Operations\Services;

use Illuminate\Support\Facades\DB;
use RuntimeException;
use Throwable;

/**
 * Database backup and restore (spec 33.3, 36 Step 8).
 *
 * WRITTEN IN PHP, NOT SHELLING OUT TO mysqldump. On Hostinger shared hosting
 * `shell_exec`, `proc_open` and `exec` are commonly disabled, and `mysqldump`
 * is frequently absent from PATH even when they are not. A backup implementation
 * that assumes a shell works perfectly in development and fails silently on the
 * one machine where the backup actually matters — which is precisely when
 * nobody discovers it until a restore is needed.
 *
 * So this streams through PDO. It is slower than mysqldump and it is available.
 *
 * The dump is written incrementally to a file handle rather than assembled in
 * memory: a 200 MB property database would exhaust the 256 MB memory limit that
 * config/installer.php only treats as *advisory*.
 */
final class BackupService
{
    /** Rows fetched per batch. Small enough to stay well inside memory limits. */
    private const CHUNK = 500;

    public function __construct(private readonly ?AuditLogger $audit = null) {}

    /**
     * @param  list<string>|null  $only  restrict to specific tables
     * @return array{path: string, bytes: int, checksum: string, tables: int, rows: int}
     */
    public function dump(string $absolutePath, ?array $only = null): array
    {
        $directory = dirname($absolutePath);

        if (! is_dir($directory) && ! @mkdir($directory, 0o750, true) && ! is_dir($directory)) {
            throw new RuntimeException(sprintf('Backup directory "%s" could not be created.', $directory));
        }

        $handle = @fopen($absolutePath, 'wb');

        if ($handle === false) {
            throw new RuntimeException(sprintf('Backup file "%s" could not be opened for writing.', $absolutePath));
        }

        $driver = DB::connection()->getDriverName();
        $tables = $only ?? $this->tables();
        $rowCount = 0;

        try {
            fwrite($handle, $this->header($driver));

            foreach ($tables as $table) {
                fwrite($handle, $this->createStatement($table, $driver));
                $rowCount += $this->writeRows($handle, $table);
            }

            fwrite($handle, $this->footer($driver));
        } finally {
            fclose($handle);
        }

        $bytes = (int) filesize($absolutePath);
        $checksum = hash_file('sha256', $absolutePath);

        $this->audit?->record('backup.created', null, [], [
            'path' => basename($absolutePath),
            'bytes' => $bytes,
            'tables' => count($tables),
        ]);

        return [
            'path' => $absolutePath,
            'bytes' => $bytes,
            'checksum' => $checksum === false ? '' : $checksum,
            'tables' => count($tables),
            'rows' => $rowCount,
        ];
    }

    /**
     * Verify a backup before relying on it.
     *
     * Called before a destructive migration, because "we took a backup" and
     * "we took a usable backup" are different claims and the gap between them
     * is only ever discovered at the worst moment.
     *
     * @return array{valid: bool, reason: string|null, bytes: int}
     */
    public function verify(string $absolutePath, ?string $expectedChecksum = null): array
    {
        if (! is_file($absolutePath)) {
            return ['valid' => false, 'reason' => 'file_missing', 'bytes' => 0];
        }

        $bytes = (int) filesize($absolutePath);

        if ($bytes === 0) {
            return ['valid' => false, 'reason' => 'file_empty', 'bytes' => 0];
        }

        if ($expectedChecksum !== null && hash_file('sha256', $absolutePath) !== $expectedChecksum) {
            return ['valid' => false, 'reason' => 'checksum_mismatch', 'bytes' => $bytes];
        }

        // A dump that does not end with its own terminator was truncated —
        // the shared host timed out mid-write. Catching that here is the
        // difference between a failed migration and an unrecoverable one.
        $tail = $this->tail($absolutePath, 200);

        if (! str_contains($tail, '-- MULKIHAWLER BACKUP END')) {
            return ['valid' => false, 'reason' => 'truncated_no_end_marker', 'bytes' => $bytes];
        }

        return ['valid' => true, 'reason' => null, 'bytes' => $bytes];
    }

    /**
     * Restore from a dump.
     *
     * Refuses to start unless the file verifies. Restoring from a truncated
     * dump leaves a half-populated schema, which is strictly worse than the
     * broken state it was meant to fix.
     *
     * @return array{restored: bool, statements: int, reason: string|null}
     */
    public function restore(string $absolutePath, ?string $expectedChecksum = null): array
    {
        $verification = $this->verify($absolutePath, $expectedChecksum);

        if (! $verification['valid']) {
            return ['restored' => false, 'statements' => 0, 'reason' => $verification['reason']];
        }

        $handle = @fopen($absolutePath, 'rb');

        if ($handle === false) {
            return ['restored' => false, 'statements' => 0, 'reason' => 'file_unreadable'];
        }

        $statements = 0;
        $buffer = '';

        try {
            DB::unprepared('SET FOREIGN_KEY_CHECKS=0');
        } catch (Throwable) {
            // SQLite and other drivers do not support it; not fatal.
        }

        try {
            while (($line = fgets($handle)) !== false) {
                $trimmed = rtrim($line);

                if ($trimmed === '' || str_starts_with($trimmed, '--')) {
                    continue;
                }

                $buffer .= $line;

                if (str_ends_with($trimmed, ';')) {
                    DB::unprepared($buffer);
                    $buffer = '';
                    $statements++;
                }
            }
        } finally {
            fclose($handle);

            try {
                DB::unprepared('SET FOREIGN_KEY_CHECKS=1');
            } catch (Throwable) {
                // As above.
            }
        }

        $this->audit?->record('backup.restored', null, [], [
            'path' => basename($absolutePath),
            'statements' => $statements,
        ], severity: 'warning');

        return ['restored' => true, 'statements' => $statements, 'reason' => null];
    }

    /** @return list<string> */
    public function tables(): array
    {
        $names = [];

        foreach (DB::getSchemaBuilder()->getTables() as $table) {
            /*
             * `getTables()` returns a row per table on the Laravel version this
             * release pins, so the scalar fallback could never be taken. The
             * emptiness check stays: a name is what gets interpolated into the
             * dump command, and an empty one must not be.
             */
            $name = $table['name'];

            if ($name !== '') {
                $names[] = $name;
            }
        }

        sort($names);

        return $names;
    }

    /** @param resource $handle */
    private function writeRows($handle, string $table): int
    {
        $written = 0;
        $offset = 0;

        do {
            $rows = DB::table($table)->offset($offset)->limit(self::CHUNK)->get();

            foreach ($rows as $row) {
                fwrite($handle, $this->insertStatement($table, (array) $row));
                $written++;
            }

            $offset += self::CHUNK;
        } while ($rows->count() === self::CHUNK);

        return $written;
    }

    /** @param array<string, mixed> $row */
    private function insertStatement(string $table, array $row): string
    {
        $columns = array_map(static fn (string $c): string => '`'.str_replace('`', '``', $c).'`', array_keys($row));

        $values = array_map(function (mixed $value): string {
            if ($value === null) {
                return 'NULL';
            }

            if (is_bool($value)) {
                return $value ? '1' : '0';
            }

            if (is_int($value) || is_float($value)) {
                return (string) $value;
            }

            return DB::connection()->getPdo()->quote((string) $value);
        }, array_values($row));

        return sprintf(
            "INSERT INTO `%s` (%s) VALUES (%s);\n",
            str_replace('`', '``', $table),
            implode(', ', $columns),
            implode(', ', $values),
        );
    }

    private function createStatement(string $table, string $driver): string
    {
        $quoted = str_replace('`', '``', $table);

        if ($driver !== 'mysql') {
            return sprintf("-- table %s\nDELETE FROM `%s`;\n", $table, $quoted);
        }

        /** @var object|null $result */
        $result = DB::selectOne(sprintf('SHOW CREATE TABLE `%s`', $quoted));
        $create = $result === null ? null : ((array) $result)['Create Table'] ?? null;

        return sprintf("DROP TABLE IF EXISTS `%s`;\n", $quoted)
            .(is_string($create) ? $create.";\n" : '');
    }

    private function header(string $driver): string
    {
        return sprintf(
            "-- MULKIHAWLER BACKUP START\n-- driver: %s\n-- version: %s\n-- created: %s\n\n",
            $driver,
            (string) config('mulkihawler.version'),
            date('c'),
        );
    }

    private function footer(string $driver): string
    {
        return sprintf("\n-- MULKIHAWLER BACKUP END (%s)\n", $driver);
    }

    private function tail(string $path, int $bytes): string
    {
        $handle = @fopen($path, 'rb');

        if ($handle === false) {
            return '';
        }

        $size = (int) filesize($path);
        fseek($handle, max(0, $size - $bytes));
        $tail = (string) fread($handle, $bytes);
        fclose($handle);

        return $tail;
    }
}
