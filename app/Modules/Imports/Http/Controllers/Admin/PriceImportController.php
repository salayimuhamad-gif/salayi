<?php

declare(strict_types=1);

namespace App\Modules\Imports\Http\Controllers\Admin;

use App\Modules\Imports\Services\PriceImportService;
use App\Modules\Imports\Support\CsvReader;
use App\Modules\Projects\Models\Project;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Price import screens (spec 14.2, 14.3).
 *
 * The preview is held in the SESSION between upload and accept rather than
 * written to the database. Writing a preview would mean rows exist before an
 * operator has agreed to them, and a half-reviewed batch abandoned at lunchtime
 * would leave records nobody chose to create.
 */
final class PriceImportController extends Controller
{
    private const REQUIRED_COLUMNS = [
        'scope_type', 'property_type', 'price_type', 'currency', 'effective_date',
    ];

    public function __construct(
        private readonly PriceImportService $imports,
        private readonly CsvReader $reader,
    ) {}

    public function index(Request $request): Response
    {
        $batches = DB::table('market_import_batches')
            ->orderByDesc('created_at')
            ->limit(50)
            ->get()
            ->map(static fn ($b): array => [
                'id' => $b->id,
                'reference' => $b->reference,
                'status' => $b->status,
                'total_rows' => (int) $b->total_rows,
                'accepted_rows' => (int) $b->accepted_rows,
                'accepted_at' => $b->accepted_at,
                'published_at' => $b->published_at,
                'rolled_back_at' => $b->rolled_back_at,
                // Rollback is only offered while unpublished (spec 14.3).
                'can_rollback' => $b->published_at === null && $b->rolled_back_at === null,
            ])
            ->all();

        return Inertia::render('Admin/Imports/Index', [
            'batches' => $batches,
            'preview' => $request->session()->get('import.preview'),
            'requiredColumns' => self::REQUIRED_COLUMNS,
        ]);
    }

    public function preview(Request $request): RedirectResponse
    {
        $request->validate([
            'file' => ['required', 'file', 'mimes:csv,txt', 'max:10240'],
        ]);

        $result = $this->reader->read($request->file('file')->getRealPath());

        if ($result['error'] !== null) {
            return back()->withErrors(['file' => __('imports.errors.'.$result['error'])]);
        }

        // Answered once, before validation, rather than as the same error on
        // four hundred rows.
        $missing = $this->reader->missingColumns($result['headers'], self::REQUIRED_COLUMNS);

        if ($missing !== []) {
            return back()->withErrors([
                'file' => __('imports.errors.missing_columns', ['columns' => implode(', ', $missing)]),
            ]);
        }

        $preview = $this->imports->preview($result['rows'], [
            'project' => Project::query()->whereNotNull('external_id')->pluck('external_id')->all(),
        ]);

        $preview['truncated'] = $result['truncated'];
        $preview['delimiter'] = $result['delimiter'] === "\t" ? 'tab' : $result['delimiter'];
        $preview['filename'] = $request->file('file')->getClientOriginalName();

        return back()->with('import.preview', $preview);
    }

    public function accept(Request $request): RedirectResponse
    {
        $preview = $request->session()->get('import.preview');

        if (! is_array($preview)) {
            return back()->withErrors(['file' => __('imports.errors.preview_expired')]);
        }

        $validated = $request->validate([
            'rows' => ['required', 'array', 'min:1'],
            'rows.*' => ['integer'],
        ]);

        $chosen = [];

        foreach ($preview['rows'] as $row) {
            // Only rows the operator selected AND that validated. A row that
            // failed cannot be forced through by posting its number.
            if (in_array($row['row_number'], $validated['rows'], true)
                && $row['status'] === 'valid'
                && is_array($row['normalised'])) {
                $chosen[] = $row['normalised'];
            }
        }

        if ($chosen === []) {
            return back()->withErrors(['rows' => __('imports.errors.no_valid_rows_selected')]);
        }

        $result = $this->imports->accept($preview['reference'], $chosen, $request->user()?->id);

        $request->session()->forget('import.preview');

        return redirect()->route('admin.imports.prices.index')->with(
            'success',
            __('imports.accepted', ['written' => $result['written'], 'skipped' => $result['skipped']]),
        );
    }

    public function rollback(Request $request, int $batch): RedirectResponse
    {
        $result = $this->imports->rollback($batch, $request->user()?->id);

        if (! $result['rolled_back']) {
            return back()->withErrors(['batch' => __('imports.errors.'.$result['reason'])]);
        }

        return back()->with('success', __('imports.rolled_back', ['deleted' => $result['deleted']]));
    }

    public function discard(Request $request): RedirectResponse
    {
        $request->session()->forget('import.preview');

        return back();
    }
}
