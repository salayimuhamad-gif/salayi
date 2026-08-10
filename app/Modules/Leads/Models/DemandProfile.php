<?php

declare(strict_types=1);

namespace App\Modules\Leads\Models;

use App\Modules\Advisor\Models\AdvisorConversation;
use App\Modules\Advisor\Models\LifestyleProfile;
use App\Modules\Companies\Models\Company;
use App\Modules\Geography\Models\Area;
use App\Modules\Identity\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * What a household is looking for (File one §9 Leads 1).
 *
 * §9 asks for demand profiles built from AI conversations AND behaviour, which
 * is why this is separate from `LifestyleProfile`: the lifestyle profile is
 * what the person deliberately TOLD us, and this is what the business
 * understands about them, including things they never stated.
 *
 * The distinction matters for consent. A person who filled in a lifestyle form
 * knows that data exists. A demand profile assembled from browsing behaviour is
 * inference, and treating the two as the same record would let inference
 * inherit the consent given for the declaration.
 *
 * ---- generated model properties (scripts/generate-model-annotations.php)
 *
 * @property int $id
 * @property int|null $user_id
 * @property int|null $lifestyle_profile_id
 * @property string $preferred_locale
 * @property string|null $budget_min
 * @property string|null $budget_max
 * @property string|null $currency
 * @property string|null $financing
 * @property string|null $objective
 * @property string|null $property_type
 * @property int|null $project_id
 * @property int|null $area_id
 * @property string|null $size_min_sqm
 * @property string|null $size_max_sqm
 * @property string|null $timeframe
 * @property string|null $risk_preference
 * @property string|null $return_preference
 * @property string|null $ai_summary
 * @property string|null $ai_summary_locale
 * @property string $stage
 * @property int|null $owner_user_id
 * @property int|null $company_id
 * @property string|null $source
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property Carbon|null $deleted_at
 * @property int|null $advisor_conversation_id
 * @property string|null $currency_source
 *
 * ---- end generated model properties
 */
final class DemandProfile extends Model
{
    protected $fillable = [
        'user_id', 'lifestyle_profile_id', 'preferred_locale',
        'budget_min', 'budget_max', 'currency', 'currency_source', 'financing', 'objective',
        'property_type', 'project_id', 'area_id',
        'size_min_sqm', 'size_max_sqm', 'timeframe',
        'risk_preference', 'return_preference',
        'ai_summary', 'ai_summary_locale',
        'stage', 'owner_user_id', 'company_id', 'source',
    ];

    /**
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * @return BelongsTo<LifestyleProfile, $this>
     */
    public function lifestyleProfile(): BelongsTo
    {
        return $this->belongsTo(LifestyleProfile::class);
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function owner(): BelongsTo
    {
        return $this->belongsTo(User::class, 'owner_user_id');
    }

    /**
     * @return BelongsTo<Company, $this>
     */
    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    /**
     * @return BelongsTo<Area, $this>
     */
    public function area(): BelongsTo
    {
        return $this->belongsTo(Area::class);
    }

    /**
     * @return BelongsTo<AdvisorConversation, $this>
     */
    public function advisorConversation(): BelongsTo
    {
        return $this->belongsTo(AdvisorConversation::class);
    }

    /**
     * @return HasMany<LeadNote, $this>
     */
    public function notes(): HasMany
    {
        return $this->hasMany(LeadNote::class);
    }

    /**
     * @return HasMany<LeadTask, $this>
     */
    public function tasks(): HasMany
    {
        return $this->hasMany(LeadTask::class);
    }

    /**
     * @return HasMany<LeadScore, $this>
     */
    public function score(): HasMany
    {
        return $this->hasMany(LeadScore::class);
    }

    /**
     * Leads a given company may work.
     *
     * Unassigned leads are visible to nobody by company. That is deliberate:
     * a lead with no `company_id` has not been routed, and letting every
     * company see unrouted leads would turn routing into a race rather than a
     * decision.
     *
     * @param  Builder<DemandProfile>  $query
     * @return Builder<DemandProfile>
     */
    public function scopeForCompany(Builder $query, int $companyId): Builder
    {
        return $query->where('company_id', $companyId);
    }

    /**
     * The pipeline, in order. The sales workspace's filters and board, and
     * the users workspace's request filters, all read this one list — the
     * stage vocabulary lives with the model that carries the column.
     *
     * @var list<string>
     */
    public const STAGES = [
        'new', 'contacted', 'qualified', 'viewing', 'negotiating', 'won', 'lost',
    ];

    /**
     * @param  Builder<DemandProfile>  $query
     * @return Builder<DemandProfile>
     */
    public function scopeInStage(Builder $query, string $stage): Builder
    {
        return $query->where('stage', $stage);
    }
}
