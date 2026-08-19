<?php

declare(strict_types=1);

namespace App\Modules\Branding\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Carbon;

/**
 * A versioned branding upload — logo, dark logo, favicon, PWA icon, social
 * image (spec 8: "All file uploads must be validated and versioned").
 *
 * Versioned rather than overwritten so that reverting a logo change is a
 * one-click action and never requires re-uploading a file nobody kept.
 *
 * @property string $slot
 * @property int $version
 * @property bool $is_current
 *
 * ---- generated model properties (scripts/generate-model-annotations.php)
 * @property int $id
 * @property string $slot
 * @property int $version
 * @property bool $is_current
 * @property string $disk
 * @property string $path
 * @property string|null $original_name
 * @property string|null $mime_type
 * @property int|null $size_bytes
 * @property int|null $width
 * @property int|null $height
 * @property string|null $checksum
 * @property int|null $uploaded_by
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property Carbon|null $deleted_at
 *
 * ---- end generated model properties
 */
final class BrandingAsset extends Model
{
    use SoftDeletes;

    protected $table = 'branding_assets';

    protected $fillable = [
        'slot', 'version', 'is_current', 'disk', 'path', 'original_name',
        'mime_type', 'size_bytes', 'width', 'height', 'checksum', 'uploaded_by',
    ];

    protected function casts(): array
    {
        return [
            'is_current' => 'boolean',
            'version' => 'integer',
            'size_bytes' => 'integer',
            'width' => 'integer',
            'height' => 'integer',
        ];
    }

    public const SLOTS = [
        'logo', 'logo_dark', 'logo_mobile', 'favicon', 'social_image',
        'pwa_icon_192', 'pwa_icon_512', 'pwa_maskable', 'splash_background',
    ];
}
