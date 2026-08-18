<?php

declare(strict_types=1);

namespace App\Modules\Advisor\Services;

use App\Modules\Advisor\Enums\LifestylePriorityKind;

/**
 * The advisor's question flow and criteria extraction (File two §9.1).
 *
 * §9.1 requires the advisor to ask ONE question at a time, hold the
 * conversation's context, and end with an EDITABLE summary of what it
 * understood. This class owns all three, and it owns them deterministically —
 * in PHP, not in a prompt.
 *
 * That placement is the whole design. If the model decides what has been
 * answered, three things follow that this product cannot accept: the same
 * conversation can take different paths on different days, an answer can be
 * silently forgotten between turns, and nobody can explain to a buyer why they
 * were asked what they were asked. The model's job in this flow is to phrase a
 * question in natural Sorani, not to decide which question comes next.
 *
 * The slot order is not arbitrary. Purpose comes first because it changes what
 * the remaining answers mean — a five-year horizon is patience for a family and
 * an exit plan for an investor. Budget comes second because it is the strongest
 * disqualifier and asking eleven further questions before discovering nothing
 * is affordable wastes the person's time.
 */
final class AdvisorConversationFlow
{
    /**
     * The slots to fill, in order.
     *
     * `kind` marks a slot that becomes a lifestyle priority rather than a
     * scalar criterion, so the same conversation can feed LifestyleMatcher
     * without a second interview.
     *
     * @return list<array{key: string, required: bool, kind: ?string}>
     */
    public function slots(): array
    {
        return [
            ['key' => 'purpose', 'required' => true, 'kind' => null],
            /*
             * Correction v5, BLOCKER 6. The currency now comes BEFORE the
             * budget — the review's preferred design, and the one that
             * makes the amount question honest: once the unit is known,
             * the budget question can show quick replies denominated in
             * THAT currency, with product-approved realistic ranges (IQD
             * in millions), instead of a bare "150,000" whose meaning the
             * person only discovers after committing to it.
             *
             * It is `required` so the interview cannot complete while the
             * unit is unknown, but it is not a trap: an answer the parser
             * cannot classify resolves to the sentinel `unknown`, which
             * counts as answered here and is stored as NULL rather than
             * guessed. The person always gets out; the database never gets
             * a fiction.
             *
             * It is usually never ASKED at all: stating "١٥٠ هەزار دۆلار"
             * anywhere answers budget and currency in one turn, and the
             * question is skipped.
             */
            ['key' => 'currency', 'required' => true, 'kind' => null],
            ['key' => 'budget', 'required' => true, 'kind' => null],
            ['key' => 'property_type', 'required' => true, 'kind' => null],
            ['key' => 'area', 'required' => false, 'kind' => null],
            ['key' => 'workplace', 'required' => false, 'kind' => LifestylePriorityKind::Workplace->value],
            ['key' => 'schools', 'required' => false, 'kind' => LifestylePriorityKind::ChildOneSchool->value],
            ['key' => 'family', 'required' => false, 'kind' => LifestylePriorityKind::Parents->value],
            ['key' => 'timeframe', 'required' => false, 'kind' => null],
            ['key' => 'payment', 'required' => false, 'kind' => null],
            ['key' => 'risk', 'required' => false, 'kind' => null],
            ['key' => 'return_goal', 'required' => false, 'kind' => null],
        ];
    }

    /**
     * The next single question, or null when the interview is complete.
     *
     * Returns ONE key. A caller that wants to ask two questions at once has to
     * work around this deliberately rather than by accident.
     *
     * @param  array<string, mixed>  $criteria  what has been answered so far
     */
    public function nextSlot(array $criteria): ?string
    {
        foreach ($this->slots() as $slot) {
            if ($this->isAnswered($criteria, $slot['key'])) {
                continue;
            }

            // Return-goal and risk are investment questions. Asking a family
            // buying a home about their target yield is noise, and noise in an
            // interview is what makes people abandon it.
            if (in_array($slot['key'], ['return_goal', 'risk'], true)
                && ($criteria['purpose'] ?? null) !== 'investment') {
                continue;
            }

            return $slot['key'];
        }

        return null;
    }

    /**
     * Whether every required slot has an answer.
     *
     * @param  array<string, mixed>  $criteria  slot name => answer
     */
    public function isComplete(array $criteria): bool
    {
        foreach ($this->slots() as $slot) {
            if ($slot['required'] && ! $this->isAnswered($criteria, $slot['key'])) {
                return false;
            }
        }

        return true;
    }

    /**
     * How far through the interview the person is, 0–100.
     *
     * Counts only the slots that will actually be asked, so a residential
     * conversation does not appear stuck at 80% because two investment
     * questions it will never see are unanswered.
     *
     * @param  array<string, mixed>  $criteria
     */
    public function progress(array $criteria): int
    {
        $applicable = 0;
        $answered = 0;

        foreach ($this->slots() as $slot) {
            if (in_array($slot['key'], ['return_goal', 'risk'], true)
                && ($criteria['purpose'] ?? null) !== 'investment') {
                continue;
            }

            $applicable++;

            if ($this->isAnswered($criteria, $slot['key'])) {
                $answered++;
            }
        }

        return $applicable === 0 ? 100 : (int) round($answered / $applicable * 100);
    }

    /**
     * The editable summary §9.1 requires.
     *
     * Every slot is listed, including the unanswered ones, and each carries
     * whether it was stated or left blank. A summary that silently omits what
     * was never asked lets a person believe the advisor knew something it did
     * not — and they would then read the recommendations as more informed than
     * they are.
     *
     * @param  array<string, mixed>  $criteria
     * @return list<array<string, mixed>>
     */
    public function summary(array $criteria): array
    {
        $summary = [];

        foreach ($this->slots() as $slot) {
            if (in_array($slot['key'], ['return_goal', 'risk'], true)
                && ($criteria['purpose'] ?? null) !== 'investment') {
                continue;
            }

            $summary[] = [
                'key' => $slot['key'],
                'value' => $criteria[$slot['key']] ?? null,
                'answered' => $this->isAnswered($criteria, $slot['key']),
                'required' => $slot['required'],
                'editable' => true,
                'priority_kind' => $slot['kind'],
            ];
        }

        return $summary;
    }

    /**
     * Merge a newly extracted value into the criteria.
     *
     * Later answers overwrite earlier ones — a person correcting themselves is
     * the common case, and treating the first answer as authoritative would
     * make corrections impossible. A null or empty value never overwrites a
     * real one: a model that failed to extract anything must not erase what the
     * person already said.
     *
     * @param  array<string, mixed>  $criteria
     * @return array<string, mixed>
     */
    public function merge(array $criteria, string $slot, mixed $value): array
    {
        if ($value === null || $value === '' || $value === []) {
            return $criteria;
        }

        $criteria[$slot] = $value;

        return $criteria;
    }

    /**
     * @param  array<string, mixed>  $criteria
     */
    private function isAnswered(array $criteria, string $key): bool
    {
        $value = $criteria[$key] ?? null;

        return $value !== null && $value !== '' && $value !== [];
    }
}
