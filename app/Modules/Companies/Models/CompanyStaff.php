<?php

declare(strict_types=1);

namespace App\Modules\Companies\Models;

use App\Modules\Identity\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * A person's membership of one company (File one §8.3, §8.4).
 *
 * The permission flags live on the MEMBERSHIP, not on the user, and the table
 * was built that way from the start. The same broker legitimately works for two
 * agencies, and rights earned at one must not travel to the other — a
 * user-level `may_view_lead_contacts` would do exactly that, and the person
 * would never know they had been over-granted.
 *
 * Contact details are the sharpest case. `may_view_lead_contacts` is separate
 * from `may_view_leads` because seeing that a lead exists and seeing the
 * person's phone number are different acts with different consent
 * consequences (§9 Leads, spec 32.2).
 *
 * ---- generated model properties (scripts/generate-model-annotations.php)
 *
 * @property int $id
 * @property int $company_id
 * @property int $user_id
 * @property int|null $company_branch_id
 * @property string $role
 * @property string|null $title
 * @property bool $may_manage_offers
 * @property bool $may_view_leads
 * @property bool $may_view_lead_contacts
 * @property bool $is_active
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property bool $may_manage_projects
 *
 * ---- end generated model properties
 */
final class CompanyStaff extends Model
{
    protected $table = 'company_staff';

    protected $fillable = [
        'company_id', 'user_id', 'company_branch_id', 'role', 'title',
        'may_manage_offers', 'may_manage_projects', 'may_view_leads', 'may_view_lead_contacts', 'is_active',
    ];

    protected function casts(): array
    {
        return [
            'may_manage_offers' => 'boolean',
            'may_manage_projects' => 'boolean',
            'may_view_leads' => 'boolean',
            'may_view_lead_contacts' => 'boolean',
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

    /**
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * @return BelongsTo<CompanyBranch, $this>
     */
    public function branch(): BelongsTo
    {
        return $this->belongsTo(CompanyBranch::class, 'company_branch_id');
    }

    /**
     * @param  Builder<CompanyStaff>  $query
     * @return Builder<CompanyStaff>
     */
    public function scopeActive(Builder $query): Builder
    {
        return $query->where('is_active', true);
    }

    /**
     * Whether this membership may act on a capability.
     *
     * A single entry point so a controller asks one question rather than
     * reading three booleans and combining them differently each time.
     */
    public function may(string $capability): bool
    {
        return match ($capability) {
            'manage_offers' => (bool) $this->may_manage_offers,
            // Entering and editing this company's projects. Per-membership,
            // like every other capability here: a global role must never carry
            // authority from one company to another.
            'manage_projects' => (bool) $this->may_manage_projects,
            'view_leads' => (bool) $this->may_view_leads,
            // Seeing a contact requires seeing the lead. Granting the narrower
            // right without the broader one is a configuration mistake, and
            // honouring it literally would produce a phone number attached to
            // a record the person cannot otherwise open.
            'view_lead_contacts' => (bool) $this->may_view_lead_contacts && (bool) $this->may_view_leads,
            default => false,
        };
    }

    public function isOwner(): bool
    {
        return $this->role === 'company_owner';
    }
}
