<?php

declare(strict_types=1);

namespace App\Modules\Advertising\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * One impression or click (File two §13).
 *
 * The `ad_events` table predates this module and its design is deliberate: a
 * per-event row rather than a daily counter. That costs storage, and it buys
 * the only thing that answers an advertiser disputing an invoice — when the
 * campaign actually ran, not merely how many times.
 *
 * `viewer_hash` is hashed at the point of writing, never raw. An impression log
 * is not a reason to retain an identifiable browsing record (spec 32.2).
 *
 * ---- generated model properties (scripts/generate-model-annotations.php)
 *
 * @property int $id
 * @property int $ad_campaign_id
 * @property int|null $ad_creative_id
 * @property string $event_type
 * @property string|null $placement
 * @property string|null $viewer_hash
 * @property string|null $locale
 * @property Carbon $occurred_at
 *
 * ---- end generated model properties
 */
final class AdEvent extends Model
{
    /** Events are written once and never modified. */
    public const UPDATED_AT = null;

    public const CREATED_AT = null;

    protected $fillable = [
        'ad_campaign_id', 'ad_creative_id', 'event_type',
        'placement', 'viewer_hash', 'locale', 'occurred_at',
    ];

    protected function casts(): array
    {
        return ['occurred_at' => 'datetime'];
    }

    /**
     * @return BelongsTo<AdCampaign, $this>
     */
    public function campaign(): BelongsTo
    {
        return $this->belongsTo(AdCampaign::class, 'ad_campaign_id');
    }

    /**
     * @param  Builder<AdEvent>  $query
     * @return Builder<AdEvent>
     */
    public function scopeImpressions(Builder $query): Builder
    {
        return $query->where('event_type', 'impression');
    }

    /**
     * @param  Builder<AdEvent>  $query
     * @return Builder<AdEvent>
     */
    public function scopeClicks(Builder $query): Builder
    {
        return $query->where('event_type', 'click');
    }
}
