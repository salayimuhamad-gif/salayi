<?php

declare(strict_types=1);

namespace App\Modules\Market\Http\Controllers\Public;

use App\Modules\Core\Support\LocalizedRoutes;
use App\Modules\Market\Models\MarketIndex;
use App\Modules\Market\Models\MarketIndexValue;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Inertia\Inertia;
use Inertia\Response;

/**
 * The public market page (spec 15.1, 15.3).
 *
 * Spec 15.3 requires every public index value to carry its period, effective
 * date, source summary, sample size, confidence, revision status, methodology
 * version, and a warning when limited. All of it travels with the figure here,
 * for the same reason it does on a project profile: a number without its
 * provenance is a number nobody can check.
 *
 * An index built from asking prices carries a qualifier wherever it appears.
 * Without one it reads as a market rate, and the gap between what sellers ask
 * and what buyers pay is exactly the thing this product exists to measure.
 */
final class MarketController extends Controller
{
    public function __invoke(Request $request): Response
    {
        $indices = MarketIndex::query()
            ->where('publication_status', 'published')
            ->orderBy('key')
            ->get()
            ->map(function (MarketIndex $index): ?array {
                $series = MarketIndexValue::query()
                    ->where('market_index_id', $index->id)
                    ->where('publication_status', 'published')
                    ->whereNotNull('value')
                    ->orderByDesc('period')
                    ->limit(24)
                    ->get();

                // An index with no published values is omitted entirely rather
                // than rendered empty. A card reading "—" tells a reader the
                // product is broken; absence tells them nothing, which is
                // accurate.
                if ($series->isEmpty()) {
                    return null;
                }

                $latest = $series->first();

                return [
                    'key' => $index->key,
                    'name' => $index->name(),
                    'price_type' => $index->price_type->value,
                    'family' => $index->price_type->family(),
                    // Spec 15.3, and the reason the asking/verified separation
                    // is worth anything at all on a public page.
                    'requires_qualifier' => $index->requiresPublicQualifier(),
                    'currency' => $index->currency,
                    'basis' => $index->basis,
                    'latest' => [
                        'period' => $latest->period,
                        'value' => $latest->value,
                        'change_percent' => $latest->change_percent,
                        'direction' => $this->direction($latest->change_percent),
                    ],
                    'explanation' => $latest->explanation(),
                    'series' => $series->reverse()->values()->map(static fn (MarketIndexValue $v): array => [
                        'period' => $v->period,
                        'value' => $v->value,
                        'is_limited' => $v->is_limited,
                    ])->all(),
                ];
            })
            ->filter()
            ->values()
            ->all();

        return Inertia::render('Public/Market/Index', [
            'indices' => $indices,
            // Declared rather than silently absent (spec 17.5): a market page
            // missing its heat map reads as a broken page, not an unbuilt one.
            // Wave 4 removed gainers_and_decliners from this list: movement is
            // now derived live from the published series by the panel below.
            'unavailable' => [
                'heat_map' => true,
                'demand_movement' => true,
                'transaction_activity' => true,
                'market_digest' => true,
            ],
            'seo' => [
                'title' => __('market.public.title'),
                'canonical' => LocalizedRoutes::canonical('/market'),
                'alternates' => LocalizedRoutes::alternates('/market'),
            ],
        ]);
    }

    private function direction(?string $change): ?string
    {
        if ($change === null) {
            return null;
        }

        $value = (float) $change;

        return $value > 0.0 ? 'up' : ($value < 0.0 ? 'down' : 'flat');
    }
}
