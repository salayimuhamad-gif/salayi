<?php

declare(strict_types=1);

namespace App\Modules\Geography\Http\Controllers\Public;

use App\Modules\Geography\Http\Requests\LocationResolveRequest;
use App\Modules\Geography\Models\Area;
use App\Modules\Geography\Services\AreaResolver;
use App\Modules\Geography\Services\AreaServiceSummary;
use App\Modules\Market\Services\AreaPriceIntelligence;
use Illuminate\Http\JsonResponse;
use Illuminate\Routing\Controller;

/**
 * "Know the Price Where You Are" — Wave 3's one backend addition.
 *
 * A read-only public endpoint that answers a single question: which published
 * area contains this point, and what real published price intelligence exists
 * for it. Everything here is composed from machinery that already exists —
 * the Coordinates value object validates, AreaResolver resolves, and the
 * price figures come from the same MarketIndex/latest-reliable-value
 * selection the map's price layer uses. Nothing is calculated twice and
 * nothing new is authoritative.
 *
 * THREE HONEST STATES, NEVER A GUESS:
 *
 *   resolved          a published polygon contains the point AND compatible
 *                     published price intelligence exists;
 *   no_data           the area is real but no suitable public price exists —
 *                     the area identity is kept, the figure is absent, and
 *                     absence is never rendered as zero;
 *   outside_coverage  no published polygon contains the point. AreaResolver
 *                     deliberately has NO nearest fallback and this endpoint
 *                     preserves that: "you are near X" is a location claim
 *                     the data cannot support, so it is never made.
 *
 * PRIVACY: coordinates are resolved and discarded. They are not persisted to
 * any table, not attached to the session or profile, and not deliberately
 * logged; the response carries `Cache-Control: no-store, private` so a
 * coordinate-keyed URL's answer is not retained by shared caches either.
 * LocationResolveTest pins the no-writes property.
 */
final class LocationResolveController extends Controller
{
    public function __construct(
        private readonly AreaResolver $resolver,
        private readonly AreaPriceIntelligence $priceIntelligence,
        private readonly AreaServiceSummary $services,
    ) {}

    public function __invoke(LocationResolveRequest $request): JsonResponse
    {
        $area = $request->filled('area')
            ? $this->manuallyChosenArea((string) $request->input('area'))
            : $this->resolveByCoordinates($request);

        if ($area === null) {
            return $this->respond([
                'state' => 'outside_coverage',
                'area' => null,
                'prices' => null,
            ]);
        }

        $ancestors = $this->publishedAncestors($area);
        // Map Phase 6 moved the price lookup into AreaPriceIntelligence so
        // the area comparison answers from the same selection rule; the
        // payload here is field-for-field what the private method returned.
        $prices = $this->priceIntelligence->for($area);

        return $this->respond([
            'state' => $prices['available'] ? 'resolved' : 'no_data',
            'area' => [
                // The slug is the stable public identifier the profile routes
                // already use; ids stay internal.
                'slug' => $area->slug,
                'name' => $area->name(),
                'type' => $area->type->value,
                'type_label' => __('geography.public.type.'.$area->type->value),
                // Hierarchy context, names only — the same published-ancestry
                // chain the area profile breadcrumb renders.
                'breadcrumb' => array_map(
                    static fn (Area $ancestor): array => ['name' => $ancestor->name()],
                    $ancestors,
                ),
                /*
                 * Map Phase 3's one extension: the SAME grouped service
                 * counts the Area profile renders, from the same extracted
                 * implementation — published places only, direct assignment,
                 * zero-count groups absent. Counts of already-public data
                 * add nothing a visitor could not read on the profile page,
                 * so the endpoint's privacy posture is unchanged.
                 */
                'services' => $this->services->summarize($area),
            ],
            'prices' => $prices['available'] ? [
                'area_name' => $prices['area_name'],
                'indices' => $prices['indices'],
            ] : null,
            'prices_reason' => $prices['reason'],
        ]);
    }

    /**
     * Geolocation mode: the point through the EXISTING resolver, published
     * areas only, most specific match, no fallback of any kind.
     */
    private function resolveByCoordinates(LocationResolveRequest $request): ?Area
    {
        $point = $request->coordinates();

        /*
         * The operating-area rule, applied as a STATE rather than an error.
         * Every published boundary lies inside the Erbil operating box, so a
         * point outside it cannot resolve; answering before touching the
         * database keeps the far-away case cheap while telling the visitor
         * the same honest thing the resolver would.
         */
        if (! $point->withinOperatingArea()) {
            return null;
        }

        return $this->resolver->resolve($point);
    }

    /**
     * Manual mode: the "Choose Area Manually" fallback names a published
     * area by slug and receives the SAME contract.
     *
     * 404 rather than 403 for anything unpublished or under an unpublished
     * ancestor — "not found" and "exists but withheld" are different
     * disclosures and only one is safe to make to a visitor (the area
     * profile's rule, applied verbatim).
     */
    private function manuallyChosenArea(string $slug): Area
    {
        $area = Area::query()
            ->published()
            ->where('slug', $slug)
            ->first();

        abort_if($area === null, 404);

        abort_if(! $this->resolver->ancestryIsPublished($area), 404);

        return $area;
    }

    /**
     * Published ancestors, outermost first — one query, same shape as the
     * area profile breadcrumb. For a resolver result the chain is complete
     * by construction (AreaResolver enforces published ancestry).
     *
     * @return list<Area>
     */
    private function publishedAncestors(Area $area): array
    {
        $ids = $area->ancestorIds();

        if ($ids === []) {
            return [];
        }

        $byId = Area::query()
            ->published()
            ->whereIn('id', $ids)
            ->get()
            ->keyBy('id');

        $ordered = [];

        foreach ($ids as $id) {
            if ($byId->has($id)) {
                $ordered[] = $byId->get($id);
            }
        }

        return $ordered;
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function respond(array $payload): JsonResponse
    {
        /*
         * Privacy-minimal: the request URL carries a person's coordinates, so
         * its answer must not be retained by shared caches. The coordinates
         * themselves are gone the moment this response is built — nothing in
         * this controller writes.
         */
        return response()->json($payload)
            ->header('Cache-Control', 'no-store, private');
    }
}
