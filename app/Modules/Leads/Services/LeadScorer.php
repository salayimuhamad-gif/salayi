<?php

declare(strict_types=1);

namespace App\Modules\Leads\Services;

use InvalidArgumentException;

/**
 * Explainable lead scoring (spec 23.3) and the consent gate that governs
 * whether a scored lead may be contacted at all.
 *
 * Spec 23.3 lists what must be stored: total score, rule version, EACH REASON,
 * points, event source, calculated date, manual override, override reason. So
 * the score is assembled from an itemised list rather than accumulated into a
 * bare integer — a sales agent asking "why is this a 78?" gets the arithmetic,
 * not a shrug.
 *
 * It then ends with one sentence that outranks everything else in the section:
 *
 *     "No marketing contact without valid consent."
 *
 * That is enforced separately from the score, and deliberately so. A lead can
 * be a perfect 100 and still be uncontactable. Scoring and permission are
 * different questions, and collapsing them is how a high-value lead ends up
 * contacted because someone reasoned that the score justified it.
 */
final class LeadScorer
{
    public const RULE_VERSION = 'v1';

    /**
     * Points per signal type (spec 23.2).
     *
     * Weighted by how much the action costs the user. Requesting a callback is
     * an explicit purchase intent; viewing a project page is idle curiosity,
     * and a hundred page views do not add up to one callback request.
     *
     * @var array<string, int>
     */
    public const SIGNAL_POINTS = [
        'callback_request' => 30,
        'company_contact' => 25,
        'portfolio_addition' => 18,
        'advisor_conversation' => 15,
        'saved_item' => 10,
        'comparison' => 8,
        'offer_view' => 6,
        'alert_subscription' => 5,
        'map_interaction' => 3,
        'project_view' => 2,
        'area_view' => 1,
    ];

    /**
     * Repetition multiplier and global ceiling for one signal type.
     *
     * The cap is RELATIVE to the signal's own weight, not a flat number. A flat
     * cap of 40 meant a hundred project views (2 points each, capped at 40)
     * outscored a single callback request (30) — idle browsing beating explicit
     * purchase intent, which inverts the entire point of the weighting. Caught
     * by the test suite.
     *
     * Three times the base weight lets genuine repeat interest register while
     * keeping a low-intent signal below any high-intent one however often it
     * repeats. The global ceiling then stops the strongest signals from
     * saturating the score on their own.
     */
    public const REPEAT_MULTIPLIER = 3;

    public const PER_SIGNAL_CEILING = 40;

    /**
     * @param  list<array{type: string, count?: int, source?: string|null, occurred_at?: string|null}>  $signals
     * @param  array{budget_max?: string|null, timeframe?: string|null, stage?: string|null}  $profile
     * @return array{
     *     total: int, rule_version: string, reasons: list<array{reason: string, points: int, source: string|null}>,
     *     calculated_at: string, is_overridden: bool, override_reason: string|null, uncapped_total: int
     * }
     */
    public function score(array $signals, array $profile = [], ?string $calculatedAt = null): array
    {
        $reasons = [];
        $perType = [];

        foreach ($signals as $signal) {
            $type = $signal['type'];

            if (! array_key_exists($type, self::SIGNAL_POINTS)) {
                // An unrecognised signal contributes nothing rather than a
                // default. A typo in an event name must not silently inflate a
                // lead score.
                continue;
            }

            $count = max(1, (int) ($signal['count'] ?? 1));
            $base = self::SIGNAL_POINTS[$type];
            $raw = $base * $count;

            $cap = min($base * self::REPEAT_MULTIPLIER, self::PER_SIGNAL_CEILING);
            $already = $perType[$type] ?? 0;
            $awarded = max(0, min($raw, $cap - $already));

            if ($awarded === 0) {
                continue;
            }

            $perType[$type] = $already + $awarded;

            $reasons[] = [
                'reason' => $count > 1 ? sprintf('%s_x%d', $type, $count) : $type,
                'points' => $awarded,
                'source' => isset($signal['source']) ? (string) $signal['source'] : null,
            ];
        }

        foreach ($this->profileReasons($profile) as $reason) {
            $reasons[] = $reason;
        }

        $uncapped = array_sum(array_column($reasons, 'points'));

        return [
            'total' => max(0, min(100, $uncapped)),
            'uncapped_total' => $uncapped,
            'rule_version' => self::RULE_VERSION,
            'reasons' => $reasons,
            'calculated_at' => $calculatedAt ?? date('c'),
            'is_overridden' => false,
            'override_reason' => null,
        ];
    }

    /**
     * A manual override replaces the score but never erases the reasoning that
     * produced the original (spec 23.3 stores both).
     *
     * @param  array<string, mixed>  $scored
     * @return array<string, mixed>
     */
    public function override(array $scored, int $manualScore, string $reason): array
    {
        if (trim($reason) === '') {
            throw new InvalidArgumentException(
                'A manual lead score override requires a reason (spec 23.3).',
            );
        }

        return array_merge($scored, [
            'total' => max(0, min(100, $manualScore)),
            'is_overridden' => true,
            'override_reason' => $reason,
            // Preserved, not replaced: the calculated reasoning stays visible
            // beside the human judgement that superseded it.
            'calculated_total' => $scored['total'],
        ]);
    }

    /**
     * @param  array{budget_max?: string|null, timeframe?: string|null, stage?: string|null}  $profile
     * @return list<array{reason: string, points: int, source: string|null}>
     */
    private function profileReasons(array $profile): array
    {
        $reasons = [];

        if (($profile['budget_max'] ?? null) !== null) {
            $reasons[] = ['reason' => 'budget_declared', 'points' => 10, 'source' => 'demand_profile'];
        }

        $timeframe = $profile['timeframe'] ?? null;

        if (in_array($timeframe, ['immediate', 'within_3_months'], true)) {
            $reasons[] = ['reason' => 'near_term_timeframe', 'points' => 15, 'source' => 'demand_profile'];
        }

        return $reasons;
    }
}
