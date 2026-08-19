<?php

declare(strict_types=1);

namespace App\Modules\Advisor\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;
use RuntimeException;

/**
 * One turn in an advisor conversation, with its full evidence record
 * (spec 17.4).
 *
 * A message that failed numeric grounding is WITHHELD, not deleted. Retaining
 * it is what makes the guard auditable — deleting the failures would leave no
 * evidence that anything was ever caught, and no way to tune the guard against
 * real cases.
 *
 * ---- generated model properties (scripts/generate-model-annotations.php)
 *
 * @property int $id
 * @property int $advisor_conversation_id
 * @property string $role
 * @property string $content
 * @property string $locale
 * @property string $content_class
 * @property array<string, mixed>|null $evidence_ids
 * @property array<string, mixed>|null $evidence_types
 * @property array<string, mixed>|null $evidence_dates
 * @property string|null $prompt_version
 * @property string|null $model
 * @property string|null $provider
 * @property Carbon|null $responded_at
 * @property string|null $validation_result
 * @property array<string, mixed>|null $validation_detail
 * @property string|null $cost_usd
 * @property int|null $latency_ms
 * @property int|null $prompt_tokens
 * @property int|null $completion_tokens
 * @property bool $is_withheld
 * @property string|null $withheld_reason
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 *
 * ---- end generated model properties
 */
final class AdvisorMessage extends Model
{
    protected $table = 'advisor_messages';

    protected $fillable = [
        'advisor_conversation_id', 'role', 'content', 'locale', 'content_class',
        'evidence_ids', 'evidence_types', 'evidence_dates', 'prompt_version',
        'model', 'provider', 'responded_at', 'validation_result', 'validation_detail',
        'cost_usd', 'latency_ms', 'prompt_tokens', 'completion_tokens',
        'is_withheld', 'withheld_reason',
    ];

    protected function casts(): array
    {
        return [
            'evidence_ids' => 'array',
            'evidence_types' => 'array',
            'evidence_dates' => 'array',
            'validation_detail' => 'array',
            'cost_usd' => 'decimal:6',
            'latency_ms' => 'integer',
            'prompt_tokens' => 'integer',
            'completion_tokens' => 'integer',
            'is_withheld' => 'boolean',
            'responded_at' => 'datetime',
        ];
    }

    /** Spec 17.5: fact, analysis, scenario and advertisement stay distinct. */
    public const CONTENT_CLASSES = ['fact', 'analysis', 'scenario', 'advertisement'];

    /**
     * @return BelongsTo<AdvisorConversation, $this>
     */
    public function conversation(): BelongsTo
    {
        return $this->belongsTo(AdvisorConversation::class, 'advisor_conversation_id');
    }

    /**
     * Only messages that passed validation may be rendered.
     *
     * @param  Builder<AdvisorMessage>  $query
     * @return Builder<AdvisorMessage>
     */
    public function scopeRenderable(Builder $query): Builder
    {
        return $query->where('is_withheld', false)
            ->where(function (Builder $q): void {
                $q->whereNull('validation_result')->orWhere('validation_result', 'passed');
            });
    }

    public function isRenderable(): bool
    {
        return ! $this->is_withheld
            && in_array($this->validation_result, [null, 'passed'], true);
    }

    protected static function booted(): void
    {
        self::saving(function (self $message): void {
            if ($message->role !== 'assistant') {
                return;
            }

            // Spec 17.4 requires the evidence record on every AI answer. An
            // assistant message that has never been validated must not be
            // storable in a renderable state — the absence of a verdict is not
            // a pass.
            if ($message->validation_result === null && ! $message->is_withheld) {
                $message->is_withheld = true;
                $message->withheld_reason = 'not_validated';
            }

            if (! in_array($message->content_class, self::CONTENT_CLASSES, true)) {
                throw new RuntimeException(sprintf(
                    'Unknown content class "%s". Spec 17.5 requires fact, analysis, scenario and advertisement to remain distinguishable.',
                    (string) $message->content_class,
                ));
            }
        });
    }
}
