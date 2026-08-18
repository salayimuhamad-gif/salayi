<?php

declare(strict_types=1);

namespace App\Modules\Projects\Models;

use App\Modules\Core\Concerns\ResolvesMediaUrl;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * An image or document attached to a project.
 *
 * This model was REFERENCED by ProjectMediaController and did not exist, so
 * every media upload through the admin fataled on an unresolvable class. It is
 * restored here against the real `project_media` schema rather than invented:
 * the column list below is the migration's, including the alt_* naming that
 * the wizard's promotion code had wrong.
 *
 * ---- generated model properties (scripts/generate-model-annotations.php)
 *
 * @property int $id
 * @property int $project_id
 * @property string $kind
 * @property string $disk
 * @property string $path
 * @property string|null $original_name
 * @property string|null $mime_type
 * @property int|null $size_bytes
 * @property int|null $width
 * @property int|null $height
 * @property string|null $checksum
 * @property string|null $alt_ckb
 * @property string|null $alt_ar
 * @property string|null $alt_en
 * @property string|null $credit
 * @property int $sort_order
 * @property bool $is_cover
 * @property int|null $uploaded_by
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property Carbon|null $deleted_at
 * @property bool $cleanup_pending
 * @property int $cleanup_attempts
 * @property string|null $cleanup_last_error
 * @property int|null $cleanup_outbox_id
 * @property Carbon|null $cleanup_handed_off_at
 *
 * ---- end generated model properties
 */
final class ProjectMedia extends Model
{
    use ResolvesMediaUrl;

    protected $table = 'project_media';

    protected $fillable = [
        'project_id', 'kind', 'disk', 'path', 'original_name',
        'mime_type', 'size_bytes', 'width', 'height', 'checksum',
        'alt_ckb', 'alt_ar', 'alt_en', 'credit',
        'sort_order', 'is_cover', 'uploaded_by',
        'cleanup_pending', 'cleanup_outbox_id', 'cleanup_handed_off_at', 'cleanup_attempts', 'cleanup_last_error',
    ];

    protected function casts(): array
    {
        return [
            'size_bytes' => 'integer',
            'width' => 'integer',
            'height' => 'integer',
            'sort_order' => 'integer',
            'is_cover' => 'boolean',
            'cleanup_pending' => 'boolean',
            'cleanup_outbox_id' => 'integer',
            'cleanup_handed_off_at' => 'datetime',
            'cleanup_attempts' => 'integer',
        ];
    }

    /**
     * @return BelongsTo<Project, $this>
     */
    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }

    /**
     * A public URL for this file, or null when there cannot be one.
     *
     * ProjectMediaController calls this and it did not exist, so the media
     * index fataled the moment it tried to serialise a row.
     *
     * Null rather than a guess in three cases: no path, an unconfigured disk,
     * or a disk with no public URL. A private disk is not a bug to route
     * around — returning a constructed path for one would produce a link that
     * 404s or, worse, exposes a file the disk was chosen to protect.
     */
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
