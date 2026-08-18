<?php

declare(strict_types=1);

namespace App\Modules\Market\Services;

use App\Modules\Core\ValueObjects\Decimal;
use App\Modules\Market\Enums\PriceType;
use App\Modules\Market\Models\MarketIndex;
use App\Modules\Market\Models\MarketIndexValue;
use App\Modules\Market\Models\PriceRecord;
use App\Modules\Operations\Services\AuditLogger;

/**
 * Feeds published price records into IndexCalculator (spec 15.2).
 *
 * Step 3 built and proved the calculator — 64 assertions covering the refusal
 * to mix provenance, the publication floor, and the explainability contract —
 * and nothing ever supplied it with rows. This is the query layer that does.
 *
 * The index's own `price_type` is the filter, not a suggestion. A
 * `market_indices` row declares exactly one, and this only ever selects records
 * matching it, so a blended index is unreachable from here as well as
 * unrepresentable in the schema (spec 14.1).
 *
 * Only PUBLISHED price records participate. An imported row lands as draft, and
 * a draft feeding an index would make the review step decorative.
 */
final class IndexBuilder
{
    public function __construct(
        private readonly IndexCalculator $calculator,
        private readonly AuditLogger $audit,
    ) {}

    /**
     * Compute and store one index value for one period.
     *
     * @return array{stored: bool, reason: string|null, value: string|null, sample_size: int}
     */
    public function buildPeriod(MarketIndex $index, string $period): array
    {
        $observations = $this->observations($index, $period);

        $result = $this->calculator->calculate($period, $observations, [
            'minimum_sample' => $index->minimum_sample,
            'methodology_version' => $index->methodology_version,
            'basis' => $index->basis,
            'currency' => $index->currency,
        ]);

        // A period with no publishable value still records the attempt, so the
        // dashboard can distinguish "we looked and there was not enough" from
        // "nobody has computed this yet". Those look identical otherwise.
        $previous = MarketIndexValue::query()
            ->where('market_index_id', $index->id)
            ->where('period', '<', $period)
            ->orderByDesc('period')
            ->first();

        $change = null;

        if ($previous !== null && $result['value'] !== null && $previous->value !== null) {
            $comparison = $this->calculator->change([
                'value' => (string) $previous->value,
                'price_type_family' => $index->price_type->family(),
                'methodology_version' => $previous->methodology_version,
                'currency' => $index->currency,
                'basis' => $index->basis,
            ], $result);

            $change = $comparison['comparable'] ? $comparison['change_percent'] : null;
        }

        $revision = (int) MarketIndexValue::query()
            ->where('market_index_id', $index->id)
            ->where('period', $period)
            ->max('revision_number');

        $existing = MarketIndexValue::query()
            ->where('market_index_id', $index->id)
            ->where('period', $period)
            ->where('revision_number', $revision)
            ->first();

        // A published value is immutable; a recomputation is a new revision.
        // Overwriting would make the published figure a moving target.
        $isNewRevision = $existing !== null && $existing->publication_status === 'published';

        MarketIndexValue::query()->create([
            'market_index_id' => $index->id,
            'period' => $period,
            'effective_date' => $period.'-01',
            'value' => $result['value'],
            'median' => $result['median'],
            'minimum' => $result['min'],
            'maximum' => $result['max'],
            'change_percent' => $change,
            'sample_size' => $result['sample_size'],
            'excluded_outliers' => $result['excluded_outliers'],
            'contributing_sources' => $result['contributing_sources'],
            'confidence' => $result['confidence'],
            'is_limited' => $result['is_limited'],
            'warning' => $result['warning'],
            'methodology_version' => $result['methodology_version'],
            'revision_status' => $isNewRevision ? 'revised' : 'initial',
            'revision_number' => $isNewRevision ? $revision + 1 : $revision,
            'publication_status' => 'draft',
        ]);

        return [
            'stored' => true,
            'reason' => $result['warning'],
            'value' => $result['value'],
            'sample_size' => $result['sample_size'],
        ];
    }

    /**
     * Rebuild every period an index has data for.
     *
     * @return array{periods: int, published: int, insufficient: int}
     */
    public function rebuild(MarketIndex $index): array
    {
        $periods = $this->periodsWithData($index);
        $published = 0;
        $insufficient = 0;

        foreach ($periods as $period) {
            $result = $this->buildPeriod($index, $period);

            $result['value'] === null ? $insufficient++ : $published++;
        }

        $this->audit->record('market_index.rebuilt', $index, [], [
            'periods' => count($periods),
            'with_value' => $published,
            'insufficient' => $insufficient,
        ]);

        return ['periods' => count($periods), 'published' => $published, 'insufficient' => $insufficient];
    }

    /**
     * Published price records matching this index's declared scope and type.
     *
     * @return list<array{value: Decimal, price_type: PriceType, source: string|null}>
     */
    private function observations(MarketIndex $index, string $period): array
    {
        $query = PriceRecord::query()
            ->where('publication_status', 'published')
            ->where('is_outlier', false)
            // The index declares ONE price type. Spec 14.1 is enforced here by
            // the query as well as by the schema.
            ->where('price_type', $index->price_type->value)
            ->where('period', $period)
            ->when($index->property_type !== null,
                fn ($q) => $q->where('property_type', $index->property_type->value))
            ->where('scope_type', $index->scope_type->value)
            ->when($index->scope_id !== null, fn ($q) => $q->where('scope_id', $index->scope_id));

        return $query->get()
            ->map(function (PriceRecord $record) use ($index): ?array {
                // Prefer the per-sqm figure when the index measures that;
                // otherwise the headline price. A mixture would compare a total
                // against a rate.
                $value = $index->basis === 'per_sqm'
                    ? $record->pricePerSqmValue()
                    : ($record->priceValue() ?? $record->pricePerSqmValue());

                if ($value === null) {
                    return null;
                }

                return [
                    'value' => $value,
                    'price_type' => $record->price_type,
                    'source' => $record->source,
                ];
            })
            ->filter()
            ->values()
            ->all();
    }

    /** @return list<string> */
    private function periodsWithData(MarketIndex $index): array
    {
        return PriceRecord::query()
            ->where('publication_status', 'published')
            ->where('price_type', $index->price_type->value)
            ->where('scope_type', $index->scope_type->value)
            ->when($index->scope_id !== null, fn ($q) => $q->where('scope_id', $index->scope_id))
            ->when($index->property_type !== null,
                fn ($q) => $q->where('property_type', $index->property_type->value))
            ->select('period')
            ->distinct()
            ->orderBy('period')
            ->pluck('period')
            ->all();
    }
}
