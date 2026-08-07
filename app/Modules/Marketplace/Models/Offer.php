<?php

declare(strict_types=1);

namespace App\Modules\Marketplace\Models;

use App\Modules\Companies\Models\Company;
use App\Modules\Core\ValueObjects\Decimal;
use App\Modules\Geography\Models\Area;
use App\Modules\Marketplace\Enums\OfferStatus;
use App\Modules\Projects\Models\Project;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;
use RuntimeException;

/**
 * A marketplace offer (spec 19).
 *
 * Two guards enforced at the model layer rather than in a controller, so no
 * code path can bypass them:
 *
 *   1. An illegal status transition throws (spec 19.3, 37.4 moderation).
 *   2. A sponsored offer without a disclosure label cannot be saved
 *      (spec 19.5, 18.4).
 *
 * ---- generated model properties (scripts/generate-model-annotations.php)
 *
 * @property int $id
 * @property string $public_id
 * @property int|null $company_id
 * @property int|null $company_branch_id
 * @property int|null $agent_user_id
 * @property int|null $owner_user_id
 * @property int|null $project_id
 * @property int|null $area_id
 * @property string $title_ckb
 * @property string|null $title_ar
 * @property string|null $title_en
 * @property string|null $search_key
 * @property string|null $description_ckb
 * @property string|null $description_ar
 * @property string|null $description_en
 * @property string $offer_type
 * @property string $property_type
 * @property string|null $unit_type
 * @property string $location_precision
 * @property string|null $latitude
 * @property string|null $longitude
 * @property string|null $size_sqm
 * @property int|null $rooms
 * @property int|null $bathrooms
 * @property int|null $floor
 * @property string|null $orientation
 * @property string|null $furnishing
 * @property string|null $price
 * @property string $currency
 * @property array<string, mixed>|null $payment_plan
 * @property string $availability
 * @property string $contact_method
 * @property array<string, mixed>|null $verification_evidence
 * @property string|null $source
 * @property OfferStatus $status
 * @property Carbon|null $submitted_at
 * @property Carbon|null $reviewed_at
 * @property int|null $reviewed_by
 * @property string|null $moderation_notes
 * @property Carbon|null $scheduled_for
 * @property Carbon|null $published_at
 * @property Carbon|null $expires_at
 * @property bool $is_sponsored
 * @property string|null $disclosure_label
 * @property int $sponsor_bid
 * @property int|null $completeness_score
 * @property int|null $verification_score
 * @property string|null $terms
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property Carbon|null $deleted_at
 *
 * ---- end generated model properties
 */
final class Offer extends Model
{
    use SoftDeletes;

    protected $table = 'offers';

    /*
     * `company_id` is DELIBERATELY ABSENT from fillable.
     *
     * Ownership is decided by OfferScope from the session context and written
     * with forceFill. Leaving it mass-assignable meant an ordinary update
     * could hand the offer to another company immediately after the ownership
     * check had passed — the check and the write protected different things.
     */
    protected $fillable = [
        'public_id', 'company_branch_id', 'agent_user_id', 'owner_user_id',
        'project_id', 'area_id',
        'title_ckb', 'title_ar', 'title_en',
        'description_ckb', 'description_ar', 'description_en',
        'offer_type', 'property_type', 'unit_type',
        'location_precision', 'latitude', 'longitude',
        'size_sqm', 'rooms', 'bathrooms', 'floor', 'orientation', 'furnishing',
        'price', 'currency', 'payment_plan', 'availability',
        'contact_method', 'verification_evidence', 'source',
        'status', 'scheduled_for', 'expires_at',
        'is_sponsored', 'disclosure_label', 'sponsor_bid', 'terms',
    ];

    protected function casts(): array
    {
        return [
            'status' => OfferStatus::class,
            'payment_plan' => 'array',
            'verification_evidence' => 'array',
            'price' => 'decimal:4',
            'size_sqm' => 'decimal:2',
            'latitude' => 'decimal:7',
            'longitude' => 'decimal:7',
            'is_sponsored' => 'boolean',
            'sponsor_bid' => 'integer',
            'submitted_at' => 'datetime',
            'reviewed_at' => 'datetime',
            'scheduled_for' => 'datetime',
            'published_at' => 'datetime',
            'expires_at' => 'datetime',
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
     * @return BelongsTo<Project, $this>
     */
    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }

    /**
     * The area this offer sits in.
     *
     * `offers.area_id` has existed since the marketplace tables were created
     * and `OfferBrowseController` renders `$offer->area?->name()`, but the
     * relation was never declared — so the public listing showed no area for
     * any offer, silently, because `?->` turned the missing relation into null.
     *
     * @return BelongsTo<Area, $this>
     */
    public function area(): BelongsTo
    {
        return $this->belongsTo(Area::class);
    }

    /**
     * @return HasMany<OfferStatusHistory, $this>
     */
    public function statusHistory(): HasMany
    {
        return $this->hasMany(OfferStatusHistory::class)->orderByDesc('created_at');
    }

    public function priceValue(): ?Decimal
    {
        return $this->price === null ? null : Decimal::of((string) $this->price, 4);
    }

    /**
     * Spec 37.4: "Offer can appear on selected project." An offer is only
     * shown against a project when it is published AND actually linked to it.
     *
     * @param  Builder<Offer>  $query
     * @return Builder<Offer>
     */
    public function scopeForProject(Builder $query, int $projectId): Builder
    {
        return $query->where('project_id', $projectId)
            ->where('status', OfferStatus::Published->value);
    }

    /**
     * @param  Builder<Offer>  $query
     * @return Builder<Offer>
     */
    public function scopePublished(Builder $query): Builder
    {
        return $query->where('status', OfferStatus::Published->value)
            ->where(function (Builder $q): void {
                $q->whereNull('expires_at')->orWhere('expires_at', '>', now());
            });
    }

    /**
     * Move through the moderation workflow.
     *
     * @throws RuntimeException on an illegal transition or an unauthorised actor
     */
    public function transitionTo(OfferStatus $target, bool $actorIsModerator = false): void
    {
        $current = $this->status;

        if (! $current->canTransitionTo($target)) {
            throw new RuntimeException(sprintf(
                'An offer cannot move from "%s" to "%s".',
                $current->value,
                $target->value,
            ));
        }

        if ($target->requiresModerator() && ! $actorIsModerator) {
            throw new RuntimeException(sprintf(
                'Only a moderator may set an offer to "%s" (spec 19.3).',
                $target->value,
            ));
        }

        $this->status = $target;

        match ($target) {
            OfferStatus::Submitted => $this->submitted_at = now(),
            OfferStatus::Approved, OfferStatus::Rejected, OfferStatus::ChangesRequested => $this->reviewed_at = now(),
            OfferStatus::Published => $this->published_at ??= now(),
            default => null,
        };
    }

    protected static function booted(): void
    {
        self::creating(function (self $offer): void {
            $offer->public_id ??= (string) Str::uuid();
        });

        self::saving(function (self $offer): void {
            // An undisclosed advertisement is the specific harm spec 19.5 and
            // 18.4 exist to prevent, so it is unstorable rather than merely
            // discouraged.
            if ($offer->is_sponsored && trim((string) $offer->disclosure_label) === '') {
                throw new RuntimeException(
                    'A sponsored offer must carry a disclosure label (spec 19.5, 18.4).',
                );
            }
        });
    }
}
