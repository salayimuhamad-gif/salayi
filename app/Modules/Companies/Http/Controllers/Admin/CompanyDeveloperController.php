<?php

declare(strict_types=1);

namespace App\Modules\Companies\Http\Controllers\Admin;

use App\Modules\Companies\Enums\AssociationManagementStatus;
use App\Modules\Companies\Models\Company;
use App\Modules\Companies\Models\CompanyDeveloperAssociation;
use App\Modules\Operations\Services\AuditLogger;
use App\Modules\Projects\Models\Developer;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Review of company↔developer links (spec 11.2).
 *
 * The domain shipped with no workflow: links could be created as `pending` and
 * nothing could approve them, so the deadlock was replaced by a queue nobody
 * could clear. A company could name a developer once and then wait forever.
 *
 * Every transition is PLATFORM-ONLY. A company approving its own claim to
 * represent a developer is not a control — that is the entire reason the link
 * is pending rather than live on creation.
 */
final class CompanyDeveloperController extends Controller
{
    public function __construct(private readonly AuditLogger $audit) {}

    /** Pending and live links, for review. */
    public function index(Request $request): Response
    {
        $status = $request->string('status')->toString() ?: AssociationManagementStatus::Pending->value;

        $links = CompanyDeveloperAssociation::query()
            ->when(
                in_array($status, AssociationManagementStatus::values(), true),
                static fn ($query) => $query->where('management_status', $status),
            )
            ->with(['company:id,name_ckb,name_ar,name_en', 'developer:id,name_ckb,name_ar,name_en'])
            ->orderByDesc('created_at')
            ->paginate(25)
            ->withQueryString();

        return Inertia::render('Admin/Companies/DeveloperLinks', [
            'links' => [
                'items' => collect($links->items())
                    ->map(static fn (CompanyDeveloperAssociation $link): array => [
                        'id' => $link->id,
                        'company' => $link->company?->name(),
                        'developer' => $link->developer?->name(),
                        'status' => $link->management_status->value,
                        'starts_on' => $link->starts_on?->toDateString(),
                        'ends_on' => $link->ends_on?->toDateString(),
                        'notes' => $link->notes,
                        'created_at' => $link->created_at?->toDateString(),
                    ])
                    ->all(),
                'total' => $links->total(),
                'current_page' => $links->currentPage(),
                'last_page' => $links->lastPage(),
            ],
            'filters' => ['status' => $status],
            'statuses' => AssociationManagementStatus::values(),
            /*
             * Selectors, so the create form does not require somebody to know
             * an id. Capped and ordered: an unbounded list is unusable, and
             * a company administrator picking from every developer on the
             * platform is the normal case here.
             */
            'companies' => Company::query()
                ->published()
                ->orderBy('name_ckb')
                ->limit(500)
                ->get(['id', 'name_ckb', 'name_ar', 'name_en'])
                ->map(static fn (Company $c): array => ['id' => $c->id, 'name' => $c->name()])
                ->all(),
            'developers' => Developer::query()
                ->orderBy('name_ckb')
                ->limit(500)
                ->get(['id', 'name_ckb', 'name_ar', 'name_en'])
                ->map(static fn (Developer $d): array => ['id' => $d->id, 'name' => $d->name()])
                ->all(),
        ]);
    }

    /** Create a link directly, already approved. */
    public function store(Request $request, Company $company): RedirectResponse
    {
        $validated = $request->validate([
            'developer_id' => ['required', 'integer', Rule::exists('developers', 'id')],
            'starts_on' => ['nullable', 'date'],
            'ends_on' => ['nullable', 'date', 'after_or_equal:starts_on'],
            'notes' => ['nullable', 'string', 'max:2000'],
        ]);

        // firstOrNew, not create: the unique index means a second attempt on an
        // existing pair would fail with a driver error rather than a message.
        $link = CompanyDeveloperAssociation::query()->firstOrNew([
            'company_id' => $company->id,
            'developer_id' => (int) $validated['developer_id'],
        ]);

        /*
         * DUPLICATE HANDLING. The unique index means a second attempt on an
         * existing pair would otherwise surface as a driver error. A revoked
         * or rejected link being re-granted is a legitimate action, so it is
         * reinstated rather than refused.
         */
        if ($link->exists && $link->management_status === AssociationManagementStatus::Approved) {
            return back()->withErrors([
                'developer_id' => __('companies.developer_links.already_linked'),
            ]);
        }

        $link->fill([
            'management_status' => AssociationManagementStatus::Approved->value,
            'is_approved' => true,
            'approved_by' => $request->user()?->id,
            'approved_at' => now(),
            'status_changed_at' => now(),
            'starts_on' => $validated['starts_on'] ?? null,
            'ends_on' => $validated['ends_on'] ?? null,
            'notes' => $validated['notes'] ?? null,
            'created_by' => $link->exists ? $link->created_by : $request->user()?->id,
        ])->save();

        $this->audit->record('company_developer.granted', $link, [], [
            'company_id' => $company->id,
            'developer_id' => $validated['developer_id'],
        ], severity: 'warning');

        return back()->with('success', __('app.states.saved'));
    }

    /** Approve a pending link. */
    public function approve(Request $request, CompanyDeveloperAssociation $link): RedirectResponse
    {
        /*
         * DUPLICATE HANDLING. The unique index means a second attempt on an
         * existing pair would otherwise surface as a driver error. A revoked
         * or rejected link being re-granted is a legitimate action, so it is
         * reinstated rather than refused.
         */
        if ($link->exists && $link->management_status === AssociationManagementStatus::Approved) {
            return back()->withErrors([
                'developer_id' => __('companies.developer_links.already_linked'),
            ]);
        }

        $link->fill([
            'management_status' => AssociationManagementStatus::Approved->value,
            'is_approved' => true,
            'approved_by' => $request->user()?->id,
            'approved_at' => now(),
            'status_changed_at' => now(),
        ])->save();

        $this->audit->record('company_developer.approved', $link, [], [
            'company_id' => $link->company_id,
            'developer_id' => $link->developer_id,
        ], severity: 'warning');

        return back()->with('success', __('app.states.saved'));
    }

    /** Refuse a pending link. */
    public function reject(Request $request, CompanyDeveloperAssociation $link): RedirectResponse
    {
        $validated = $request->validate([
            // A refusal without a reason cannot be explained to the company
            // that made the claim.
            'notes' => ['required', 'string', 'max:2000'],
        ]);

        $link->fill([
            'management_status' => AssociationManagementStatus::Rejected->value,
            'is_approved' => false,
            'rejected_by' => $request->user()?->id,
            'rejected_at' => now(),
            'status_changed_at' => now(),
            'notes' => $validated['notes'],
        ])->save();

        $this->audit->record('company_developer.rejected', $link, [], [
            'company_id' => $link->company_id,
        ], severity: 'warning');

        return back()->with('success', __('app.states.saved'));
    }

    /**
     * Withdraw an approved link.
     *
     * Marked, never deleted: which developers a company was entitled to
     * represent, and when, is the history a dispute turns on.
     */
    public function revoke(Request $request, CompanyDeveloperAssociation $link): RedirectResponse
    {
        $validated = $request->validate([
            'notes' => ['required', 'string', 'max:2000'],
        ]);

        $link->fill([
            'management_status' => AssociationManagementStatus::Revoked->value,
            'is_approved' => false,
            'revoked_by' => $request->user()?->id,
            'revoked_at' => now(),
            'status_changed_at' => now(),
            'notes' => $validated['notes'],
        ])->save();

        $this->audit->record('company_developer.revoked', $link, [], [
            'company_id' => $link->company_id,
        ], severity: 'warning');

        return back()->with('success', __('app.states.saved'));
    }
}
