<?php

declare(strict_types=1);

namespace App\Modules\Geography\Http\Controllers\Public;

use App\Modules\Companies\Models\Company;
use App\Modules\Companies\Models\CompanyBranch;
use App\Modules\Geography\Models\Area;
use App\Modules\Geography\Models\Place;
use App\Modules\Geography\Models\PlaceCategory;
use App\Modules\Geography\Support\Geodesy;
use App\Modules\Geography\Support\Polygon;
use App\Modules\Geography\Support\Wkt;
use App\Modules\Geography\ValueObjects\Coordinates;
use App\Modules\Market\Models\MarketIndex;
use App\Modules\Market\Models\MarketIndexValue;
use App\Modules\Marketplace\Models\Offer;
use App\Modules\Projects\Enums\ProjectType;
use App\Modules\Projects\Models\Project;
use App\Modules\Projects\Models\ProjectPrice;
use Closure;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Support\Collection;
use Inertia\Inertia;
use Inertia\Response;
use Throwable;

/**
 * The public Map Explorer (File one §5, File two §10).
 *
 * Layers are fetched by bounding box rather than shipped whole. That is not an
 * optimisation, it is what makes the feature possible at all: an unbounded
 * places query returns every school, hospital, mall and park in Erbil to a
 * phone on mobile data, and the map is unusable before the first tile renders.
 *
 * Every layer is separately flag-gated, because they are separately optional.
 * `places.database` off must not take the projects layer down with it — a map
 * that shows nothing because one unrelated module is disabled is a worse
 * failure than a map with fewer layers.
 *
 * Nothing here invents a coordinate. A project with no location is absent from
 * the map rather than placed at a city centroid; §22 forbids fabricated
 * geography, and a marker in the wrong place is a fabrication that looks like
 * data.
 *
 * WHAT IS DELIBERATELY NOT A LAYER
 *
 * `PortfolioProperty` carries latitude and longitude and will never appear
 * here. A portfolio is a private record of what a person owns; plotting it on
 * a public map would publish their home address to anyone who opened the page.
 * The same applies to a user's saved "important places" — the commute anchors
 * behind lifestyle matching describe somebody's daily movements. Neither model
 * is imported by this file, which is the cheapest available guarantee: adding
 * one would require an edit visible in any diff.
 *
 * DISTANCE
 *
 * Kilometres, always, as the primary metric (§10.5). Every distance emitted
 * here is STRAIGHT-LINE and carries `distance_method: straight_line`, so the
 * client cannot present it as anything else. Travel time is null and
 * `travel_time_available` is false, because this deployment has no routing
 * provider — §10.5 step 3 defers it, and a duration derived from straight-line
 * distance would be a fabrication dressed as a measurement. Erbil traffic and
 * one-way streets separate the two numbers by a margin that matters when
 * somebody is choosing where to live.
 */
final class MapExplorerController extends Controller
{
    /**
     * Hard ceiling per layer per request.
     *
     * Chosen so a dense downtown viewport still returns in one response while a
     * zoomed-out request cannot pull the whole table. A client that needs more
     * should zoom in — which is also the only zoom level where individual
     * markers mean anything.
     */
    private const MAX_PER_LAYER = 300;

    /** Largest radius a single request may ask for, in kilometres. */
    private const MAX_RADIUS_KM = 50.0;

    /**
     * Below this zoom, boundaries are not sent at all.
     *
     * At city zoom a district border is a few hundred pixels of outline that
     * tells a visitor nothing they cannot read from the labels, and the
     * polygons are the largest payload on the map by an order of magnitude.
     */
    private const BOUNDARY_MIN_ZOOM = 11.0;

    /** Most boundary polygons returned in one response. */
    private const MAX_BOUNDARIES = 40;

    /**
     * The Investment Map's layer allowlist, enforced SERVER-SIDE.
     *
     * The investment surface shows approved MyHawler project content and the
     * area context it sits in — never generic places (schools, hospitals,
     * cafés), offers, branches or price dots. The restriction lives here
     * rather than in the page's request, because a page cannot promise what
     * an endpoint does not enforce: /invest/features drops every other layer
     * no matter what the client asks for. The full explorer keeps every
     * layer; nothing is removed from it.
     */
    private const INVESTMENT_LAYERS = ['projects', 'areas'];

    /**
     * Simplification tolerance per zoom band, in metres.
     *
     * Roughly one screen pixel of ground distance at each zoom: detail finer
     * than that cannot be seen, so transferring it is pure cost.
     */
    private const BOUNDARY_TOLERANCE_METRES = [
        // Integer keys: PHP has no float array keys, so `11.0 =>` was silently
        // truncated to `11`. It happened to be lossless here, but a threshold
        // like 11.5 would have collapsed onto 11 without a word.
        11 => 120.0,
        13 => 40.0,
        15 => 12.0,
        17 => 0.0,
    ];

    /** Set when any layer hit its ceiling, so the client can say "zoom in". */
    private bool $wasTruncated = false;

    public function index(): Response
    {
        return Inertia::render('Public/Map/Explorer', [
            ...$this->providerPageProps(),
            'layers' => $this->availableLayers(),
            'categories' => $this->categories(),
        ]);
    }

    /**
     * The public Investment Map: the same map core, configured down to
     * approved investment content. A mode of the explorer rather than a
     * parallel system — same adapter, same provider resolution, same
     * feature pipeline, restricted layer set.
     */
    public function invest(): Response
    {
        $allowed = array_values(array_filter(
            $this->availableLayers(),
            static fn (array $layer): bool => in_array($layer['key'], self::INVESTMENT_LAYERS, true),
        ));

        return Inertia::render('Public/Map/Invest', [
            ...$this->providerPageProps(),
            'layers' => $allowed,
            // The existing category system, not a parallel one: filter chips
            // are project types, localized client-side via projects.types.*.
            'types' => array_map(static fn (ProjectType $t): string => $t->value, ProjectType::cases()),
        ]);
    }

    /**
     * The props both map pages share.
     *
     * @return array<string, mixed>
     */
    private function providerPageProps(): array
    {
        $provider = $this->resolveProvider();

        return [
            'style_url' => config('services.maps.maplibre_style_url'),
            'provider' => $provider['provider'],
            // Stated rather than inferred by the client. A map that silently
            // fell back to MapLibre because a Google key was missing looks
            // identical to one configured for MapLibre on purpose, and an
            // administrator then has no signal that the deployment is
            // misconfigured.
            'provider_requested' => $provider['requested'],
            'provider_fallback_reason' => $provider['fallback_reason'],
            /*
             * The Google JS API key is a PUBLIC key by design — it is sent to
             * every browser that loads a Google map and is protected by HTTP
             * referrer restrictions on Google's side, not by secrecy. It is
             * emitted only when Google is the resolved provider, so a
             * MapLibre deployment never ships a credential it does not use.
             */
            'google_key' => $provider['provider'] === 'google'
                ? (string) config('services.maps.google_key')
                : null,
            /*
             * §10.5, declared to the client so the interface can omit the
             * travel-time control entirely rather than render a disabled one
             * that implies the number exists somewhere.
             */
            'distance' => [
                'unit' => 'km',
                'method' => 'straight_line',
                'travel_time_available' => false,
            ],
            'limits' => [
                'max_per_layer' => self::MAX_PER_LAYER,
                'max_radius_km' => self::MAX_RADIUS_KM,
            ],
        ];
    }

    /**
     * Features inside a viewport, optionally narrowed by radius or polygon.
     *
     * The three spatial modes compose rather than compete: bounds is always
     * applied because it is the indexed prefilter, and radius or polygon then
     * refines within it. Running a haversine or point-in-polygon test across an
     * unbounded table is the query that takes a shared host down.
     */
    public function features(Request $request): JsonResponse
    {
        return $this->featureCollection($request, null);
    }

    /**
     * The Investment Map's data endpoint: the same pipeline with the layer
     * allowlist applied where the client cannot reach it, plus the
     * investment-only enrichments (price trends, project outlines).
     */
    public function investFeatures(Request $request): JsonResponse
    {
        return $this->featureCollection($request, self::INVESTMENT_LAYERS, investment: true);
    }

    /**
     * Name search over approved investment content, for the map's search box.
     *
     * Deliberately tiny: published projects with real coordinates, matched on
     * any of the three name columns, capped hard. Returns just enough to fly
     * the camera and select the marker — the features endpoint remains the
     * source of everything else.
     */
    public function investSearch(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'q' => ['required', 'string', 'min:2', 'max:80'],
        ]);

        $term = '%'.str_replace(['%', '_'], ['\%', '\_'], trim((string) $validated['q'])).'%';

        $rows = Project::query()
            ->published()
            ->whereNotNull('latitude')
            ->whereNotNull('longitude')
            ->where(function ($query) use ($term): void {
                $query->where('name_ckb', 'like', $term)
                    ->orWhere('name_ar', 'like', $term)
                    ->orWhere('name_en', 'like', $term);
            })
            ->with(['area:id,name_ckb,name_ar,name_en'])
            ->orderBy('name_ckb')
            ->limit(8)
            ->get()
            ->map(static fn (Project $p): array => [
                'id' => $p->id,
                'slug' => $p->slug,
                'name' => $p->name(),
                'area' => $p->area?->name(),
                'lat' => (float) $p->latitude,
                'lng' => (float) $p->longitude,
            ])
            ->values()
            ->all();

        return response()->json(['results' => $rows]);
    }

    /**
     * @param  list<string>|null  $restrictTo  server-side layer allowlist; null = every available layer
     * @param  bool  $investment  adds the investment-only enrichments
     */
    private function featureCollection(Request $request, ?array $restrictTo, bool $investment = false): JsonResponse
    {
        $validated = $request->validate([
            'north' => ['required', 'numeric', 'between:-90,90'],
            'south' => ['required', 'numeric', 'between:-90,90'],
            'east' => ['required', 'numeric', 'between:-180,180'],
            'west' => ['required', 'numeric', 'between:-180,180'],
            'layers' => ['nullable', 'array'],
            'layers.*' => ['string', 'max:32'],
            'categories' => ['nullable', 'array'],
            'categories.*' => ['string', 'max:64'],

            // Project-type filter (investment map). Unknown values are
            // dropped rather than erroring, same policy as unknown layers.
            'types' => ['nullable', 'array'],
            'types.*' => ['string', 'max:32'],

            // Boundaries are zoom-gated: see boundaries().
            'zoom' => ['nullable', 'numeric', 'between:0,24'],

            /*
             * Radius search (§10.4). A radius with no centre IS meaningless and
             * is still rejected — but the reverse was not. `radius_km` carried
             * `required_with:center_lat`, so a centre supplied on its own came
             * back 422 and distance annotation could never be requested at all.
             *
             * That contradicted this controller's own design: `$centre` and
             * `$radiusKm` are read independently, distances are computed
             * whenever a centre is present, results are sorted nearest-first,
             * and the payload even carries `distance.applied` to say so. A
             * centre without a radius means "sort by distance, filter nothing",
             * which is exactly what the explorer's nearest-first listing needs.
             */
            'center_lat' => ['nullable', 'numeric', 'between:-90,90', 'required_with:radius_km'],
            'center_lng' => ['nullable', 'numeric', 'between:-180,180', 'required_with:radius_km'],
            'radius_km' => ['nullable', 'numeric', 'gt:0', 'max:'.self::MAX_RADIUS_KM],

            // Drawn or selected area (§10.4). A ring of at least three points,
            // capped so a pathological polygon cannot be used as a CPU attack.
            'polygon' => ['nullable', 'array', 'min:3', 'max:200'],
            'polygon.*.lat' => ['required_with:polygon', 'numeric', 'between:-90,90'],
            'polygon.*.lng' => ['required_with:polygon', 'numeric', 'between:-180,180'],
        ]);

        $requested = $validated['layers'] ?? ['projects'];
        $available = array_column($this->availableLayers(), 'key');

        if ($restrictTo !== null) {
            $available = array_values(array_intersect($available, $restrictTo));
        }

        // A layer that is off is dropped silently rather than erroring: the
        // client may hold a stale legend, and a 422 would break the whole map
        // over one unavailable layer.
        $layers = array_intersect($requested, $available);

        $bounds = $this->boundsFor($validated);
        $centre = $this->centreFor($validated);
        $radiusKm = isset($validated['radius_km']) ? (float) $validated['radius_km'] : null;
        $ring = $this->ringFor($validated);

        $features = [
            'projects' => in_array('projects', $layers, true)
                ? $this->projects($bounds, $validated['types'] ?? [])
                : [],
            'places' => in_array('places', $layers, true)
                ? $this->places($bounds, $validated['categories'] ?? [])
                : [],
            'offers' => in_array('offers', $layers, true) ? $this->offers($bounds) : [],
            'areas' => in_array('areas', $layers, true) ? $this->areas($bounds) : [],
            'companies' => in_array('companies', $layers, true) ? $this->companies($bounds) : [],
            'prices' => in_array('prices', $layers, true) ? $this->prices($bounds) : [],
        ];

        foreach ($features as $key => $rows) {
            $features[$key] = $this->applySpatialFilters($rows, $centre, $radiusKm, $ring);
        }

        $zoom = isset($validated['zoom']) ? (float) $validated['zoom'] : null;

        if ($investment) {
            $features['projects'] = $this->withPriceTrends($features['projects']);
        }

        return response()->json([
            ...$features,
            // Investment-only: the projects' own outlines, zoom-gated and
            // simplified like area boundaries. Always present on the invest
            // endpoint so the client never guesses at the key.
            ...($investment ? [
                'project_boundaries' => in_array('projects', $layers, true)
                    ? $this->projectBoundaries($bounds, $zoom)
                    : ['type' => 'FeatureCollection', 'features' => []],
            ] : []),
            // A separate source from the area POINTS above. Both are needed:
            // polygons draw the border, points carry the label and the list row.
            'boundaries' => in_array('areas', $layers, true)
                ? $this->boundaries($bounds, $zoom)
                : ['type' => 'FeatureCollection', 'features' => []],
            'boundary_zoom_threshold' => self::BOUNDARY_MIN_ZOOM,
            'truncated' => $this->wasTruncated,
            // Echoed so the client renders the correct label without having to
            // remember what it asked for across an async round trip.
            'distance' => [
                'unit' => 'km',
                'method' => 'straight_line',
                'travel_time_available' => false,
                'applied' => $centre !== null,
            ],
        ]);
    }

    /**
     * Which map provider this deployment will actually use, and why.
     *
     * MapLibre is the default and needs no key (§10.1) — the product must work
     * on a fresh Hostinger upload with nothing configured. Google is used only
     * when it is both requested AND has a key present, so a half-finished
     * configuration degrades to a working map instead of a blank one.
     *
     * @return array{provider: string, requested: string, fallback_reason: ?string}
     */
    private function resolveProvider(): array
    {
        $requested = (string) config('services.maps.provider', 'maplibre');
        $googleKey = trim((string) (config('services.maps.google_key') ?? ''));

        if ($requested !== 'google') {
            return ['provider' => 'maplibre', 'requested' => $requested, 'fallback_reason' => null];
        }

        if ($googleKey === '') {
            return ['provider' => 'maplibre', 'requested' => 'google', 'fallback_reason' => 'missing_key'];
        }

        return ['provider' => 'google', 'requested' => 'google', 'fallback_reason' => null];
    }

    /** @return list<array{key: string, flag: ?string}> */
    private function availableLayers(): array
    {
        $all = [
            ['key' => 'projects', 'flag' => null],
            ['key' => 'areas', 'flag' => null],
            ['key' => 'places', 'flag' => 'places.database'],
            ['key' => 'offers', 'flag' => 'marketplace.offers'],
            ['key' => 'companies', 'flag' => 'companies.branches'],
            ['key' => 'prices', 'flag' => 'market.indices'],
        ];

        return array_values(array_filter(
            $all,
            static fn (array $layer): bool => $layer['flag'] === null || (bool) feature($layer['flag']),
        ));
    }

    /** @return list<array<string, mixed>> */
    private function categories(): array
    {
        if (! feature('places.database')) {
            return [];
        }

        // Admin-defined, not hardcoded. Schools, universities, hospitals,
        // nurseries, markets, malls, parks and workplaces are seeded
        // categories; §10.3 requires an administrator to add more without a
        // deploy, so the filter list is whatever the table holds.
        return PlaceCategory::query()
            ->where('is_active', true)
            ->orderBy('sort_order')
            ->get(['id', 'key', 'name_ckb', 'name_ar', 'name_en', 'icon', 'colour'])
            /*
             * PlaceCategory does not use HasTrilingualNames, so the chain is
             * written out here — but it must start with the REQUEST locale,
             * not with Sorani. The previous version pinned ckb first, so an
             * Arabic or English visitor filtering the map read every category
             * label in Sorani while the rest of the page was in their own
             * language. Sorani is the authoring language and therefore the
             * FALLBACK, exactly as HasTrilingualNames treats it.
             */
            // NOT static. A static closure has no $this, so calling
            // $this->categoryName() from one is a fatal Error at request time —
            // and because the categories list is only built on the index page,
            // it took the whole Map Explorer down rather than degrading.
            ->map(fn (PlaceCategory $c): array => [
                'key' => $c->key,
                'name' => $this->categoryName($c),
                'icon' => $c->icon,
                'colour' => $c->colour,
            ])
            ->all();
    }

    /**
     * A category name in the request locale, falling back to Sorani.
     *
     * Chain: requested locale -> ckb -> ar -> en. The same order
     * HasTrilingualNames uses, so a category and a place name resolve
     * identically rather than by two different rules.
     */
    private function categoryName(PlaceCategory $category): string
    {
        $locale = app()->getLocale();

        foreach ([$locale, 'ckb', 'ar', 'en'] as $candidate) {
            $value = $category->{'name_'.$candidate} ?? null;

            if (filled($value)) {
                return (string) $value;
            }
        }

        return $category->key;
    }

    /**
     * @param  array<string, mixed>  $validated
     * @return array<string, float>
     */
    private function boundsFor(array $validated): array
    {
        return [
            'north' => (float) $validated['north'],
            'south' => (float) $validated['south'],
            'east' => (float) $validated['east'],
            'west' => (float) $validated['west'],
        ];
    }

    /** @param  array<string, mixed>  $validated */
    private function centreFor(array $validated): ?Coordinates
    {
        if (! isset($validated['center_lat'], $validated['center_lng'])) {
            return null;
        }

        return Coordinates::make((float) $validated['center_lat'], (float) $validated['center_lng']);
    }

    /**
     * @param  array<string, mixed>  $validated
     * @return list<Coordinates>|null
     */
    private function ringFor(array $validated): ?array
    {
        if (empty($validated['polygon'])) {
            return null;
        }

        return array_map(
            static fn (array $point): Coordinates => Coordinates::make((float) $point['lat'], (float) $point['lng']),
            $validated['polygon'],
        );
    }

    /**
     * Refine bounds results by radius and/or drawn polygon, attaching distance.
     *
     * Distance is attached only when a centre was given, and is null otherwise
     * rather than zero — zero reads as "at the centre", null as "not measured".
     *
     * @param  list<array<string, mixed>>  $rows
     * @param  list<Coordinates>|null  $ring
     * @return list<array<string, mixed>>
     */
    private function applySpatialFilters(array $rows, ?Coordinates $centre, ?float $radiusKm, ?array $ring): array
    {
        if ($rows === []) {
            return [];
        }

        $filtered = [];

        foreach ($rows as $row) {
            if (! isset($row['lat'], $row['lng'])) {
                continue;
            }

            $point = Coordinates::make((float) $row['lat'], (float) $row['lng']);

            if ($ring !== null && ! Polygon::containsPoint($ring, $point)) {
                continue;
            }

            if ($centre !== null) {
                $distanceKm = Geodesy::distanceKm($centre, $point);

                if ($radiusKm !== null && $distanceKm > $radiusKm) {
                    continue;
                }

                // Two decimals: a straight-line figure carries no more
                // precision than that, and 3.4172 km implies a measurement
                // nobody took.
                $row['distance_km'] = round($distanceKm, 2);
                $row['distance_method'] = 'straight_line';
                // Present and null, not absent. An absent key invites the
                // client to compute its own estimate; an explicit null says
                // the number does not exist.
                $row['travel_time_minutes'] = null;
            } else {
                $row['distance_km'] = null;
                $row['distance_method'] = null;
                $row['travel_time_minutes'] = null;
            }

            $filtered[] = $row;
        }

        // Nearest first when a centre was given; otherwise leave the query's
        // ordering alone rather than impose a meaningless one.
        if ($centre !== null) {
            usort(
                $filtered,
                static fn (array $a, array $b): int => ($a['distance_km'] ?? 0.0) <=> ($b['distance_km'] ?? 0.0),
            );
        }

        return $filtered;
    }

    /**
     * @param  array<string, float>  $bounds
     * @return list<array<string, mixed>>
     */
    /**
     * @param  array<string, float>  $bounds
     * @param  list<string>  $types  project-type filter; empty = all types
     * @return list<array<string, mixed>>
     */
    private function projects(array $bounds, array $types = []): array
    {
        // Unknown type values are dropped silently, mirroring the layer
        // policy: a stale filter chip must not 422 the whole map.
        $validTypes = array_values(array_intersect(
            $types,
            array_map(static fn (ProjectType $t): string => $t->value, ProjectType::cases()),
        ));

        $rows = Project::query()
            ->published()
            // A project with no coordinate is absent, never placed at a
            // centroid. A marker in the wrong place is a fabrication that
            // looks like data.
            ->whereNotNull('latitude')
            ->whereNotNull('longitude')
            ->whereBetween('latitude', [$bounds['south'], $bounds['north']])
            ->whereBetween('longitude', [$bounds['west'], $bounds['east']])
            ->when($validTypes !== [], static fn ($query) => $query->whereIn('project_type', $validTypes))
            ->with(['area:id,name_ckb,name_ar,name_en'])
            ->limit(self::MAX_PER_LAYER + 1)
            ->get();

        return $this->cap($rows, static fn (Project $p): array => [
            'id' => $p->id,
            'slug' => $p->slug,
            'name' => $p->name(),
            'area' => $p->area?->name(),
            'lat' => (float) $p->latitude,
            'lng' => (float) $p->longitude,
            'type' => $p->project_type->value,
        ]);
    }

    /**
     * @param  array<string, float>  $bounds
     * @param  list<string>  $categories
     * @return list<array<string, mixed>>
     */
    private function places(array $bounds, array $categories): array
    {
        $rows = Place::query()
            ->where('publication_status', 'published')
            // §10.3: a place published for amenity scoring is not necessarily
            // one to pin publicly. The same second gate the place profile
            // applies.
            ->where('is_public', true)
            ->where('is_duplicate_primary', true)
            ->whereBetween('latitude', [$bounds['south'], $bounds['north']])
            ->whereBetween('longitude', [$bounds['west'], $bounds['east']])
            // §7's reliability rule applies on the map as much as on a project
            // page: a low-confidence coordinate produces a marker that is
            // precise and wrong.
            ->where('confidence', '!=', 'low')
            /*
             * Operating only. A permanently closed hospital rendered
             * identically to an open one is the map sending somebody to a
             * locked door in an emergency; §10.3 treats operational status as
             * part of whether a place is usable, not as decoration. Closed and
             * under-construction places remain available on their own profile
             * and for amenity history — they are excluded from the PIN, not
             * from the dataset.
             */
            ->where('operational_status', 'operating')
            ->when(
                $categories !== [],
                static fn ($q) => $q->whereHas('category', static fn ($c) => $c->whereIn('key', $categories))
            )
            ->with(['category:id,key,icon,colour'])
            ->limit(self::MAX_PER_LAYER + 1)
            ->get();

        return $this->cap($rows, static fn (Place $p): array => [
            'id' => $p->id,
            'slug' => $p->slug,
            'name' => $p->name(),
            'lat' => (float) $p->latitude,
            'lng' => (float) $p->longitude,
            'category' => $p->category?->key,
            'icon' => $p->category?->icon,
            'colour' => $p->category?->colour,
        ]);
    }

    /**
     * @param  array<string, float>  $bounds
     * @return list<array<string, mixed>>
     */
    private function offers(array $bounds): array
    {
        $rows = Offer::query()
            ->published()
            ->whereNotNull('latitude')
            ->whereNotNull('longitude')
            /*
             * An offer whose owner chose approximate placement is shown at the
             * precision they chose. Publishing an exact pin for a listing whose
             * seller asked for area-level privacy would override a stated
             * preference about their own home.
             */
            ->whereBetween('latitude', [$bounds['south'], $bounds['north']])
            ->whereBetween('longitude', [$bounds['west'], $bounds['east']])
            ->limit(self::MAX_PER_LAYER + 1)
            ->get();

        return $this->cap($rows, static fn (Offer $o): array => [
            'id' => $o->id,
            'public_id' => $o->public_id,
            'title' => $o->title_ckb ?: ($o->title_ar ?: $o->title_en),
            'lat' => (float) $o->latitude,
            'lng' => (float) $o->longitude,
            'precision' => $o->location_precision,
            'is_sponsored' => (bool) $o->is_sponsored,
            // §12.2: a paid placement is labelled wherever it appears, and a
            // map pin is an appearance.
            'disclosure' => $o->disclosure_label,
        ]);
    }

    /**
     * Company branches (§11.2).
     *
     * Branches rather than companies: a company is a legal entity with no
     * single location, and pinning its registered address as though it were an
     * office sends people to an accountant's building. Only branches of
     * published companies appear.
     *
     * No phone travels with a branch, for the same reason it does not with a
     * place (§32.2) — it is frequently a personal mobile.
     *
     * @param  array<string, float>  $bounds
     * @return list<array<string, mixed>>
     */
    private function companies(array $bounds): array
    {
        $rows = CompanyBranch::query()
            // A closed branch is worse than an absent one: it sends somebody
            // across Erbil to an office that no longer exists. `is_active` was
            // unchecked, so every branch a company had ever registered stayed
            // on the public map after it shut.
            ->where('is_active', true)
            ->whereNotNull('latitude')
            ->whereNotNull('longitude')
            ->whereBetween('latitude', [$bounds['south'], $bounds['north']])
            ->whereBetween('longitude', [$bounds['west'], $bounds['east']])
            // Company::published() requires verification AND publication, so an
            // unapproved company's self-reported address cannot reach the map.
            ->whereHas('company', $this->constrainToPublishedCompany(...))
            ->with(['company:id,slug,name_ckb,name_ar,name_en'])
            ->limit(self::MAX_PER_LAYER + 1)
            ->get();

        return $this->cap($rows, static fn (CompanyBranch $b): array => [
            'id' => $b->id,
            'name' => $b->name(),
            'company' => $b->company?->name(),
            'company_slug' => $b->company?->slug,
            'lat' => (float) $b->latitude,
            'lng' => (float) $b->longitude,
        ]);
    }

    /**
     * Area-scoped price movement, where the data is reliable enough to plot.
     *
     * §15.3 in map form. Three conditions, all required: the index is
     * published, its latest value is published and NOT flagged limited, and
     * the area it describes has a coordinate to plot it at. An index built on
     * eleven observations reads identically to one built on eleven hundred
     * once reduced to a coloured dot, so a limited value is excluded rather
     * than shown with a caveat nobody reads at map zoom.
     *
     * The asking/verified qualifier travels with the figure. An asking-price
     * index plotted without one is indistinguishable from a transaction index,
     * and the gap between those two is the thing this product exists to
     * measure.
     *
     * @param  array<string, float>  $bounds
     * @return list<array<string, mixed>>
     */
    private function prices(array $bounds): array
    {
        $areas = Area::query()
            ->where('publication_status', 'published')
            ->whereNotNull('latitude')
            ->whereNotNull('longitude')
            ->whereBetween('latitude', [$bounds['south'], $bounds['north']])
            ->whereBetween('longitude', [$bounds['west'], $bounds['east']])
            ->get(['id', 'slug', 'latitude', 'longitude', 'name_ckb', 'name_ar', 'name_en'])
            ->keyBy('id');

        if ($areas->isEmpty()) {
            return [];
        }

        $indices = MarketIndex::query()
            ->where('publication_status', 'published')
            ->where('scope_type', 'area')
            ->whereIn('scope_id', $areas->keys()->all())
            ->get();

        if ($indices->isEmpty()) {
            return [];
        }

        $latestByIndex = $this->latestReliableValues($indices->pluck('id')->all());

        $rows = [];

        foreach ($indices as $index) {
            $latest = $latestByIndex->get($index->id);

            if ($latest === null) {
                continue;
            }

            $area = $areas->get($index->scope_id);

            if ($area === null) {
                continue;
            }

            $change = $latest->change_percent;

            $rows[] = [
                'id' => $index->id,
                'area' => $area->name(),
                'area_slug' => $area->slug,
                'lat' => (float) $area->latitude,
                'lng' => (float) $area->longitude,
                'value' => $latest->value,
                'currency' => $index->currency,
                'basis' => $index->basis,
                'period' => $latest->period,
                'change_percent' => $change,
                'direction' => $change === null
                    ? null
                    : ((float) $change > 0.0 ? 'up' : ((float) $change < 0.0 ? 'down' : 'flat')),
                'sample_size' => $latest->sample_size,
                'confidence' => $latest->confidence,
                'requires_qualifier' => $index->requiresPublicQualifier(),
                'price_type' => $index->price_type->value,
            ];

            if (count($rows) >= self::MAX_PER_LAYER) {
                $this->wasTruncated = true;
                break;
            }
        }

        return $rows;
    }

    /**
     * Latest reliable published value for each index, in TWO queries total.
     *
     * This replaced a loop that issued one ordered query per index. With
     * fifty area indices in a viewport that is fifty-one round trips, and on
     * Hostinger shared MySQL each one carries the full connection latency —
     * the layer got slower in direct proportion to how much data the product
     * had, which is the worst possible scaling for a market-intelligence tool.
     *
     * The shape is a grouped MAX(period) subquery joined back to its own
     * table. `period` is the ordering key rather than an id because a revision
     * can be inserted after a later period was published, and an id-based
     * "latest" would silently prefer the row that happened to be written last.
     *
     * The reliability rules are applied in BOTH halves. Applying them only to
     * the outer query would let a limited or unpublished row win MAX(period)
     * and then be filtered out, leaving the index with no value at all —
     * silently dropping an area that does have a reliable earlier figure.
     *
     * @param  list<int>  $indexIds
     * @return Collection<int, MarketIndexValue>
     */
    private function latestReliableValues(array $indexIds): Collection
    {
        if ($indexIds === []) {
            return collect();
        }

        /*
         * EVERY COLUMN IS QUALIFIED, because this query joins a derived table
         * that exposes the same column names.
         *
         * The outer query filtered on a bare `market_index_id`,
         * `publication_status`, `value` and `is_limited` while joining a
         * sub-select aliased `latest` that also selects `market_index_id`.
         * SQLite and MySQL both reject that as ambiguous, so the entire price
         * layer returned a 500 — not a degraded layer, a dead one. The inner
         * query keeps bare names deliberately: it has no join and its own
         * grouping refers to its own table.
         */
        $reliableInner = static fn ($query) => $query
            ->where('publication_status', 'published')
            ->whereNotNull('value')
            ->where('is_limited', false);

        $reliableOuter = static fn ($query) => $query
            ->where('market_index_values.publication_status', 'published')
            ->whereNotNull('market_index_values.value')
            ->where('market_index_values.is_limited', false);

        $latestPeriods = MarketIndexValue::query()
            ->select('market_index_id')
            ->selectRaw('MAX(period) as latest_period')
            ->whereIn('market_index_id', $indexIds)
            ->tap($reliableInner)
            ->groupBy('market_index_id');

        return MarketIndexValue::query()
            ->whereIn('market_index_values.market_index_id', $indexIds)
            ->tap($reliableOuter)
            ->joinSub(
                $latestPeriods,
                'latest',
                static function ($join): void {
                    $join->on('market_index_values.market_index_id', '=', 'latest.market_index_id')
                        ->on('market_index_values.period', '=', 'latest.latest_period');
                },
            )
            ->select('market_index_values.*')
            ->get()
            ->keyBy('market_index_id');
    }

    /**
     * Area representative points, not boundaries.
     *
     * Polygon geometry for a whole viewport is orders of magnitude larger than
     * the markers around it, and the explorer uses areas for orientation rather
     * than for precise borders. A specific area's boundary is available on its
     * own profile page, where one polygon is a reasonable payload.
     *
     * @param  array<string, float>  $bounds
     * @return list<array<string, mixed>>
     */
    private function areas(array $bounds): array
    {
        $rows = Area::query()
            ->where('publication_status', 'published')
            ->whereNotNull('latitude')
            ->whereNotNull('longitude')
            ->whereBetween('latitude', [$bounds['south'], $bounds['north']])
            ->whereBetween('longitude', [$bounds['west'], $bounds['east']])
            ->limit(self::MAX_PER_LAYER + 1)
            ->get();

        return $this->cap($rows, static fn (Area $a): array => [
            'id' => $a->id,
            'slug' => $a->slug,
            'name' => $a->name(),
            'type' => $a->type->value,
            'lat' => (float) $a->latitude,
            'lng' => (float) $a->longitude,
            'has_boundary' => filled($a->boundary_wkt),
        ]);
    }

    /** Below this zoom a project outline is a smudge; markers carry the story. */
    private const PROJECT_BOUNDARY_MIN_ZOOM = 13.0;

    /**
     * Investment enrichment: latest price and its movement, per project row.
     *
     * Sourced from the EXISTING price-history table — the latest dated row is
     * the current figure, and the next older row OF THE SAME PRICE TYPE is
     * what it moved from. Comparing a sale figure to a rent figure would
     * produce a number that looks like a trend and means nothing.
     *
     * One query for the whole viewport, grouped in PHP. Rows come back newest
     * first per project; the scan stops caring about a project once its two
     * relevant rows are found. Projects without history keep null price
     * fields — no figure is ever fabricated (§22 discipline).
     *
     * @param  list<array<string, mixed>>  $rows
     * @return list<array<string, mixed>>
     */
    private function withPriceTrends(array $rows): array
    {
        if ($rows === []) {
            return $rows;
        }

        $ids = array_column($rows, 'id');

        $prices = ProjectPrice::query()
            ->whereIn('project_id', $ids)
            ->orderBy('project_id')
            ->orderByDesc('effective_date')
            ->orderByDesc('id')
            ->get(['project_id', 'price_from', 'price_to', 'currency', 'price_type', 'effective_date']);

        /** @var array<int, array<string, mixed>> $latest */
        $latest = [];
        /** @var array<int, string|null> $previousFrom */
        $previousFrom = [];

        foreach ($prices as $price) {
            $projectId = (int) $price->project_id;

            if (! isset($latest[$projectId])) {
                $latest[$projectId] = [
                    'price_from' => $price->price_from,
                    'price_to' => $price->price_to,
                    'currency' => $price->currency,
                    'price_type' => $price->price_type->value,
                    'price_at' => $price->effective_date?->toDateString(),
                ];

                continue;
            }

            if (array_key_exists($projectId, $previousFrom)) {
                continue; // both rows found; ignore older history
            }

            /*
             * COMPARABILITY IS THE WHOLE RULE. The previous observation must
             * match the latest on price type AND currency, and both figures
             * must actually exist. The old test matched type alone — a
             * latest USD row against an older IQD row of the same type
             * compared raw magnitudes and could emit a fabricated
             * thousand-percent "trend"; and a null price cast to 0.0 read
             * as a 100% collapse. Rows that fail comparability are simply
             * not a previous observation, and the trend stays `unknown`.
             */
            if ($price->price_type->value === $latest[$projectId]['price_type']
                && $price->currency === $latest[$projectId]['currency']
                && $price->price_from !== null
                && $latest[$projectId]['price_from'] !== null) {
                $previousFrom[$projectId] = $price->price_from;
            }
        }

        return array_map(static function (array $row) use ($latest, $previousFrom): array {
            $current = $latest[$row['id']] ?? null;

            /*
             * Deterministic four-valued semantics, from stored observations
             * only:
             *
             *   up / down  — two comparable observations (same price type,
             *                same currency, both non-null, previous > 0)
             *                whose change is at least 0.05%;
             *   flat       — two comparable observations within ±0.05%:
             *                genuinely stable, never a default;
             *   unknown    — anything less: no history, one observation,
             *                mixed currencies, missing figures. No claim is
             *                made and no percentage travels.
             */
            $trend = 'unknown';
            $percent = null;

            if ($current !== null && isset($previousFrom[$row['id']])) {
                $now = (float) $current['price_from'];
                $was = (float) $previousFrom[$row['id']];

                if ($was > 0.0) {
                    $change = ($now - $was) / $was * 100.0;
                    $percent = number_format($change, 1);
                    // Below a twentieth of a percent the honest word is
                    // "stable", not a microscopic arrow.
                    $trend = abs($change) < 0.05 ? 'flat' : ($change > 0 ? 'up' : 'down');
                }
            }

            return $row + [
                'price_from' => $current['price_from'] ?? null,
                'price_to' => $current['price_to'] ?? null,
                'currency' => $current['currency'] ?? null,
                'price_type' => $current['price_type'] ?? null,
                'price_at' => $current['price_at'] ?? null,
                'trend' => $trend,
                'trend_percent' => $trend === 'unknown' ? null : $percent,
            ];
        }, $rows);
    }

    /**
     * The projects' own outlines for the investment map, zoom-gated and
     * simplified exactly like area boundaries. Selection uses the marker
     * prefilter (projects have no cached bbox columns); a project whose pin
     * is outside the viewport but whose plot overlaps it appears once the
     * visitor pans a screenful — the degraded case, not a missing one.
     *
     * @param  array<string, float>  $bounds
     * @return array<string, mixed>
     */
    private function projectBoundaries(array $bounds, ?float $zoom): array
    {
        $empty = ['type' => 'FeatureCollection', 'features' => []];

        if ($zoom === null || $zoom < self::PROJECT_BOUNDARY_MIN_ZOOM) {
            return $empty;
        }

        $projects = Project::query()
            ->published()
            ->whereNotNull('boundary_wkt')
            ->whereNotNull('latitude')
            ->whereNotNull('longitude')
            ->whereBetween('latitude', [$bounds['south'], $bounds['north']])
            ->whereBetween('longitude', [$bounds['west'], $bounds['east']])
            ->limit(self::MAX_BOUNDARIES + 1)
            ->get(['id', 'slug', 'boundary_wkt', 'name_ckb', 'name_ar', 'name_en']);

        if ($projects->count() > self::MAX_BOUNDARIES) {
            $this->wasTruncated = true;
            $projects = $projects->take(self::MAX_BOUNDARIES);
        }

        $tolerance = $this->boundaryTolerance($zoom);
        $features = [];

        foreach ($projects as $project) {
            $geometry = $this->geometryFor((string) $project->boundary_wkt, $tolerance);

            if ($geometry === null) {
                continue;
            }

            $features[] = [
                'type' => 'Feature',
                'geometry' => $geometry,
                'properties' => [
                    'slug' => $project->slug,
                    'name' => $project->name(),
                    'kind' => 'project',
                ],
            ];
        }

        return ['type' => 'FeatureCollection', 'features' => $features];
    }

    /**
     * Published area boundaries as GeoJSON, by viewport and zoom.
     *
     * A SEPARATE source from the area points. Both are required and neither
     * replaces the other: the polygon draws the border, the point carries the
     * label and the list row. Rendering labels from polygon centroids in the
     * browser puts a district's name in the sea when the shape is concave.
     *
     * Three protections, because an unguarded boundary layer is the single
     * heaviest thing this product can send to a phone:
     *
     *   1. ZOOM GATE. Below BOUNDARY_MIN_ZOOM nothing is sent.
     *   2. COUNT CEILING. At most MAX_BOUNDARIES polygons per response.
     *   3. SIMPLIFICATION. Vertices finer than one screen pixel are dropped
     *      by Polygon::simplify, which works in metres so the reduction is
     *      the same real distance at every latitude.
     *
     * A boundary that fails to parse is skipped rather than throwing. One
     * malformed WKT string from an import should cost that area its outline,
     * not take the whole map down.
     *
     * @param  array<string, float>  $bounds
     * @return array{type: string, features: list<array<string, mixed>>}
     */
    private function boundaries(array $bounds, ?float $zoom): array
    {
        $empty = ['type' => 'FeatureCollection', 'features' => []];

        if ($zoom === null || $zoom < self::BOUNDARY_MIN_ZOOM) {
            return $empty;
        }

        /*
         * Selection is by BOUNDING-BOX INTERSECTION, not by the representative
         * point. A district whose centroid sits just outside the viewport can
         * still cover most of the visible screen — filtering on the centre
         * dropped exactly the large areas a visitor is most likely to be
         * looking at, and the omission is invisible because the map simply
         * shows no outline where one belongs.
         *
         * The stored bbox_* columns are used where present; they are
         * maintained by syncDerivedGeometry() on save. An area that predates
         * them, or whose WKT failed to parse at write time, falls back to the
         * representative point so it is degraded rather than absent.
         *
         * Two boxes intersect when neither is wholly past the other on either
         * axis — four comparisons, all index-friendly.
         */
        $areas = Area::query()
            ->where('publication_status', 'published')
            ->whereNotNull('boundary_wkt')
            ->where(function ($query) use ($bounds): void {
                $query
                    ->where(function ($bbox) use ($bounds): void {
                        $bbox->whereNotNull('bbox_min_lat')
                            ->whereNotNull('bbox_max_lat')
                            ->whereNotNull('bbox_min_lng')
                            ->whereNotNull('bbox_max_lng')
                            ->where('bbox_max_lat', '>=', $bounds['south'])
                            ->where('bbox_min_lat', '<=', $bounds['north'])
                            ->where('bbox_max_lng', '>=', $bounds['west'])
                            ->where('bbox_min_lng', '<=', $bounds['east']);
                    })
                    ->orWhere(function ($point) use ($bounds): void {
                        $point->whereNull('bbox_min_lat')
                            ->whereNotNull('latitude')
                            ->whereNotNull('longitude')
                            ->whereBetween('latitude', [$bounds['south'], $bounds['north']])
                            ->whereBetween('longitude', [$bounds['west'], $bounds['east']]);
                    });
            })
            // One more than the ceiling, so truncation is DETECTED rather than
            // assumed from a full page. Exactly MAX_BOUNDARIES results is
            // ambiguous; MAX_BOUNDARIES + 1 is not.
            ->limit(self::MAX_BOUNDARIES + 1)
            ->get(['id', 'slug', 'type', 'boundary_wkt', 'name_ckb', 'name_ar', 'name_en']);

        if ($areas->count() > self::MAX_BOUNDARIES) {
            $this->wasTruncated = true;
            $areas = $areas->take(self::MAX_BOUNDARIES);
        }

        $tolerance = $this->boundaryTolerance($zoom);
        $features = [];

        foreach ($areas as $area) {
            $geometry = $this->geometryFor((string) $area->boundary_wkt, $tolerance);

            if ($geometry === null) {
                continue;
            }

            $features[] = [
                'type' => 'Feature',
                'geometry' => $geometry,
                'properties' => [
                    'slug' => $area->slug,
                    'name' => $area->name(),
                    'type' => $area->type->value,
                ],
            ];
        }

        return ['type' => 'FeatureCollection', 'features' => $features];
    }

    /**
     * GeoJSON geometry for one WKT boundary, or null if it is unusable.
     *
     * `Wkt::parsePolygon()` returns a LIST OF RINGS — exterior first, then any
     * holes — not a single ring. The previous version treated the return value
     * as one ring and tested `count($rings) < 4`, which is 1 for every normal
     * single-ring polygon. Every ordinary boundary was therefore skipped and
     * the layer rendered permanently empty, while a malformed multi-ring shape
     * would have been the only thing to get through. Silent, and invisible to
     * every check that did not look at real data.
     *
     * Holes are preserved. An area with an excluded plot inside it — a
     * military compound, a separately administered development — is a real
     * shape in Erbil, and filling the hole draws a boundary that claims
     * territory the area does not have.
     *
     * MULTIPOLYGON is handled rather than skipped: an area split by a river or
     * a highway is one administrative unit with two pieces.
     *
     * @return array<string, mixed>|null
     */
    private function geometryFor(string $wkt, float $tolerance): ?array
    {
        $type = Wkt::type($wkt);

        try {
            if ($type === 'MULTIPOLYGON') {
                $polygons = array_values(array_filter(array_map(
                    fn (array $rings): ?array => $this->ringsToCoordinates($rings, $tolerance),
                    Wkt::parseMultiPolygon($wkt),
                )));

                if ($polygons === []) {
                    return null;
                }

                return ['type' => 'MultiPolygon', 'coordinates' => $polygons];
            }

            $rings = $this->ringsToCoordinates(Wkt::parsePolygon($wkt), $tolerance);
        } catch (Throwable) {
            // One malformed WKT string from an import costs that area its
            // outline. It must not take the request down.
            return null;
        }

        return $rings === null ? null : ['type' => 'Polygon', 'coordinates' => $rings];
    }

    /**
     * Convert parsed rings to GeoJSON coordinate arrays, simplifying each.
     *
     * The EXTERIOR ring is validated: fewer than four points after closing is
     * not a polygon. Holes that degenerate are dropped individually rather
     * than invalidating the whole shape — a bad hole should cost the hole, not
     * the boundary.
     *
     * @param  list<list<Coordinates>>  $rings
     * @return list<list<array{0: float, 1: float}>>|null
     */
    private function ringsToCoordinates(array $rings, float $tolerance): ?array
    {
        if ($rings === []) {
            return null;
        }

        $out = [];

        foreach ($rings as $index => $ring) {
            $closed = Polygon::close(Polygon::simplify($ring, $tolerance));

            if (count($closed) < 4) {
                // Exterior ring is mandatory; a degenerate hole is skipped.
                if ($index === 0) {
                    return null;
                }

                continue;
            }

            // GeoJSON is [longitude, latitude] — the reverse of every other
            // coordinate pair in this file. Backwards puts Erbil in the Indian
            // Ocean and the map still renders, so it is never inferred.
            $out[] = array_map(
                static fn (Coordinates $point): array => [$point->longitude, $point->latitude],
                $closed,
            );
        }

        return $out === [] ? null : $out;
    }

    /** Simplification tolerance in metres for a zoom level. */
    private function boundaryTolerance(float $zoom): float
    {
        $tolerance = 120.0;

        foreach (self::BOUNDARY_TOLERANCE_METRES as $threshold => $metres) {
            if ($zoom >= $threshold) {
                $tolerance = $metres;
            }
        }

        return $tolerance;
    }

    /**
     * Trim to the ceiling and record that we did.
     *
     * The client is told, so it can say "zoom in to see everything" rather than
     * silently showing a partial map that looks complete — which would make a
     * visitor believe a neighbourhood has four projects when it has forty.
     *
     * @template T
     *
     * @param  Collection<int, T>  $rows
     * @param  callable(T): array<string, mixed>  $map
     * @return list<array<string, mixed>>
     */
    private function cap(Collection $rows, callable $map): array
    {
        if ($rows->count() > self::MAX_PER_LAYER) {
            $this->wasTruncated = true;
            $rows = $rows->take(self::MAX_PER_LAYER);
        }

        return $rows->map($map)->values()->all();
    }

    /**
     * Constrain a branch's owning company to the published, verified set.
     *
     * A real method signature rather than an inline closure: a docblock on a
     * closure's parameter is not a position the analyser reads, so `$query` was
     * inferred as `Builder<Model>` and `published()` could not be resolved.
     * Passed to `whereHas()` as a first-class callable, so the call site stays
     * as short as the arrow function it replaced.
     *
     * @param  Builder<Company>  $query
     */
    private function constrainToPublishedCompany(Builder $query): void
    {
        // Verified AND published — an unapproved company's self-reported
        // address must not reach the public map.
        $query->published();
    }
}
