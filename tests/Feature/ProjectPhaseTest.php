<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Modules\Projects\Enums\ConstructionStatus;
use App\Modules\Projects\Enums\DeliveryStatus;
use App\Modules\Projects\Enums\PublicationStatus;
use App\Modules\Projects\Models\Project;
use App\Modules\Projects\Models\ProjectPhase;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * `Project::phases()` must actually work.
 *
 * The relation called `hasMany(ProjectPhase::class)` while no such class
 * existed anywhere in the tree, so every call was a fatal "Class not found".
 * Nothing in the suite exercised it, which is why a table with a full schema
 * and a foreign key pointing at it went unnoticed for the whole roadmap.
 */
final class ProjectPhaseTest extends TestCase
{
    use RefreshDatabase;

    private function project(): Project
    {
        return Project::query()->create([
            'slug' => 'phased-'.uniqid(),
            'name_ckb' => 'پرۆژە',
            'project_type' => 'residential',
            'construction_status' => ConstructionStatus::Planning->value,
            'delivery_status' => DeliveryStatus::cases()[0]->value,
            'publication_status' => PublicationStatus::Draft->value,
        ]);
    }

    public function test_a_project_can_load_its_phases(): void
    {
        $project = $this->project();

        ProjectPhase::query()->create([
            'project_id' => $project->id,
            'name_ckb' => 'قۆناغی یەکەم',
            'sequence' => 1,
            'construction_status' => ConstructionStatus::Planning->value,
            'delivery_status' => DeliveryStatus::cases()[0]->value,
        ]);

        $this->assertCount(1, $project->phases()->get());
    }

    /** Phases are ordered by sequence, not by insertion. */
    public function test_phases_come_back_in_sequence_order(): void
    {
        $project = $this->project();

        foreach ([3, 1, 2] as $sequence) {
            ProjectPhase::query()->create([
                'project_id' => $project->id,
                'name_ckb' => 'قۆناغ '.$sequence,
                'sequence' => $sequence,
                'construction_status' => ConstructionStatus::Planning->value,
                'delivery_status' => DeliveryStatus::cases()[0]->value,
            ]);
        }

        $this->assertSame([1, 2, 3], $project->phases()->get()->pluck('sequence')->all());
    }

    /** A phase belongs back to its project. */
    public function test_a_phase_belongs_to_its_project(): void
    {
        $project = $this->project();

        $phase = ProjectPhase::query()->create([
            'project_id' => $project->id,
            'name_ckb' => 'قۆناغ',
            'sequence' => 1,
            'construction_status' => ConstructionStatus::Planning->value,
            'delivery_status' => DeliveryStatus::cases()[0]->value,
        ]);

        $this->assertSame($project->id, $phase->project->id);
    }

    /** The trilingual fallback matches every other named entity. */
    public function test_the_phase_name_falls_back_through_the_locales(): void
    {
        $phase = new ProjectPhase(['name_ckb' => 'قۆناغ', 'name_en' => 'Phase one']);

        $this->assertSame('قۆناغ', $phase->name('ckb'));
        $this->assertSame('Phase one', $phase->name('en'));
        // No Arabic name: it must still render rather than showing nothing.
        $this->assertSame('قۆناغ', $phase->name('ar'));
    }
}
