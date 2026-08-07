<?php

declare(strict_types=1);

namespace App\Modules\Knowledge\Models;

use App\Modules\Geography\Concerns\HasTrilingualNames;
use App\Modules\Knowledge\Enums\KnowledgeStatus;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Carbon;
use RuntimeException;

/**
 * A timeline event (spec 21).
 *
 * The Step 7 exit criterion is "event appears on project AND AI uses it with
 * evidence". The second half is what `aiUsable()` guards: an event reaches an
 * AI answer only when its status permits it (spec 17.5, no draft or expired)
 * AND its per-event permission is set (spec 21.2) AND it has not passed its
 * expiry date. Three independent conditions, because any one of them alone has
 * a plausible failure mode.
 *
 * ---- generated model properties (scripts/generate-model-annotations.php)
 *
 * @property int $id
 * @property string|null $entity_type
 * @property int|null $entity_id
 * @property string $title_ckb
 * @property string|null $title_ar
 * @property string|null $title_en
 * @property string|null $summary_ckb
 * @property string|null $summary_ar
 * @property string|null $summary_en
 * @property string|null $details
 * @property string|null $search_key
 * @property string $event_type
 * @property string $direction
 * @property int $strength
 * @property Carbon $effective_date
 * @property Carbon|null $expected_date
 * @property Carbon|null $publication_date
 * @property Carbon|null $expires_on
 * @property string|null $source
 * @property string|null $source_url
 * @property string|null $source_document_path
 * @property string $confidence
 * @property bool $ai_usage_permitted
 * @property KnowledgeStatus $status
 * @property int|null $author_id
 * @property int|null $reviewed_by
 * @property Carbon|null $reviewed_at
 * @property array<string, mixed>|null $related_project_ids
 * @property array<string, mixed>|null $related_area_ids
 * @property array<string, mixed>|null $related_place_ids
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property Carbon|null $deleted_at
 *
 * ---- end generated model properties
 */
final class KnowledgeEvent extends Model
{
    use HasTrilingualNames;
    use SoftDeletes;

    protected $table = 'knowledge_events';

    protected $fillable = [
        'entity_type', 'entity_id',
        'title_ckb', 'title_ar', 'title_en',
        'summary_ckb', 'summary_ar', 'summary_en', 'details',
        'event_type', 'direction', 'strength',
        'effective_date', 'expected_date', 'publication_date', 'expires_on',
        'source', 'source_url', 'source_document_path', 'confidence',
        'ai_usage_permitted', 'status',
        'related_project_ids', 'related_area_ids', 'related_place_ids',
    ];

    protected function casts(): array
    {
        return [
            'status' => KnowledgeStatus::class,
            'ai_usage_permitted' => 'boolean',
            'strength' => 'integer',
            'effective_date' => 'date',
            'expected_date' => 'date',
            'publication_date' => 'date',
            'expires_on' => 'date',
            'reviewed_at' => 'datetime',
            'related_project_ids' => 'array',
            'related_area_ids' => 'array',
            'related_place_ids' => 'array',
        ];
    }

    /**
     * @return MorphTo<Model, $this>
     */
    public function entity(): MorphTo
    {
        return $this->morphTo();
    }

    /** All three conditions, evaluated together. */
    public function aiUsable(): bool
    {
        if (! $this->status->isAiUsable() || ! $this->ai_usage_permitted) {
            return false;
        }

        return $this->expires_on === null || ! $this->expires_on->isPast();
    }

    /**
     * @param  Builder<KnowledgeEvent>  $query
     * @return Builder<KnowledgeEvent>
     */
    public function scopeAiUsable(Builder $query): Builder
    {
        return $query
            ->whereIn('status', [KnowledgeStatus::Approved->value, KnowledgeStatus::Published->value])
            ->where('ai_usage_permitted', true)
            ->where(function (Builder $q): void {
                $q->whereNull('expires_on')->orWhere('expires_on', '>=', now()->toDateString());
            });
    }

    /**
     * @param  Builder<KnowledgeEvent>  $query
     * @return Builder<KnowledgeEvent>
     */
    public function scopePublished(Builder $query): Builder
    {
        return $query->where('status', KnowledgeStatus::Published->value);
    }

    public function transitionTo(KnowledgeStatus $target): void
    {
        if (! $this->status->canTransitionTo($target)) {
            throw new RuntimeException(sprintf(
                'A knowledge event cannot move from "%s" to "%s".',
                $this->status->value,
                $target->value,
            ));
        }

        $this->status = $target;
    }

    protected static function booted(): void
    {
        self::saving(function (self $event): void {
            $event->syncSearchKey();

            // Spec 5: every public fact carries a source. An event with no
            // source cannot be approved, because approval is the point at which
            // it becomes usable as evidence.
            if (in_array($event->status, [KnowledgeStatus::Approved, KnowledgeStatus::Published], true)
                && trim((string) $event->source) === '') {
                throw new RuntimeException(
                    'A knowledge event cannot be approved or published without a source (spec 5, 21.2).',
                );
            }
        });
    }
}
