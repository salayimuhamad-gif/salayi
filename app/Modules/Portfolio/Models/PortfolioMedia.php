<?php

declare(strict_types=1);

namespace App\Modules\Portfolio\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * One image or document attached to a portfolio property (correction §6.8).
 *
 * Everything ownership-related is derived through the property — this model
 * deliberately has no user_id of its own, so there is only one place the
 * owner check can live and no second copy to drift.
 *
 * @property int $id
 * @property int $portfolio_property_id
 * @property string $kind
 * @property string $disk
 * @property string $path
 * @property string $original_name
 * @property string $mime
 * @property int $size_bytes
 * @property Carbon|null $deletion_pending_at
 * @property string|null $status
 * @property string|null $staged_path
 * @property int $deletion_attempts
 * @property Carbon|null $deletion_last_attempt_at
 * @property Carbon|null $deletion_next_attempt_at
 * @property string|null $deletion_last_error
 * @property Carbon|null $deletion_escalated_at
 * @property Carbon|null $created_at
 *
 * ---- generated model properties (scripts/generate-model-annotations.php)
 * @property int $id
 * @property int $portfolio_property_id
 * @property string $kind
 * @property string $disk
 * @property string $path
 * @property string $original_name
 * @property string $mime
 * @property int $size_bytes
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property Carbon|null $deletion_pending_at
 * @property string|null $status
 * @property string|null $staged_path
 * @property int $deletion_attempts
 * @property Carbon|null $deletion_last_attempt_at
 * @property Carbon|null $deletion_next_attempt_at
 * @property string|null $deletion_last_error
 * @property Carbon|null $deletion_escalated_at
 *
 * ---- end generated model properties
 */
final class PortfolioMedia extends Model
{
    protected $table = 'portfolio_media';

    /**
     * The upload lifecycle (v4, BLOCKER 8).
     *
     *   STAGING  — the row holds a slot against the 12-file cap; its bytes
     *              are written but not yet at their final path. Never
     *              served, never exported.
     *   READY    — the bytes are where the row says. The only status any
     *              read path accepts.
     *   ORPHANED — finalisation failed. Invisible to every read path; the
     *              sweeper owns it.
     */
    public const STATUS_STAGING = 'staging';

    public const STATUS_READY = 'ready';

    public const STATUS_ORPHANED = 'orphaned';

    protected $fillable = [
        'kind',
        'disk',
        'path',
        'original_name',
        'mime',
        'size_bytes',
    ];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'deletion_pending_at' => 'datetime',
            'deletion_last_attempt_at' => 'datetime',
            'deletion_next_attempt_at' => 'datetime',
            'deletion_escalated_at' => 'datetime',
            'deletion_attempts' => 'integer',
        ];
    }

    /** @return BelongsTo<PortfolioProperty, $this> */
    public function property(): BelongsTo
    {
        return $this->belongsTo(PortfolioProperty::class, 'portfolio_property_id');
    }

    /**
     * The payload a page may carry: label, kind, size — never a path.
     *
     * @return array{id: int, kind: string, name: string, mime: string, size_bytes: int, created_at: string|null}
     */
    public function toDisplayArray(): array
    {
        return [
            'id' => $this->id,
            'kind' => $this->kind,
            'name' => $this->original_name,
            'mime' => $this->mime,
            'size_bytes' => (int) $this->size_bytes,
            'created_at' => $this->created_at?->toDateString(),
        ];
    }
}
