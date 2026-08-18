<?php

declare(strict_types=1);

namespace App\Modules\Marketplace\Models;

use App\Modules\Core\Concerns\ResolvesMediaUrl;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * An image attached to a marketplace offer.
 *
 * A PRE-EXISTING gap, not one the wizard introduced: OfferBrowseController
 * imports this class and it did not exist, so any code path reaching it
 * fataled on an unresolvable class. Found by the new structural check that
 * verifies every model a controller imports is actually present.
 *
 * Columns are the migration's, including `moderation_status` — offer media is
 * user-submitted and is reviewed before it appears publicly (§12.2).
 *
 * ---- generated model properties (scripts/generate-model-annotations.php)
 *
 * @property int $id
 * @property int $offer_id
 * @property string $kind
 * @property string $disk
 * @property string $path
 * @property string|null $mime_type
 * @property int|null $size_bytes
 * @property string|null $checksum
 * @property string|null $alt_ckb
 * @property string|null $alt_ar
 * @property string|null $alt_en
 * @property int $sort_order
 * @property bool $is_cover
 * @property string $moderation_status
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property Carbon|null $deleted_at
 * @property bool $cleanup_pending
 * @property int $cleanup_attempts
 * @property string|null $cleanup_last_error
 * @property string|null $moderation_reason
 * @property Carbon|null $moderated_at
 * @property int|null $cleanup_outbox_id
 * @property Carbon|null $cleanup_handed_off_at
 * @property string|null $original_name
 *
 * ---- end generated model properties
 */
final class OfferMedia extends Model
{
    use ResolvesMediaUrl;

    protected $table = 'offer_media';

    protected $fillable = [
        'offer_id', 'kind', 'disk', 'path', 'original_name',
        'mime_type', 'size_bytes', 'checksum',
        'alt_ckb', 'alt_ar', 'alt_en',
        'sort_order', 'is_cover', 'moderation_status',
        'moderation_reason', 'moderated_at',
        'cleanup_pending', 'cleanup_outbox_id', 'cleanup_handed_off_at', 'cleanup_attempts', 'cleanup_last_error',
    ];

    protected function casts(): array
    {
        return [
            'size_bytes' => 'integer',
            'sort_order' => 'integer',
            'is_cover' => 'boolean',
            'cleanup_pending' => 'boolean',
            'cleanup_outbox_id' => 'integer',
            'cleanup_handed_off_at' => 'datetime',
            'cleanup_attempts' => 'integer',
            'moderated_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<Offer, $this>
     */
    public function offer(): BelongsTo
    {
        return $this->belongsTo(Offer::class);
    }

    /**
     * Only approved media is publicly visible.
     *
     * The default must be restrictive: user-submitted imagery that has not
     * been looked at should never reach a public page because a scope was
     * forgotten.
     *
     * @param  Builder<OfferMedia>  $query
     * @return Builder<OfferMedia>
     */
    public function scopeApproved(Builder $query): Builder
    {
        return $query->where('moderation_status', 'approved')
            // A row awaiting deletion must never be publicly visible, and
            // must never become approved or cover again.
            ->where('cleanup_pending', false);
    }

    /** Trilingual alt text, current locale first, Sorani as fallback. */
    public function alt(?string $locale = null): ?string
    {
        $locale ??= app()->getLocale();

        foreach ([$locale, 'ckb', 'ar', 'en'] as $candidate) {
            $value = $this->{'alt_'.$candidate} ?? null;

            if (filled($value)) {
                return (string) $value;
            }
        }

        return null;
    }
}
