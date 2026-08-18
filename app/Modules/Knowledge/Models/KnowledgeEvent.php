<?php

declare(strict_types=1);

namespace App\Modules\Knowledge\Models;

use App\Modules\Geography\Concerns\HasTrilingualNames;
use App\Modules\Knowledge\Enums\EvidenceClass;
use App\Modules\Knowledge\Enums\KnowledgeStatus;
use App\Modules\Localization\Support\SoraniText;
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
 * @property EvidenceClass|null $evidence_class
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
        'evidence_class', 'ai_usage_permitted', 'status',
        'related_project_ids', 'related_area_ids', 'related_place_ids',
    ];

    protected function casts(): array
    {
        return [
            'status' => KnowledgeStatus::class,
            'evidence_class' => EvidenceClass::class,
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

    /**
     * The class this event's claims are spoken with (Phase 12).
     *
     * Null — every pre-Phase-12 row — reads as admin_observation, never as
     * verified_fact: an unclassified note is at most something the team
     * recorded. Callers that phrase answers MUST use this accessor rather
     * than the raw column, so the conservative default cannot be skipped.
     */
    public function effectiveEvidenceClass(): EvidenceClass
    {
        return $this->evidence_class ?? EvidenceClass::AdminObservation;
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

    /**
     * What a PUBLIC page may show (Phase 13): Published AND not past its
     * expiry date.
     *
     * The hourly sweep flips expired Published rows to Expired through the
     * real state machine, so the inline date guard only closes the sub-hour
     * window between a row lapsing and the sweep noticing — the same
     * belt-and-braces stance scopeAiUsable() takes. Deliberately DISTINCT
     * from scopeAiUsable(): Approved is AI evidence, not a public statement,
     * and ai_usage_permitted has never gated visibility (spec 21.3).
     *
     * @param  Builder<KnowledgeEvent>  $query
     * @return Builder<KnowledgeEvent>
     */
    public function scopePubliclyCurrent(Builder $query): Builder
    {
        return $query
            ->published()
            ->where(function (Builder $q): void {
                $q->whereNull('expires_on')->orWhere('expires_on', '>=', now()->toDateString());
            });
    }

    /** Localized title, house fallback chain [locale, ckb, en, ar]. */
    public function title(?string $locale = null): string
    {
        return $this->trilingual('title', $locale);
    }

    /** Localized summary, same chain; null when no locale has one. */
    public function summary(?string $locale = null): ?string
    {
        $value = $this->trilingual('summary', $locale);

        return $value === '' ? null : $value;
    }

    /**
     * Title-keyed override of the trait's name-keyed derivation (Phase 15).
     *
     * HasTrilingualNames::syncSearchKey() reads name_ckb/name_ar/name_en —
     * columns this table has never had — so every save wrote an EMPTY key
     * and the admin knowledge text search could not match a single row.
     * The trait serves eight healthy name_* consumers and stays untouched;
     * this model already localizes its name-centric API (title() and
     * summary() beside name()), and the search key follows the same route:
     * identical fold, identical column, titles as the source. Summaries and
     * details are excluded on purpose — every sibling keys its names only,
     * and the admin contract is "find the event by its title".
     */
    public function syncSearchKey(): void
    {
        $this->setAttribute('search_key', SoraniText::searchKey(implode(' ', array_filter([
            (string) ($this->title_ckb ?? ''),
            (string) ($this->title_ar ?? ''),
            (string) ($this->title_en ?? ''),
        ]))));
    }

    /**
     * The one public shape of a timeline entry (Phase 13), shared by every
     * public consumer so the project and area timelines cannot drift apart.
     *
     * Only visitor-facing facts: no ai_usage_permitted, no author/reviewer
     * ids, no internal details, no related_* metadata, no workflow state.
     * Confidence stays because the shipped timeline component has always
     * rendered it — an existing public contract, not a Phase 13 addition.
     *
     * @return array<string, mixed>
     */
    public function publicTimelineEntry(): array
    {
        return [
            'id' => $this->id,
            'title' => $this->title(),
            'summary' => $this->summary(),
            'event_type' => $this->event_type,
            'direction' => $this->direction,
            'strength' => $this->strength,
            // effectiveEvidenceClass(), never the raw column: a legacy null
            // must read as an observation, never as a verified fact.
            'evidence_class' => $this->effectiveEvidenceClass()->value,
            'effective_date' => $this->effective_date->toDateString(),
            'expected_date' => $this->expected_date?->toDateString(),
            'source' => $this->source,
            'source_url' => $this->source_url,
            'confidence' => $this->confidence,
        ];
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
