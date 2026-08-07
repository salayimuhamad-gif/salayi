<?php

declare(strict_types=1);

namespace App\Modules\Projects\Http\Controllers\Public;

use App\Modules\Core\Support\LocalizedRoutes;
use App\Modules\Knowledge\Models\KnowledgeEvent;
use App\Modules\Market\Services\ProjectPriceHistory;
use App\Modules\Projects\Enums\PublicationStatus;
use App\Modules\Projects\Models\Project;
use App\Modules\Projects\Models\ProjectMedia;
use App\Modules\Projects\Models\ProjectNearbyPlace;
use App\Modules\Projects\Services\ProjectRatingService;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Inertia\Inertia;
use Inertia\Response;

/**
 * The public project profile (spec 12.1, 31.2, 37.2).
 *
 * Spec 37.2 requires that the "public profile displays evidence and dates", and
 * spec 5 requires every public fact to carry a source and a freshness marker.
 * That is the product's central promise, so this controller shapes the payload
 * around it: a fact and its provenance travel together, and a fact with no
 * source is omitted rather than shown bare.
 *
 * Omitting rather than showing an unsourced figure is the important half. A
 * unit count with no source, rendered identically to a sourced one, is exactly
 * the kind of quiet unreliability that costs a market intelligence platform its
 * credibility — and nobody notices it is happening.
 */
final class ProjectProfileController extends Controller
{
    public function index(Request $request): Response
    {
        $projects = Project::query()
            ->published()
            ->with(['area:id,name_ckb,name_ar,name_en', 'developer:id,name_ckb,name_ar,name_en'])
            ->when($request->string('q')->toString() !== '', fn ($q) => $q->where(
                'search_key', 'like', '%'.sorani_search_key($request->string('q')->toString()).'%',
            ))
            ->orderByDesc('published_at')
            ->paginate(12)
            ->withQueryString()
            ->through(fn (Project $project): array => [
                'slug' => $project->slug,
                'name' => $project->name(),
                'area' => $project->area?->name(),
                'developer' => $project->developer?->name(),
                'type' => $project->project_type->value,
                'construction_status' => $project->construction_status->value,
                'is_adverse' => $project->construction_status->isAdverse(),
                'completion_percent' => $project->completion_percent,
            ]);

        return Inertia::render('Public/Projects/Index', [
            'projects' => $projects,
            'filters' => ['q' => $request->string('q')->toString()],
        ]);
    }

    public function __construct(
        private readonly ProjectPriceHistory $priceHistory,
        private readonly ProjectRatingService $ratings,
    ) {}

    public function show(Request $request, string $slug): Response
    {
        $project = Project::query()
            ->where('slug', $slug)
            ->with(['area', 'developer'])
            ->firstOrFail();

        // A draft is not merely hidden, it does not exist publicly. A 403 would
        // confirm the slug is taken and leak the pipeline.
        abort_unless($project->publication_status === PublicationStatus::Published, 404);

        return Inertia::render('Public/Projects/Show', [
            'project' => $this->profile($project),
            'seo' => $this->seo($project, $request),
            // Declared rather than silently absent. Spec 17.5's first rule is
            // "say when data is insufficient"; the same courtesy applies to a
            // page that has sections the product has not populated yet.
            'nearby' => $this->nearby($project),
            'prices' => $this->priceHistory->forProject($project),
            'ratings' => $this->ratings->forProject($project),
            // Published events only. An approved-but-unpublished event is
            // usable as AI evidence and is NOT yet a public statement — the
            // two are deliberately different thresholds (spec 21.3).
            'timeline' => KnowledgeEvent::query()
                ->published()
                ->where('entity_type', 'project')
                ->where('entity_id', $project->id)
                ->orderByDesc('effective_date')
                ->limit(25)
                ->get()
                ->map(static fn (KnowledgeEvent $e): array => [
                    'title' => $e->title_ckb,
                    'summary' => $e->summary_ckb,
                    'event_type' => $e->event_type,
                    'direction' => $e->direction,
                    'strength' => $e->strength,
                    'effective_date' => $e->effective_date->toDateString(),
                    'expected_date' => $e->expected_date?->toDateString(),
                    // Every timeline entry names its source, as every other
                    // public fact on this page does.
                    'source' => $e->source,
                    'source_url' => $e->source_url,
                    'confidence' => $e->confidence,
                ])->all(),
            'media' => ProjectMedia::query()
                ->where('project_id', $project->id)
            // Rows awaiting cleanup are on their way out; showing them
            // offers an image that is about to stop existing.
                ->where('cleanup_pending', false)
                ->orderByDesc('is_cover')
                ->orderBy('sort_order')
                ->get()
                ->map(static fn (ProjectMedia $m): array => [
                    'url' => $m->url(),
                    // Alt text falls back through the locale chain like any
                    // other translated string, and an image with none is
                    // marked decorative rather than given a filename to read
                    // aloud.
                    'alt' => $m->alt_ckb ?? $m->alt_ar ?? $m->alt_en,
                    'credit' => $m->credit,
                    'width' => $m->width,
                    'height' => $m->height,
                ])->all(),
            'unavailable' => [
                // Now driven by whether anything was actually calculated,
                // rather than hardcoded. A project with no nearby places still
                // declares the section rather than omitting it silently.
                'nearby_places' => ProjectNearbyPlace::query()
                    ->where('project_id', $project->id)
                    ->where('is_hidden', false)
                    ->doesntExist(),
                // Driven by whether anything is actually published for this
                // project, rather than hardcoded.
                'price_history' => $this->priceHistory->forProject($project)['series'] === [],
                'ratings' => ! $this->ratings->forProject($project)['has_any'],
                'media' => ProjectMedia::query()
                    ->where('project_id', $project->id)
                    // A row awaiting cleanup is not a photograph the visitor
                    // will see, so it must not count as "has media".
                    ->where('cleanup_pending', false)
                    ->doesntExist(),
            ],
        ]);
    }

    /**
     * Shape the profile so every fact carries its provenance.
     *
     * @return array<string, mixed>
     */
    private function profile(Project $project): array
    {
        $provenance = [
            'source' => $project->source,
            'verified_at' => $project->verified_at?->toDateString(),
            'confidence' => $project->confidence,
            // Freshness in days, so the page can say "verified 40 days ago"
            // rather than only showing a date a reader has to interpret.
            'age_days' => $project->verified_at?->diffInDays(now()),
        ];

        return [
            'slug' => $project->slug,
            'name' => $project->name(),
            'name_is_fallback' => $project->nameIsFallback(),
            'description' => $project->description(),
            'missing_translations' => $project->missingTranslations('name'),

            'developer' => $project->developer === null ? null : [
                'name' => $project->developer->name(),
                'is_verified' => $project->developer->is_verified,
            ],
            'area' => $project->area?->name(),

            'type' => $project->project_type->value,
            'construction_status' => $project->construction_status->value,
            'delivery_status' => $project->delivery_status->value,
            'is_adverse' => ($project->construction_status->isAdverse())
                || $project->delivery_status->isAdverse(),
            'completion_percent' => $project->completion_percent,
            // Flagged when derived from the construction status rather than
            // recorded, because an implied figure is not an observed one.
            'completion_is_implied' => $project->completion_percent !== null
                && $project->completion_percent === $project->construction_status->impliedCompletionPercent(),

            'dates' => array_filter([
                'launch' => $project->launch_date?->toDateString(),
                'expected_delivery' => $project->expected_delivery?->toDateString(),
                'actual_delivery' => $project->actual_delivery?->toDateString(),
            ]),

            // Sourced facts only. An unsourced figure is omitted, not shown bare.
            'facts' => $project->source === null || $project->source === ''
                ? []
                : array_filter([
                    'land_area_sqm' => $project->land_area_sqm,
                    'building_count' => $project->building_count,
                    'unit_count' => $project->unit_count,
                ], static fn ($value): bool => $value !== null),

            'geometry' => [
                'latitude' => $project->latitude === null ? null : (float) $project->latitude,
                'longitude' => $project->longitude === null ? null : (float) $project->longitude,
                'boundary_wkt' => $project->boundary_wkt,
            ],

            'official_url' => $project->official_url,
            'provenance' => $provenance,
            'published_at' => $project->published_at?->toIso8601String(),
        ];
    }

    /**
     * Nearby places for the public profile (spec 10.5, 12.1).
     *
     * Only published places with a source: an unsourced place on a public
     * profile is the same failure as an unsourced price, and the distance is
     * a claim about the world like any other.
     *
     * The straight-line distance is labelled as such. Presenting it as a travel
     * distance would be a fabrication — a school 400 m away across a motorway
     * with no crossing is not a four-hundred-metre walk.
     *
     * @return list<array<string, mixed>>
     */
    private function nearby(Project $project): array
    {
        return ProjectNearbyPlace::query()
            ->where('project_id', $project->id)
            ->where('is_hidden', false)
            ->with(['place' => fn ($q) => $q->with('category')])
            ->orderBy('rank')
            ->get()
            /*
             * File two §7: "hide distances that are not reliable".
             *
             * Three separate reliability conditions, and each rules out a
             * different way a distance can be wrong: an unpublished place is
             * not ours to show, an unsourced one is an unverifiable claim (the
             * same failure as an unsourced price), and a place whose own
             * coordinates are low-confidence produces a distance that is
             * arithmetically exact and factually meaningless.
             */
            ->filter(fn (ProjectNearbyPlace $row): bool => $row->place !== null
                && $row->place->publication_status->isPubliclyVisible()
                && filled($row->place->source)
                // `straight_line_m` is NOT NULL: a nearby-place row is written
                // with its distance or not at all.
                && $row->place->confidence !== 'low')
            ->map(fn (ProjectNearbyPlace $row): array => [
                'name' => $row->place->name(),
                'category' => $row->place->category?->name(),
                // The category key drives both the icon and the filter chips
                // File two §7 asks for; the display name cannot, because it is
                // translated and would change what the filter matches.
                'category_key' => $row->place->category?->key,
                'icon' => $row->place->category?->icon,
                'group' => $row->place->category?->group,
                'distance_m' => $row->straight_line_m,
                'compass' => $row->compass_point,
                // §7 "show the location on the map".
                'latitude' => (float) $row->place->latitude,
                'longitude' => (float) $row->place->longitude,
                // Spec 10.5 step 7: a stale figure says so rather than being
                // quietly presented as current.
                'is_stale' => $row->is_stale,
                'has_travel_data' => $row->hasTravelData(),
                'source' => $row->place->source,
                // §7 "show the date of the last update". A distance with no
                // date is a claim with no age, and the reader cannot judge it.
                'calculated_at' => $row->calculated_at?->toDateString(),
            ])
            /*
             * File two §7 requires the list be ordered BY DISTANCE. The stored
             * `rank` is relevance — deliberately not distance, because a
             * hospital 4 km away matters more to a family than twelve cafés at
             * 200 m, and the ranker uses that to decide WHICH places earn a
             * place in the panel.
             *
             * Both are right about different questions. Relevance selects;
             * distance orders what was selected. Sorting here rather than in
             * the query keeps the ranker's per-category cap intact while giving
             * §7 exactly the ascending-kilometre list it specifies.
             */
            ->sortBy('distance_m')
            ->values()
            ->all();
    }

    /**
     * SEO payload (spec 31.2).
     *
     * hreflang alternates for all three locales, and JSON-LD carrying only
     * facts the page itself shows. Structured data that asserts more than the
     * visible page is the definition of misleading markup.
     *
     * @return array<string, mixed>
     */
    private function seo(Project $project, Request $request): array
    {
        $path = '/projects/'.$project->slug;
        $base = rtrim((string) config('app.url'), '/');

        return [
            'title' => $project->name(),
            'description' => mb_substr((string) ($project->description() ?? ''), 0, 160),
            // Canonical and alternates are both derived from the same routing
            // strategy the router uses, so they cannot drift apart again.
            'canonical' => LocalizedRoutes::canonical($path),
            'alternates' => LocalizedRoutes::alternates($path),
            'locale' => app()->getLocale(),
            'structured_data' => array_filter([
                '@context' => 'https://schema.org',
                '@type' => 'Residence',
                'name' => $project->name(),
                'description' => $project->description(),
                'url' => LocalizedRoutes::canonical($path),
                'address' => $project->area === null ? null : [
                    '@type' => 'PostalAddress',
                    'addressLocality' => $project->area->name(),
                    'addressCountry' => (string) config('mulkihawler.city.default_country', 'IQ'),
                ],
                'geo' => $project->latitude === null ? null : [
                    '@type' => 'GeoCoordinates',
                    'latitude' => (float) $project->latitude,
                    'longitude' => (float) $project->longitude,
                ],
            ], static fn ($value): bool => $value !== null),
        ];
    }
}
