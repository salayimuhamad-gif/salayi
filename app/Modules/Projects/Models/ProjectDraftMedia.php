<?php

declare(strict_types=1);

namespace App\Modules\Projects\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * An upload belonging to one wizard draft (spec 12.1, 32.2).
 *
 * Ownership is intrinsic: a row is bound to its draft, its uploader and its
 * company at insert time. There is no id an attacker can craft that reaches
 * somebody else's upload, because the scope is part of the query rather than
 * a check applied afterwards.
 *
 * ---- generated model properties (scripts/generate-model-annotations.php)
 *
 * @property int $id
 * @property int $project_draft_id
 * @property int $uploaded_by
 * @property int|null $acting_company_id
 * @property string $kind
 * @property string $disk
 * @property string $path
 * @property string|null $original_name
 * @property string $mime_type
 * @property int $size_bytes
 * @property int|null $width
 * @property int|null $height
 * @property string|null $checksum
 * @property string|null $alt_ckb
 * @property string|null $alt_ar
 * @property string|null $alt_en
 * @property int $sort_order
 * @property bool $is_cover
 * @property Carbon|null $expires_at
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property bool $cleanup_pending
 * @property int $cleanup_attempts
 * @property string|null $cleanup_last_error
 * @property int|null $cleanup_outbox_id
 * @property Carbon|null $cleanup_handed_off_at
 *
 * ---- end generated model properties
 */
final class ProjectDraftMedia extends Model
{
    protected $table = 'project_draft_media';

    protected $fillable = [
        'project_draft_id', 'uploaded_by', 'acting_company_id',
        'kind', 'disk', 'path', 'original_name',
        'mime_type', 'size_bytes', 'width', 'height', 'checksum',
        'alt_ckb', 'alt_ar', 'alt_en',
        'sort_order', 'is_cover', 'expires_at',

        'cleanup_outbox_id', 'cleanup_handed_off_at',
    ];

    protected function casts(): array
    {
        return [
            'size_bytes' => 'integer',
            'width' => 'integer',
            'height' => 'integer',
            'sort_order' => 'integer',
            'is_cover' => 'boolean',
            'expires_at' => 'datetime',
            'cleanup_pending' => 'boolean',
            'cleanup_attempts' => 'integer',
            'cleanup_outbox_id' => 'integer',
            'cleanup_handed_off_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<ProjectDraft, $this>
     */
    public function draft(): BelongsTo
    {
        return $this->belongsTo(ProjectDraft::class, 'project_draft_id');
    }

    /**
     * Media this user may touch on this draft.
     *
     * Both conditions, always. Scoping by draft alone would let a second user
     * who somehow reached the draft act on another's uploads; scoping by
     * uploader alone would let a crafted draft id move media between drafts.
     *
     * @param  Builder<ProjectDraftMedia>  $query
     * @return Builder<ProjectDraftMedia>
     */
    public function scopeOwnedBy(Builder $query, int $draftId, int $userId): Builder
    {
        return $query->where('project_draft_id', $draftId)->where('uploaded_by', $userId);
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
