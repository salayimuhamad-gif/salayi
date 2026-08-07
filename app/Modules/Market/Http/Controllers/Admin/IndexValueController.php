<?php

declare(strict_types=1);

namespace App\Modules\Market\Http\Controllers\Admin;

use App\Modules\Market\Models\MarketIndex;
use App\Modules\Market\Models\MarketIndexValue;
use App\Modules\Operations\Services\AuditLogger;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Index value review and publication (spec 15.3).
 *
 * Computed values land as draft. This is the gate between "we calculated a
 * number" and "we are telling Erbil this is the market" — and those are
 * different claims. A figure that reaches a public page has been looked at by
 * someone who can be asked why.
 */
final class IndexValueController extends Controller
{
    public function __construct(private readonly AuditLogger $audit) {}

    public function index(Request $request, MarketIndex $index): Response
    {
        $values = $index->values()
            ->orderByDesc('period')
            ->orderByDesc('revision_number')
            ->paginate(60)
            ->through(fn (MarketIndexValue $v): array => [
                'id' => $v->id,
                'period' => $v->period,
                'value' => $v->value,
                'median' => $v->median,
                'minimum' => $v->minimum,
                'maximum' => $v->maximum,
                'change_percent' => $v->change_percent,
                'status' => $v->publication_status,
                // Spec 15.3 requires all of these alongside any public figure,
                // so the reviewer sees exactly what a reader would.
                'explanation' => $v->explanation(),
            ]);

        return Inertia::render('Admin/Market/IndexValues', [
            'index' => [
                'id' => $index->id,
                'key' => $index->key,
                'name' => $index->name(),
                'price_type' => $index->price_type->value,
                'family' => $index->price_type->family(),
                'requires_qualifier' => $index->requiresPublicQualifier(),
                'currency' => $index->currency,
                'basis' => $index->basis,
                'methodology_version' => $index->methodology_version,
                'status' => $index->publication_status,
            ],
            'values' => $values,
            'can' => ['publish' => $request->user()?->hasPermission('market.indices.configure') ?? false],
        ]);
    }

    public function publish(Request $request, MarketIndex $index): RedirectResponse
    {
        $validated = $request->validate([
            'ids' => ['required', 'array', 'min:1'],
            'ids.*' => ['integer'],
            'action' => ['required', 'string', 'in:publish,unpublish'],
        ]);

        $target = $validated['action'] === 'publish' ? 'published' : 'draft';

        $query = MarketIndexValue::query()
            ->where('market_index_id', $index->id)
            ->whereIn('id', $validated['ids']);

        if ($target === 'published') {
            // A value with no figure has nothing to publish, and one below the
            // publication floor was already refused by the calculator. Letting
            // either through here would route around that refusal.
            $query->whereNotNull('value');
        }

        $affected = $query->update([
            'publication_status' => $target,
            'published_at' => $target === 'published' ? now() : null,
        ]);

        $this->audit->record('index_values.'.$validated['action'], $index, [], [
            'requested' => count($validated['ids']),
            'affected' => $affected,
        ], severity: 'warning');

        return back()->with('success', __('market.values.'.$validated['action'].'ed', ['count' => $affected]));
    }
}
