<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Modules\Geography\Models\Area;
use App\Modules\Projects\Models\Project;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Concerns\RunnableMigration;
use Tests\TestCase;

/**
 * The scope_id backfill repairs exactly what it can prove, and nothing else.
 *
 * Every price record accepted before the importer resolved scope ids has
 * `scope_id NULL` beside a `scope_external_id` — invisible to IndexBuilder
 * and every other scope_id-filtered consumer. The migration under test
 * repairs those rows so Wave 4 can consume history, under rules this suite
 * pins one by one: a row is repaired only when (scope_type,
 * scope_external_id) resolves byte-exactly to exactly one LIVE canonical
 * row and its canonical period slot is free; everything else — unknown ids,
 * deleted scopes, case mismatches, occupied slots, scope types with no
 * internal identity, rows that already carry a scope_id — stays byte-for-
 * byte untouched and remains reportable. No price, provenance, publication
 * or timestamp value moves, and running the repair twice is the same as
 * running it once.
 */
final class PriceRecordScopeBackfillMigrationTest extends TestCase
{
    use RefreshDatabase;

    private const PATH = 'app/Modules/Market/Database/Migrations/2026_08_21_000100_backfill_price_record_scope_ids.php';

    /**
     * @return Migration&RunnableMigration
     */
    private function migration(): Migration
    {
        /** @var Migration&RunnableMigration $migration */
        $migration = require base_path(self::PATH);

        return $migration;
    }

    private function area(string $externalId): Area
    {
        return Area::query()->create([
            'type' => 'district',
            'slug' => strtolower($externalId),
            'name_ckb' => 'ناوچە '.$externalId,
            'external_id' => $externalId,
            'publication_status' => 'published',
        ]);
    }

    private function project(string $externalId): Project
    {
        return Project::query()->create([
            'external_id' => $externalId,
            'slug' => strtolower($externalId),
            'name_ckb' => 'پڕۆژە '.$externalId,
            'project_type' => 'residential',
            'construction_status' => 'under_construction',
            'delivery_status' => 'not_started',
            'publication_status' => 'published',
        ]);
    }

    /**
     * A pre-fix record, exactly as the broken importer wrote it.
     *
     * Raw insert, deliberately: the rows this migration exists for were
     * written by earlier releases, so the fixture must not depend on the
     * repaired model path.
     *
     * @param  array<string, mixed>  $overrides
     */
    private function brokenRecord(string $scopeType, string $externalId, array $overrides = []): int
    {
        return (int) DB::table('price_records')->insertGetId($overrides + [
            'scope_type' => $scopeType,
            'scope_id' => null,
            'scope_external_id' => $externalId,
            'property_type' => 'apartment',
            'unit_type' => '2br',
            'transaction_type' => 'sale',
            'price_type' => 'sale_asking',
            'currency' => 'USD',
            'price' => '240000.0000',
            'effective_date' => '2026-06-15',
            'period' => '2026-06',
            'source' => 'historical import',
            'confidence' => 'high',
            'is_outlier' => false,
            'publication_status' => 'published',
            'created_at' => '2026-06-20 10:00:00',
            'updated_at' => '2026-06-20 10:00:00',
        ]);
    }

    public function test_uniquely_resolvable_rows_are_backfilled_without_touching_anything_else(): void
    {
        $area = $this->area('AR-001');
        $project = $this->project('PRJ-001');

        $areaRow = $this->brokenRecord('area', 'AR-001');
        $projectRow = $this->brokenRecord('project', 'PRJ-001', ['unit_type' => '3br', 'publication_status' => 'draft']);
        // Soft-deleted rows are repaired too — a restored record must come
        // back visible to its index, matching the search-key precedent.
        $trashedRow = $this->brokenRecord('area', 'AR-001', ['unit_type' => '4br', 'deleted_at' => '2026-07-01 09:00:00']);

        $this->migration()->up();

        $repaired = DB::table('price_records')->where('id', $areaRow)->first();
        $this->assertSame((int) $area->id, (int) $repaired->scope_id);
        // The repair writes the key the importer forgot — and only the key.
        $this->assertSame('AR-001', $repaired->scope_external_id);
        $this->assertSame('240000.0000', (string) $repaired->price);
        $this->assertSame('published', $repaired->publication_status);
        $this->assertSame('historical import', $repaired->source);
        $this->assertSame('2026-06-20 10:00:00', (string) $repaired->updated_at);

        $this->assertSame(
            (int) $project->id,
            (int) DB::table('price_records')->where('id', $projectRow)->value('scope_id'),
        );
        $this->assertSame(
            'draft',
            DB::table('price_records')->where('id', $projectRow)->value('publication_status'),
            'the repair must not publish drafts',
        );
        $this->assertSame(
            (int) $area->id,
            (int) DB::table('price_records')->where('id', $trashedRow)->value('scope_id'),
        );
    }

    public function test_unresolvable_ambiguous_and_conflicting_rows_stay_untouched(): void
    {
        $area = $this->area('AR-001');

        $deleted = $this->area('AR-GONE');
        $deleted->delete();

        $unknown = $this->brokenRecord('area', 'AR-NOWHERE', ['unit_type' => 'u1']);
        $pointsAtDeleted = $this->brokenRecord('area', 'AR-GONE', ['unit_type' => 'u2']);
        // Byte-exact means byte-exact on BOTH engines: MariaDB's collation
        // would happily equate 'ar-001' with 'AR-001'; the repair must not.
        $caseMismatch = $this->brokenRecord('area', 'ar-001', ['unit_type' => 'u3']);
        // A city row has no internal identity to repair toward, whatever its
        // external id happens to say.
        $city = $this->brokenRecord('city', 'AR-001', ['unit_type' => 'u4']);

        // The canonical slot for this candidate is already occupied by a
        // record addressed by internal id; repairing the candidate would
        // collide with price_records_period_slot or double the period.
        $occupant = DB::table('price_records')->insertGetId([
            'scope_type' => 'area',
            'scope_id' => $area->id,
            'scope_external_id' => null,
            'property_type' => 'apartment',
            'unit_type' => 'u5',
            'transaction_type' => 'sale',
            'price_type' => 'sale_asking',
            'currency' => 'USD',
            'price' => '111111.0000',
            'effective_date' => '2026-06-01',
            'period' => '2026-06',
            'confidence' => 'medium',
            'is_outlier' => false,
            'publication_status' => 'published',
            'created_at' => '2026-06-01 08:00:00',
            'updated_at' => '2026-06-01 08:00:00',
        ]);
        $conflicting = $this->brokenRecord('area', 'AR-001', ['unit_type' => 'u5']);

        $this->migration()->up();

        foreach ([$unknown, $pointsAtDeleted, $caseMismatch, $city, $conflicting] as $id) {
            $this->assertNull(
                DB::table('price_records')->where('id', $id)->value('scope_id'),
                "row {$id} must stay untouched and reportable",
            );
        }

        // Untouched means reportable: the unrepaired set is exactly the rows
        // still carrying an external id with no internal one.
        $this->assertSame(
            5,
            DB::table('price_records')->whereNull('scope_id')->whereNotNull('scope_external_id')->count(),
        );

        $this->assertSame(
            '111111.0000',
            (string) DB::table('price_records')->where('id', $occupant)->value('price'),
            'the slot occupant is not the repair\'s to touch',
        );
    }

    public function test_rows_that_already_carry_a_scope_id_are_never_second_guessed(): void
    {
        $this->area('AR-001');
        $other = $this->area('AR-OTHER');

        // scope_id disagrees with what the external id would resolve to —
        // deciding which is right is a human's call, not a migration's.
        $id = $this->brokenRecord('area', 'AR-001', ['scope_id' => $other->id, 'unit_type' => 'k1']);

        $this->migration()->up();

        $this->assertSame(
            (int) $other->id,
            (int) DB::table('price_records')->where('id', $id)->value('scope_id'),
        );
    }

    public function test_the_repair_is_deterministic_and_idempotent(): void
    {
        $this->area('AR-001');
        $this->area('AR-GONE')->delete();

        $this->brokenRecord('area', 'AR-001', ['unit_type' => 'i1']);
        $this->brokenRecord('area', 'AR-GONE', ['unit_type' => 'i2']);

        $snapshot = static fn (): array => DB::table('price_records')
            ->orderBy('id')
            ->get()
            ->map(static fn (object $row): array => (array) $row)
            ->all();

        $this->migration()->up();
        $first = $snapshot();

        $this->migration()->up();

        $this->assertSame($first, $snapshot(), 'a second run must change nothing');
    }
}
