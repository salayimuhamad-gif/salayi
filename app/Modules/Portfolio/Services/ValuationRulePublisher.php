<?php

declare(strict_types=1);

namespace App\Modules\Portfolio\Services;

use App\Modules\Core\ValueObjects\Decimal;
use App\Modules\Portfolio\Exceptions\ValuationRulePublishException;
use App\Modules\Portfolio\Models\ValuationQuestion;
use App\Modules\Portfolio\Models\ValuationQuestionOption;
use App\Modules\Portfolio\Models\ValuationRuleSet;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;

/**
 * The rule-set lifecycle (Wave 6): draft -> active -> retired, with every
 * transition validated where it cannot be skipped.
 *
 * Publishing is the moment a set starts governing real valuations, so it is
 * where the guarantees are proven, atomically:
 *
 *   - the draft must actually ASK something: at least one active question,
 *     each with at least one active option, every percent inside the
 *     authoring bound — a set that passes cannot render an empty form or
 *     apply an unbounded percentage;
 *   - actives in the same scope family whose applicability intersects the
 *     draft's are its predecessors and are retired IN THE SAME TRANSACTION,
 *     so there is no moment when two sets both claim the same property;
 *   - a post-transition assertion re-reads the family and REFUSES (rolling
 *     everything back) if an overlapping active would remain — the race
 *     nobody expects becomes a refusal instead of an ambiguous state the
 *     resolver has to tiebreak forever.
 *
 * Corrections never edit published content: duplicateAsDraft() copies a set
 * into version N+1 with fresh rows, which is what keeps every persisted
 * answer meaning exactly what it meant when the owner gave it.
 */
final class ValuationRulePublisher
{
    /** The authoring bound for a single option's |percent| (spec ±25). */
    public const MAX_ABS_ADJUSTMENT_PERCENT = '25.000';

    public function publish(ValuationRuleSet $set): ValuationRuleSet
    {
        if (! $set->isDraft()) {
            throw new ValuationRulePublishException('not_a_draft', ['status' => $set->status]);
        }

        $questions = $set->questions()->where('is_active', true)->with('options')->get();

        if ($questions->isEmpty()) {
            throw new ValuationRulePublishException('no_active_questions');
        }

        foreach ($questions as $question) {
            $activeOptions = $question->options->where('is_active', true);

            if ($activeOptions->isEmpty()) {
                throw new ValuationRulePublishException('question_without_options', [
                    'question_key' => $question->key,
                ]);
            }

            foreach ($activeOptions as $option) {
                $percent = Decimal::of((string) $option->adjustment_percent, 3);

                if ($percent->abs()->compareTo(self::MAX_ABS_ADJUSTMENT_PERCENT) > 0) {
                    throw new ValuationRulePublishException('adjustment_out_of_bounds', [
                        'question_key' => $question->key,
                        'option_key' => $option->key,
                        'adjustment_percent' => $percent->toString(),
                    ]);
                }
            }
        }

        return DB::transaction(function () use ($set): ValuationRuleSet {
            /*
             * Predecessors: actives in the same family whose applicability
             * intersects. Locked before reading so two concurrent publishes
             * serialise instead of both retiring the same predecessor and
             * both going active.
             */
            $predecessors = $this->familyActives($set)
                ->lockForUpdate()
                ->get()
                ->filter(fn (ValuationRuleSet $active): bool => $this->scopeIntersects($active, $set));

            foreach ($predecessors as $predecessor) {
                $predecessor->status = ValuationRuleSet::STATUS_RETIRED;
                $predecessor->retired_at = now();
                $predecessor->save();
            }

            $set->status = ValuationRuleSet::STATUS_ACTIVE;
            $set->published_at = now();
            $set->save();

            /*
             * The fail-closed assertion. By construction nothing should
             * remain, but a publish that would leave TWO active sets both
             * applicable to one property must refuse — throwing here rolls
             * back the whole transition, predecessor retirements included.
             */
            $overlapping = $this->familyActives($set)
                ->whereKeyNot($set->id)
                ->get()
                ->filter(fn (ValuationRuleSet $active): bool => $this->scopeIntersects($active, $set));

            if ($overlapping->isNotEmpty()) {
                throw new ValuationRulePublishException('ambiguous_scope', [
                    'conflicting_ids' => $overlapping->pluck('id')->all(),
                ]);
            }

            return $set->refresh();
        });
    }

    public function retire(ValuationRuleSet $set): ValuationRuleSet
    {
        if (! $set->isActive()) {
            throw new ValuationRulePublishException('not_active', ['status' => $set->status]);
        }

        $set->status = ValuationRuleSet::STATUS_RETIRED;
        $set->retired_at = now();
        $set->save();

        return $set->refresh();
    }

    /**
     * A correction: the source's full content copied into a NEW draft at
     * version max+1 with fresh row ids. The source is not touched — its
     * rows are what persisted answers and history reference.
     */
    public function duplicateAsDraft(ValuationRuleSet $source, ?int $actorId = null): ValuationRuleSet
    {
        return DB::transaction(function () use ($source, $actorId): ValuationRuleSet {
            $draft = ValuationRuleSet::query()->create([
                'name' => $source->name,
                'scope_transaction' => $source->scope_transaction,
                'project_id' => $source->project_id,
                'property_types' => $source->property_types,
                'version' => $this->nextVersion($source->scope_transaction, $source->project_id),
                'status' => ValuationRuleSet::STATUS_DRAFT,
                'created_by' => $actorId,
            ]);

            foreach ($source->questions()->with('options')->get() as $question) {
                /** @var ValuationQuestion $copy */
                $copy = $draft->questions()->create([
                    'key' => $question->key,
                    'question_type' => $question->question_type,
                    'label_ckb' => $question->label_ckb,
                    'label_ar' => $question->label_ar,
                    'label_en' => $question->label_en,
                    'help_ckb' => $question->help_ckb,
                    'help_ar' => $question->help_ar,
                    'help_en' => $question->help_en,
                    'sort_order' => $question->sort_order,
                    'is_active' => $question->is_active,
                ]);

                foreach ($question->options as $option) {
                    /** @var ValuationQuestionOption $option */
                    $copy->options()->create([
                        'key' => $option->key,
                        'label_ckb' => $option->label_ckb,
                        'label_ar' => $option->label_ar,
                        'label_en' => $option->label_en,
                        'adjustment_percent' => (string) $option->adjustment_percent,
                        'sort_order' => $option->sort_order,
                        'is_active' => $option->is_active,
                    ]);
                }
            }

            return $draft;
        });
    }

    /**
     * The next free version in a scope family. Computed, not trusted from
     * input: the unique index cannot police NULL-project families (NULLs
     * never collide in a unique index), so the sequence is derived from
     * the family's actual maximum every time.
     */
    public function nextVersion(string $scopeTransaction, ?int $projectId): int
    {
        $max = ValuationRuleSet::query()
            ->where('scope_transaction', $scopeTransaction)
            ->when(
                $projectId === null,
                static fn ($query) => $query->whereNull('project_id'),
                static fn ($query) => $query->where('project_id', $projectId),
            )
            ->max('version');

        return ((int) $max) + 1;
    }

    /**
     * Active sets in the same scope family (same transaction basis, same
     * project scope), excluding none — callers filter further.
     *
     * @return Builder<ValuationRuleSet>
     */
    private function familyActives(ValuationRuleSet $set): Builder
    {
        return ValuationRuleSet::query()
            ->where('status', ValuationRuleSet::STATUS_ACTIVE)
            ->where('scope_transaction', $set->scope_transaction)
            ->when(
                $set->project_id === null,
                static fn ($query) => $query->whereNull('project_id'),
                static fn ($query) => $query->where('project_id', $set->project_id),
            );
    }

    /**
     * Whether two sets could both claim one property: a null or empty type
     * list means "every type", so it intersects everything; two explicit
     * lists intersect when they share a type.
     */
    private function scopeIntersects(ValuationRuleSet $a, ValuationRuleSet $b): bool
    {
        $typesA = $a->property_types;
        $typesB = $b->property_types;

        if ($typesA === null || $typesA === [] || $typesB === null || $typesB === []) {
            return true;
        }

        return array_intersect($typesA, $typesB) !== [];
    }
}
