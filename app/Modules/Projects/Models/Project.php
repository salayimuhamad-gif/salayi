<?php

declare(strict_types=1);

namespace App\Modules\Projects\Models;

use App\Modules\Geography\Concerns\HasCoordinates;
use App\Modules\Geography\Concerns\HasTrilingualNames;
use App\Modules\Geography\Models\Area;
use App\Modules\Identity\Models\User;
use App\Modules\Projects\Enums\ConstructionStatus;
use App\Modules\Projects\Enums\DeliveryStatus;
use App\Modules\Projects\Enums\ProjectType;
use App\Modules\Projects\Enums\PublicationStatus;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Carbon;
use RuntimeException;

/**
 * A development project (spec 12.1).
 *
 * @property PublicationStatus $publication_status
 *
 * ---- generated model properties (scripts/generate-model-annotations.php)
 * @property int $id
 * @property string|null $external_id
 * @property string $slug
 * @property int|null $developer_id
 * @property int|null $area_id
 * @property string $name_ckb
 * @property string|null $name_ar
 * @property string|null $name_en
 * @property array<string, mixed>|null $aliases
 * @property string|null $search_key
 * @property string|null $description_ckb
 * @property string|null $description_ar
 * @property string|null $description_en
 * @property ProjectType $project_type
 * @property ConstructionStatus $construction_status
 * @property DeliveryStatus $delivery_status
 * @property int|null $completion_percent
 * @property string|null $latitude
 * @property string|null $longitude
 * @property string|null $boundary_wkt
 * @property string|null $bbox_min_lat
 * @property string|null $bbox_min_lng
 * @property string|null $bbox_max_lat
 * @property string|null $bbox_max_lng
 * @property Carbon|null $launch_date
 * @property Carbon|null $expected_delivery
 * @property Carbon|null $actual_delivery
 * @property int|null $land_area_sqm
 * @property int|null $building_count
 * @property int|null $unit_count
 * @property array<string, mixed>|null $facilities
 * @property array<string, mixed>|null $services
 * @property array<string, mixed>|null $ownership_rules
 * @property array<string, mixed>|null $payment_plans
 * @property string|null $maintenance_fee_monthly
 * @property string|null $maintenance_fee_currency
 * @property string|null $official_url
 * @property array<string, mixed>|null $video_urls
 * @property array<string, mixed>|null $virtual_tour_urls
 * @property int|null $quality_score
 * @property int|null $completeness_score
 * @property int|null $investment_score
 * @property int|null $family_suitability_score
 * @property int|null $nearby_amenity_score
 * @property string|null $demand_level
 * @property string|null $source
 * @property array<string, mixed>|null $data_sources
 * @property string $confidence
 * @property Carbon|null $verified_at
 * @property PublicationStatus $publication_status
 * @property Carbon|null $published_at
 * @property string|null $wizard_step
 * @property array<string, mixed>|null $wizard_state
 * @property int|null $created_by
 * @property int|null $reviewed_by
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property Carbon|null $deleted_at
 * @property bool $area_is_manual
 * @property Carbon|null $area_assigned_at
 * @property string|null $area_match_type
 * @property bool $area_unresolved
 *
 * ---- end generated model properties
 */
final class Project extends Model
{
    use HasCoordinates;
    use HasTrilingualNames;
    use SoftDeletes;

    protected $table = 'projects';

    protected $fillable = [
        'external_id', 'slug', 'developer_id', 'area_id',
        'name_ckb', 'name_ar', 'name_en', 'aliases',
        'description_ckb', 'description_ar', 'description_en',
        'project_type', 'construction_status', 'delivery_status', 'completion_percent',
        'latitude', 'longitude', 'boundary_wkt',
        'launch_date', 'expected_delivery', 'actual_delivery',
        'land_area_sqm', 'building_count', 'unit_count',
        'facilities', 'services', 'ownership_rules', 'payment_plans',
        'maintenance_fee_monthly', 'maintenance_fee_currency',
        'official_url', 'video_urls', 'virtual_tour_urls',
        'source', 'data_sources', 'confidence', 'verified_at',
        'publication_status', 'wizard_step', 'wizard_state',
        /*
         * Wizard-persisted fields. Absent from $fillable, Project::create()
         * silently DROPPED every one of them — the wizard appeared to work,
         * the project was created, and the creator, the area assignment and
         * its provenance simply were not there. Mass-assignment protection
         * failing closed is correct; failing closed without saying so is how
         * data loss looks like success.
         */
        'created_by', 'area_is_manual', 'area_assigned_at', 'area_match_type',
        'area_unresolved',
    ];

    protected function casts(): array
    {
        return [
            'project_type' => ProjectType::class,
            'construction_status' => ConstructionStatus::class,
            'delivery_status' => DeliveryStatus::class,
            'publication_status' => PublicationStatus::class,
            'aliases' => 'array',
            // Area assignment provenance (File two §4): manual links are
            // protected from the automatic resolver.
            'area_is_manual' => 'boolean',
            'area_unresolved' => 'boolean',
            'area_assigned_at' => 'datetime',
            'facilities' => 'array',
            'services' => 'array',
            'ownership_rules' => 'array',
            'payment_plans' => 'array',
            'video_urls' => 'array',
            'virtual_tour_urls' => 'array',
            'data_sources' => 'array',
            'wizard_state' => 'array',
            'latitude' => 'decimal:7',
            'longitude' => 'decimal:7',
            'launch_date' => 'date',
            'expected_delivery' => 'date',
            'actual_delivery' => 'date',
            'verified_at' => 'datetime',
            'published_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<Developer, $this>
     */
    public function developer(): BelongsTo
    {
        return $this->belongsTo(Developer::class);
    }

    /**
     * Who entered this project (§5 provenance).
     *
     * @return BelongsTo<User, $this>
     */
    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    /**
     * @return HasMany<ProjectPrice, $this>
     */
    public function prices(): HasMany
    {
        return $this->hasMany(ProjectPrice::class);
    }

    /**
     * @return BelongsTo<Area, $this>
     */
    public function area(): BelongsTo
    {
        return $this->belongsTo(Area::class);
    }

    /**
     * @return HasMany<ProjectPhase, $this>
     */
    public function phases(): HasMany
    {
        return $this->hasMany(ProjectPhase::class)->orderBy('sequence');
    }

    /**
     * @return HasMany<ProjectRating, $this>
     */
    public function ratings(): HasMany
    {
        return $this->hasMany(ProjectRating::class);
    }

    /**
     * @return HasMany<ProjectNearbyPlace, $this>
     */
    public function nearbyPlaces(): HasMany
    {
        return $this->hasMany(ProjectNearbyPlace::class)->orderBy('rank');
    }

    /**
     * @param  Builder<Project>  $query
     * @return Builder<Project>
     */
    public function scopePublished(Builder $query): Builder
    {
        return $query->where('publication_status', PublicationStatus::Published->value);
    }

    /**
     * Spec 37.2: "Polygon or coordinate is required."
     *
     * Checked at the publish transition rather than on every save, because the
     * 14-step wizard legitimately saves a draft before the location step.
     */
    public function hasRequiredGeometry(): bool
    {
        return $this->coordinates() !== null || $this->hasBoundary();
    }

    /**
     * @return array{ok: bool, blockers: list<string>}
     */
    public function publicationReadiness(): array
    {
        $blockers = [];

        if (! $this->hasRequiredGeometry()) {
            $blockers[] = 'geometry_missing';
        }

        if (blank($this->name_ckb)) {
            // Sorani is the authoring language; publishing without it would
            // leave the primary audience reading a fallback.
            $blockers[] = 'sorani_name_missing';
        }

        if (blank($this->source)) {
            $blockers[] = 'source_missing';
        }

        if ($this->verified_at === null) {
            $blockers[] = 'never_verified';
        }

        if ($this->area_id === null) {
            $blockers[] = 'area_missing';
        }

        return ['ok' => $blockers === [], 'blockers' => $blockers];
    }

    /**
     * Move through the publication workflow, refusing illegal transitions and
     * refusing to publish an incomplete record.
     */
    public function transitionTo(PublicationStatus $target): void
    {
        if (! $this->publication_status->canTransitionTo($target)) {
            throw new RuntimeException(sprintf(
                'Cannot move a project from "%s" to "%s".',
                $this->publication_status->value,
                $target->value,
            ));
        }

        if ($target === PublicationStatus::Published) {
            $readiness = $this->publicationReadiness();

            if (! $readiness['ok']) {
                throw new RuntimeException(
                    'This project cannot be published yet: '.implode(', ', $readiness['blockers']).'.',
                );
            }

            $this->published_at ??= now();
        }

        $this->publication_status = $target;
    }

    protected static function booted(): void
    {
        self::saving(function (self $project): void {
            $project->syncSearchKey();
            $project->syncDerivedGeometry();

            // Never invent a completion figure for a stalled project.
            if ($project->completion_percent === null) {
                $project->completion_percent = $project->construction_status->impliedCompletionPercent();
            }
        });
    }
}
