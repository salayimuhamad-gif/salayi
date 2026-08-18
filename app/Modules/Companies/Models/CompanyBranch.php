<?php

declare(strict_types=1);

namespace App\Modules\Companies\Models;

use App\Modules\Geography\Concerns\HasCoordinates;
use App\Modules\Geography\Concerns\HasTrilingualNames;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Crypt;
use Throwable;

/**
 * A company branch with its own contact data (spec 18.2, 37.4).
 *
 * ---- generated model properties (scripts/generate-model-annotations.php)
 *
 * @property int $id
 * @property int $company_id
 * @property int|null $area_id
 * @property string $name_ckb
 * @property string|null $name_ar
 * @property string|null $name_en
 * @property string|null $address_ckb
 * @property string|null $address_ar
 * @property string|null $address_en
 * @property string|null $latitude
 * @property string|null $longitude
 * @property string|null $phone_encrypted
 * @property string|null $whatsapp_encrypted
 * @property string|null $email
 * @property array<string, mixed>|null $opening_hours
 * @property array<string, mixed>|null $languages
 * @property int|null $manager_user_id
 * @property bool $is_primary
 * @property bool $is_active
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property Carbon|null $deleted_at
 * @property string|null $search_key
 *
 * ---- end generated model properties
 */
final class CompanyBranch extends Model
{
    use HasCoordinates;
    use HasTrilingualNames;
    use SoftDeletes;

    protected $table = 'company_branches';

    protected $fillable = [
        'company_id', 'area_id', 'name_ckb', 'name_ar', 'name_en',
        'address_ckb', 'address_ar', 'address_en', 'latitude', 'longitude',
        'email', 'opening_hours', 'languages', 'manager_user_id',
        'is_primary', 'is_active',
    ];

    protected $hidden = ['phone_encrypted', 'whatsapp_encrypted'];

    protected function casts(): array
    {
        return [
            'opening_hours' => 'array',
            'languages' => 'array',
            'latitude' => 'decimal:7',
            'longitude' => 'decimal:7',
            'is_primary' => 'boolean',
            'is_active' => 'boolean',
        ];
    }

    /**
     * @return BelongsTo<Company, $this>
     */
    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
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
        self::saving(fn (self $branch) => $branch->syncSearchKey());
    }

    /**
     * The branch address in the active locale, falling back to Sorani.
     *
     * Address is trilingual per column rather than through HasTrilingualNames,
     * which resolves `name_*` and `description_*` only. Falling back to ckb
     * rather than English is the house rule: a branch that supplied one
     * language supplied that one.
     */
    public function addressForLocale(?string $locale = null): ?string
    {
        $locale = $locale ?? app()->getLocale();

        return match ($locale) {
            'ar' => $this->address_ar ?: $this->address_ckb,
            'en' => $this->address_en ?: $this->address_ckb,
            default => $this->address_ckb,
        };
    }
}
