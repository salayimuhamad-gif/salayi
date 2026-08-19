<?php

declare(strict_types=1);

namespace App\Modules\Content\Models;

use App\Modules\Identity\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * A full snapshot of a content item at a point in time (File one §10.2).
 *
 * Immutable by intent: there is no update path and no `updated_at`. A revision
 * that can be edited is not a revision, it is a second copy of the present —
 * and the entire reason to keep these is the day somebody has to prove what the
 * site said last March.
 *
 * ---- generated model properties (scripts/generate-model-annotations.php)
 *
 * @property int $id
 * @property int $content_item_id
 * @property int $revision
 * @property array<string, mixed>|null $snapshot
 * @property int|null $author_id
 * @property string|null $author_label
 * @property string|null $note
 * @property Carbon $created_at
 *
 * ---- end generated model properties
 */
final class ContentRevision extends Model
{
    /** The schema has only created_at; a revision is never modified. */
    public const UPDATED_AT = null;

    protected $fillable = ['content_item_id', 'revision', 'snapshot', 'author_id', 'author_label', 'note'];

    protected function casts(): array
    {
        return [
            'snapshot' => 'array',
            'revision' => 'integer',
            'created_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<ContentItem, $this>
     */
    public function item(): BelongsTo
    {
        return $this->belongsTo(ContentItem::class, 'content_item_id');
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function author(): BelongsTo
    {
        return $this->belongsTo(User::class, 'author_id');
    }

    /**
     * The title this revision held, for a history list.
     *
     * Reads from the snapshot rather than the live item, which is the whole
     * point — showing the current title beside every revision would make the
     * history look like it never changed.
     */
    public function titleAtTime(): ?string
    {
        $snapshot = $this->snapshot;

        return is_array($snapshot) ? ($snapshot['title_ckb'] ?? null) : null;
    }
}
