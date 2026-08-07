<?php

declare(strict_types=1);

namespace App\Modules\Localization\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;
use RuntimeException;

/**
 * @property string $code
 * @property bool $is_enabled
 * @property bool $is_default
 *
 * ---- generated model properties (scripts/generate-model-annotations.php)
 * @property int $id
 * @property string $code
 * @property string $name
 * @property string $native_name
 * @property string $direction
 * @property bool $is_enabled
 * @property bool $is_default
 * @property int $sort_order
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 *
 * ---- end generated model properties
 */
final class Language extends Model
{
    protected $table = 'languages';

    protected $fillable = ['code', 'name', 'native_name', 'direction', 'is_enabled', 'is_default', 'sort_order'];

    protected function casts(): array
    {
        return [
            'is_enabled' => 'boolean',
            'is_default' => 'boolean',
            'sort_order' => 'integer',
        ];
    }

    /** ckb can never be disabled (spec 7.1). Guarded here as well as in the UI. */
    protected static function booted(): void
    {
        self::updating(function (self $language): void {
            $immutable = (string) config('localization.immutable_default', 'ckb');

            if ($language->code === $immutable && $language->is_enabled === false) {
                throw new RuntimeException('Kurdish Sorani is the product default language and cannot be disabled.');
            }
        });

        self::deleting(function (self $language): void {
            if ($language->code === (string) config('localization.immutable_default', 'ckb')) {
                throw new RuntimeException('Kurdish Sorani cannot be deleted.');
            }
        });
    }
}
