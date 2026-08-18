<?php

declare(strict_types=1);

namespace App\Modules\Localization\Models;

use App\Modules\Localization\Support\SoraniText;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * A single translatable string under admin control (spec 7.3, 7.4).
 *
 * `status` follows the spec 7.4 workflow. `ai_suggested` is a first-class
 * status, not a boolean flag, because spec 7.4 forbids AI output publishing
 * itself: a row can only reach `published` by passing through `human_reviewed`
 * or `approved`, and the transition is what the audit log records.
 *
 * @property string $group
 * @property string $key
 * @property string $locale
 * @property string|null $value
 * @property string $status
 *
 * ---- generated model properties (scripts/generate-model-annotations.php)
 * @property int $id
 * @property string $group
 * @property string $key
 * @property string $locale
 * @property string|null $value
 * @property string $status
 * @property bool $is_overridden
 * @property string|null $source
 * @property string|null $search_key
 * @property int|null $reviewed_by
 * @property Carbon|null $reviewed_at
 * @property Carbon|null $published_at
 * @property string|null $note
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 *
 * ---- end generated model properties
 */
final class Translation extends Model
{
    protected $table = 'translations';

    protected $fillable = [
        'group', 'key', 'locale', 'value', 'status', 'is_overridden',
        'source', 'reviewed_by', 'reviewed_at', 'published_at', 'note',
    ];

    protected function casts(): array
    {
        return [
            'is_overridden' => 'boolean',
            'reviewed_at' => 'datetime',
            'published_at' => 'datetime',
        ];
    }

    /** Spec 7.4 statuses, in workflow order. */
    public const STATUSES = [
        'missing', 'draft', 'ai_suggested', 'human_reviewed',
        'approved', 'published', 'needs_re_review',
    ];

    /** A status from which `published` is legally reachable (spec 7.4). */
    public const PUBLISHABLE_FROM = ['human_reviewed', 'approved'];

    public function canPublish(): bool
    {
        return in_array($this->status, self::PUBLISHABLE_FROM, true);
    }

    protected static function booted(): void
    {
        // Search index key is derived, never entered. Keeping it in sync here
        // means an admin edit and a bulk import cannot diverge.
        self::saving(function (self $translation): void {
            $translation->search_key = SoraniText::searchKey($translation->value);
        });
    }
}
