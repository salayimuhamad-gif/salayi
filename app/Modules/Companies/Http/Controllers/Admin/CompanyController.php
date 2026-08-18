<?php

declare(strict_types=1);

namespace App\Modules\Companies\Http\Controllers\Admin;

use App\Modules\Companies\Enums\AssociationManagementStatus;
use App\Modules\Companies\Enums\AssociationRole;
use App\Modules\Companies\Http\Requests\CompanyRequest;
use App\Modules\Companies\Models\Company;
use App\Modules\Companies\Models\CompanyProjectAssociation;
use App\Modules\Companies\Support\CompanyScope;
use App\Modules\Operations\Services\AuditLogger;
use App\Modules\Projects\Models\Project;
use App\Modules\Projects\Support\ActingCompanyContext;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Inertia\Inertia;
use Inertia\Response;
use RuntimeException;

/**
 * Company administration (spec 18.1-18.3, 37.4).
 *
 * Three things Step 5 built and nothing exercised meet a user here:
 * verification as a separate act, admin-controlled project associations, and
 * the disclosure label that a sponsored or advertising relationship cannot be
 * saved without.
 */
final class CompanyController extends Controller
{
    public function __construct(
        private readonly AuditLogger $audit,
        private readonly CompanyScope $scope,
    ) {}

    public function index(Request $request): Response
    {
        /*
         * A portal role sees ONE company: the one it acts for. Listing every
         * company to a company account manager is a directory of competitors,
         * their verification state and their subscription tier.
         */
        $platformWide = $request->user()?->hasPermission('companies.update') === true
            || $request->user()?->hasPermission('companies.verify') === true;

        $scopedTo = $platformWide ? null : ActingCompanyContext::current($request);

        /*
         * FAIL CLOSED. A scoped user whose context is missing, expired or
         * pointing at a company they no longer belong to previously fell
         * through to an UNSCOPED query — a missing session became platform-wide
         * access, which is the most dangerous possible default.
         */
        $noValidContext = ! $platformWide && $scopedTo === null;

        $companies = Company::query()
            ->when($scopedTo !== null, static fn ($query) => $query->whereKey($scopedTo))
            // Nothing, not everything.
            ->when($noValidContext, static fn ($query) => $query->whereRaw('1 = 0'))
            ->when($request->string('q')->toString() !== '', fn ($q) => $q->where(
                'search_key', 'like', '%'.sorani_search_key($request->string('q')->toString()).'%',
            ))
            ->when($request->string('verification')->toString() !== '',
                fn ($q) => $q->where('verification_status', $request->string('verification')->toString()))
            ->withCount(['branches', 'projectAssociations'])
            ->orderBy('name_ckb')
            ->paginate(25)
            ->withQueryString()
            ->through(fn (Company $c): array => [
                'id' => $c->id,
                'name' => $c->name(),
                'legal_name' => $c->legal_name,
                'verification_status' => $c->verification_status,
                'status' => $c->publication_status,
                'branches' => $c->branches_count,
                'associations' => $c->project_associations_count,
                'has_licence' => filled($c->license_number),
                'licence_expired' => $c->license_expires_at !== null && $c->license_expires_at->isPast(),
                'missing_translations' => $c->missingTranslations('name'),
            ]);

        return Inertia::render('Admin/Companies/Index', [
            'companies' => $companies,
            'filters' => [
                'q' => $request->string('q')->toString(),
                'verification' => $request->string('verification')->toString(),
            ],
            'can' => [
                'create' => $request->user()?->hasPermission('companies.create') ?? false,
                'verify' => $request->user()?->hasPermission('companies.verify') ?? false,
            ],
        ]);
    }

    public function create(): Response
    {
        return Inertia::render('Admin/Companies/Form', $this->payload(null));
    }

    public function store(CompanyRequest $request): RedirectResponse
    {
        $company = Company::query()->create($request->validated() + [
            'verification_status' => 'pending',
            'publication_status' => 'draft',
        ]);

        $this->audit->record('company.created', $company, $request->validated());

        return redirect()->route('admin.companies.edit', $company)->with('success', __('app.states.saved'));
    }

    /**
     * A company portal user administers ONE company: their own.
     *
     * `companies.update` is platform-wide. A portal role holds
     * `companies.update_own` instead, which is only meaningful alongside a
     * check that the company in front of them is the one they act for —
     * otherwise the permission name is the only thing that is scoped.
     */
    private function assertMayAdminister(Request $request, Company $company): void
    {
        $user = $request->user();

        // Platform administration: unrestricted by design.
        if ($user?->hasPermission('companies.update') === true) {
            return;
        }

        abort_unless($user?->hasPermission('companies.update_own') === true, 403);

        // 404 rather than 403: confirming which company ids exist tells a
        // competitor how many companies the platform holds.
        abort_unless(
            ActingCompanyContext::current($request) === (int) $company->id,
            404,
        );
    }

    public function edit(Request $request, Company $company): Response
    {
        $this->assertMayAdminister($request, $company);

        return Inertia::render('Admin/Companies/Form', $this->payload($company));
    }

    public function update(CompanyRequest $request, Company $company): RedirectResponse
    {
        $this->assertMayAdminister($request, $company);

        $company->fill($request->validated());
        $company->save();

        $this->audit->recordModelChange('company.updated', $company);

        return back()->with('success', __('app.states.saved'));
    }

    /**
     * Verification (spec 37.4 "company requires approval").
     *
     * Its own permission and its own endpoint. A company that appears verified
     * on a project page is a claim buyers act on, so it cannot be a side effect
     * of an editor correcting a description.
     */
    public function verify(Request $request, Company $company): RedirectResponse
    {
        $this->assertMayAdminister($request, $company);

        $validated = $request->validate([
            'verification_status' => ['required', 'string', 'in:pending,verified,rejected,suspended'],
            'notes' => ['nullable', 'string', 'max:2000'],
        ]);

        if (! ($request->user()?->hasPermission('companies.verify') ?? false)) {
            abort(403, __('identity.errors.missing_permission'));
        }

        $company->forceFill([
            'verification_status' => $validated['verification_status'],
            'verification_notes' => $validated['notes'] ?? null,
            'verified_at' => $validated['verification_status'] === 'verified' ? now() : null,
            // `auth`, `mfa` and `permission:companies.verify` all guard this
            // route, so the verifier is guaranteed.
            'verified_by' => $validated['verification_status'] === 'verified' ? $request->user()->id : null,
        ])->save();

        $this->audit->record('company.verification_changed', $company, [], [
            'to' => $validated['verification_status'],
        ], severity: 'warning');

        return back()->with('success', __('app.states.saved'));
    }

    /**
     * Grant a project association (spec 18.3, 37.4 "admin-controlled").
     *
     * A company may never grant itself one. `CompanyScope` states that rule and
     * this is the only route that can create one.
     */
    public function associate(Request $request, Company $company): RedirectResponse
    {
        $this->assertMayAdminister($request, $company);

        $verdict = $this->scope->mayGrantProjectAssociation(
            $request->user()?->hasPermission('companies.associations.manage') ?? false,
        );

        if (! $verdict['allowed']) {
            abort(403, __('companies.errors.'.$verdict['reason']));
        }

        /**
         * Declaring the key type makes the analyser check every validated key
         * against CompanyProjectAssociation's real columns, so a rule added for a field the model
         * does not have fails here rather than silently never persisting.
         *
         * @var array<model-property<CompanyProjectAssociation>, mixed> $validated
         */
        $validated = $request->validate([
            'project_id' => ['required', 'integer', 'exists:projects,id'],
            'role' => ['required', 'string', 'in:'.implode(',', array_map(
                static fn (AssociationRole $r): string => $r->value,
                AssociationRole::cases(),
            ))],
            'is_sponsored' => ['boolean'],
            'disclosure_label' => ['nullable', 'string', 'max:191'],
            'starts_on' => ['nullable', 'date'],
            'ends_on' => ['nullable', 'date', 'after_or_equal:starts_on'],
            'notes' => ['nullable', 'string', 'max:2000'],
        ]);

        $role = AssociationRole::from($validated['role']);

        // An official-status claim needs the company verified first. "Official
        // developer" on a project page from an unverified company is exactly
        // the assertion this product cannot afford to make loosely.
        if ($role->assertsOfficialStatus() && ! $company->isVerified()) {
            return back()->withErrors(['role' => __('companies.errors.official_requires_verification')]);
        }

        try {
            /*
             * `management_status` MUST be written here.
             *
             * The column defaults to `pending`, and a pending association only
             * confers rights when it carries Wizard draft provenance — which
             * an administrator grant has none of. So an administrator granting
             * an association created one that was approved by every legacy
             * measure and invisible to ProjectScope: the company was told they
             * had the project and could not open it.
             */
            $association = CompanyProjectAssociation::query()->create($validated + [
                'company_id' => $company->id,
                'management_status' => AssociationManagementStatus::Approved->value,
                'is_approved' => true,
                'approved_by' => $request->user()?->id,
                'approved_at' => now(),
                'status_changed_at' => now(),
            ]);
        } catch (RuntimeException $e) {
            // The model refuses a sponsored or advertising association with no
            // disclosure label. Surfaced as a field error, not a 500.
            return back()->withErrors(['disclosure_label' => $e->getMessage()]);
        }

        $this->audit->record('company.association_granted', $association, [], [
            'company_id' => $company->id,
            'project_id' => $validated['project_id'],
            'role' => $role->value,
        ], severity: 'warning');

        return back()->with('success', __('app.states.saved'));
    }

    /**
     * Revoke an association.
     *
     * Marked, NOT deleted. A hard delete destroyed the record that a company
     * once represented this project — which is precisely the history a dispute
     * turns on, and the audit entry alone cannot reconstruct the role, dates
     * or who approved it originally.
     */
    public function revokeAssociation(Request $request, Company $company, int $association): RedirectResponse
    {
        $this->assertMayAdminister($request, $company);

        /*
         * Loaded and saved through the MODEL, not a raw update().
         *
         * A query-builder update bypasses the `saving` guard entirely, so this
         * path could write a lifecycle state the model would have refused —
         * and the database CHECK would then reject it at commit as a driver
         * error rather than a field error.
         */
        $record = CompanyProjectAssociation::query()
            ->where('company_id', $company->id)
            ->find($association);

        if ($record === null) {
            return back()->withErrors(['association' => __('app.states.not_found')]);
        }

        $record->fill([
            'management_status' => AssociationManagementStatus::Revoked->value,
            'is_approved' => false,
            'revoked_by' => $request->user()?->id,
            'revoked_at' => now(),
            'status_changed_at' => now(),
        ])->save();

        $this->audit->record('company.association_revoked', $company, [], [
            'association_id' => $association,
        ], severity: 'warning');

        return back()->with('success', __('app.states.saved'));
    }

    /** @return array<string, mixed> */
    private function payload(?Company $company): array
    {
        return [
            'company' => $company === null ? null : [
                'id' => $company->id,
                ...$company->only([
                    'legal_name', 'brand_name', 'slug',
                    'name_ckb', 'name_ar', 'name_en',
                    'description_ckb', 'description_ar', 'description_en',
                    'website', 'email', 'telegram_username',
                    'license_number', 'license_authority',
                ]),
                'license_expires_at' => $company->license_expires_at?->toDateString(),
                'verification_status' => $company->verification_status,
                'verification_notes' => $company->verification_notes,
                'publication_status' => $company->publication_status,
                'associations' => $company->projectAssociations()
                    ->with('project:id,name_ckb,name_ar,name_en')
                    ->get()
                    ->map(fn (CompanyProjectAssociation $a): array => [
                        'id' => $a->id,
                        'project' => $a->project?->name(),
                        'role' => $a->role->value,
                        'asserts_official' => $a->role->assertsOfficialStatus(),
                        'is_sponsored' => $a->is_sponsored,
                        'disclosure_label' => $a->disclosure_label,
                        'display_priority' => $a->display_priority,
                    ])->all(),
            ],
            'options' => [
                'roles' => array_map(static fn (AssociationRole $r): array => [
                    'value' => $r->value,
                    'label' => __('companies.association_roles.'.$r->value),
                    'asserts_official' => $r->assertsOfficialStatus(),
                    'requires_disclosure' => $r->requiresDisclosure(),
                    'priority' => $r->defaultPriority(),
                ], AssociationRole::cases()),
                'projects' => Project::query()->orderBy('name_ckb')->limit(500)
                    ->get(['id', 'name_ckb', 'name_ar', 'name_en'])
                    ->map(fn (Project $p): array => ['value' => $p->id, 'label' => $p->name()])->all(),
            ],
        ];
    }
}
