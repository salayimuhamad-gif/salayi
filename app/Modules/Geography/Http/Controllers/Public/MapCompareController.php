<?php

declare(strict_types=1);

namespace App\Modules\Geography\Http\Controllers\Public;

use App\Modules\Geography\Http\Requests\MapCompareRequest;
use App\Modules\Geography\Services\AreaComparisonService;
use Illuminate\Http\JsonResponse;
use Illuminate\Routing\Controller;

/**
 * GET /map/compare — 2–3 areas side by side (Map Phase 6).
 *
 * A thin boundary: validation in MapCompareRequest, every rule and figure
 * in AreaComparisonService, which itself only composes the existing
 * authorities. A null from the service means some submitted slug failed
 * the public rule — missing, unpublished, or under unpublished ancestry —
 * and all three read as the same 404, disclosing nothing about which.
 */
final class MapCompareController extends Controller
{
    public function __invoke(MapCompareRequest $request, AreaComparisonService $comparison): JsonResponse
    {
        $result = $comparison->compare(
            $request->slugs(),
            $request->transactionMode(),
            $request->window(),
            $request->propertyType(),
        );

        abort_if($result === null, 404);

        return response()->json($result);
    }
}
