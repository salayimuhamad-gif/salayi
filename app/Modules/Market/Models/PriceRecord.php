<?php

declare(strict_types=1);

namespace App\Modules\Market\Models;

use App\Modules\Core\ValueObjects\Decimal;
use App\Modules\Market\Enums\PriceType;
use App\Modules\Market\Enums\PropertyType;
use App\Modules\Market\Enums\ScopeType;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Carbon;

/**
 * One price observation (spec 14.1, Appendix B).
 *
 * Money is read back through Decimal, never as a float. The `decimal:4` cast
 * gives an exact string; `priceValue()` wraps it so callers cannot accidentally
 * do arithmetic on the raw attribute.
 *
 * ---- generated model properties (scripts/generate-model-annotations.php)
 *
 * @property int $id
 * @property string|null $record_id
 * @property ScopeType $scope_type
 * @property int|null $scope_id
 * @property string|null $scope_external_id
 * @property PropertyType $property_type
 * @property string|null $unit_type
 * @property string $transaction_type
 * @property PriceType $price_type
 * @property string $currency
 * @property string|null $price
 * @property string|null $price_per_sqm
 * @property string|null $minimum
 * @property string|null $maximum
 * @property string|null $median
 * @property int|null $sample_size
 * @property int|null $source_count
 * @property Carbon $effective_date
 * @property Carbon|null $published_date
 * @property string $period
 * @property string|null $source
 * @property string|null $source_url
 * @property string $confidence
 * @property string|null $notes
 * @property bool $is_outlier
 * @property string|null $outlier_reason
 * @property int|null $import_batch_id
 * @property string $publication_status
 * @property int|null $created_by
 * @property int|null $reviewed_by
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property Carbon|null $deleted_at
 *
 * ---- end generated model properties
 */
final class PriceRecord extends Model
{
    use SoftDeletes;

    protected $table = 'price_records';

    protected $fillable = [
        'record_id', 'scope_type', 'scope_id', 'scope_external_id',
        'property_type', 'unit_type', 'transaction_type', 'price_type',
        'currency', 'price', 'price_per_sqm', 'minimum', 'maximum', 'median',
        'sample_size', 'source_count', 'effective_date', 'published_date', 'period',
        'source', 'source_url', 'confidence', 'notes',
        'is_outlier', 'outlier_reason', 'import_batch_id', 'publication_status',
        // PriceImportService::accept() attributes the row to the operator who
        // accepted it. Absent from this list, every accept threw
        // MassAssignmentException outside production (where discarding is
        // strict) and silently dropped the attribution in production — the
        // same gap Area::$fillable documents for 'created_by'. Nothing
        // exercised this door until PriceImportScopeIntegrityTest did.
        'created_by',
    ];

    protected function casts(): array
    {
        return [
            'scope_type' => ScopeType::class,
            'property_type' => PropertyType::class,
            'price_type' => PriceType::class,
            'price' => 'decimal:4',
            'price_per_sqm' => 'decimal:4',
            'minimum' => 'decimal:4',
            'maximum' => 'decimal:4',
            'median' => 'decimal:4',
            'sample_size' => 'integer',
            'source_count' => 'integer',
            'is_outlier' => 'boolean',
            'effective_date' => 'date',
            'published_date' => 'date',
        ];
    }

    public function priceValue(): ?Decimal
    {
        return $this->price === null ? null : Decimal::of((string) $this->price, 4);
    }

    public function pricePerSqmValue(): ?Decimal
    {
        return $this->price_per_sqm === null ? null : Decimal::of((string) $this->price_per_sqm, 4);
    }

    /**
     * Restrict a query to one provenance family and transaction basis.
     *
     * Every aggregation query must go through this. Spec 14.1 is a guarantee
     * about what reaches a user, and a raw `where('price_type', ...)` scattered
     * across call sites is how that guarantee erodes.
     *
     * @param  Builder<PriceRecord>  $query
     * @return Builder<PriceRecord>
     */
    public function scopeOfPriceType(Builder $query, PriceType $type): Builder
    {
        return $query->where('price_type', $type->value);
    }

    /**
     * Only verified transactions — the strongest evidence (spec 14.1).
     *
     * @param  Builder<PriceRecord>  $query
     * @return Builder<PriceRecord>
     */
    public function scopeTransactionEvidence(Builder $query): Builder
    {
        return $query->whereIn('price_type', array_map(
            static fn (PriceType $t): string => $t->value,
            PriceType::inFamily('verified'),
        ));
    }

    /**
     * @param  Builder<PriceRecord>  $query
     * @return Builder<PriceRecord>
     */
    public function scopePublished(Builder $query): Builder
    {
        return $query->where('publication_status', 'published')->where('is_outlier', false);
    }

    /**
     * @param  Builder<PriceRecord>  $query
     * @return Builder<PriceRecord>
     */
    public function scopeForPeriod(Builder $query, string $period): Builder
    {
        return $query->where('period', $period);
    }
}
