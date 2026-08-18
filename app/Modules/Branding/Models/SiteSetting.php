<?php

declare(strict_types=1);

namespace App\Modules\Branding\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * One admin-editable setting (spec 8).
 *
 * `is_secret` marks a value that must never round-trip to a browser. Spec 37.5:
 * "Normal admin does not see system secrets." True secrets live in .env; this
 * flag covers the grey area — a third-party account id, a webhook path — that
 * is configurable but should not appear in a screenshot.
 *
 * @property string $key
 * @property string $group
 * @property string $type
 * @property string|null $value
 * @property bool $is_secret
 * @property bool $is_public
 *
 * ---- generated model properties (scripts/generate-model-annotations.php)
 * @property int $id
 * @property string $key
 * @property string $group
 * @property string $type
 * @property string|null $value
 * @property bool $is_secret
 * @property bool $is_public
 * @property string|null $description
 * @property int|null $updated_by
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 *
 * ---- end generated model properties
 */
final class SiteSetting extends Model
{
    protected $table = 'site_settings';

    protected $fillable = ['key', 'group', 'type', 'value', 'is_secret', 'is_public', 'description', 'updated_by'];

    protected $hidden = ['value'];

    protected function casts(): array
    {
        return [
            'is_secret' => 'boolean',
            'is_public' => 'boolean',
        ];
    }

    /**
     * Settings safe to ship to the front end in the Inertia shared payload.
     */
    public function scopePublic(mixed $query): mixed
    {
        return $query->where('is_public', true)->where('is_secret', false);
    }
}
