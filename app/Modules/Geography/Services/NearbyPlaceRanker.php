<?php

declare(strict_types=1);

namespace App\Modules\Geography\Services;

use App\Modules\Geography\Enums\PlaceCategoryKey;

/**
 * Ranking and relevance for nearby places (spec 10.5 step 5, "rank nearby
 * places").
 *
 * Storage-free so the ranking judgement can be argued about and tested on its
 * own. The database work — the bounding-box pre-filter and the distance
 * calculation — lives in NearbyPlaceCalculator.
 *
 * The judgement encoded here: relevance is not distance. A hospital 4 km away
 * is more relevant to a family choosing a home than a café 200 m away, and a
 * panel sorted purely by distance would bury it under twelve coffee shops.
 * Relevance therefore combines
 *
 *   proximity  — decaying with distance, relative to the category's own radius
 *   importance — the category's amenity weight
 *
 * and the panel is capped per category so no single category can crowd out the
 * others.
 */
final class NearbyPlaceRanker
{
    /** Most places of one category shown before others are given room. */
    public const MAX_PER_CATEGORY = 3;

    /**
     * Proximity component, 1.0 at zero distance falling to 0.0 at the
     * category's radius.
     *
     * Deliberately NOT linear. The difference between 200 m and 600 m is a
     * walk or not a walk, and matters enormously; the difference between
     * 4.2 km and 4.6 km is a rounding error in a car journey. A quadratic
     * ease-out keeps the resolution where the decision actually changes.
     */
    public function proximity(int $distanceMetres, int $radiusMetres): float
    {
        if ($radiusMetres <= 0) {
            return 0.0;
        }

        if ($distanceMetres <= 0) {
            return 1.0;
        }

        if ($distanceMetres >= $radiusMetres) {
            return 0.0;
        }

        $ratio = $distanceMetres / $radiusMetres;

        return round((1.0 - $ratio) ** 2, 6);
    }

    /**
     * Combined relevance, 0..1.
     *
     * @param  float|null  $categoryWeight  overrides the enum weight when the
     *                                      category is admin-created
     */
    public function relevance(
        int $distanceMetres,
        PlaceCategoryKey|string $category,
        ?int $radiusMetres = null,
        ?float $categoryWeight = null,
    ): float {
        $enum = $category instanceof PlaceCategoryKey
            ? $category
            : PlaceCategoryKey::tryFrom($category);

        $radius = $radiusMetres ?? $enum?->defaultRadiusMetres() ?? 3_000;
        // An admin-created category with no declared weight sits mid-scale
        // rather than at zero — unknown importance is not zero importance.
        $weight = $categoryWeight ?? $enum?->amenityWeight() ?? 0.5;

        return round($this->proximity($distanceMetres, $radius) * $weight, 6);
    }

    /**
     * Rank a candidate set, applying the per-category cap.
     *
     * Manual entries (spec 10.5 step 6) always outrank calculated ones and are
     * exempt from the cap: an administrator who pinned a place to a project did
     * so on purpose and must not have it silently dropped.
     *
     * @param  list<array{place_id: int, category: string, distance_m: int, radius_m?: int|null, weight?: float|null, is_manual?: bool, is_hidden?: bool}>  $candidates
     * @return list<array{place_id: int, category: string, distance_m: int, relevance: float, rank: int, is_manual: bool}>
     */
    public function rank(array $candidates): array
    {
        $scored = [];

        foreach ($candidates as $candidate) {
            if (($candidate['is_hidden'] ?? false) === true) {
                continue;
            }

            $scored[] = [
                'place_id' => $candidate['place_id'],
                'category' => $candidate['category'],
                'distance_m' => $candidate['distance_m'],
                'relevance' => $this->relevance(
                    $candidate['distance_m'],
                    $candidate['category'],
                    $candidate['radius_m'] ?? null,
                    $candidate['weight'] ?? null,
                ),
                'is_manual' => (bool) ($candidate['is_manual'] ?? false),
            ];
        }

        usort($scored, static function (array $a, array $b): int {
            // Manual pins first.
            if ($a['is_manual'] !== $b['is_manual']) {
                return $a['is_manual'] ? -1 : 1;
            }

            // Then relevance, descending.
            $byRelevance = $b['relevance'] <=> $a['relevance'];

            if ($byRelevance !== 0) {
                return $byRelevance;
            }

            // Then nearest, so ties are broken by something a user can verify.
            $byDistance = $a['distance_m'] <=> $b['distance_m'];

            // Finally place id, so the order is deterministic across runs and
            // a re-rank does not shuffle equal rows in the admin table.
            return $byDistance !== 0 ? $byDistance : $a['place_id'] <=> $b['place_id'];
        });

        $perCategory = [];
        $ranked = [];
        $position = 1;

        foreach ($scored as $entry) {
            if (! $entry['is_manual']) {
                $count = $perCategory[$entry['category']] ?? 0;

                if ($count >= self::MAX_PER_CATEGORY) {
                    continue;
                }

                $perCategory[$entry['category']] = $count + 1;
            }

            $entry['rank'] = $position++;
            $ranked[] = $entry;
        }

        return $ranked;
    }

    /**
     * Amenity score for a project, 0..100 (spec 12.1 "nearby places" feeding
     * the quality score).
     *
     * Saturating rather than linear: the second supermarket within a kilometre
     * adds far less than the first, and a linear sum would reward a project
     * beside a retail strip over one with a balanced mix of services. Diversity
     * across category groups is what the score rewards.
     *
     * @param  list<array{category: string, distance_m: int, relevance?: float}>  $nearby
     */
    public function amenityScore(array $nearby): int
    {
        if ($nearby === []) {
            return 0;
        }

        /** @var array<string, float> $bestPerGroup */
        $bestPerGroup = [];

        foreach ($nearby as $entry) {
            $enum = PlaceCategoryKey::tryFrom($entry['category']);
            $group = $enum?->group() ?? 'other';

            $relevance = $entry['relevance'] ?? $this->relevance($entry['distance_m'], $entry['category']);

            // Keep the single strongest signal per group, then sum groups.
            // This is what makes the score reward breadth over repetition.
            $bestPerGroup[$group] = max($bestPerGroup[$group] ?? 0.0, $relevance);
        }

        // Eleven groups exist; a project realistically reaches a handful.
        // Normalising against six keeps a genuinely well-served project near
        // the top of the scale without requiring the impossible.
        $sum = array_sum($bestPerGroup);

        return (int) min(100, round($sum / 6.0 * 100));
    }
}
