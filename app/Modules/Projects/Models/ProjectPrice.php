<?php

declare(strict_types=1);

namespace App\Modules\Projects\Models;

use App\Modules\Market\Enums\PriceType;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * A price range advertised for a project (spec 15.1).
 *
 * Deliberately NOT a `PriceRecord`. That table is market-observation schema —
 * every row feeds an index whose confidence depends on its scope, transaction
 * type and effective date. A developer's advertised range for their own
 * project is a claim, not an observation; recording it there would inject
 * asking prices into indices as sampled data, which is exactly the
 * asking/verified contamination the product exists to measure rather than
 * commit.
 *
 * @property PriceType $price_type
 *
 * ---- generated model properties (scripts/generate-model-annotations.php)
 * @property int $id
 * @property int $project_id
 * @property string|null $price_from
 * @property string|null $price_to
 * @property string $currency
 * @property PriceType $price_type
 * @property string|null $period
 * @property Carbon|null $effective_date
 * @property string|null $source
 * @property string $confidence
 * @property int|null $created_by
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 *
 * ---- end generated model properties
 */
final class ProjectPrice extends Model
{
    protected $table = 'project_prices';

    protected $fillable = [
        'project_id', 'price_from', 'price_to', 'currency',
        'price_type', 'period', 'effective_date',
        'source', 'confidence', 'created_by',
    ];

    protected function casts(): array
    {
        return [
            'price_type' => PriceType::class,
            'price_from' => 'decimal:2',
            'price_to' => 'decimal:2',
            'effective_date' => 'date',
        ];
    }

    /**
     * @return BelongsTo<Project, $this>
     */
    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }

    /**
     * Whether this figure needs an asking-price qualifier wherever it appears.
     *
     * §15.3. An asking range rendered without one is indistinguishable from a
     * transaction price, and the gap between the two is the thing this
     * product measures.
     */
    public function requiresQualifier(): bool
    {
        return in_array($this->price_type, [PriceType::SaleAsking, PriceType::RentAsking], true);
    }
}
