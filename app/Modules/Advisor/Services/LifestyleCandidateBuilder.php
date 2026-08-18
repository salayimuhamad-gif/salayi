<?php

declare(strict_types=1);

namespace App\Modules\Advisor\Services;

use App\Modules\Advisor\Models\LifestylePriority;
use App\Modules\Advisor\Models\LifestyleProfile;
use App\Modules\Geography\Support\Geodesy;
use App\Modules\Geography\ValueObjects\Coordinates;
use App\Modules\Market\Models\PriceRecord;
use App\Modules\Projects\Models\Project;
use App\Modules\Projects\Models\ProjectNearbyPlace;
use Illuminate\Support\Collection;

/**
 * Turns a household profile into ranked, explainable project matches
 * (File two §8).
 *
 * `LifestyleMatcher` was built first and proved against 33 assertions, and it
 * had never seen a real candidate — nothing assembled one. This is the missing
 * half: it gathers actual projects, measures actual distances from the
 * household's own pinned locations, and hands the matcher facts to score.
 *
 * The division of labour is the point, and it is what §8 means by "the result
 * must be explainable and not computed by the language model alone":
 *
 *     this class      — gathers facts (price, type, distances, amenity, risk)
 *     LifestyleMatcher — applies weights and returns a score WITH reasons
 *     the language model — writes prose about a number it cannot change
 *
 * Distances are straight-line kilometres, consistent with File two §6's
 * decision that kilometres are the primary metric and travel time is an
 * optional enhancement. A household that set "within 5 km of work" gets an
 * answer that does not depend on a routing provider being configured.
 */
final class LifestyleCandidateBuilder
{
    /** Never return more than this many scored candidates in one pass. */
    private const MAX_CANDIDATES = 200;

    public function __construct(private readonly LifestyleMatcher $matcher) {}

    /**
     * Score every publishable project against a profile, best first.
     *
     * @return list<array<string, mixed>>
     */
    public function rank(LifestyleProfile $profile, int $limit = 5): array
    {
        $profile->loadMissing(['priorities.place']);

        $matcherProfile = $profile->toMatcherProfile();
        $anchors = $this->anchors($profile);

        $scored = [];

        foreach ($this->candidates() as $project) {
            $candidate = $this->describe($project, $anchors);
            $result = $this->matcher->score($matcherProfile, $candidate);

            /*
             * A score the matcher itself declares unexplainable is dropped
             * rather than shown. §8 requires the result be explainable; a
             * number without a complete component breakdown cannot be defended
             * to a buyer who asks why one project outranked another, and
             * "the model felt it was a better fit" is not an answer this
             * product gives.
             */
            if ($result['explainable'] !== true) {
                continue;
            }

            $scored[] = [
                'project' => [
                    'id' => $project->id,
                    'slug' => $project->slug,
                    'name' => $project->name(),
                    'area' => $project->area?->name(),
                    'property_type' => $candidate['property_type'],
                    'price' => $candidate['price'],
                ],
                'score' => $result['score'],
                'components' => $result['components'],
                'disqualified' => $result['disqualified'],
                'disqualification_reasons' => $result['disqualification_reasons'],
                'confidence' => $result['confidence'],
                // The measured distances are returned alongside the score so the
                // interface can show "4.2 km from your work" rather than only a
                // percentage. §8's explainability is not satisfied by a number.
                'distances' => $candidate['distances'],
            ];
        }

        /*
         * Disqualified candidates sort last regardless of score. They already
         * score zero, but sorting on score alone would interleave them with
         * genuinely weak matches and imply they are merely poor rather than
         * outside a stated hard requirement.
         */
        usort($scored, static function (array $a, array $b): int {
            if ($a['disqualified'] !== $b['disqualified']) {
                return $a['disqualified'] ? 1 : -1;
            }

            return $b['score'] <=> $a['score'];
        });

        return array_slice($scored, 0, max(1, $limit));
    }

    /**
     * The household's own pinned locations, by matcher kind.
     *
     * @return array<string, Coordinates>
     */
    private function anchors(LifestyleProfile $profile): array
    {
        $anchors = [];

        foreach ($profile->priorities as $priority) {
            /** @var LifestylePriority $priority */
            $coordinates = $priority->coordinates();

            if ($coordinates !== null) {
                $anchors[$priority->kind->value] = $coordinates;
            }
        }

        return $anchors;
    }

    /** @return Collection<int, Project> */
    private function candidates(): Collection
    {
        return Project::query()
            ->published()
            ->whereNotNull('latitude')
            ->whereNotNull('longitude')
            ->with(['area:id,name_ckb,name_ar,name_en'])
            ->limit(self::MAX_CANDIDATES)
            ->get();
    }

    /**
     * Describe one project as the facts the matcher scores.
     *
     * @param  array<string, Coordinates>  $anchors
     * @return array<string, mixed>
     */
    private function describe(Project $project, array $anchors): array
    {
        $origin = $project->coordinates();
        $distances = [];

        if ($origin !== null) {
            foreach ($anchors as $kind => $point) {
                // Straight-line metres, per File two §6. Rounded to the metre:
                // sub-metre precision on a great-circle distance between two
                // hand-placed pins is false precision.
                $distances[$kind] = (int) round(Geodesy::distanceMetres($origin, $point));
            }
        }

        return [
            'price' => $this->representativePrice($project),
            'property_type' => $project->project_type->value,
            'distances' => $distances,
            'amenity_score' => $this->amenityScore($project),
            'data_confidence' => $project->confidence ?? 'medium',
            /*
             * There is no risk column on projects, and inventing one would be
             * exactly the fabrication File two §22 forbids. `quality_score`
             * measures how complete and well-sourced the project record is,
             * which is a real and different thing — a sparsely documented
             * project genuinely IS a riskier purchase, but only because less is
             * known about it.
             *
             * Inverted because the matcher's risk_adjustment expects risk, not
             * quality: 90% quality is 10 risk. Null stays null so an unscored
             * project is treated as unmeasured rather than as risk-free.
             */
            'risk_score' => $project->quality_score === null
                ? null
                : (int) max(0, 100 - (int) $project->quality_score),
        ];
    }

    /**
     * A project's amenity coverage, from its own nearby-place snapshot.
     *
     * Reuses the Phase 4/5 pipeline rather than recomputing: those rows are the
     * published, reviewed, admin-curated set, so a place an administrator hid
     * from the project page does not quietly come back to influence a score.
     */
    private function amenityScore(Project $project): ?int
    {
        $count = ProjectNearbyPlace::query()
            ->where('project_id', $project->id)
            ->where('is_hidden', false)
            ->where('is_stale', false)
            ->count();

        if ($count === 0) {
            // Null, not zero. "No amenities" and "we have not calculated this
            // project's amenities" are different claims, and the matcher treats
            // the second as unmeasured rather than as a bad result.
            return null;
        }

        return (int) min(100, $count * 5);
    }

    /**
     * The price used for budget fitting.
     *
     * Projects carry no price column: prices live in the market module as
     * reviewed, sourced, period-stamped records. The lowest published,
     * non-outlier figure for the project is used, because a household's budget
     * question is "can I get into this project at all", not "what is the
     * average unit here".
     *
     * Returns null when nothing is published. The matcher distinguishes
     * "outside budget" from "price unknown", and inventing a figure here would
     * collapse that distinction into a confident wrong answer — the precise
     * failure File two §22 and the evidence rules exist to prevent.
     */
    private function representativePrice(Project $project): ?string
    {
        $price = PriceRecord::query()
            ->where('scope_type', 'project')
            ->where('scope_id', $project->id)
            ->where('publication_status', 'published')
            ->where('is_outlier', false)
            ->whereNotNull('price')
            ->orderBy('price')
            ->value('price');

        return $price === null ? null : (string) $price;
    }
}
