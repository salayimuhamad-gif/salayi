<?php

declare(strict_types=1);

namespace App\Modules\Market\Models;

use App\Modules\Geography\Concerns\HasTrilingualNames;
use App\Modules\Market\Enums\PriceType;
use App\Modules\Market\Enums\PropertyType;
use App\Modules\Market\Enums\ScopeType;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Carbon;
use RuntimeException;

/**
 * An index definition (spec 15.2).
 *
 * An index declares exactly ONE price_type. That is what makes a blended index
 * unrepresentable rather than merely discouraged — there is no row shape that
 * could hold an asking-and-verified average even if application code tried.
 *
 * ---- generated model properties (scripts/generate-model-annotations.php)
 *
 * @property int $id
 * @property string $key
 * @property string $name_ckb
 * @property string|null $name_ar
 * @property string|null $name_en
 * @property ScopeType $scope_type
 * @property int|null $scope_id
 * @property PropertyType|null $property_type
 * @property PriceType $price_type
 * @property string $basis
 * @property string $currency
 * @property string $methodology_version
 * @property string|null $methodology_ckb
 * @property string|null $methodology_ar
 * @property string|null $methodology_en
 * @property array<string, mixed>|null $data_source_rules
 * @property array<string, mixed>|null $weights
 * @property int $minimum_sample
 * @property array<string, mixed>|null $outlier_rules
 * @property array<string, mixed>|null $confidence_rules
 * @property string $publication_status
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property Carbon|null $deleted_at
 * @property string|null $search_key
 *
 * ---- end generated model properties
 */
final class MarketIndex extends Model
{
    use HasTrilingualNames;
    use SoftDeletes;

    protected $table = 'market_indices';

    protected $fillable = [
        'key', 'name_ckb', 'name_ar', 'name_en',
        'scope_type', 'scope_id', 'property_type', 'price_type',
        'basis', 'currency', 'methodology_version',
        'methodology_ckb', 'methodology_ar', 'methodology_en',
        'data_source_rules', 'weights', 'minimum_sample',
        'outlier_rules', 'confidence_rules', 'publication_status',
    ];

    protected function casts(): array
    {
        return [
            'scope_type' => ScopeType::class,
            'property_type' => PropertyType::class,
            'price_type' => PriceType::class,
            'data_source_rules' => 'array',
            'weights' => 'array',
            'outlier_rules' => 'array',
            'confidence_rules' => 'array',
            'minimum_sample' => 'integer',
        ];
    }

    /**
     * @return HasMany<MarketIndexValue, $this>
     */
    public function values(): HasMany
    {
        return $this->hasMany(MarketIndexValue::class)->orderByDesc('effective_date');
    }

    /**
     * Spec 15.3: a public index whose price type is not verified transaction
     * evidence must carry a qualifier. An "Erbil Apartment Index" built from
     * asking prices reads as a market rate unless it says otherwise.
     */
    public function requiresPublicQualifier(): bool
    {
        return $this->price_type->requiresPublicQualifier();
    }

    protected static function booted(): void
    {
        self::saving(function (self $index): void {
            $index->syncSearchKey();

            // Changing the price type of a published index would silently
            // redefine every historical value under it.
            if ($index->exists && $index->isDirty('price_type') && $index->publication_status === 'published') {
                throw new RuntimeException(
                    'The price type of a published index cannot be changed. '.
                    'Create a new index with a new methodology version instead.',
                );
            }
        });
    }
}
