<?php

declare(strict_types=1);

namespace App\Modules\Geography\Models;

use App\Modules\Geography\Concerns\HasTrilingualNames;
use App\Modules\Geography\Enums\PlaceCategoryKey;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;
use RuntimeException;

/**
 * A place category (spec 10.4).
 *
 * The table is authoritative because spec 10.4 requires an admin to add
 * categories without code. PlaceCategoryKey only guarantees stable keys for the
 * 31 shipped categories that later steps reference by name.
 *
 * ---- generated model properties (scripts/generate-model-annotations.php)
 *
 * @property int $id
 * @property string $key
 * @property int|null $parent_id
 * @property string $group
 * @property string $name_ckb
 * @property string|null $name_ar
 * @property string|null $name_en
 * @property string|null $icon
 * @property string|null $colour
 * @property int $default_radius_m
 * @property float $amenity_weight
 * @property bool $is_system
 * @property bool $is_active
 * @property int $sort_order
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 *
 * ---- end generated model properties
 */
final class PlaceCategory extends Model
{
    /*
     * THE SAME TRILINGUAL CONTRACT AS EVERY OTHER NAMED ENTITY.
     *
     * The table carries `name_ckb`, `name_ar` and `name_en`, and the public
     * place profile calls `$category->name()` to render the label — but this
     * model never used the trait that defines it, so every place profile and
     * every category-filtered directory page raised
     * `Call to undefined method PlaceCategory::name()` and returned a 500.
     * Adding the trait resolves the label through the documented ckb -> en -> ar
     * fallback rather than inventing a second naming rule here.
     */
    use HasTrilingualNames;

    protected $table = 'place_categories';

    protected $fillable = [
        'key', 'parent_id', 'group', 'name_ckb', 'name_ar', 'name_en',
        'icon', 'colour', 'default_radius_m', 'amenity_weight',
        'is_system', 'is_active', 'sort_order',
    ];

    protected function casts(): array
    {
        return [
            'is_system' => 'boolean',
            'is_active' => 'boolean',
            'default_radius_m' => 'integer',
            'amenity_weight' => 'float',
            'sort_order' => 'integer',
        ];
    }

    /**
     * @return HasMany<Place, $this>
     */
    public function places(): HasMany
    {
        return $this->hasMany(Place::class);
    }

    public function enum(): ?PlaceCategoryKey
    {
        return PlaceCategoryKey::tryFrom($this->key);
    }

    /** A system category may be deactivated but never deleted. */
    protected static function booted(): void
    {
        self::deleting(function (self $category): bool {
            if ($category->is_system) {
                throw new RuntimeException(
                    'System place categories cannot be deleted. Deactivate the category instead.',
                );
            }

            return true;
        });
    }
}
