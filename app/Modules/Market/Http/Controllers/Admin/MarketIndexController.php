<?php

declare(strict_types=1);

namespace App\Modules\Market\Http\Controllers\Admin;

use App\Modules\Market\Enums\PriceType;
use App\Modules\Market\Enums\PropertyType;
use App\Modules\Market\Enums\ScopeType;
use App\Modules\Market\Http\Requests\MarketIndexRequest;
use App\Modules\Market\Jobs\RebuildMarketIndex;
use App\Modules\Market\Models\MarketIndex;
use App\Modules\Operations\Services\AuditLogger;
use BackedEnum;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Inertia\Inertia;
use Inertia\Response;
use RuntimeException;

/**
 * Index definitions (spec 15.2).
 *
 * `IndexBuilder` had nothing to build because `market_indices` rows could only
 * be created by hand in SQL. This is that screen.
 */
final class MarketIndexController extends Controller
{
    public function __construct(private readonly AuditLogger $audit) {}

    public function index(Request $request): Response
    {
        $indices = MarketIndex::query()
            ->withCount('values')
            ->orderBy('key')
            ->get()
            ->map(fn (MarketIndex $i): array => [
                'id' => $i->id,
                'key' => $i->key,
                'name' => $i->name(),
                'scope_type' => $i->scope_type->value,
                'property_type' => $i->property_type?->value,
                'price_type' => $i->price_type->value,
                'family' => $i->price_type->family(),
                // Spec 15.3: an index built from asking prices must carry a
                // qualifier wherever it appears, or it reads as a market rate.
                'requires_qualifier' => $i->requiresPublicQualifier(),
                'basis' => $i->basis,
                'currency' => $i->currency,
                'methodology_version' => $i->methodology_version,
                'minimum_sample' => $i->minimum_sample,
                'status' => $i->publication_status,
                'value_count' => $i->values_count,
                'latest' => $i->values()->first()?->only(['period', 'value', 'confidence', 'sample_size']),
            ]);

        return Inertia::render('Admin/Market/Indices', [
            'indices' => $indices,
            'options' => $this->options(),
            'can' => [
                'manage' => $request->user()?->hasPermission('market.indices.configure') ?? false,
            ],
        ]);
    }

    public function store(MarketIndexRequest $request): RedirectResponse
    {
        $index = MarketIndex::query()->create($request->validated() + ['publication_status' => 'draft']);

        $this->audit->record('market_index.created', $index, $request->validated());

        return back()->with('success', __('app.states.saved'));
    }

    public function update(MarketIndexRequest $request, MarketIndex $index): RedirectResponse
    {
        try {
            $index->fill($request->validated());
            $index->save();
        } catch (RuntimeException $e) {
            return back()->withErrors(['price_type' => $e->getMessage()]);
        }

        $this->audit->recordModelChange('market_index.updated', $index);

        return back()->with('success', __('app.states.saved'));
    }

    /** Queue a rebuild by hand, for an editor who does not want to wait. */
    public function rebuild(Request $request, MarketIndex $index): RedirectResponse
    {
        RebuildMarketIndex::dispatch($index->id);

        $this->audit->record('market_index.rebuild_requested', $index);

        return back()->with('success', __('market.indices.rebuild_queued'));
    }

    public function transition(Request $request, MarketIndex $index): RedirectResponse
    {
        $validated = $request->validate(['status' => ['required', 'string', 'in:draft,published,archived']]);

        $index->publication_status = $validated['status'];
        $index->save();

        $this->audit->record('market_index.transitioned', $index, [], [
            'to' => $validated['status'],
        ], severity: 'warning');

        return back()->with('success', __('app.states.saved'));
    }

    /** @return array<string, mixed> */
    private function options(): array
    {
        return [
            'scope_types' => $this->enumOptions(ScopeType::cases(), 'market.scope_types'),
            'property_types' => $this->enumOptions(PropertyType::cases(), 'market.property_types'),
            'price_types' => array_map(static fn (PriceType $t): array => [
                'value' => $t->value,
                'label' => __('market.price_types.'.$t->value),
                'family' => $t->family(),
            ], PriceType::cases()),
            'bases' => [
                ['value' => 'median', 'label' => __('market.basis.median')],
                ['value' => 'mean', 'label' => __('market.basis.mean')],
                ['value' => 'weighted_mean', 'label' => __('market.basis.weighted_mean')],
                ['value' => 'per_sqm', 'label' => __('market.basis.per_sqm')],
            ],
        ];
    }

    /**
     * @param  array<int, BackedEnum>  $cases
     * @return list<array{value: string, label: string}>
     */
    private function enumOptions(array $cases, string $group): array
    {
        return array_map(static fn ($c): array => [
            'value' => $c->value,
            'label' => __($group.'.'.$c->value),
        ], $cases);
    }
}
