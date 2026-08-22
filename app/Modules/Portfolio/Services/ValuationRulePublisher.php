<?php

declare(strict_types=1);

namespace App\Modules\Portfolio\Services;

use App\Modules\Core\ValueObjects\Decimal;
use App\Modules\Portfolio\Exceptions\ValuationRulePublishException;
use App\Modules\Portfolio\Models\ValuationQuestion;
use App\Modules\Portfolio\Models\ValuationQuestionOption;
use App\Modules\Portfolio\Models\ValuationRuleSet;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;

/**
 * The rule-set lifecycle (Wave 6): draft -> active -> retired, with every
 * transition validated where it cannot be skipped.
 *
 * Publishing is the moment a set starts governing real valuations, so it is
 * where the guarantees are proven, atomically and UNDER LOCK — everything
 * happens inside one transaction, in this order:
 *
 *   - the target row is locked and re-read FIRST; every later decision
 *     reads that fresh row, never the caller's instance (probes 4b/4c:
 *     a scope that drifted between load and publish used to aim
 *     supersession at the wrong family);
 *   - ALL of the target's questions and options are locked — inactive
 *     rows included, because an inactive row that can still be flipped
 *     is content that escapes validation (probes 5g/5h) — and the ACTIVE
 *     subset of those locked rows is validated: at least one active
 *     question, each with at least one active option, every percent
 *     inside the authoring bound;
 *   - actives in the same scope family whose applicability intersects the
 *     target's are its predecessors and are retired IN THE SAME
 *     TRANSACTION, so there is no moment when two sets both claim the
 *     same property;
 *   - after activation, a final FRESH re-read re-proves everything from
 *     the database itself: the target is active in its persisted scope,
 *     the persisted active content still satisfies the publish
 *     invariants, and no overlapping active remains in the persisted
 *     family. Anything a racer slipped past the in-memory picture
 *     becomes a refusal that rolls the whole transition back (probe 4a).
 *
 * Corrections never edit published content: duplicateAsDraft() copies a set
 * into version N+1 with fresh rows, which is what keeps every persisted
 * answer meaning exactly what it meant when the owner gave it.
 *
 * This service is the ONLY lifecycle channel. The model refuses every dirty
 * lifecycle column through ordinary saves in every state, so the publisher
 * persists its own validated transitions with saveQuietly() — an event
 * bypass that exists only at these three call sites, never as a writable
 * flag somebody else could set.
 */
final class ValuationRulePublisher
{
    /** The authoring bound for a single option's |percent| (spec ±25). */
    public const MAX_ABS_ADJUSTMENT_PERCENT = '25.000';

    public function publish(ValuationRuleSet $set): ValuationRuleSet
    {
        return DB::transaction(function () use ($set): ValuationRuleSet {
            /*
             * Lock and RE-READ the target first. The caller's instance may
             * be stale in status, scope or content; from here on only this
             * fresh row decides anything.
             */
            $target = ValuationRuleSet::query()
                ->whereKey($set->getKey())
                ->lockForUpdate()
                ->firstOrFail();

            if (! $target->isDraft()) {
                throw new ValuationRulePublishException('not_a_draft', ['status' => $target->status]);
            }

            /*
             * Lock EVERY question and EVERY option — inactive rows too. An
             * unlocked inactive row could be flipped active behind the
             * validation below, becoming published content nothing ever
             * checked. The ACTIVE subset of these locked rows is what gets
             * validated.
             */
            $questions = $target->questions()
                ->lockForUpdate()
                ->with(['options' => static function ($query): void {
                    $query->lockForUpdate();
                }])
                ->get();

            $this->assertPublishable($questions);

            /*
             * Predecessors: actives in the same family whose applicability
             * intersects the LOCKED target's persisted scope. Locked before
             * reading so two concurrent publishes serialise instead of both
             * retiring the same predecessor and both going active.
             */
            $predecessors = $this->familyActives($target)
                ->lockForUpdate()
                ->get()
                ->filter(fn (ValuationRuleSet $active): bool => $this->scopeIntersects($active, $target));

            /*
             * saveQuietly(): the model's updating guard refuses EVERY
             * lifecycle change through ordinary saves, publisher-shaped or
             * not. These transitions are validated above and serialised by
             * the locks, so they bypass the events instead of carrying a
             * bypass flag the guard would have to trust.
             */
            foreach ($predecessors as $predecessor) {
                $predecessor->status = ValuationRuleSet::STATUS_RETIRED;
                $predecessor->retired_at = now();
                $predecessor->saveQuietly();
            }

            $target->status = ValuationRuleSet::STATUS_ACTIVE;
            $target->published_at = now();
            $target->saveQuietly();

            /*
             * The fail-closed FINAL assertion, re-proven from FRESH reads
             * rather than anything this method held in memory: the row must
             * be active in its persisted scope, the persisted active
             * content must still satisfy every publish invariant, and no
             * overlapping active may remain in the persisted family. A
             * throw here rolls back the whole transition, predecessor
             * retirements included.
             */
            $fresh = ValuationRuleSet::query()->findOrFail($target->getKey());

            if (! $fresh->isActive()) {
                throw new ValuationRulePublishException('ambiguous_scope', [
                    'conflicting_ids' => [],
                ]);
            }

            $this->assertPublishable($fresh->questions()->with('options')->get());

            $overlapping = $this->familyActives($fresh)
                ->whereKeyNot($fresh->getKey())
                ->get()
                ->filter(fn (ValuationRuleSet $active): bool => $this->scopeIntersects($active, $fresh));

            if ($overlapping->isNotEmpty()) {
                throw new ValuationRulePublishException('ambiguous_scope', [
                    'conflicting_ids' => $overlapping->pluck('id')->all(),
                ]);
            }

            return $fresh;
        });
    }

    /**
     * The content contract a set must satisfy to govern valuations, judged
     * on the ACTIVE subset of the given rows: at least one active question,
     * every active question with at least one active option, every active
     * option inside the authoring bound. Runs twice per publish — once on
     * the locked rows before any transition, once on a fresh re-read just
     * before the transaction commits.
     *
     * @param  Collection<int, ValuationQuestion>  $questions
     */
    private function assertPublishable(Collection $questions): void
    {
        $active = $questions->where('is_active', true);

        if ($active->isEmpty()) {
            throw new ValuationRulePublishException('no_active_questions');
        }

        foreach ($active as $question) {
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
    }

    public function retire(ValuationRuleSet $set): ValuationRuleSet
    {
        return DB::transaction(static function () use ($set): ValuationRuleSet {
            /*
             * Same discipline as publish: lock, re-read, judge the
             * PERSISTED status. A stale instance that still remembers
             * being active — a double retire, a raced admin tab — refuses
             * here instead of rewriting retired_at (probe 4d).
             */
            $target = ValuationRuleSet::query()
                ->whereKey($set->getKey())
                ->lockForUpdate()
                ->firstOrFail();

            if (! $target->isActive()) {
                throw new ValuationRulePublishException('not_active', ['status' => $target->status]);
            }

            // The third and last of the publisher's quiet saves — the
            // explicit active -> retired transition, validated under lock.
            $target->status = ValuationRuleSet::STATUS_RETIRED;
            $target->retired_at = now();
            $target->saveQuietly();

            return $target->refresh();
        });
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
     * The next free version in a scope family, derived from the family's
     * actual maximum every time — computed, never trusted from input.
     *
     * Uniqueness itself is the database's: the project_family generated key
     * (coalesce(project_id, 0), migration 2026_08_22_000200) folds the
     * NULL-project family into an indexable value, so when two writers both
     * read MAX+1 concurrently the second insert dies on the unique index
     * instead of minting a duplicate version.
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
