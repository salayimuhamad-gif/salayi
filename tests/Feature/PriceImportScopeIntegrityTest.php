<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Modules\Geography\Models\Area;
use App\Modules\Identity\Models\User;
use App\Modules\Imports\Services\PriceImportService;
use App\Modules\Market\Models\MarketIndex;
use App\Modules\Market\Models\PriceRecord;
use App\Modules\Market\Services\IndexBuilder;
use App\Modules\Projects\Models\Project;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Imported price records carry the canonical internal scope_id (spec 14.2,
 * 15.2, Appendix B).
 *
 * The defect pinned here: accept() stored `scope_type` and
 * `scope_external_id` but never resolved `scope_id`, while every scoped
 * consumer — IndexBuilder first among them — filters records by `scope_id`.
 * An imported area- or project-scoped price was therefore accepted,
 * reviewable, publishable, and invisible to the very index it was imported
 * to feed.
 *
 * The contracts: an accepted area/project row stores the id its external id
 * resolves to, byte-exactly, against the right table only; a row whose
 * required scope cannot be resolved AT ACCEPT TIME is skipped with a named
 * reason rather than written broken — preview and accept are separated in
 * time, so accept re-resolves against what the database holds at the moment
 * of writing; the duplicate-slot guarantee holds across both identity paths,
 * so the same logical scope cannot be imported twice just because one row is
 * addressed by external id and an existing record by internal id; and
 * everything the importer already promised — draft landing, value and
 * provenance fidelity, rollback — is unchanged.
 *
 * City, project_phase and unit_type scopes are asserted as what the
 * architecture makes them: NOT internally backed. City indices declare
 * scope_id NULL and match on scope type alone; project_phases and
 * project_unit_types have no external_id column to resolve against. Their
 * rows keep scope_id NULL deliberately, and a test failing here means that
 * architectural decision changed, not that the importer broke.
 */
final class PriceImportScopeIntegrityTest extends TestCase
{
    use RefreshDatabase;

    /* ---------------------------------------------------------- fixtures */

    private function area(string $externalId, ?string $slug = null): Area
    {
        return Area::query()->create([
            'type' => 'district',
            'slug' => $slug ?? strtolower($externalId),
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
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    private function csvRow(array $overrides = []): array
    {
        return $overrides + [
            'scope_type' => 'area',
            'scope_external_id' => 'AR-001',
            'property_type' => 'apartment',
            'unit_type' => '2br',
            'price_type' => 'sale_asking',
            'currency' => 'USD',
            'price' => '240000',
            'effective_date' => '2026-07-15',
            'sample_size' => '12',
            'source' => 'erbil bureau',
            'confidence' => 'high',
            'notes' => 'imported fixture',
        ];
    }

    /** @return array<string, list<string>> exactly what the controller passes */
    private function knownScopes(): array
    {
        return [
            'area' => Area::query()->whereNotNull('external_id')->pluck('external_id')->all(),
            'project' => Project::query()->whereNotNull('external_id')->pluck('external_id')->all(),
        ];
    }

    /**
     * Run the real preview -> accept pipeline, exactly as the controller does.
     *
     * @param  list<array<string, mixed>>  $rows
     * @param  array<string, list<string>>|null  $knownScopes
     * @return array{batch_id: int|null, written: int, skipped: int, skipped_rows: list<array{slot: string, reason: string}>}
     */
    private function import(array $rows, ?array $knownScopes = null): array
    {
        $service = app(PriceImportService::class);

        $preview = $service->preview($rows, $knownScopes ?? $this->knownScopes());

        $normalised = [];

        foreach ($preview['rows'] as $row) {
            if ($row['status'] === 'valid' && is_array($row['normalised'])) {
                $normalised[] = $row['normalised'];
            }
        }

        return $service->accept($preview['reference'], $normalised);
    }

    /* --------------------------------------- canonical ids on the way in */

    public function test_area_scoped_import_writes_the_canonical_scope_id(): void
    {
        $area = $this->area('AR-001');
        // A second area proves the resolver picked BY external id, not "the
        // only row in the table".
        $this->area('AR-002');

        $result = $this->import([$this->csvRow()]);

        $this->assertSame(1, $result['written']);
        $this->assertSame(0, $result['skipped']);

        $record = PriceRecord::query()->sole();
        $this->assertSame((int) $area->id, (int) $record->scope_id);
        $this->assertSame('AR-001', $record->scope_external_id);
        $this->assertSame('area', $record->scope_type->value);
    }

    public function test_project_scoped_import_writes_the_canonical_scope_id(): void
    {
        $project = $this->project('PRJ-001');
        $this->project('PRJ-002');

        $result = $this->import([$this->csvRow([
            'scope_type' => 'project',
            'scope_external_id' => 'PRJ-001',
        ])]);

        $this->assertSame(1, $result['written']);

        $record = PriceRecord::query()->sole();
        $this->assertSame((int) $project->id, (int) $record->scope_id);
        $this->assertSame('PRJ-001', $record->scope_external_id);
        $this->assertSame('project', $record->scope_type->value);
    }

    public function test_city_phase_and_unit_type_scopes_stay_external_only_by_design(): void
    {
        // City: single-city product, external id optional, city indices
        // declare scope_id NULL. Phase and unit type: no external_id column
        // exists on project_phases / project_unit_types to resolve against.
        $result = $this->import([
            $this->csvRow(['scope_type' => 'city', 'scope_external_id' => '', 'unit_type' => 'c1']),
            $this->csvRow(['scope_type' => 'project_phase', 'scope_external_id' => 'PH-77', 'unit_type' => 'p1']),
            $this->csvRow(['scope_type' => 'unit_type', 'scope_external_id' => 'UT-88', 'unit_type' => 'u1']),
        ]);

        $this->assertSame(3, $result['written']);
        $this->assertSame(0, $result['skipped']);

        foreach (PriceRecord::query()->get() as $record) {
            $this->assertNull($record->scope_id, $record->scope_type->value.' must stay external-id-only');
        }
    }

    /* ------------------------------------------------ refusal to guess */

    public function test_an_external_id_never_resolves_across_entity_types(): void
    {
        $this->area('AR-CROSS');
        $this->project('PRJ-CROSS');

        // Preview WITHOUT known-scope lists: the state a stale session or a
        // pre-fix preview would be in. Accept alone must still refuse.
        $result = $this->import([
            $this->csvRow(['scope_type' => 'project', 'scope_external_id' => 'AR-CROSS', 'unit_type' => 'x1']),
            $this->csvRow(['scope_type' => 'area', 'scope_external_id' => 'PRJ-CROSS', 'unit_type' => 'x2']),
        ], []);

        $this->assertSame(0, $result['written']);
        $this->assertSame(2, $result['skipped']);
        $this->assertSame(
            ['scope_unresolved', 'scope_unresolved'],
            array_column($result['skipped_rows'], 'reason'),
        );
        $this->assertSame(0, PriceRecord::query()->count());
    }

    public function test_an_unresolved_required_scope_cannot_become_a_null_scope_id_record(): void
    {
        $this->area('AR-001');

        $result = $this->import(
            [$this->csvRow(['scope_external_id' => 'AR-DOES-NOT-EXIST'])],
            [], // no preview-time list: accept is the last line of defence
        );

        $this->assertSame(0, $result['written']);
        $this->assertSame([['slot' => 'area|AR-DOES-NOT-EXIST|apartment|2br|sale_asking|2026-07', 'reason' => 'scope_unresolved']], $result['skipped_rows']);
        $this->assertSame(0, PriceRecord::query()->count());

        // The skip is recorded on the batch, deterministically, not lost in
        // a count.
        $report = DB::table('market_import_batches')->where('id', $result['batch_id'])->value('report');
        $this->assertIsString($report);
        $this->assertStringContainsString('scope_unresolved', $report);
        $this->assertStringContainsString('AR-DOES-NOT-EXIST', $report);
    }

    public function test_a_scope_deleted_between_preview_and_accept_fails_that_row_safely(): void
    {
        $area = $this->area('AR-001');

        $service = app(PriceImportService::class);
        $preview = $service->preview([$this->csvRow()], $this->knownScopes());
        $this->assertSame(1, $preview['valid'], 'the row must be valid while the scope exists');

        // The gap between preview and accept is real time; the scope goes
        // away inside it.
        $area->delete();

        $result = $service->accept($preview['reference'], [$preview['rows'][0]['normalised']]);

        $this->assertSame(0, $result['written']);
        $this->assertSame('scope_unresolved', $result['skipped_rows'][0]['reason']);
        $this->assertSame(0, PriceRecord::query()->count());
    }

    /* ------------------------------------------------- duplicate slots */

    public function test_duplicate_slots_are_refused_across_both_identity_paths(): void
    {
        $area = $this->area('AR-001');

        // Path one: the external-id slot, exactly as before the fix. Both
        // batches are previewed BEFORE either is accepted — the concurrent
        // scenario the accept-time re-check exists for, which preview-time
        // duplicate detection cannot see.
        $service = app(PriceImportService::class);
        $previewA = $service->preview([$this->csvRow()], $this->knownScopes());
        $previewB = $service->preview([$this->csvRow()], $this->knownScopes());

        $first = $service->accept($previewA['reference'], [$previewA['rows'][0]['normalised']]);
        $this->assertSame(1, $first['written']);

        $again = $service->accept($previewB['reference'], [$previewB['rows'][0]['normalised']]);

        $this->assertSame(0, $again['written']);
        $this->assertSame('duplicate_slot', $again['skipped_rows'][0]['reason']);

        // Path two: a record addressed by INTERNAL id only — no external id
        // at all — must still block an import of the same logical slot.
        // Before the fix these could not collide, and on this schema the
        // second insert would abort the whole batch on
        // price_records_period_slot.
        PriceRecord::query()->create([
            'scope_type' => 'area',
            'scope_id' => $area->id,
            'property_type' => 'apartment',
            'unit_type' => '3br',
            'transaction_type' => 'sale',
            'price_type' => 'sale_asking',
            'currency' => 'USD',
            'price' => '111111',
            'effective_date' => '2026-07-01',
            'period' => '2026-07',
            'publication_status' => 'published',
        ]);

        $internal = $this->import([$this->csvRow(['unit_type' => '3br'])]);

        $this->assertSame(0, $internal['written']);
        $this->assertSame('duplicate_slot', $internal['skipped_rows'][0]['reason']);
        $this->assertSame(2, PriceRecord::query()->count(), 'no third record for the same logical scope');
    }

    /* -------------------------------------- unchanged import guarantees */

    public function test_imported_rows_land_as_draft_with_values_and_provenance_intact(): void
    {
        $this->area('AR-001');
        $operator = User::factory()->create();

        $service = app(PriceImportService::class);
        $preview = $service->preview([$this->csvRow()], $this->knownScopes());
        $service->accept($preview['reference'], [$preview['rows'][0]['normalised']], (int) $operator->id);

        $record = PriceRecord::query()->sole();

        // The operator who accepted the batch is the row's author. This threw
        // MassAssignmentException until created_by joined the fillable list —
        // the accept path had never run outside production.
        $this->assertSame((int) $operator->id, (int) $record->created_by);
        $this->assertSame('draft', $record->publication_status);
        $this->assertSame('240000.0000', (string) $record->price);
        $this->assertSame('USD', $record->currency);
        $this->assertSame('sale_asking', $record->price_type->value);
        $this->assertSame('sale', $record->transaction_type);
        $this->assertSame('apartment', $record->property_type->value);
        $this->assertSame('2br', $record->unit_type);
        $this->assertSame(12, $record->sample_size);
        $this->assertSame('erbil bureau', $record->source);
        $this->assertSame('high', $record->confidence);
        $this->assertSame('imported fixture', $record->notes);
        $this->assertSame('2026-07-15', $record->effective_date->format('Y-m-d'));
        $this->assertSame('2026-07', $record->period);
    }

    public function test_rollback_still_removes_an_unpublished_batch(): void
    {
        $this->area('AR-001');
        $this->area('AR-002');

        $result = $this->import([
            $this->csvRow(),
            $this->csvRow(['scope_external_id' => 'AR-002', 'unit_type' => '4br']),
        ]);
        $this->assertSame(2, $result['written']);

        $rollback = app(PriceImportService::class)->rollback((int) $result['batch_id']);

        $this->assertTrue($rollback['rolled_back']);
        $this->assertSame(2, $rollback['deleted']);
        $this->assertSame(0, PriceRecord::query()->withTrashed()->count());
        $this->assertSame(
            'rolled_back',
            DB::table('market_import_batches')->where('id', $result['batch_id'])->value('status'),
        );

        // A rolled-back slot is free again — under BOTH identity paths.
        $again = $this->import([$this->csvRow()]);
        $this->assertSame(1, $again['written']);
    }

    /* ------------------------------------ the point of the whole thing */

    public function test_index_builder_sees_imported_published_records_through_scope_id(): void
    {
        $ankawa = $this->area('AR-ANKAWA');
        $other = $this->area('AR-OTHER');

        // Three unit types in Ankawa (three observations for one period is
        // the calculator's publication floor) and one record in the other
        // area that MUST NOT leak into Ankawa's index.
        $result = $this->import([
            $this->csvRow(['scope_external_id' => 'AR-ANKAWA', 'unit_type' => '1br', 'price' => '100000']),
            $this->csvRow(['scope_external_id' => 'AR-ANKAWA', 'unit_type' => '2br', 'price' => '110000']),
            $this->csvRow(['scope_external_id' => 'AR-ANKAWA', 'unit_type' => '3br', 'price' => '120000']),
            $this->csvRow(['scope_external_id' => 'AR-OTHER', 'unit_type' => '2br', 'price' => '999999']),
        ]);
        $this->assertSame(4, $result['written']);

        // Publish in bulk, without model events, so nothing rebuilds behind
        // the assertion's back and the builder is exercised explicitly.
        PriceRecord::query()->toBase()->update(['publication_status' => 'published']);

        $index = MarketIndex::query()->create([
            'key' => 'ankawa-sale-asking',
            'name_ckb' => 'پێوەری ئەنکاوە',
            'scope_type' => 'area',
            'scope_id' => $ankawa->id,
            'property_type' => 'apartment',
            'price_type' => 'sale_asking',
            'basis' => 'median',
            'currency' => 'USD',
            'methodology_version' => 'v1',
            'minimum_sample' => 3,
            'publication_status' => 'published',
        ]);

        $built = app(IndexBuilder::class)->buildPeriod($index, '2026-07');

        // Three observations seen, none leaked: the 999999 outlier-bait in
        // the other area would drag the median to 115000 if scope filtering
        // ever regressed to "anything with the right scope type".
        $this->assertSame(3, $built['sample_size']);
        $this->assertSame('110000.0000', $built['value']);

        // The other area's own index sees exactly its own record — proof the
        // filter is the scope_id, not the import batch or the period.
        $otherIndex = MarketIndex::query()->create([
            'key' => 'other-sale-asking',
            'name_ckb' => 'پێوەری تر',
            'scope_type' => 'area',
            'scope_id' => $other->id,
            'property_type' => 'apartment',
            'price_type' => 'sale_asking',
            'basis' => 'median',
            'currency' => 'USD',
            'methodology_version' => 'v1',
            'minimum_sample' => 3,
            'publication_status' => 'published',
        ]);

        $builtOther = app(IndexBuilder::class)->buildPeriod($otherIndex, '2026-07');

        $this->assertSame(1, $builtOther['sample_size']);
        $this->assertNull($builtOther['value'], 'one observation sits below the publication floor');
    }

    public function test_draft_imported_records_stay_invisible_to_the_index(): void
    {
        $ankawa = $this->area('AR-ANKAWA');

        $this->import([
            $this->csvRow(['scope_external_id' => 'AR-ANKAWA', 'unit_type' => '1br', 'price' => '100000']),
        ]);

        $index = MarketIndex::query()->create([
            'key' => 'ankawa-sale-asking',
            'name_ckb' => 'پێوەری ئەنکاوە',
            'scope_type' => 'area',
            'scope_id' => $ankawa->id,
            'price_type' => 'sale_asking',
            'basis' => 'median',
            'currency' => 'USD',
            'methodology_version' => 'v1',
            'minimum_sample' => 3,
            'publication_status' => 'published',
        ]);

        $built = app(IndexBuilder::class)->buildPeriod($index, '2026-07');

        // Correct scope_id does not shortcut review: draft stays out.
        $this->assertSame(0, $built['sample_size']);
    }
}
