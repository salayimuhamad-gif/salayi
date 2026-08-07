<?php

declare(strict_types=1);

namespace App\Modules\Content\Models;

use App\Modules\Identity\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Carbon;

/**
 * A piece of editorial content (File one §10.1, §10.2).
 *
 * §10.1 lists eight content types — pages, news, market analysis, videos,
 * methodology, FAQs, updates and opportunities. They share one table because
 * they share every operational concern: trilingual bodies, revisions, an
 * approval step, scheduled publication, SEO fields, related entities and an
 * expiry. Eight near-identical tables would mean eight places to forget the
 * expiry check.
 *
 * The publication rule is the part worth stating. `status` alone does not make
 * something public: an item is visible only when it is approved AND its
 * publish window has opened AND has not closed. §10.2 asks for scheduling and
 * expiry, and honouring only the flag would leave a market analysis from last
 * quarter sitting on the site as though it were current — which for a product
 * that sells price intelligence is not a cosmetic error.
 *
 * ---- generated model properties (scripts/generate-model-annotations.php)
 *
 * @property int $id
 * @property string $type
 * @property string $slug
 * @property string $title_ckb
 * @property string|null $title_ar
 * @property string|null $title_en
 * @property string|null $body_ckb
 * @property string|null $body_ar
 * @property string|null $body_en
 * @property string|null $excerpt_ckb
 * @property string|null $excerpt_ar
 * @property string|null $excerpt_en
 * @property string|null $search_key
 * @property string|null $cover_image_path
 * @property string|null $video_url
 * @property array<string, mixed>|null $related_entity_ids
 * @property string|null $seo_title
 * @property string|null $seo_description
 * @property bool $seo_indexable
 * @property string $status
 * @property Carbon|null $publish_at
 * @property Carbon|null $published_at
 * @property Carbon|null $unpublish_at
 * @property int|null $author_id
 * @property int|null $reviewed_by
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property Carbon|null $deleted_at
 *
 * ---- end generated model properties
 */
final class ContentItem extends Model
{
    use SoftDeletes;

    protected $fillable = [
        'type', 'slug',
        'title_ckb', 'title_ar', 'title_en',
        'body_ckb', 'body_ar', 'body_en',
        'excerpt_ckb', 'excerpt_ar', 'excerpt_en',
        'search_key', 'cover_image_path', 'video_url', 'related_entity_ids',
        'seo_title', 'seo_description', 'seo_indexable',
        'status', 'publish_at', 'published_at', 'unpublish_at',
        'author_id', 'reviewed_by',
    ];

    protected function casts(): array
    {
        return [
            'related_entity_ids' => 'array',
            'seo_indexable' => 'boolean',
            'publish_at' => 'datetime',
            'published_at' => 'datetime',
            'unpublish_at' => 'datetime',
        ];
    }

    /** The eight types §10.1 enumerates. */
    public const TYPES = [
        'page', 'news', 'market_analysis', 'video',
        'methodology', 'faq', 'update', 'opportunity',
    ];

    /**
     * @return BelongsTo<User, $this>
     */
    public function author(): BelongsTo
    {
        return $this->belongsTo(User::class, 'author_id');
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function reviewer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reviewed_by');
    }

    /**
     * @return HasMany<ContentRevision, $this>
     */
    public function revisions(): HasMany
    {
        return $this->hasMany(ContentRevision::class)->orderByDesc('revision');
    }

    /**
     * Publicly visible right now.
     *
     * Three conditions, not one. A `published` flag on an item whose
     * `unpublish_at` passed last week is a stale claim the interface would
     * present as current.
     *
     * @param  Builder<ContentItem>  $query
     * @return Builder<ContentItem>
     */
    public function scopeVisible(Builder $query): Builder
    {
        $now = now();

        return $query
            ->where('status', 'published')
            ->where(fn (Builder $q) => $q->whereNull('publish_at')->orWhere('publish_at', '<=', $now))
            ->where(fn (Builder $q) => $q->whereNull('unpublish_at')->orWhere('unpublish_at', '>', $now));
    }

    /**
     * @param  Builder<ContentItem>  $query
     * @return Builder<ContentItem>
     */
    public function scopeOfType(Builder $query, string $type): Builder
    {
        return $query->where('type', $type);
    }

    public function isVisible(): bool
    {
        if ($this->status !== 'published') {
            return false;
        }

        if ($this->publish_at !== null && $this->publish_at->isFuture()) {
            return false;
        }

        return $this->unpublish_at === null || $this->unpublish_at->isFuture();
    }

    /**
     * Title in the requested language, falling back to Sorani.
     *
     * Falls back to ckb rather than English: this is a Sorani-first platform,
     * and an editor who wrote only one language wrote that one.
     */
    public function title(?string $locale = null): string
    {
        return match ($locale ?? app()->getLocale()) {
            'ar' => $this->title_ar ?: $this->title_ckb,
            'en' => $this->title_en ?: $this->title_ckb,
            default => $this->title_ckb,
        };
    }

    public function body(?string $locale = null): ?string
    {
        return match ($locale ?? app()->getLocale()) {
            'ar' => $this->body_ar ?: $this->body_ckb,
            'en' => $this->body_en ?: $this->body_ckb,
            default => $this->body_ckb,
        };
    }

    /**
     * Snapshot the current state before it changes (§10.2 revisions).
     *
     * A full snapshot, matching what the schema comment already committed to:
     * reconstructing an old version by replaying diffs fails permanently the
     * moment one diff is lost, and the reason to keep revisions at all is the
     * day somebody needs to prove what the site said last March.
     */
    public function snapshot(?int $authorId, ?string $note = null): ContentRevision
    {
        $next = (int) $this->revisions()->max('revision') + 1;

        return ContentRevision::query()->create([
            'content_item_id' => $this->id,
            'revision' => $next,
            'snapshot' => $this->only($this->getFillable()),
            'author_id' => $authorId,
            'note' => $note,
        ]);
    }
}
