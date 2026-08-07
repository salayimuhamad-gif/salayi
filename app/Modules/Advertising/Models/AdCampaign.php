<?php

declare(strict_types=1);

namespace App\Modules\Advertising\Models;

use App\Modules\Companies\Models\Company;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Carbon;

/**
 * A paid campaign (File two §13).
 *
 * Deliberately has no method, column or relation that a ranking service could
 * read. §8.9 requires sponsorship never to alter organic scores, and the way
 * that survives future maintenance is for there to be nothing here to reach for.
 *
 * ---- generated model properties (scripts/generate-model-annotations.php)
 *
 * @property int $id
 * @property int $company_id
 * @property string $name
 * @property string $placement
 * @property int|null $target_project_id
 * @property int|null $target_area_id
 * @property string|null $locale
 * @property Carbon $starts_on
 * @property Carbon $ends_on
 * @property int|null $daily_cap
 * @property int|null $impression_cap
 * @property int|null $click_cap
 * @property bool $is_approved
 * @property int|null $approved_by
 * @property Carbon|null $approved_at
 * @property string|null $invoice_reference
 * @property string $disclosure_label
 * @property string $status
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property Carbon|null $deleted_at
 *
 * ---- end generated model properties
 */
final class AdCampaign extends Model
{
    use SoftDeletes;

    /*
     * These columns belong to the `ad_campaigns` table created by the
     * Marketplace migration, which predates this module. Phase 9 originally
     * shipped a SECOND migration creating the same tables — it would have
     * failed on first run with "table already exists", and was removed once
     * the collision check in migration-guard.php exposed it.
     *
     * The existing schema is the better one in one important respect:
     * `disclosure_label` is NOT NULL there, so a campaign cannot exist
     * undisclosed. That is a stronger guarantee than validating it on the way
     * in, and it is kept.
     */
    protected $fillable = [
        'company_id', 'name', 'placement',
        'target_project_id', 'target_area_id', 'locale',
        'starts_on', 'ends_on',
        'daily_cap', 'impression_cap', 'click_cap',
        'is_approved', 'approved_by', 'approved_at',
        'invoice_reference', 'disclosure_label', 'status',
    ];

    protected function casts(): array
    {
        return [
            'starts_on' => 'date',
            'ends_on' => 'date',
            'approved_at' => 'datetime',
            'is_approved' => 'boolean',
            'daily_cap' => 'integer',
            'impression_cap' => 'integer',
            'click_cap' => 'integer',
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
     * @return HasMany<AdCreative, $this>
     */
    public function creatives(): HasMany
    {
        return $this->hasMany(AdCreative::class);
    }

    /**
     * Impression and click events.
     *
     * The existing schema records one row per event rather than a daily
     * aggregate. That is more storage, and it is also the only shape that can
     * answer "when did this campaign actually run" after the fact — which is
     * the question an advertiser disputing an invoice asks.
     *
     * @return HasMany<AdEvent, $this>
     */
    public function events(): HasMany
    {
        return $this->hasMany(AdEvent::class);
    }

    /**
     * Campaigns eligible to serve right now.
     *
     * Approval is checked as well as status: an advertiser must never be able
     * to publish their own campaign by setting a field, so `approved_at` is the
     * authority and `status` alone is not enough.
     *
     * @param  Builder<AdCampaign>  $query
     * @return Builder<AdCampaign>
     */
    public function scopeServable(Builder $query): Builder
    {
        $today = now()->toDateString();

        return $query
            ->where('status', 'active')
            // Approval is the authority, not status: an advertiser with any
            // write path to their own campaign must not be able to go live.
            ->where('is_approved', true)
            ->whereNotNull('approved_at')
            ->where(fn (Builder $q) => $q->whereNull('starts_on')->orWhere('starts_on', '<=', $today))
            ->where(fn (Builder $q) => $q->whereNull('ends_on')->orWhere('ends_on', '>=', $today));
    }

    /** Impressions served today, for the daily cap. */
    public function impressionsToday(): int
    {
        return $this->events()
            ->where('event_type', 'impression')
            ->whereDate('occurred_at', now()->toDateString())
            ->count();
    }

    public function impressionsLifetime(): int
    {
        return $this->events()->where('event_type', 'impression')->count();
    }

    /**
     * Has this campaign exhausted a cap?
     *
     * Checked before serving rather than after counting. A cap enforced after
     * the fact has already delivered the impression the advertiser did not buy,
     * and on a busy page that overshoot compounds.
     */
    public function hasReachedCap(): bool
    {
        if ($this->daily_cap !== null && $this->impressionsToday() >= $this->daily_cap) {
            return true;
        }

        return $this->impression_cap !== null
            && $this->impressionsLifetime() >= $this->impression_cap;
    }
}
