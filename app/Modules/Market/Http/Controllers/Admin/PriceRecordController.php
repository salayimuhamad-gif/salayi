<?php

declare(strict_types=1);

namespace App\Modules\Market\Http\Controllers\Admin;

use App\Modules\Market\Enums\PriceType;
use App\Modules\Market\Models\PriceRecord;
use App\Modules\Operations\Services\AuditLogger;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Price record review and publication (spec 14.1, 14.3).
 *
 * Imported rows land as draft. This is the step that makes that meaningful: a
 * human confirms what a spreadsheet asserted before it can reach an index, a
 * valuation, or a public page.
 *
 * Bulk publication exists because reviewing four hundred rows one at a time is
 * how a review step becomes a rubber stamp — but it is scoped to a filtered
 * selection rather than "everything", so the reviewer publishes a set they
 * chose to look at.
 */
final class PriceRecordController extends Controller
{
    public function __construct(private readonly AuditLogger $audit) {}

    public function index(Request $request): Response
    {
        $records = PriceRecord::query()
            ->when($request->string('status')->toString() !== '',
                fn ($q) => $q->where('publication_status', $request->string('status')->toString()))
            ->when($request->string('price_type')->toString() !== '',
                fn ($q) => $q->where('price_type', $request->string('price_type')->toString()))
            ->when($request->string('period')->toString() !== '',
                fn ($q) => $q->where('period', $request->string('period')->toString()))
            ->when($request->integer('batch') > 0,
                fn ($q) => $q->where('import_batch_id', $request->integer('batch')))
            ->orderByDesc('effective_date')
            ->orderBy('id')
            ->paginate(50)
            ->withQueryString()
            ->through(fn (PriceRecord $r): array => [
                'id' => $r->id,
                'scope_type' => $r->scope_type->value,
                'scope_external_id' => $r->scope_external_id,
                'property_type' => $r->property_type->value,
                'unit_type' => $r->unit_type,
                'price_type' => $r->price_type->value,
                // The family drives the interface warning: asking prices
                // published without their qualifier read as market rates.
                'family' => $r->price_type->family(),
                'requires_qualifier' => $r->price_type->requiresPublicQualifier(),
                'currency' => $r->currency,
                'price' => $r->price,
                'period' => $r->period,
                'sample_size' => $r->sample_size,
                'source' => $r->source,
                'confidence' => $r->confidence,
                'status' => $r->publication_status,
                'is_outlier' => $r->is_outlier,
            ]);

        return Inertia::render('Admin/Market/Prices', [
            'records' => $records,
            'filters' => [
                'status' => $request->string('status')->toString(),
                'price_type' => $request->string('price_type')->toString(),
                'period' => $request->string('period')->toString(),
                'batch' => $request->integer('batch'),
            ],
            'priceTypes' => array_map(
                static fn (PriceType $t): array => [
                    'value' => $t->value,
                    'label' => __('market.price_types.'.$t->value),
                    'family' => $t->family(),
                ],
                PriceType::cases(),
            ),
            'counts' => [
                'draft' => PriceRecord::query()->where('publication_status', 'draft')->count(),
                'published' => PriceRecord::query()->where('publication_status', 'published')->count(),
            ],
            'can' => ['publish' => $request->user()?->hasPermission('market.prices.approve') ?? false],
        ]);
    }

    public function publish(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'ids' => ['required', 'array', 'min:1'],
            'ids.*' => ['integer'],
            'action' => ['required', 'string', 'in:publish,unpublish'],
        ]);

        $target = $validated['action'] === 'publish' ? 'published' : 'draft';

        $affected = PriceRecord::query()
            ->whereIn('id', $validated['ids'])
            // A record flagged as an outlier is not publishable in bulk. If a
            // reviewer believes it is genuine, clearing the flag is a separate,
            // deliberate act — otherwise bulk publish silently readmits exactly
            // the rows the detector caught.
            ->when($target === 'published', fn ($q) => $q->where('is_outlier', false))
            ->update(['publication_status' => $target]);

        $this->audit->record('price_records.'.$validated['action'], null, [], [
            'requested' => count($validated['ids']),
            'affected' => $affected,
        ], severity: 'warning');

        return back()->with('success', __('market.records.'.$validated['action'].'ed', ['count' => $affected]));
    }

    public function flagOutlier(Request $request, PriceRecord $price): RedirectResponse
    {
        $validated = $request->validate([
            'is_outlier' => ['required', 'boolean'],
            'reason' => ['nullable', 'string', 'max:64'],
        ]);

        $price->update([
            'is_outlier' => $validated['is_outlier'],
            'outlier_reason' => $validated['is_outlier'] ? ($validated['reason'] ?? 'manual_review') : null,
        ]);

        $this->audit->record('price_record.outlier_flag_changed', $price, [], [
            'is_outlier' => $validated['is_outlier'],
        ], severity: 'warning');

        return back()->with('success', __('app.states.saved'));
    }
}
