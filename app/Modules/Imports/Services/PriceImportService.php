<?php

declare(strict_types=1);

namespace App\Modules\Imports\Services;

use App\Modules\Imports\Support\PriceRowValidator;
use App\Modules\Market\Models\PriceRecord;
use App\Modules\Operations\Services\AuditLogger;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Historical price import orchestration (spec 14.2, 14.3).
 *
 * Three phases, deliberately separate: PREVIEW validates and stores per-row
 * results without writing a single price; ACCEPT commits the rows an operator
 * chose; ROLLBACK undoes an unpublished batch.
 *
 * Separating preview from accept is what makes spec 14.3's "support partial
 * acceptance" and "support rollback before publication" possible at all. An
 * importer that validates and writes in one pass can only offer all-or-nothing,
 * and an operator facing 400 rows where 12 are wrong will either abandon the
 * import or accept the 12.
 */
final class PriceImportService
{
    public function __construct(
        private readonly PriceRowValidator $validator,
        private readonly AuditLogger $audit,
    ) {}

    /**
     * Validate a parsed spreadsheet without writing any prices.
     *
     * @param  list<array<string, mixed>>  $rows
     * @param  array<string, list<string>>  $knownScopes  scope type => external ids already known
     * @return array{
     *     reference: string, total: int, valid: int, errors: int, warnings: int,
     *     rows: list<array<string, mixed>>
     * }
     */
    public function preview(array $rows, array $knownScopes = []): array
    {
        $reference = 'IMP-'.Str::upper(Str::random(10));
        $seen = $this->existingSlots();
        $results = [];
        $normalised = [];

        foreach ($rows as $index => $raw) {
            $result = $this->validator->validate($raw, [
                'known_scopes' => $knownScopes,
                'seen_slots' => $seen,
            ]);

            if ($result['valid']) {
                // Added immediately so a duplicate WITHIN the same upload is
                // caught, not only a duplicate against what is already stored.
                $seen[] = $result['normalised']['slot'];
                $normalised[$index] = $result['normalised'];
            }

            $results[$index] = [
                'row_number' => $index + 1,
                'raw' => $raw,
                'normalised' => $result['normalised'],
                'errors' => $result['errors'],
                'warnings' => $result['warnings'],
                'status' => $result['valid'] ? 'valid' : 'error',
            ];
        }

        // Jump detection runs across the accepted set, because a jump is a
        // property of a series and cannot be seen one row at a time.
        foreach ($this->validator->detectJumps($normalised) as $index => $flags) {
            $results[$index]['warnings'] = array_merge($results[$index]['warnings'], $flags);
        }

        $rowsOut = array_values($results);

        return [
            'reference' => $reference,
            'total' => count($rowsOut),
            'valid' => count(array_filter($rowsOut, static fn (array $r): bool => $r['status'] === 'valid')),
            'errors' => count(array_filter($rowsOut, static fn (array $r): bool => $r['status'] === 'error')),
            'warnings' => count(array_filter($rowsOut, static fn (array $r): bool => $r['warnings'] !== [])),
            'rows' => $rowsOut,
        ];
    }

    /**
     * Commit the chosen rows.
     *
     * Rows arrive already validated by preview(). They are re-checked for slot
     * collisions inside the transaction anyway, because a concurrent import
     * could have taken the slot between preview and accept — and a unique-key
     * violation mid-batch would abort rows the operator had approved.
     *
     * @param  list<array<string, mixed>>  $normalisedRows
     * @return array{batch_id: int|null, written: int, skipped: int}
     */
    public function accept(string $reference, array $normalisedRows, ?int $actorId = null): array
    {
        if ($normalisedRows === []) {
            return ['batch_id' => null, 'written' => 0, 'skipped' => 0];
        }

        $written = 0;
        $skipped = 0;
        $batchId = null;

        DB::transaction(function () use ($reference, $normalisedRows, $actorId, &$written, &$skipped, &$batchId): void {
            $batchId = (int) DB::table('market_import_batches')->insertGetId([
                'reference' => $reference,
                'kind' => 'price',
                'total_rows' => count($normalisedRows),
                'valid_rows' => count($normalisedRows),
                'status' => 'accepted',
                'accepted_at' => now(),
                'created_by' => $actorId,
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            $taken = $this->existingSlots();

            foreach ($normalisedRows as $row) {
                if (in_array($row['slot'], $taken, true)) {
                    $skipped++;

                    continue;
                }

                PriceRecord::query()->create([
                    'record_id' => $row['record_id'],
                    'scope_type' => $row['scope_type'],
                    'scope_external_id' => $row['scope_external_id'],
                    'property_type' => $row['property_type'],
                    'unit_type' => $row['unit_type'],
                    'transaction_type' => $row['transaction_type'],
                    'price_type' => $row['price_type'],
                    'currency' => $row['currency'],
                    'price' => $row['price'],
                    'price_per_sqm' => $row['price_per_sqm'],
                    'minimum' => $row['minimum'],
                    'maximum' => $row['maximum'],
                    'median' => $row['median'],
                    'sample_size' => $row['sample_size'],
                    'source_count' => $row['source_count'],
                    'effective_date' => $row['effective_date'],
                    'published_date' => $row['published_date'],
                    'period' => $row['period'],
                    'source' => $row['source'],
                    'confidence' => $row['confidence'],
                    'notes' => $row['notes'],
                    'import_batch_id' => $batchId,
                    // Imported rows land as DRAFT. Spec 14.1's separation only
                    // holds if a human confirms what a spreadsheet asserted,
                    // and an import that publishes directly makes the review
                    // step optional in practice.
                    'publication_status' => 'draft',
                    'created_by' => $actorId,
                ]);

                $taken[] = $row['slot'];
                $written++;
            }

            DB::table('market_import_batches')->where('id', $batchId)
                ->update(['accepted_rows' => $written, 'updated_at' => now()]);
        });

        $this->audit->record('price_import.accepted', null, [], [
            'reference' => $reference, 'written' => $written, 'skipped' => $skipped,
        ], severity: 'warning');

        return ['batch_id' => $batchId, 'written' => $written, 'skipped' => $skipped];
    }

    /**
     * Undo an unpublished batch (spec 14.3 "support rollback before
     * publication").
     *
     * Refuses once published. After publication a figure may already be in an
     * index, a valuation or a screenshot someone acted on; the correction is a
     * new batch with an audit trail, not an erasure.
     *
     * @return array{rolled_back: bool, deleted: int, reason: string|null}
     */
    public function rollback(int $batchId, ?int $actorId = null): array
    {
        $batch = DB::table('market_import_batches')->where('id', $batchId)->first();

        if ($batch === null) {
            return ['rolled_back' => false, 'deleted' => 0, 'reason' => 'batch_not_found'];
        }

        if ($batch->published_at !== null) {
            return ['rolled_back' => false, 'deleted' => 0, 'reason' => 'already_published'];
        }

        $deleted = 0;

        DB::transaction(function () use ($batchId, &$deleted): void {
            $deleted = PriceRecord::query()->where('import_batch_id', $batchId)->forceDelete();

            DB::table('market_import_batches')->where('id', $batchId)->update([
                'status' => 'rolled_back',
                'rolled_back_at' => now(),
                'updated_at' => now(),
            ]);
        });

        $this->audit->record('price_import.rolled_back', null, [], [
            'batch_id' => $batchId, 'deleted' => $deleted,
        ], severity: 'warning');

        return ['rolled_back' => true, 'deleted' => $deleted, 'reason' => null];
    }

    /**
     * Slots already occupied, for duplicate detection.
     *
     * @return list<string>
     */
    private function existingSlots(): array
    {
        return PriceRecord::query()
            ->select(['scope_type', 'scope_external_id', 'property_type', 'unit_type', 'price_type', 'period'])
            ->get()
            ->map(static fn ($r): string => implode('|', [
                $r->scope_type->value, (string) $r->scope_external_id,
                $r->property_type->value, (string) $r->unit_type,
                $r->price_type->value, (string) $r->period,
            ]))
            ->all();
    }
}
