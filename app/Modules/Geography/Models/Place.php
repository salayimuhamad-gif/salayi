<?php

declare(strict_types=1);

namespace App\Modules\Geography\Models;

use App\Modules\Geography\Concerns\HasCoordinates;
use App\Modules\Geography\Concerns\HasTrilingualNames;
use App\Modules\Projects\Enums\PublicationStatus;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Crypt;
use Throwable;

/**
 * A point of interest (spec 11).
 *
 * Coordinates are NOT nullable here, unlike on Project: a place with no
 * location has no purpose, whereas a project draft legitimately exists before
 * its location is known.
 *
 * ---- generated model properties (scripts/generate-model-annotations.php)
 *
 * @property int $id
 * @property string|null $external_id
 * @property int $place_category_id
 * @property string|null $subcategory
 * @property int|null $area_id
 * @property string $name_ckb
 * @property string|null $name_ar
 * @property string|null $name_en
 * @property array<string, mixed>|null $aliases
 * @property string|null $search_key
 * @property string $latitude
 * @property string $longitude
 * @property string|null $address_ckb
 * @property string|null $address_ar
 * @property string|null $address_en
 * @property string|null $phone_encrypted
 * @property string|null $website
 * @property array<string, mixed>|null $opening_hours
 * @property string|null $accessibility_notes
 * @property string $operational_status
 * @property bool $is_public
 * @property array<string, mixed>|null $tags
 * @property string|null $source
 * @property string|null $source_url
 * @property Carbon|null $verified_at
 * @property string $confidence
 * @property int|null $completeness_score
 * @property int|null $source_quality_score
 * @property int|null $location_accuracy_score
 * @property int|null $freshness_score
 * @property int|null $translation_score
 * @property int|null $quality_score
 * @property string $verification_status
 * @property string|null $duplicate_group
 * @property bool $is_duplicate_primary
 * @property PublicationStatus $publication_status
 * @property int|null $created_by
 * @property int|null $reviewed_by
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property Carbon|null $deleted_at
 * @property bool $area_is_manual
 * @property Carbon|null $area_assigned_at
 * @property string|null $area_match_type
 * @property string|null $slug
 *
 * ---- end generated model properties
 */
final class Place extends Model
{
    use HasCoordinates;
    use HasTrilingualNames;
    use SoftDeletes;

    protected $table = 'places';

    protected $fillable = [
        'external_id', 'slug', 'place_category_id', 'subcategory', 'area_id',
        'name_ckb', 'name_ar', 'name_en', 'aliases',
        'latitude', 'longitude',
        'address_ckb', 'address_ar', 'address_en',
        'website', 'opening_hours', 'accessibility_notes',
        'operational_status', 'is_public', 'tags',
        'source', 'source_url', 'verified_at', 'confidence',
        'publication_status', 'duplicate_group', 'is_duplicate_primary',
        // Same authorship attribution as Area: PlaceController::store sets it,
        // so it must be assignable — the admin create door threw without it.
        'created_by',
    ];

    /** A place phone is frequently a personal mobile (spec 32.2). */
    protected $hidden = ['phone_encrypted'];

    protected function casts(): array
    {
        return [
            'area_is_manual' => 'boolean',
            'area_assigned_at' => 'datetime',
            'publication_status' => PublicationStatus::class,
            'aliases' => 'array',
            'tags' => 'array',
            'opening_hours' => 'array',
            'latitude' => 'decimal:7',
            'longitude' => 'decimal:7',
            'is_public' => 'boolean',
            'is_duplicate_primary' => 'boolean',
            'verified_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<PlaceCategory, $this>
     */
    public function category(): BelongsTo
    {
        return $this->belongsTo(PlaceCategory::class, 'place_category_id');
    }

    /**
     * @return BelongsTo<Area, $this>
     */
    public function area(): BelongsTo
    {
        return $this->belongsTo(Area::class);
    }

    /**
     * @param  Builder<Place>  $query
     * @return Builder<Place>
     */
    public function scopePublished(Builder $query): Builder
    {
        return $query
            ->where('publication_status', PublicationStatus::Published->value)
            ->where('is_duplicate_primary', true);
    }

    /**
     * @param  Builder<Place>  $query
     * @return Builder<Place>
     */
    public function scopeOperating(Builder $query): Builder
    {
        return $query->where('operational_status', 'operating');
    }

    public function setPhone(?string $value): void
    {
        $this->phone_encrypted = $value === null || $value === '' ? null : Crypt::encryptString($value);
    }

    public function phone(): ?string
    {
        if ($this->phone_encrypted === null) {
            return null;
        }

        try {
            return Crypt::decryptString($this->phone_encrypted);
        } catch (Throwable) {
            return null;
        }
    }

    protected static function booted(): void
    {
        self::saving(function (self $place): void {
            $place->syncSearchKey();
        });
    }
}
