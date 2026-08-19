<?php

declare(strict_types=1);

namespace App\Modules\Advertising\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * The rendered advertisement (File two §13).
 *
 * ---- generated model properties (scripts/generate-model-annotations.php)
 *
 * @property int $id
 * @property int $ad_campaign_id
 * @property string $locale
 * @property string $headline
 * @property string|null $body
 * @property string|null $image_path
 * @property string $click_url
 * @property string $moderation_status
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 *
 * ---- end generated model properties
 */
final class AdCreative extends Model
{
    /*
     * The existing `ad_creatives` table stores ONE ROW PER LOCALE rather than
     * trilingual columns on a single row. That is the right shape here: an
     * advertiser routinely supplies Sorani and Arabic but not English, and a
     * row that simply does not exist is a clearer "no creative in this
     * language" than three nullable columns two of which are empty.
     *
     * The disclosure lives on the campaign in this schema, where it is NOT
     * NULL — so it cannot be omitted per-creative.
     */
    protected $fillable = [
        'ad_campaign_id', 'locale', 'headline', 'body',
        'image_path', 'click_url', 'moderation_status',
    ];

    /**
     * @return BelongsTo<AdCampaign, $this>
     */
    public function campaign(): BelongsTo
    {
        return $this->belongsTo(AdCampaign::class, 'ad_campaign_id');
    }

    /**
     * Only creatives an advertiser has actually had approved.
     *
     * @param  Builder<AdCreative>  $query
     * @return Builder<AdCreative>
     */
    public function scopeApproved(Builder $query): Builder
    {
        return $query->where('moderation_status', 'approved');
    }
}
