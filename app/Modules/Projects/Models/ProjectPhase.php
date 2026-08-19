<?php

declare(strict_types=1);

namespace App\Modules\Projects\Models;

use App\Modules\Projects\Enums\ConstructionStatus;
use App\Modules\Projects\Enums\DeliveryStatus;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * One delivery phase of a project (spec 14.2).
 *
 * THIS MODEL WAS MISSING. `Project::phases()` has always called
 * `hasMany(ProjectPhase::class)`, and the class existed nowhere in the tree, so
 * every call raised `Class "App\Modules\Projects\Models\ProjectPhase" not
 * found` — a fatal, not a degraded result. The `project_phases` table has been
 * created and populated by 2026_02_01_000200 since the beginning, and
 * `project_unit_types.project_phase_id` references it, so the schema and the
 * relation both expected this class to exist. Static analysis found it because
 * nothing in the suite had ever called the relation.
 *
 * The shape below is taken from the migration, not invented: phases are
 * ordered by `sequence`, which is unique per project, and each carries its own
 * construction and delivery status because a project delivers in stages and a
 * single project-level status cannot describe that.
 *
 * ---- generated model properties (scripts/generate-model-annotations.php)
 *
 * @property int $id
 * @property int $project_id
 * @property string $name_ckb
 * @property string|null $name_ar
 * @property string|null $name_en
 * @property int $sequence
 * @property ConstructionStatus $construction_status
 * @property DeliveryStatus $delivery_status
 * @property int|null $completion_percent
 * @property Carbon|null $expected_delivery
 * @property Carbon|null $actual_delivery
 * @property int|null $unit_count
 * @property string|null $boundary_wkt
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 *
 * ---- end generated model properties
 */
final class ProjectPhase extends Model
{
    protected $table = 'project_phases';

    protected $fillable = [
        'project_id',
        'name_ckb', 'name_ar', 'name_en',
        'sequence',
        'construction_status', 'delivery_status',
        'completion_percent',
        'expected_delivery', 'actual_delivery',
        'unit_count',
        'boundary_wkt',
    ];

    /**
     * @return BelongsTo<Project, $this>
     */
    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }

    /**
     * The phase name in the reader's language, Sorani first.
     *
     * The same ckb -> en -> ar fallback every other named entity uses: a phase
     * with no Arabic name must still render, and falling back to the id would
     * show a number to a human.
     */
    public function name(?string $locale = null): string
    {
        $locale ??= app()->getLocale();

        $candidates = match ($locale) {
            'ar' => [$this->name_ar, $this->name_ckb, $this->name_en],
            'en' => [$this->name_en, $this->name_ckb, $this->name_ar],
            default => [$this->name_ckb, $this->name_en, $this->name_ar],
        };

        foreach ($candidates as $candidate) {
            if (is_string($candidate) && $candidate !== '') {
                return $candidate;
            }
        }

        return '';
    }

    protected function casts(): array
    {
        return [
            'project_id' => 'integer',
            'sequence' => 'integer',
            'construction_status' => ConstructionStatus::class,
            'delivery_status' => DeliveryStatus::class,
            'completion_percent' => 'integer',
            'expected_delivery' => 'date',
            'actual_delivery' => 'date',
            'unit_count' => 'integer',
        ];
    }
}
