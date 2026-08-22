<?php

declare(strict_types=1);

namespace App\Modules\Portfolio\Services;

use App\Modules\Portfolio\Models\ValuationRuleSet;
use Illuminate\Support\Facades\Log;

/**
 * Which ACTIVE rule set applies to a property (Wave 6).
 *
 * Deterministic by construction: the same property facts always resolve to
 * the same set, whatever order rows were inserted in. Specificity decides —
 * a project-scoped set beats a global one, an explicit property-type list
 * beats "every type" — and the publisher's supersession keeps overlapping
 * actives from existing at equal specificity in the first place. If one
 * ever appears anyway (a race, a manual row), the tie breaks on
 * published_at DESC then id ASC and the anomaly is LOGGED, because a
 * silently arbitrary winner is exactly what this resolver exists to
 * prevent.
 *
 * Zero applicable sets is a normal, inert state: the valuation flow simply
 * behaves as it did before the rule engine existed.
 */
final class ValuationRuleResolver
{
    public function activeSetFor(?int $projectId, string $propertyType): ?ValuationRuleSet
    {
        /*
         * Active sets are inherently few — at most one per scope family —
         * so the candidate pool is fetched once and filtered in PHP, which
         * keeps the JSON property_types test identical on SQLite and
         * MariaDB instead of trusting two engines' JSON operators to agree.
         */
        $candidates = ValuationRuleSet::query()
            ->where('status', ValuationRuleSet::STATUS_ACTIVE)
            ->where('scope_transaction', ValuationRuleSet::SCOPE_TRANSACTION_SALE)
            ->orderByDesc('published_at')
            ->orderBy('id')
            ->get()
            ->filter(static fn (ValuationRuleSet $set): bool => $set->appliesTo($projectId, $propertyType))
            ->values();

        if ($candidates->isEmpty()) {
            return null;
        }

        $best = null;
        $bestScore = -1;
        $tied = false;

        foreach ($candidates as $candidate) {
            $score = $this->specificity($candidate);

            if ($score > $bestScore) {
                $best = $candidate;
                $bestScore = $score;
                $tied = false;

                continue;
            }

            if ($score === $bestScore) {
                // The earlier candidate (published_at DESC, id ASC) keeps
                // winning — deterministic — but the state is anomalous.
                $tied = true;
            }
        }

        if ($tied && $best !== null) {
            Log::warning('valuation_rules.ambiguous_active_scope', [
                'winner_id' => $best->id,
                'candidate_ids' => $candidates->pluck('id')->all(),
                'project_id' => $projectId,
                'property_type' => $propertyType,
            ]);
        }

        return $best;
    }

    /**
     * Project scope outweighs a type list: naming the project is the
     * stronger claim about WHICH properties the author meant.
     */
    private function specificity(ValuationRuleSet $set): int
    {
        $score = $set->project_id !== null ? 2 : 0;

        $types = $set->property_types;

        if ($types !== null && $types !== []) {
            $score += 1;
        }

        return $score;
    }
}
