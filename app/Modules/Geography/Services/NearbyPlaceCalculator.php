<?php

declare(strict_types=1);

namespace App\Modules\Geography\Services;

use App\Modules\Geography\Models\Place;
use App\Modules\Geography\Support\Geodesy;
use App\Modules\Geography\ValueObjects\BoundingBox;
use App\Modules\Geography\ValueObjects\Coordinates;
use App\Modules\Projects\Models\Project;
use App\Modules\Projects\Models\ProjectNearbyPlace;
use Illuminate\Support\Facades\DB;

/**
 * The database-backed half of nearby-place matching (spec 10.5).
 *
 * Step 2 built NearbyPlaceRanker — the judgement about what is *relevant* —
 * and proved it against 33 assertions. It has never had real data. This is the
 * piece that finds candidates and freezes the result.
 *
 * The query strategy matters on shared hosting. A great-circle distance per row
 * is trigonometry over the whole places table; instead the candidates are cut
 * to a bounding box first — four indexed range predicates on the DECIMAL
 * lat/lng columns, which is an index scan on both MySQL and SQLite — and exact
 * distance is computed in PHP over the survivors. One box per category, sized
 * to that category's own radius, because a pharmacy at 20 km is noise while an
 * airport at 20 km is a selling point.
 *
 * Results are a SNAPSHOT (spec 10.5 step 4). A published distance does not
 * silently change when a place is corrected; the row is marked stale instead
 * (step 7), so nobody sees a figure move under them after acting on it.
 */
final class NearbyPlaceCalculator
{
    public function __construct(private readonly NearbyPlaceRanker $ranker) {}

    /**
     * Recalculate a project's nearby places.
     *
     * @return array{written: int, hidden_preserved: int, manual_preserved: int, candidates: int}
     */
    public function recalculate(Project $project): array
    {
        $origin = $project->coordinates();

        if ($origin === null) {
            return ['written' => 0, 'hidden_preserved' => 0, 'manual_preserved' => 0, 'candidates' => 0];
        }

        // Manual pins and hidden entries are administrator decisions and must
        // survive a recalculation. Overwriting them would mean an editor's
        // override silently disappears the next time anything triggers a
        // refresh — which they would experience as the system fighting them.
        $preserved = ProjectNearbyPlace::query()
            ->where('project_id', $project->id)
            ->where(fn ($q) => $q->where('is_manual', true)->orWhere('is_hidden', true))
            ->get()
            ->keyBy('place_id');

        $candidates = $this->candidates($origin);

        $ranked = $this->ranker->rank(array_map(
            fn (array $candidate): array => [
                'place_id' => $candidate['place']->id,
                'category' => $candidate['category_key'],
                'distance_m' => $candidate['distance_m'],
                'radius_m' => $candidate['radius_m'],
                'weight' => $candidate['weight'],
                'is_manual' => $preserved[$candidate['place']->id]->is_manual ?? false,
                'is_hidden' => $preserved[$candidate['place']->id]->is_hidden ?? false,
            ],
            $candidates,
        ));

        $byId = [];
        foreach ($candidates as $candidate) {
            $byId[$candidate['place']->id] = $candidate;
        }

        $written = 0;

        DB::transaction(function () use ($project, $ranked, $byId, $preserved, &$written): void {
            // Calculated rows are replaced wholesale; preserved rows are not
            // touched, so their frozen distances stay frozen.
            ProjectNearbyPlace::query()
                ->where('project_id', $project->id)
                ->where('is_manual', false)
                ->where('is_hidden', false)
                ->delete();

            foreach ($ranked as $entry) {
                if (isset($preserved[$entry['place_id']])) {
                    continue;
                }

                $candidate = $byId[$entry['place_id']] ?? null;

                if ($candidate === null) {
                    continue;
                }

                ProjectNearbyPlace::query()->create([
                    'project_id' => $project->id,
                    'place_id' => $entry['place_id'],
                    'straight_line_m' => $entry['distance_m'],
                    // Travel distance and time stay null until a routing
                    // provider supplies them. Spec 10.5 step 3 makes them
                    // conditional, and presenting a straight line as a travel
                    // distance would be a fabrication.
                    'travel_distance_m' => null,
                    'travel_time_s' => null,
                    'travel_provider' => null,
                    'compass_point' => $candidate['compass'],
                    'rank' => $entry['rank'],
                    'relevance' => $entry['relevance'],
                    'is_manual' => false,
                    'is_hidden' => false,
                    'is_stale' => false,
                    'calculated_at' => now(),
                ]);

                $written++;
            }

            $project->forceFill([
                'nearby_amenity_score' => $this->ranker->amenityScore(array_map(
                    static fn (array $e): array => [
                        'category' => $e['category'],
                        'distance_m' => $e['distance_m'],
                        'relevance' => $e['relevance'],
                    ],
                    $ranked,
                )),
            ])->saveQuietly();
        });

        return [
            'written' => $written,
            'hidden_preserved' => $preserved->where('is_hidden', true)->count(),
            'manual_preserved' => $preserved->where('is_manual', true)->count(),
            'candidates' => count($candidates),
        ];
    }

    /**
     * Mark every snapshot referencing a place as stale (spec 10.5 step 7).
     *
     * Called when a place moves. The rows keep their published distances and
     * are flagged for recalculation, rather than being silently rewritten.
     */
    public function markStaleForPlace(int $placeId): int
    {
        return ProjectNearbyPlace::query()
            ->where('place_id', $placeId)
            ->where('is_manual', false)
            ->update(['is_stale' => true]);
    }

    /**
     * Candidate places within each category's own radius.
     *
     * @return list<array{place: Place, category_key: string, distance_m: int, radius_m: int, weight: float, compass: string}>
     */
    private function candidates(Coordinates $origin): array
    {
        $categories = DB::table('place_categories')
            ->where('is_active', true)
            ->get(['id', 'key', 'default_radius_m', 'amenity_weight']);

        $candidates = [];

        foreach ($categories as $category) {
            $radius = (int) $category->default_radius_m;
            $box = BoundingBox::aroundRadius($origin, (float) $radius);

            // The coarse pre-filter: four indexed range predicates. Exact
            // distance is computed below, only over what survives.
            $places = Place::query()
                ->published()
                ->operating()
                ->where('place_category_id', $category->id)
                ->whereBetween('latitude', [$box->minLatitude, $box->maxLatitude])
                ->whereBetween('longitude', [$box->minLongitude, $box->maxLongitude])
                ->get();

            foreach ($places as $place) {
                $point = $place->coordinates();

                if ($point === null) {
                    continue;
                }

                $distance = Geodesy::distanceMetres($origin, $point);

                // The box is a superset of the circle, so its corners exceed
                // the radius. Discarding them here is what makes the box a
                // pre-filter rather than the answer.
                if ($distance > $radius) {
                    continue;
                }

                $candidates[] = [
                    'place' => $place,
                    'category_key' => (string) $category->key,
                    'distance_m' => (int) round($distance),
                    'radius_m' => $radius,
                    'weight' => (float) $category->amenity_weight,
                    'compass' => Geodesy::compassPoint($origin, $point),
                ];
            }
        }

        return $candidates;
    }
}
