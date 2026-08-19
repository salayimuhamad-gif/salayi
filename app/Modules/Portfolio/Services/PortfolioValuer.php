<?php

declare(strict_types=1);

namespace App\Modules\Portfolio\Services;

use App\Modules\Geography\Models\Area;
use App\Modules\Market\Enums\ScopeType;
use App\Modules\Market\Models\PriceRecord;
use App\Modules\Portfolio\Models\PortfolioProperty;
use App\Modules\Portfolio\Models\PortfolioValuation;
use App\Modules\Projects\Models\Project;

/**
 * The valuation TRIGGER (spec §11): the missing link between the shipped
 * ValuationEngine — which is a pure function over comparables — and a button
 * an owner can press.
 *
 * What feeds the engine, and what deliberately does not:
 *
 *   - PUBLISHED price records only, through the model's own published()
 *     scope. Draft and internal figures never participate.
 *   - The evidence for THIS property's lineage: records scoped to its
 *     project, its area, and its area's ancestors (the materialised path
 *     gives ancestry as a prefix test, so "wider area" is real geography
 *     rather than a keyword).
 *   - Records are AGGREGATE statistics — a median for a scope and period,
 *     not a unit sale — so no per-comparable size is invented for them.
 *     The engine's size-matched tiers therefore stay out of reach and the
 *     honest outcome is a wider-area figure or an explicit refusal, which
 *     is exactly what an aggregate can support. Fabricating unit-level
 *     comparables from a per-square-metre figure would let the result claim
 *     a match precision the data does not have.
 *
 * Cost, because this runs on shared hosting: one bounded query for the
 *  ancestor areas, one bounded query for the records, and arithmetic. No
 * queue is needed for that; the route-level rate limit bounds the worst
 * case instead.
 */
final class PortfolioValuer
{
    private const MAX_COMPARABLES = 400;

    public function __construct(private readonly ValuationEngine $engine) {}

    /**
     * Value the property from published evidence and append the result —
     * including a refusal, which is a result worth keeping — to its history.
     */
    public function value(PortfolioProperty $property): PortfolioValuation
    {
        [$areaPath, $ancestorIds] = $this->lineage($property);

        $comparables = $this->comparables($property, $ancestorIds);

        $result = $this->engine->value(
            [
                'project_id' => $property->project_id,
                'area_id' => $property->area_id,
                'area_path' => $areaPath,
                'property_type' => $property->property_type,
                'unit_type' => $property->unit_type,
                'size_sqm' => $property->size_sqm,
            ],
            $comparables,
            $property->currency,
        );

        return PortfolioValuation::query()->create([
            'portfolio_property_id' => $property->id,
            'midpoint' => $result['midpoint'],
            'low' => $result['low'],
            'high' => $result['high'],
            'currency' => $result['currency'],
            'confidence' => $result['confidence'],
            'match_level' => $result['match_level'],
            'match_label' => $result['match_label'],
            'methodology' => $result['methodology'],
            'comparison_count' => $result['comparison_count'],
            'data_period_from' => $result['data_period']['from'],
            'data_period_to' => $result['data_period']['to'],
            'excluded_asking_count' => $result['excluded_asking_count'],
            'excluded_asking_note' => $result['excluded_asking_note'],
            // Null on success; on refusal it carries WHY, and the interface
            // shows that sentence instead of a fabricated number.
            'no_valuation_reason' => $result['reason'],
            'calculated_at' => now(),
        ]);
    }

    /**
     * The property's area path and every ancestor id, self included.
     *
     * @return array{0: string|null, 1: list<int>}
     */
    private function lineage(PortfolioProperty $property): array
    {
        $areaId = $property->area_id;

        /*
         * A property linked to a project but not to an area still HAS a
         * location — the project's. Falling back to it is reading a fact the
         * platform already models, not guessing one.
         */
        if ($areaId === null && $property->project_id !== null) {
            $areaId = Project::query()
                ->whereKey($property->project_id)
                ->value('area_id');
        }

        if ($areaId === null) {
            return [null, []];
        }

        $area = Area::query()->find($areaId);

        if ($area === null) {
            return [null, []];
        }

        /*
         * Ancestors are the areas whose path PREFIXES this one — the
         * materialised path makes ancestry a string test, and "self" rides
         * along because a path prefixes itself.
         */
        $ancestors = Area::query()
            ->whereRaw('? LIKE CONCAT(path, \'%\')', [$area->path])
            ->get(['id', 'path']);

        return [$area->path, $ancestors->pluck('id')->map(static fn ($id): int => (int) $id)->all()];
    }

    /**
     * Published, bounded, recent-first evidence for this property's lineage.
     *
     * @param  list<int>  $ancestorIds
     * @return list<array<string, mixed>>
     */
    private function comparables(PortfolioProperty $property, array $ancestorIds): array
    {
        $query = PriceRecord::query()
            ->published()
            ->where(function ($scope) use ($property, $ancestorIds): void {
                $applied = false;

                if ($property->project_id !== null) {
                    $scope->orWhere(function ($inner) use ($property): void {
                        $inner->where('scope_type', ScopeType::Project)
                            ->where('scope_id', $property->project_id);
                    });
                    $applied = true;
                }

                if ($ancestorIds !== []) {
                    $scope->orWhere(function ($inner) use ($ancestorIds): void {
                        $inner->where('scope_type', ScopeType::Area)
                            ->whereIn('scope_id', $ancestorIds);
                    });
                    $applied = true;
                }

                if (! $applied) {
                    // No project and no area: there is no lineage to match,
                    // and matching NOTHING must return nothing rather than
                    // everything.
                    $scope->whereRaw('1 = 0');
                }
            })
            ->orderByDesc('effective_date')
            ->limit(self::MAX_COMPARABLES);

        $records = $query->get();

        // One lookup for every area path the mapped comparables will carry.
        $areaPaths = Area::query()
            ->whereIn('id', $records->where('scope_type', ScopeType::Area)->pluck('scope_id')->filter()->unique())
            ->pluck('path', 'id');

        return $records
            ->map(function (PriceRecord $record) use ($areaPaths): ?array {
                $price = $record->price ?? $record->median;

                if ($price === null) {
                    return null;
                }

                $isArea = $record->scope_type === ScopeType::Area;

                return [
                    'price' => (string) $price,
                    'price_type' => $record->price_type,
                    'project_id' => $record->scope_type === ScopeType::Project ? (int) $record->scope_id : null,
                    'area_id' => $isArea ? (int) $record->scope_id : null,
                    'area_path' => $isArea ? ($areaPaths[$record->scope_id] ?? null) : null,
                    /*
                     * The record casts property_type to its enum; the engine
                     * compares with strict === against the property's plain
                     * string. An enum on one side of === is a silent
                     * never-match, so the backing value crosses the boundary.
                     * unit_type is a plain string|null on the record already.
                     */
                    'property_type' => $record->property_type->value,
                    'unit_type' => $record->unit_type,
                    // Aggregates carry no unit size; none is invented.
                    'size_sqm' => null,
                    'effective_date' => $record->effective_date->toDateString(),
                ];
            })
            ->filter()
            ->values()
            ->all();
    }
}
