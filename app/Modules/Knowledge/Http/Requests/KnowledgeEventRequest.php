<?php

declare(strict_types=1);

namespace App\Modules\Knowledge\Http\Requests;

use App\Modules\Knowledge\Enums\EvidenceClass;
use App\Modules\Knowledge\Enums\KnowledgeStatus;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

/**
 * The keys `validated()` can return are exactly the rules above, and every
 * one of them is a column on the model this request feeds. Stating that
 * lets the analyser verify `Model::create($request->validated())` instead of
 * seeing an array of unknown strings — and it fails loudly if a rule is ever
 * added for a field the model does not have.
 *
 * @method array<'title_ckb'|'title_ar'|'title_en'|'summary_ckb'|'summary_ar'|'summary_en'|'details'|'event_type'|'direction'|'strength'|'entity_type'|'entity_id'|'effective_date'|'expected_date'|'expires_on'|'source'|'source_url'|'confidence'|'evidence_class'|'ai_usage_permitted', mixed> validated(string|null $key = null, mixed $default = null)
 */
final class KnowledgeEventRequest extends FormRequest
{
    /** Spec 21.1. */
    public const EVENT_TYPES = [
        'road', 'bridge', 'electricity', 'water', 'internet', 'school', 'hospital',
        'mall', 'park', 'transport', 'delivery', 'construction', 'developer',
        'regulation', 'legal', 'demand', 'supply', 'price', 'rent', 'risk',
        'opportunity', 'company', 'news', 'planned_infrastructure',
    ];

    public function authorize(): bool
    {
        return $this->user()?->hasPermission(
            $this->route('event') ? 'knowledge.events.update' : 'knowledge.events.create',
        ) ?? false;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'title_ckb' => ['required', 'string', 'max:191'],
            'title_ar' => ['nullable', 'string', 'max:191'],
            'title_en' => ['nullable', 'string', 'max:191'],
            'summary_ckb' => ['nullable', 'string', 'max:2000'],
            'summary_ar' => ['nullable', 'string', 'max:2000'],
            'summary_en' => ['nullable', 'string', 'max:2000'],
            'details' => ['nullable', 'string', 'max:20000'],
            'event_type' => ['required', 'string', 'in:'.implode(',', self::EVENT_TYPES)],
            'direction' => ['required', 'string', 'in:positive,neutral,negative'],
            'strength' => ['required', 'integer', 'between:1,5'],
            'entity_type' => ['nullable', 'string', 'in:project,area,place,company'],
            'entity_id' => ['nullable', 'integer'],
            'effective_date' => ['required', 'date'],
            'expected_date' => ['nullable', 'date'],
            'expires_on' => ['nullable', 'date', 'after:effective_date'],
            'source' => ['nullable', 'string', 'max:191'],
            'source_url' => ['nullable', 'url', 'max:255'],
            'confidence' => ['required', 'string', 'in:low,medium,high'],
            /*
             * Phase 12: required on CREATE — every new event states what kind
             * of claim it makes. On update it stays optional so an edit to a
             * legacy (null-class) row is not held hostage; those rows read as
             * admin_observation until someone classifies them.
             */
            'evidence_class' => [
                $this->route('event') ? 'nullable' : 'required',
                'string',
                'in:'.implode(',', array_column(EvidenceClass::cases(), 'value')),
            ],
            'ai_usage_permitted' => ['boolean'],
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            $event = $this->route('event');

            // The model throws when a source is missing on approval. Doing the
            // check here turns that into a field message an editor can act on.
            $movingToEvidence = $event !== null
                && in_array($event->status, [KnowledgeStatus::Approved, KnowledgeStatus::Published], true);

            if ($movingToEvidence && trim((string) $this->input('source')) === '') {
                $validator->errors()->add('source', __('knowledge.errors.source_required'));
            }

            // Spec 17.5: an event AI may use is one the AI will cite. Permitting
            // an unsourced event for AI use means the assistant would state it
            // with no evidence to point at, which is the failure mode the whole
            // retrieval guard exists to prevent.
            if ($this->boolean('ai_usage_permitted') && trim((string) $this->input('source')) === '') {
                $validator->errors()->add('ai_usage_permitted', __('knowledge.errors.ai_needs_source'));
            }

            // An entity type without an id points at nothing.
            if ($this->input('entity_type') !== null && $this->input('entity_id') === null) {
                $validator->errors()->add('entity_id', __('knowledge.errors.entity_id_required'));
            }

            // Phase 12: a VERIFIED FACT is spoken directly with attribution —
            // "according to {source}". Without a source there is nothing to
            // attribute, and the claim belongs in another class.
            if ($this->input('evidence_class') === EvidenceClass::VerifiedFact->value
                && trim((string) $this->input('source')) === '') {
                $validator->errors()->add('evidence_class', __('knowledge.errors.fact_needs_source'));
            }
        });
    }
}
