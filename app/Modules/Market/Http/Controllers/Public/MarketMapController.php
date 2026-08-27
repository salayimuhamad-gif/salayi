<?php

declare(strict_types=1);

namespace App\Modules\Market\Http\Controllers\Public;

use App\Modules\Geography\Models\Area;
use App\Modules\Market\Http\Requests\MarketMapRequest;
use App\Modules\Market\Services\MarketMovementService;
use Illuminate\Http\JsonResponse;
use Illuminate\Routing\Controller;

/**
 * Market heat for the map's visible area polygons (Map Phase 4).
 *
 * A thin, read-only boundary in MarketMovementController's mould: scoping
 * here, derivation in the service, nothing stored, nothing cached. The
 * scope is exactly the polygons the explorer can paint — published areas
 * WITH a boundary whose bbox intersects the viewport, the same
 * intersection rule and the same 40-polygon ceiling `/map/features`
 * applies to the boundaries themselves — so heat and geometry describe
 * the same set while staying separately fetchable and separately
 * throttled (`map-market` is its own bucket; RC9's lesson).
 *
 * The movement data itself is MarketMovementService::areaMovement(): the
 * SAME eligibility, reliability, window-pairing and calculator rules the
 * Market pulse panel answers from. An area with no honest claim has no
 * row — absence is the wire form of "unknown", never flat, never zero.
 */
final class MarketMapController extends Controller
{
    /** The explorer's own boundary ceiling, mirrored — heat for what can paint. */
    private const MAX_AREAS = 40;

    public function __construct(private readonly MarketMovementService $movement) {}

    public function __invoke(MarketMapRequest $request): JsonResponse
    {
        $bounds = $request->bounds();

        /*
         * Bounding-box INTERSECTION over the cached bbox columns, with the
         * representative-point fallback — verbatim the boundaries()
         * selection in MapExplorerController, minus the geometry payload.
         * An area only qualifies when it can actually be painted: published
         * and carrying a boundary.
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
            // One more than the ceiling so truncation is detected, not
            // assumed from a full page — the boundaries() idiom.
            ->limit(self::MAX_AREAS + 1)
            ->get(['id']);

        $truncated = $areas->count() > self::MAX_AREAS;

        $areaIds = $areas->take(self::MAX_AREAS)->pluck('id')->all();

        return response()->json(array_merge(
            $this->movement->areaMovement(
                $request->transactionMode(),
                $request->window(),
                $request->propertyType(),
                $areaIds,
            ),
            ['truncated' => $truncated],
        ));
    }
}
