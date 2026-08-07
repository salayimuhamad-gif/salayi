<?php

declare(strict_types=1);

namespace App\Modules\Market\Services;

use App\Modules\Core\ValueObjects\Decimal;
use App\Modules\Market\Enums\PriceType;
use App\Modules\Market\Models\PriceRecord;
use App\Modules\Projects\Models\Project;

/**
 * Price history for one project's public profile (spec 14.1, 15.3).
 *
 * The published price records exist and no public page has ever read one. This
 * assembles them for a project profile — and the assembly is where spec 14.1
 * could be violated without any arithmetic being wrong.
 *
 * Series are grouped by price type and returned as SEPARATE series, never
 * merged into one line. A chart showing asking prices and verified transactions
 * as a single trend would be the most misleading thing this product could
 * render: the two diverge most in a falling market, which is exactly when a
 * buyer is relying on the distinction.
 */
final class ProjectPriceHistory
{
    /** Below this a series is not shown at all. */
    private const MINIMUM_POINTS = 2;

    /**
     * @return array{
     *     series: list<array<string, mixed>>,
     *     has_verified: bool,
     *     has_asking_only: bool,
     *     currency: string|null,
     *     period_from: string|null,
     *     period_to: string|null
     * }
     */
    public function forProject(Project $project): array
    {
        $records = PriceRecord::query()
            ->where('publication_status', 'published')
            ->where('is_outlier', false)
            ->where('scope_type', 'project')
            ->where('scope_id', $project->id)
            ->whereNotNull('price')
            ->orderBy('period')
            ->get();

        if ($records->isEmpty()) {
            return [
                'series' => [], 'has_verified' => false, 'has_asking_only' => false,
                'currency' => null, 'period_from' => null, 'period_to' => null,
            ];
        }

        $grouped = [];

        foreach ($records as $record) {
            // `price_records.price_type` is NOT NULL and enum-cast, so every
            // record carries a type; a row that somehow lacked one would fail
            // on read rather than arrive here as null.
            $type = $record->price_type;

            // Keyed on price type AND currency. Plotting USD and IQD figures on
            // one axis would produce a line that jumps by three orders of
            // magnitude and means nothing.
            $key = $type->value.'|'.$record->currency;

            $grouped[$key]['type'] = $type;
            $grouped[$key]['currency'] = $record->currency;
            $grouped[$key]['points'][] = [
                'period' => $record->period,
                'value' => (string) $record->price,
                'sample_size' => $record->sample_size,
                'source' => $record->source,
                'confidence' => $record->confidence,
            ];
        }

        $series = [];
        $hasVerified = false;

        foreach ($grouped as $group) {
            if (count($group['points']) < self::MINIMUM_POINTS) {
                continue;
            }

            /** @var PriceType $type */
            $type = $group['type'];

            if ($type->isTransactionEvidence()) {
                $hasVerified = true;
            }

            $series[] = [
                'price_type' => $type->value,
                'family' => $type->family(),
                // Spec 15.3's rule applied to a series rather than a single
                // figure: an asking-price line needs its qualifier as much as
                // an asking-price index does.
                'requires_qualifier' => $type->requiresPublicQualifier(),
                'currency' => $group['currency'],
                'points' => $group['points'],
                'change_percent' => $this->change($group['points']),
                'sources' => $this->sources($group['points']),
            ];
        }

        // Verified series first. An asking line rendered above a verified one
        // reads as the headline trend whatever its label says.
        usort($series, static function (array $a, array $b): int {
            $rank = static fn (string $family): int => ['verified' => 0, 'official' => 1, 'asking' => 2][$family] ?? 3;

            return $rank($a['family']) <=> $rank($b['family']);
        });

        $periods = $records->pluck('period')->filter()->sort()->values();

        return [
            'series' => $series,
            'has_verified' => $hasVerified,
            // The disclosure that matters: if the only thing on the page is
            // what sellers were asking, the reader is told so explicitly.
            'has_asking_only' => $series !== [] && ! $hasVerified,
            'currency' => $series[0]['currency'] ?? null,
            'period_from' => $periods->first(),
            'period_to' => $periods->last(),
        ];
    }

    /**
     * First-to-last change within one series.
     *
     * Only ever within a series — comparing an asking figure against a verified
     * one would produce a percentage that looks like a market movement and is
     * actually the spread between hope and reality.
     *
     * @param  list<array{period: string, value: string}>  $points
     */
    private function change(array $points): ?string
    {
        if (count($points) < 2) {
            return null;
        }

        $first = Decimal::of($points[0]['value'], 4);
        $change = $first->percentageChangeTo($points[count($points) - 1]['value'], 2);

        return $change?->toString();
    }

    /**
     * @param  list<array{source: string|null}>  $points
     * @return list<string>
     */
    private function sources(array $points): array
    {
        $sources = array_values(array_unique(array_filter(
            array_column($points, 'source'),
            static fn (?string $s): bool => $s !== null && $s !== '',
        )));

        sort($sources);

        return $sources;
    }
}
