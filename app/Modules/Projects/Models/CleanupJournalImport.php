<?php

declare(strict_types=1);

namespace App\Modules\Projects\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * Which emergency-journal entries have been imported (spec 26.1).
 *
 * The idempotency key lives HERE, unique in the database, rather than as a
 * nullable column on the job. A job can be fed by several journal entries, and
 * a single column could only remember the last — so the previous design
 * overwrote its own evidence and then relied on a pre-check that two workers
 * could both pass.
 *
 * A row in this table means: this exact entry has been consumed, once, ever.
 */
/**
 * v6: property annotations, absent from the strict branch's model, so
 * static analysis could not see the ledger's own columns.
 *
 * @property int $id
 * @property string $entry_id
 * @property int $orphaned_file_id
 *                                 `payload_hash` is NOT NULL in the schema, but legacy or corrupt rows
 *                                 predate that contract and the replay must be able to recognise them —
 *                                 so the annotation admits null rather than making the fail-closed check
 *                                 unreachable.
 * @property string|null $payload_hash
 * @property Carbon|null $imported_at
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 *
 * ---- generated model properties (scripts/generate-model-annotations.php)
 * @property int $id
 * @property string $entry_id
 * @property int $orphaned_file_id
 * @property string $payload_hash
 * @property Carbon $imported_at
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 *
 * ---- end generated model properties
 */
final class CleanupJournalImport extends Model
{
    protected $table = 'cleanup_journal_imports';

    protected $fillable = ['entry_id', 'orphaned_file_id', 'payload_hash', 'imported_at'];

    /*
     * `payload_hash` is NOT NULL in the schema. A row without one carries no
     * integrity evidence, and the replay treats a null as a conflict rather
     * than a pass — the column constraint and that rule must agree.
     */

    protected function casts(): array
    {
        return [
            'orphaned_file_id' => 'integer',
            'imported_at' => 'datetime',
        ];
    }
}
