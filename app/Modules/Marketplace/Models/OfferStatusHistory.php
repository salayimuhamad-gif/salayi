<?php

declare(strict_types=1);

namespace App\Modules\Marketplace\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;
use RuntimeException;

/**
 * Append-only moderation trail (spec 19.3, 37.4).
 *
 * ---- generated model properties (scripts/generate-model-annotations.php)
 *
 * @property int $id
 * @property int $offer_id
 * @property string|null $from_status
 * @property string $to_status
 * @property int|null $actor_id
 * @property string|null $actor_label
 * @property bool $actor_was_moderator
 * @property string|null $reason
 * @property Carbon $created_at
 *
 * ---- end generated model properties
 */
final class OfferStatusHistory extends Model
{
    public const UPDATED_AT = null;

    protected $table = 'offer_status_history';

    protected $fillable = [
        'offer_id', 'from_status', 'to_status', 'actor_id',
        'actor_label', 'actor_was_moderator', 'reason',
    ];

    protected function casts(): array
    {
        return ['actor_was_moderator' => 'boolean'];
    }

    protected static function booted(): void
    {
        self::updating(static function (): bool {
            throw new RuntimeException('Offer moderation history is append-only.');
        });

        self::deleting(static function (): bool {
            throw new RuntimeException('Offer moderation history is append-only.');
        });
    }
}
