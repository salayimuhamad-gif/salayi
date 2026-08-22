<?php

declare(strict_types=1);

namespace App\Modules\Portfolio\Services;

use App\Modules\Portfolio\Exceptions\ValuationRulePublishException;
use App\Modules\Portfolio\Models\ValuationRuleSet;
use Closure;
use Illuminate\Support\Facades\DB;

/**
 * The single serialization point for STRUCTURAL draft edits (Wave 6
 * hardening).
 *
 * Every write that changes what a rule set says — the set row's content,
 * its questions, their options — races the publisher for the same row:
 * an editor that observed DRAFT and a publisher that wins the lock used
 * to interleave so the edit landed on the now-ACTIVE set (probe rounds
 * #257/#259). The cure is one contract, owned here and nowhere else:
 *
 *   transaction -> lock the parent rule-set row -> re-check the
 *   PERSISTED status under that lock -> only then touch anything.
 *
 * If the edit wins the lock it commits first and the publisher validates
 * the final content; if the publisher wins, the edit's re-check reads
 * ACTIVE (or RETIRED) and refuses. Callers must resolve every mutable
 * child THROUGH the locked set inside the operation — a preloaded model
 * from before the lock is exactly the staleness this exists to kill.
 *
 * On MariaDB the guarantee is the InnoDB row lock. On SQLite,
 * lockForUpdate() compiles to nothing; the equivalent safety comes from
 * the database-level writer lock, under which a competing write either
 * serialises behind the transaction or fails busy — never interleaves.
 */
final class ValuationRuleEditor
{
    /**
     * Run one structural draft edit against the locked, freshly-read set.
     *
     * @template TReturn
     *
     * @param  Closure(ValuationRuleSet): TReturn  $operation
     * @return TReturn
     *
     * @throws ValuationRulePublishException when the persisted row is no
     *                                       longer a draft ('frozen').
     */
    public function withLockedDraft(int $setId, Closure $operation): mixed
    {
        /** @var TReturn $result */
        $result = DB::transaction(static function () use ($setId, $operation): mixed {
            $locked = ValuationRuleSet::query()
                ->whereKey($setId)
                ->lockForUpdate()
                ->firstOrFail();

            if (! $locked->isDraft()) {
                // The race's honest outcome: whoever loaded this set as a
                // draft lost to a publish (or retire) — the edit refuses
                // instead of rewriting published content.
                throw new ValuationRulePublishException('frozen', ['status' => $locked->status]);
            }

            return $operation($locked);
        });

        return $result;
    }

    /**
     * Delete a set that is not ACTIVE — drafts and retired sets are both
     * deletable (history lives in valuation snapshots, never here), and
     * the same lock + persisted re-check keeps a stale draft-era request
     * from deleting a set that activated meanwhile.
     *
     * Returns the deleted model so the caller can audit its last state.
     *
     * @throws ValuationRulePublishException when the persisted row is
     *                                       active ('delete_active').
     */
    public function deleteSetIfNotActive(int $setId): ValuationRuleSet
    {
        return DB::transaction(static function () use ($setId): ValuationRuleSet {
            $locked = ValuationRuleSet::query()
                ->whereKey($setId)
                ->lockForUpdate()
                ->firstOrFail();

            if ($locked->isActive()) {
                throw new ValuationRulePublishException('delete_active', ['status' => $locked->status]);
            }

            $locked->delete();

            return $locked;
        });
    }
}
