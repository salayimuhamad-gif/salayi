<?php

declare(strict_types=1);

namespace App\Modules\Market\Http\Controllers\Public;

use App\Modules\Market\Http\Requests\MarketMovementRequest;
use App\Modules\Market\Services\MarketMovementService;
use Illuminate\Http\JsonResponse;
use Illuminate\Routing\Controller;

/**
 * The public movement answer (Wave 4, spec 15.2/15.3).
 *
 * A thin boundary: validation lives in the request, derivation in the
 * service, and nothing is cached or stored — the answer is recomputed from
 * the published index series on every call, exactly as the market page
 * recomputes its cards. An empty result is a 200 with a structured reason,
 * never an error and never a zero: the difference between "the market did
 * not move" and "we cannot honestly say" is the whole product.
 */
final class MarketMovementController extends Controller
{
    public function __construct(private readonly MarketMovementService $movement) {}

    public function __invoke(MarketMovementRequest $request): JsonResponse
    {
        return response()->json($this->movement->movement(
            $request->transactionMode(),
            $request->window(),
            $request->propertyTypes(),
        ));
    }
}
