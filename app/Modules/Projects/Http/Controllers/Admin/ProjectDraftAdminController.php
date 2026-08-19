<?php

declare(strict_types=1);

namespace App\Modules\Projects\Http\Controllers\Admin;

use App\Modules\Companies\Models\Company;
use App\Modules\Operations\Services\AuditLogger;
use App\Modules\Projects\Models\ProjectDraft;
use App\Modules\Projects\Services\ProjectDraftMediaService;
use App\Modules\Projects\Support\ActingCompanyContext;
use App\Modules\Projects\Support\ProjectScope;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Administrator view of wizard drafts (spec 12.1, 26.1).
 *
 * Drafts were invisible to everyone but their author. That is fine until
 * somebody leaves, or a submission half-fails, or a company asks why the
 * project they entered last week is not on the site — at which point there was
 * no way to look. The prune command could delete them and nothing could read
 * them first.
 *
 * SCOPE IS NOT RELAXED HERE. A platform operator sees everything; a company
 * administrator sees their own company's drafts and no others. "Administrator
 * listing" does not mean "listing that ignores the scoping the rest of the
 * product enforces".
 *
 * RECOVERY reassigns an abandoned draft to a new owner rather than copying it,
 * so there is exactly one draft and its history stays intact.
 */
final class ProjectDraftAdminController extends Controller
{
    public function __construct(private readonly AuditLogger $audit) {}

    public function index(Request $request): Response
    {
        $isPlatform = ProjectScope::inPlatformMode($request);
        $companyId = ActingCompanyContext::current($request);

        abort_unless($isPlatform || $companyId !== null, 403);

        $filter = $request->string('state')->toString() ?: 'open';

        $drafts = ProjectDraft::query()
            // Company scope, unless genuinely operating platform-wide.
            ->when(! $isPlatform, static fn ($query) => $query->where(function ($scope) use ($companyId): void {
                $scope->where('company_id', $companyId)->orWhere('acting_company_id', $companyId);
            }))
            ->when($filter === 'open', static fn ($query) => $query->whereNull('submitted_at'))
            ->when($filter === 'submitted', static fn ($query) => $query->whereNotNull('submitted_at'))
            ->when($filter === 'stale', static fn ($query) => $query
                ->whereNull('submitted_at')
                ->where('last_touched_at', '<', now()->subDays(
                    (int) config('mulkihawler.wizard.draft_retention_days', 30),
                )))
            ->with(['user:id,name'])
            ->withCount('media')
            ->orderByDesc('updated_at')
            ->paginate(25)
            ->withQueryString();

        $companies = Company::query()
            ->whereIn('id', $drafts->pluck('company_id')->filter()->unique())
            ->get(['id', 'name_ckb', 'name_ar', 'name_en'])
            ->mapWithKeys(static fn (Company $c): array => [$c->id => $c->name()]);

        $retentionDays = (int) config('mulkihawler.wizard.draft_retention_days', 30);

        return Inertia::render('Admin/Projects/DraftAdmin', [
            'drafts' => [
                'items' => collect($drafts->items())
                    ->map(static fn (ProjectDraft $draft): array => [
                        'id' => $draft->id,
                        'owner' => $draft->user?->name,
                        'company' => $companies[$draft->company_id] ?? null,
                        'current_step' => $draft->current_step,
                        'completed' => count($draft->completed_steps ?? []),
                        'media_count' => $draft->media_count ?? 0,
                        'project_id' => $draft->project_id,
                        'submitted_at' => $draft->submitted_at?->toDateTimeString(),
                        'last_touched_at' => $draft->last_touched_at?->toDateTimeString(),
                        'is_stale' => $draft->submitted_at === null
                            && $draft->last_touched_at !== null
                            && $draft->last_touched_at->lt(now()->subDays($retentionDays)),
                    ])
                    ->all(),
                'total' => $drafts->total(),
                'current_page' => $drafts->currentPage(),
                'last_page' => $drafts->lastPage(),
            ],
            'filters' => ['state' => $filter],
            'retention_days' => $retentionDays,
            'can_recover' => $isPlatform,
        ]);
    }

    /**
     * Take ownership of an abandoned draft.
     *
     * Platform-only. Reassigning somebody's working document is an
     * intervention, not a convenience, and a company administrator doing it to
     * a colleague mid-edit would be indistinguishable from losing their work.
     *
     * A SUBMITTED draft is never recovered: it is an audit record, and its
     * project already exists.
     */
    public function recover(Request $request, ProjectDraft $draft): RedirectResponse
    {
        abort_unless(ProjectScope::inPlatformMode($request), 403);
        abort_if($draft->isSubmitted(), 409);

        $previousOwner = $draft->user_id;

        $draft->forceFill([
            'user_id' => $request->user()->id,
            'last_touched_at' => now(),
            // The version moves: anybody holding the old one is now stale, and
            // should be told rather than silently overwriting the new owner.
            'version' => (int) $draft->version + 1,
        ])->save();

        $this->audit->record('project_draft.recovered', $draft, [], [
            'previous_owner_id' => $previousOwner,
            'new_owner_id' => $request->user()->id,
        ], severity: 'warning');

        return redirect()->route('admin.projects.wizard.show', [
            'draft' => $draft->id,
            'step' => $draft->current_step,
        ]);
    }

    /**
     * Discard a stale draft immediately, with its files.
     *
     * The scheduled prune does this on a retention schedule; this is the
     * manual equivalent for an administrator who already knows the draft is
     * dead. Same rules: submitted drafts are refused, and bytes go before rows.
     */
    public function purge(Request $request, ProjectDraft $draft, ProjectDraftMediaService $media): RedirectResponse
    {
        abort_unless(ProjectScope::inPlatformMode($request), 403);
        abort_if($draft->isSubmitted(), 409);

        /*
         * ONE cleanup path. This used to unlink files directly, which meant
         * the staged-failure handling lived in three places with three
         * behaviours — and this copy had no retry state at all.
         */
        $stuck = $media->purgeDraft((int) $draft->id);

        if ($stuck !== []) {
            // The rows are the last reference to bytes still on disk.
            return back()->withErrors(['media' => __('projects.wizard.creation.error_media_cleanup')]);
        }

        /*
         * The ONLY deleter. Its return value is the safety proof — ignoring it
         * and calling delete() anyway, which this did, removed the draft
         * whether or not its media had actually gone.
         */
        if (! $media->completePurge((int) $draft->id)) {
            return back()->withErrors(['media' => __('projects.wizard.creation.error_media_cleanup')]);
        }

        $this->audit->record('project_draft.purged', $draft, [], [
            'owner_id' => $draft->user_id,
        ], severity: 'warning');

        return back()->with('success', __('app.states.saved'));
    }
}
