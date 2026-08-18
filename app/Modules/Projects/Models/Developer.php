<?php

declare(strict_types=1);

namespace App\Modules\Projects\Models;

use App\Modules\Geography\Concerns\HasTrilingualNames;
use App\Modules\Projects\Enums\PublicationStatus;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Carbon;

/**
 * ---- generated model properties (scripts/generate-model-annotations.php)
 *
 * @property int $id
 * @property string|null $external_id
 * @property string $slug
 * @property string $name_ckb
 * @property string|null $name_ar
 * @property string|null $name_en
 * @property string|null $search_key
 * @property string|null $description_ckb
 * @property string|null $description_ar
 * @property string|null $description_en
 * @property string|null $website
 * @property string|null $logo_path
 * @property int|null $founded_year
 * @property string|null $country
 * @property bool $is_verified
 * @property Carbon|null $verified_at
 * @property string|null $source
 * @property PublicationStatus $publication_status
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property Carbon|null $deleted_at
 *
 * ---- end generated model properties
 */
final class Developer extends Model
{
    use HasTrilingualNames;
    use SoftDeletes;

    protected $table = 'developers';

    protected $fillable = [
        'external_id', 'slug', 'name_ckb', 'name_ar', 'name_en',
        'description_ckb', 'description_ar', 'description_en',
        'website', 'logo_path', 'founded_year', 'country',
        'is_verified', 'verified_at', 'source', 'publication_status',
    ];

    protected function casts(): array
    {
        return [
            'publication_status' => PublicationStatus::class,
            'is_verified' => 'boolean',
            'verified_at' => 'datetime',
            'founded_year' => 'integer',
        ];
    }

    /**
     * @return HasMany<Project, $this>
     */
    public function projects(): HasMany
    {
        return $this->hasMany(Project::class);
    }

    protected static function booted(): void
    {
        self::saving(fn (self $developer) => $developer->syncSearchKey());
    }
}
