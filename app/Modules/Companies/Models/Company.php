<?php

declare(strict_types=1);

namespace App\Modules\Companies\Models;

use App\Modules\Geography\Concerns\HasTrilingualNames;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Carbon;

/**
 * A company account (spec 18.1).
 *
 * Spec 37.4: "Company requires approval." The published scope requires
 * verification, so an unapproved company cannot appear publicly regardless of
 * its publication_status.
 *
 * ---- generated model properties (scripts/generate-model-annotations.php)
 *
 * @property int $id
 * @property string $slug
 * @property string|null $external_id
 * @property string $legal_name
 * @property string|null $brand_name
 * @property string|null $name_ckb
 * @property string|null $name_ar
 * @property string|null $name_en
 * @property string|null $search_key
 * @property string|null $description_ckb
 * @property string|null $description_ar
 * @property string|null $description_en
 * @property array<string, mixed>|null $specialties
 * @property array<string, mixed>|null $languages
 * @property array<string, mixed>|null $operating_area_ids
 * @property string|null $logo_path
 * @property string|null $website
 * @property string|null $email
 * @property string|null $phones_encrypted
 * @property string|null $whatsapp_encrypted
 * @property string|null $telegram_username
 * @property array<string, mixed>|null $social_links
 * @property string|null $license_number
 * @property string|null $license_authority
 * @property Carbon|null $license_expires_at
 * @property array<string, mixed>|null $license_evidence
 * @property string $verification_status
 * @property Carbon|null $verified_at
 * @property int|null $verified_by
 * @property string|null $verification_notes
 * @property string|null $subscription_plan
 * @property Carbon|null $subscription_expires_at
 * @property bool $advertising_enabled
 * @property int|null $median_response_minutes
 * @property string $publication_status
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property Carbon|null $deleted_at
 *
 * ---- end generated model properties
 */
final class Company extends Model
{
    use HasTrilingualNames;
    use SoftDeletes;

    protected $table = 'companies';

    protected $fillable = [
        'slug', 'external_id', 'legal_name', 'brand_name',
        'name_ckb', 'name_ar', 'name_en',
        'description_ckb', 'description_ar', 'description_en',
        'specialties', 'languages', 'operating_area_ids',
        'logo_path', 'website', 'email', 'telegram_username', 'social_links',
        'license_number', 'license_authority', 'license_expires_at', 'license_evidence',
        'verification_status', 'subscription_plan', 'subscription_expires_at',
        'advertising_enabled', 'publication_status',
    ];

    protected $hidden = ['phones_encrypted', 'whatsapp_encrypted'];

    protected function casts(): array
    {
        return [
            'specialties' => 'array',
            'languages' => 'array',
            'operating_area_ids' => 'array',
            'social_links' => 'array',
            'license_evidence' => 'array',
            'advertising_enabled' => 'boolean',
            'license_expires_at' => 'date',
            'verified_at' => 'datetime',
            'subscription_expires_at' => 'datetime',
        ];
    }

    /**
     * @return HasMany<CompanyBranch, $this>
     */
    public function branches(): HasMany
    {
        return $this->hasMany(CompanyBranch::class);
    }

    /**
     * @return HasMany<CompanyProjectAssociation, $this>
     */
    public function projectAssociations(): HasMany
    {
        return $this->hasMany(CompanyProjectAssociation::class);
    }

    public function isVerified(): bool
    {
        return $this->verification_status === 'verified';
    }

    /**
     * Both conditions, always. Approval is not implied by publication.
     *
     * @param  Builder<Company>  $query
     * @return Builder<Company>
     */
    public function scopePublished(Builder $query): Builder
    {
        return $query->where('verification_status', 'verified')
            ->where('publication_status', 'published');
    }

    protected static function booted(): void
    {
        self::saving(fn (self $company) => $company->syncSearchKey());
    }
}
