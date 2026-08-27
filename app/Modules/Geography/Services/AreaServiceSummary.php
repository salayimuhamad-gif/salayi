<?php

declare(strict_types=1);

namespace App\Modules\Geography\Services;

use App\Modules\Geography\Models\Area;
use App\Modules\Geography\Models\Place;
use App\Modules\Geography\Models\PlaceCategory;
use Illuminate\Support\Facades\Lang;

/**
 * One area's grouped service counts, from the places database (Map Phase 2,
 * extracted in Phase 3 so the Area profile and the map's location contract
 * answer from the SAME implementation instead of two counting paths).
 *
 * The gates are the map layer's own public-pin contract — published, public,
 * duplicate-primary, operating — over the area's DIRECT assignment, so a
 * count of 8 schools stands above a list that can actually show those 8.
 * Zero-count groups are simply absent, never rendered as empty shelves, and
 * a count is only ever a count: nothing here fabricates a figure.
 */
final class AreaServiceSummary
{
    /**
     * @return list<array{key: string, label: string, count: int, categories: list<array{key: string, label: string, count: int}>}>
     */
    public function summarize(Area $area): array
    {
        $counts = Place::query()
            ->published()
            ->where('is_public', true)
            ->operating()
            ->where('area_id', $area->id)
            ->selectRaw('place_category_id, COUNT(*) as aggregate')
            ->groupBy('place_category_id')
            ->pluck('aggregate', 'place_category_id')
            ->map(static fn ($count): int => (int) $count);

        if ($counts->isEmpty()) {
            return [];
        }

        $categories = PlaceCategory::query()
            ->whereIn('id', $counts->keys()->all())
            ->orderBy('sort_order')
            ->orderBy('id')
            ->get();

        $groups = [];

        foreach ($categories as $category) {
            $group = $category->group;

            $groups[$group] ??= [
                'key' => $group,
                'label' => $this->groupLabel($group),
                'count' => 0,
                'categories' => [],
            ];
            $count = $counts->get($category->id, 0);
            $groups[$group]['count'] += $count;
            $groups[$group]['categories'][] = [
                'key' => $category->key,
                'label' => $this->categoryLabel($category),
                'count' => $count,
            ];
        }

        // The product's group order first, then anything an admin invented.
        $order = array_flip([
            'education', 'health', 'shopping', 'transport', 'recreation',
            'worship', 'civic', 'finance', 'hospitality', 'employment', 'other',
        ]);

        $list = array_values($groups);

        usort($list, static fn (array $a, array $b): int => ($order[$a['key']] ?? 99) <=> ($order[$b['key']] ?? 99));

        return $list;
    }

    /**
     * Localized group label, degrading to the raw key for a group an admin
     * invented after these translations shipped.
     */
    private function groupLabel(string $group): string
    {
        $key = 'geography.public.service_groups.'.$group;

        return Lang::has($key) ? __($key) : $group;
    }

    /**
     * Category label in the request locale — the same locale -> ckb -> ar ->
     * en chain HasTrilingualNames and the map categories endpoint use.
     */
    private function categoryLabel(PlaceCategory $category): string
    {
        foreach ([app()->getLocale(), 'ckb', 'ar', 'en'] as $candidate) {
            $value = $category->{'name_'.$candidate} ?? null;

            if (filled($value)) {
                return (string) $value;
            }
        }

        return $category->key;
    }
}
