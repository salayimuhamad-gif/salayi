<?php

declare(strict_types=1);

namespace App\Modules\Projects\Services;

use App\Modules\Projects\Enums\RatingCategory;
use App\Modules\Projects\Enums\RatingType;
use App\Modules\Projects\Models\Project;
use App\Modules\Projects\Models\ProjectRating;

/**
 * Assembles ratings for display (spec 13).
 *
 * `RatingAggregator` was proved across 67 assertions in Step 2 and never given
 * a database row. This is the query layer, and it is where two of spec 13's
 * rules could be broken without the aggregator being wrong:
 *
 *   - Only APPROVED ratings are read. An unreviewed submission feeding a public
 *     score would make review decorative, and a company submitting five
 *     five-star ratings about itself is the obvious attack.
 *   - Types stay separate all the way to the template. The aggregator returns
 *     them apart; nothing here flattens them.
 */
final class ProjectRatingService
{
    /**
     * @return array{
     *     categories: list<array<string, mixed>>,
     *     has_any: bool,
     *     official_count: int,
     *     unreviewed_count: int
     * }
     */
    public function forProject(Project $project, bool $includeUnreviewed = false): array
    {
        $query = ProjectRating::query()->where('project_id', $project->id);

        if (! $includeUnreviewed) {
            $query->where('review_status', 'approved');
        }

        $ratings = $query->get();

        $byCategory = [];

        foreach ($ratings as $rating) {
            // Both are enum-cast on the model already; casting an enum to
            // string is a fatal Error, so this re-parse could never succeed.
            // Both are enum-cast on NOT NULL columns, so every rating row
            // carries a category and a type.
            $category = $rating->category;
            $type = $rating->type;

            $byCategory[$category->value][] = ['type' => $type, 'value' => $rating->value];
        }

        $aggregator = new RatingAggregator;
        $categories = [];
        $officialCount = 0;

        foreach ($byCategory as $categoryKey => $entries) {
            $category = RatingCategory::from($categoryKey);
            $result = $aggregator->aggregateCategory($category, $entries);

            // A category where nothing cleared its minimum sample has nothing
            // to say. Rendering it with an empty score would read as "we looked
            // and it scored badly".
            $displayable = array_filter(
                $result['by_type'],
                static fn (array $t): bool => $t['displayable'],
            );

            if ($displayable === [] && $result['official']['score'] === null) {
                continue;
            }

            if ($result['official']['score'] !== null) {
                $officialCount++;
            }

            $categories[] = [
                'category' => $category->value,
                'group' => $category->group(),
                // Spec 13.1: some categories are better when lower (traffic,
                // noise). The template must not draw a low score as bad.
                'inverted' => $result['inverted'],
                'official' => $result['official'],
                'by_type' => array_values(array_map(
                    static fn (array $t): array => $t + [
                        // Spec 13.2: a public-user aggregate and an expert
                        // rating are different claims and must be labelled
                        // as such wherever they appear.
                        'requires_provenance_label' => RatingType::from($t['type'])->requiresPublicProvenanceLabel(),
                    ],
                    $displayable,
                )),
            ];
        }

        usort($categories, static fn (array $a, array $b): int => strcmp($a['group'], $b['group']));

        return [
            'categories' => $categories,
            'has_any' => $categories !== [],
            'official_count' => $officialCount,
            'unreviewed_count' => ProjectRating::query()
                ->where('project_id', $project->id)
                ->where('review_status', '!=', 'approved')
                ->count(),
        ];
    }
}
