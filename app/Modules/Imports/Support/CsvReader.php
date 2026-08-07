<?php

declare(strict_types=1);

namespace App\Modules\Imports\Support;

/**
 * Delimited-file reading for imports (spec 14.2 "CSV template").
 *
 * Uses PHP's built-in fgetcsv rather than a spreadsheet library. That is a
 * constraint, not a preference: PhpSpreadsheet would read .xlsx and is a
 * Composer dependency, and this build has never been able to reach Packagist.
 * CSV covers the generated templates and needs nothing.
 *
 * Streams line by line. A year of Erbil price history is a small file, but an
 * importer that loads the whole thing into memory fails on the one upload that
 * matters and works on every test.
 */
final class CsvReader
{
    /** Rows read per file, so a runaway upload cannot exhaust the request. */
    public const MAX_ROWS = 5000;

    /**
     * Read a delimited file into header-keyed rows.
     *
     * @return array{
     *     headers: list<string>, rows: list<array<string, string>>,
     *     truncated: bool, delimiter: string, error: string|null
     * }
     */
    public function read(string $absolutePath, ?string $delimiter = null): array
    {
        $empty = ['headers' => [], 'rows' => [], 'truncated' => false, 'delimiter' => ',', 'error' => null];

        if (! is_file($absolutePath) || ! is_readable($absolutePath)) {
            return array_merge($empty, ['error' => 'file_unreadable']);
        }

        $handle = @fopen($absolutePath, 'r');

        if ($handle === false) {
            return array_merge($empty, ['error' => 'file_unreadable']);
        }

        $delimiter ??= $this->detectDelimiter($absolutePath);
        $headers = [];
        $rows = [];
        $truncated = false;

        try {
            $first = fgetcsv($handle, 0, $delimiter);

            if ($first === false || $first === [null]) {
                return array_merge($empty, ['error' => 'file_empty', 'delimiter' => $delimiter]);
            }

            // Excel writes a UTF-8 BOM on the first cell. Left in place it
            // makes the first column header never match, and the resulting
            // error — "missing required field" on every row — points nowhere
            // near the actual cause.
            $first[0] = preg_replace('/^\x{FEFF}/u', '', (string) $first[0]) ?? '';

            $headers = array_map(
                static fn (?string $h): string => strtolower(trim((string) $h)),
                $first,
            );

            while (($line = fgetcsv($handle, 0, $delimiter)) !== false) {
                if ($line === [null]) {
                    continue; // blank line
                }

                if (count($rows) >= self::MAX_ROWS) {
                    $truncated = true;
                    break;
                }

                $row = [];

                foreach ($headers as $index => $header) {
                    if ($header === '') {
                        continue;
                    }

                    $row[$header] = trim((string) ($line[$index] ?? ''));
                }

                // A row that is entirely empty is padding, not data.
                if (array_filter($row, static fn (string $v): bool => $v !== '') === []) {
                    continue;
                }

                $rows[] = $row;
            }
        } finally {
            fclose($handle);
        }

        return [
            'headers' => array_values(array_filter($headers, static fn (string $h): bool => $h !== '')),
            'rows' => $rows,
            'truncated' => $truncated,
            'delimiter' => $delimiter,
            'error' => null,
        ];
    }

    /**
     * Which columns the template expects but the file does not carry.
     *
     * Reported before validation runs, because "column missing" answered once
     * is more useful than the same error repeated on four hundred rows.
     *
     * @param  list<string>  $headers
     * @param  list<string>  $required
     * @return list<string>
     */
    public function missingColumns(array $headers, array $required): array
    {
        return array_values(array_diff($required, $headers));
    }

    /**
     * Detect the delimiter from the header line.
     *
     * A semicolon is what Excel writes on an Arabic or Kurdish Windows locale,
     * where the comma is the decimal separator. Assuming a comma there yields
     * one column containing the entire row, and an error message about missing
     * fields that tells the operator nothing about why.
     */
    private function detectDelimiter(string $path): string
    {
        $handle = @fopen($path, 'r');

        if ($handle === false) {
            return ',';
        }

        $line = (string) fgets($handle, 8192);
        fclose($handle);

        $counts = [
            ',' => substr_count($line, ','),
            ';' => substr_count($line, ';'),
            "\t" => substr_count($line, "\t"),
        ];

        arsort($counts);
        $best = array_key_first($counts);

        return $counts[$best] > 0 ? (string) $best : ',';
    }
}
