<?php

declare(strict_types=1);

namespace App\Modules\Projects\Http\Controllers\Admin;

use App\Modules\Companies\Enums\AssociationManagementStatus;
use App\Modules\Companies\Enums\AssociationRole;
use App\Modules\Companies\Models\Company;
use App\Modules\Companies\Models\CompanyDeveloperAssociation;
use App\Modules\Companies\Models\CompanyProjectAssociation;
use App\Modules\Companies\Models\CompanyStaff;
use App\Modules\Core\Support\MediaUploader;
use App\Modules\Core\Support\SafeText;
use App\Modules\Geography\Models\Area;
use App\Modules\Geography\Models\Place;
use App\Modules\Geography\Services\AreaResolver;
use App\Modules\Geography\Support\Geodesy;
use App\Modules\Geography\Support\Polygon;
use App\Modules\Geography\ValueObjects\Coordinates;
use App\Modules\Market\Enums\PriceType;
use App\Modules\Projects\Enums\ConstructionStatus;
use App\Modules\Projects\Enums\DeliveryStatus;
use App\Modules\Projects\Enums\ProjectType;
use App\Modules\Projects\Enums\PublicationStatus;
use App\Modules\Projects\Exceptions\DuplicateMediaException;
/*
 * IMPORTED, because the return type on nearby() had nothing to resolve to.
 *
 * `nearby(): JsonResponse` without this use statement resolves to
 * App\Modules\Projects\Http\Controllers\Admin\JsonResponse — a class that does
 * not exist — so PHP raised a TypeError on the way OUT of the method and the
 * endpoint returned 500 every single time it was called. The wizard's nearby
 * area/place preview has never worked.
 */
use App\Modules\Projects\Models\Developer;
use App\Modules\Projects\Models\OrphanedFile;
use App\Modules\Projects\Models\Project;
use App\Modules\Projects\Models\ProjectDraft;
use App\Modules\Projects\Models\ProjectDraftMedia;
use App\Modules\Projects\Models\ProjectPrice;
use App\Modules\Projects\Services\ProjectDraftMediaService;
use App\Modules\Projects\Services\ProjectMediaService;
use App\Modules\Projects\Support\ActingCompany;
use App\Modules\Projects\Support\ActingCompanyContext;
use App\Modules\Projects\Support\ProjectScope;
use App\Modules\Projects\Support\WizardStep;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Throwable;

/**
 * The Project Creation Wizard (spec 12.1, 37.2).
 *
 * A project carries roughly forty fields across identity, developer, location,
 * details, pricing and media. Presented as one form on Erbil mobile data, a
 * dropped connection loses all of it — and the observed consequence is not
 * that people retype, it is that they enter less. The catalogue then fills
 * with thin records nobody can act on, which is the failure this exists to
 * prevent.
 *
 * THREE RULES THAT SHAPE EVERYTHING HERE
 *
 * 1. EVERY STEP IS VALIDATED TWICE. Once when it is saved, and again in full
 *    at submission. A stored draft is stored data, not trusted data: it can be
 *    weeks old, its area may since have been deleted, and the row is writable
 *    by anyone who can reach it.
 *
 * 2. SAVING NEVER FAILS ON INCOMPLETENESS. A step saves what it has even when
 *    it is not yet valid; it simply is not marked complete. A wizard that
 *    refuses to remember a half-filled step is a wizard people abandon.
 *
 * 3. COMPANY SCOPING IS ENFORCED SERVER-SIDE, ON EVERY REQUEST. A company user
 *    cannot create a project for another company, cannot resume another
 *    company's draft, and cannot select a developer outside their own company.
 *    The interface hides those options; hiding is not enforcement.
 */
final class ProjectWizardController extends Controller
{
    /**
     * The single authoritative area resolver.
     *
     * Injected rather than re-implemented so the wizard, the geometry observer
     * and any recalculation job all answer the same question the same way.
     */
    public function __construct(
        private readonly AreaResolver $areas,
        private readonly ProjectMediaService $media,
        private readonly ProjectDraftMediaService $draftMedia,
    ) {}

    /** Nearby places previewed on the location step. */
    private const NEARBY_LIMIT = 8;

    /** Radius for the nearby preview, in kilometres. */
    private const NEARBY_RADIUS_KM = 3.0;

    /**
     * Start a new draft, or resume the most recent unfinished one.
     *
     * The acting-company context is resolved and validated BEFORE anything is
     * written. A draft created first and scoped afterwards is a draft that
     * exists in an undefined scope for the length of one request, and for a
     * multi-company user there is no correct value to write — so nothing is
     * created until the question is answered.
     */
    public function start(Request $request): RedirectResponse
    {
        /*
         * The flag is checked HERE so the entry point can explain itself. The
         * operational routes stay behind the middleware, so switching the
         * feature off still makes every one of them unreachable.
         */
        if (! feature('projects.wizard')) {
            return redirect()->route('admin.projects.wizard.unavailable', ['reason' => 'feature_disabled']);
        }

        /*
         * The permission is checked HERE, not by middleware, so the refusal can
         * be explained. The operational routes keep their middleware, so this
         * grants nothing — it only changes what a rejected visitor sees.
         */
        /*
         * EITHER permission. Checking only the scoped one turned away platform
         * operators, whose whole role is unscoped creation.
         */
        $user = $request->user();

        $mayCreate = ($user?->hasPermission(ActingCompany::SCOPED_PERMISSION) ?? false)
            || ($user?->hasPermission(ActingCompany::PLATFORM_PERMISSION) ?? false);

        if (! $mayCreate) {
            return redirect()->route('admin.projects.wizard.unavailable', ['reason' => 'permission_denied']);
        }

        /*
         * The SESSION context, not a fresh per-request resolution.
         *
         * ActingCompany::resolve() answers "which company could this user act
         * for" from one request. Entering the Wizard a second time after
         * choosing a company therefore asked the question again — the stored
         * answer was ignored and the visitor was bounced back to the selector
         * every single time.
         */
        $companyId = ActingCompanyContext::current($request);

        if (ActingCompanyContext::mustChoose($request)) {
            return redirect()->route('admin.projects.wizard.company');
        }

        $isPlatform = $companyId === null
            && ($request->user()?->hasPermission(ActingCompany::PLATFORM_PERMISSION) ?? false);

        /*
         * A bare 403 leaves somebody unable to tell whether the feature is
         * off, they lack a permission, or something broke — and the three need
         * different actions from them. The page says which.
         */
        if ($companyId === null && ! $isPlatform) {
            return redirect()->route('admin.projects.wizard.unavailable', ['reason' => 'permission_denied']);
        }

        $acting = ['company_id' => $companyId, 'is_platform' => $isPlatform];

        $draft = ProjectDraft::query()
            ->ownedBy((int) $request->user()->id)
            ->whereNull('project_id')
            ->whereNull('submitted_at')
            // Resume only within the SAME acting context. A draft started for
            // company A must not reopen while acting for company B.
            ->where(function ($query) use ($acting): void {
                $acting['company_id'] === null
                    ? $query->whereNull('company_id')
                    : $query->where('company_id', $acting['company_id']);
            })
            ->latest('updated_at')
            ->first();

        if ($draft === null) {
            $draft = ProjectDraft::query()->create([
                'user_id' => $request->user()->id,
                'company_id' => $acting['company_id'],
                'acting_company_id' => $acting['company_id'],
                'current_step' => WizardStep::IDENTITY,
                'payload' => [],
                'completed_steps' => [],
                'last_touched_at' => now(),
            ]);
        }

        return redirect()->route('admin.projects.wizard.show', [
            'draft' => $draft->id,
            'step' => $draft->current_step,
        ]);
    }

    /**
     * What to do next, after a project has been created.
     *
     * The hand-offs live here rather than on the review screen, because by the
     * time they are useful the draft is a submitted audit record and cannot be
     * reopened. Each destination is checked against the visitor's actual
     * permissions, so nothing offers a link that will refuse them.
     */
    public function done(Request $request, Project $project): Response
    {
        ProjectScope::authorise($request, $project);

        $user = $request->user();

        return Inertia::render('Admin/Projects/WizardDone', [
            'project' => [
                'id' => $project->id,
                'name' => $project->name(),
                'publication_status' => $project->publication_status->value,
            ],
            /*
             * Capabilities, not guesses. A link somebody cannot follow is a
             * worse answer than an explanation of why it is missing.
             */
            'can' => [
                'edit' => (bool) $user?->hasPermission('projects.update'),
                'media' => (bool) $user?->hasPermission('projects.update'),
                'ratings' => (bool) $user?->hasPermission('projects.view'),
                'publish' => (bool) $user?->hasPermission('projects.publish'),
                'wizard' => (bool) $user?->hasPermission(ActingCompany::SCOPED_PERMISSION)
                    && (bool) feature('projects.wizard'),
            ],
        ]);
    }

    /** Why the Wizard cannot be opened, said plainly. */
    public function unavailable(Request $request): Response
    {
        $reason = $request->string('reason')->toString();

        return Inertia::render('Admin/Projects/WizardUnavailable', [
            'reason' => in_array($reason, ['feature_disabled', 'permission_denied'], true)
                ? $reason
                : 'permission_denied',
        ]);
    }

    /**
     * Acting-company selection for a user who belongs to more than one.
     *
     * Names are resolved trilingually so the choice is readable in the
     * visitor's own language rather than by company id.
     */
    public function company(Request $request): Response
    {
        /*
         * Only memberships that may MANAGE PROJECTS are offered. Listing every
         * active membership and rejecting the choice afterwards shows somebody
         * an option and then refuses it — the selector must not contain an
         * answer the server will not accept.
         */
        $available = ActingCompanyContext::available($request->user());

        $companies = Company::query()
            ->whereIn('id', $available)
            ->get(['id', 'name_ckb', 'name_ar', 'name_en'])
            ->map(static fn (Company $company): array => [
                'id' => $company->id,
                'name' => $company->name(),
            ])
            ->all();

        return Inertia::render('Admin/Projects/WizardCompany', [
            'companies' => $companies,
            // Offered only to an explicitly authorised platform operator.
            'may_use_platform_mode' => $available === []
                && (bool) $request->user()?->hasPermission(ActingCompany::PLATFORM_PERMISSION),
        ]);
    }

    /**
     * Switch the acting company from anywhere in the admin.
     *
     * Distinct from chooseCompany(), which also creates or resumes a draft.
     * This one only changes context and returns the visitor where they were,
     * so switching from the project index does not silently start a wizard.
     */
    public function switchCompany(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            // `platform` is an explicit choice, not a null company id.
            'acting_company_id' => ['required'],
        ]);

        if ($validated['acting_company_id'] === 'platform') {
            abort_unless(ActingCompanyContext::switchToPlatform($request), 403);

            return back();
        }

        // Membership is validated inside switchTo(); a company the user does
        // not manage is refused rather than quietly ignored.
        abort_unless(
            ActingCompanyContext::switchTo($request, (int) $validated['acting_company_id']),
            403,
        );

        return back();
    }

    /** Record the chosen acting company and continue into the wizard. */
    public function chooseCompany(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'acting_company_id' => [
                'required',
                'integer',
                // Validated against memberships that may manage projects, not
                // merely against the companies table and not against every
                // membership the user happens to hold.
                Rule::in(ActingCompanyContext::available($request->user())),
            ],
        ]);

        $companyId = (int) $validated['acting_company_id'];

        // Persist the choice for the whole admin session, so the project
        // index, links, pagination, media and ratings all agree with it
        // without every URL having to carry the id.
        abort_unless(ActingCompanyContext::switchTo($request, $companyId), 403);

        $draft = ProjectDraft::query()
            ->ownedBy((int) $request->user()->id)
            ->whereNull('project_id')
            ->whereNull('submitted_at')
            ->where('company_id', $companyId)
            ->latest('updated_at')
            ->first()
            ?? ProjectDraft::query()->create([
                'user_id' => $request->user()->id,
                'company_id' => $companyId,
                'acting_company_id' => $companyId,
                'current_step' => WizardStep::IDENTITY,
                'payload' => [],
                'completed_steps' => [],
                'last_touched_at' => now(),
            ]);

        return redirect()->route('admin.projects.wizard.show', [
            'draft' => $draft->id,
            'step' => $draft->current_step,
        ]);
    }

    public function show(Request $request, ProjectDraft $draft, string $step): Response
    {
        $this->authoriseDraft($request, $draft);

        abort_unless(WizardStep::exists($step), 404);

        abort_if($draft->submitted_at !== null, 409);

        $this->assertStepReachable($draft, $step);

        $draft->forceFill(['current_step' => $step, 'last_touched_at' => now()])->save();

        return Inertia::render('Admin/Projects/Wizard', [
            'draft' => [
                'id' => $draft->id,
                'current_step' => $step,
                'completed_steps' => $draft->completed_steps ?? [],
                'values' => $draft->step($step),
                'all_values' => $draft->flattened(),
                'missing_steps' => $draft->missingSteps(),
                'is_submittable' => $draft->isSubmittable(),
                'project_id' => $draft->project_id,
                'updated_at' => $draft->updated_at?->toIso8601String(),
                // Echoed back on save so a stale tab is rejected rather than
                // silently overwriting another device's work.
                'version' => (int) $draft->version,
                'submitted_at' => $draft->submitted_at?->toIso8601String(),
            ],
            /*
             * The DRAFT's stored context, not a fresh resolution. Resolving
             * again returned must_choose=true on every later request for a
             * multi-company user, so the wizard kept asking which company to
             * act for after the draft had already recorded the answer.
             */
            'retention_days' => (int) config('mulkihawler.wizard.draft_retention_days', 30),
            'acting' => [
                'company_id' => $draft->scopedCompanyId(),
                'company_name' => $draft->scopedCompanyId() === null
                    ? null
                    : Company::query()->find($draft->scopedCompanyId())?->name(),
                'is_platform' => $draft->scopedCompanyId() === null,
                'must_choose' => false,
            ],
            // Draft-owned uploads. Never ids the client supplied.
            'media' => ProjectDraftMedia::query()
                ->where('project_draft_id', $draft->id)
                // Rows awaiting cleanup are on their way out; showing them
                // offers an image that is about to stop existing.
                ->where('cleanup_pending', false)
                ->orderBy('sort_order')
                ->get()
                ->map(static fn (ProjectDraftMedia $item): array => [
                    'id' => $item->id,
                    'path' => $item->path,
                    'alt' => $item->alt(),
                    // The three locales individually, so the editor shows what
                    // is actually stored rather than the fallback.
                    'alt_ckb' => $item->alt_ckb,
                    'alt_ar' => $item->alt_ar,
                    'alt_en' => $item->alt_en,
                    // Authenticated, draft-scoped preview. Never a public path.
                    'preview_url' => route('admin.projects.wizard.media.preview', [
                        'draft' => $draft->id,
                        'media' => $item->id,
                    ]),
                    'is_cover' => (bool) $item->is_cover,
                ])
                ->all(),
            'steps' => WizardStep::all(),
            'required_steps' => WizardStep::required(),
            'options' => $this->optionsFor($request, $step, $draft),
            'can' => [
                // Publishing is a separate permission from creating. Somebody
                // who may enter a project is not thereby entitled to put it in
                // front of the public.
                'publish' => (bool) $request->user()?->can('projects.publish'),
            ],
        ]);
    }

    /**
     * Save one step.
     *
     * Two-phase on purpose: the values are stored regardless, then validated
     * to decide whether the step counts as complete. Storing only valid steps
     * would mean a visitor who fills half a step and closes the tab loses it.
     */
    public function save(Request $request, ProjectDraft $draft, string $step): RedirectResponse
    {
        $this->authoriseDraft($request, $draft);

        abort_unless(WizardStep::exists($step), 404);

        // A submitted draft is a historical record, not a working document.
        abort_if($draft->submitted_at !== null, 409);

        $this->assertStepReachable($draft, $step);

        /*
         * Optimistic lock, MANDATORY.
         *
         * It was previously skipped when the client sent no version — which
         * meant any request that simply omitted the field bypassed the lock
         * entirely, and the protection existed only for clients that opted in.
         * A missing version is now the same failure as a stale one.
         */
        $clientVersion = $request->integer('version');

        if ($clientVersion !== (int) $draft->version) {
            return back()->withErrors([
                'version' => __('projects.wizard.creation.error_stale'),
            ])->withInput();
        }

        $rules = WizardStep::rules($step);
        $input = $request->only(array_map(
            static fn (string $key): string => explode('.', $key)[0],
            array_keys($rules),
        ));

        $payload = $draft->payload ?? [];
        $payload[$step] = $input;

        $completed = $draft->completed_steps ?? [];
        $validator = validator($input, $rules);

        $scopeErrors = $this->scopeViolations($request, $input, $draft);

        // A required step also has to CONTAIN something. Every rule on the
        // developer step was nullable, so an empty post satisfied all of them
        // and the step was marked complete having collected nothing.
        $meaningful = WizardStep::isMeaningful($step, $input);
        $isRequired = in_array($step, WizardStep::required(), true);

        if ($validator->fails() || $scopeErrors !== [] || ($isRequired && ! $meaningful)) {
            $errors = $validator->errors()->toArray();

            foreach ($scopeErrors as $field => $message) {
                $errors[$field][] = $message;
            }

            if ($isRequired && ! $meaningful && $scopeErrors === [] && ! $validator->fails()) {
                $errors['_step'][] = __('projects.wizard.creation.error_step_empty');
            }

            // Stored anyway, and NOT marked complete.
            $draft->forceFill([
                'payload' => $payload,
                'completed_steps' => array_values(array_diff($completed, [$step])),
                'last_touched_at' => now(),
                'version' => (int) $draft->version + 1,
            ])->save();

            return back()->withErrors($errors)->withInput();
        }

        if (! in_array($step, $completed, true)) {
            $completed[] = $step;
        }

        $draft->forceFill([
            'payload' => $payload,
            'completed_steps' => array_values($completed),
            'acting_company_id' => $draft->acting_company_id ?? $draft->company_id,
            'last_touched_at' => now(),
            'version' => (int) $draft->version + 1,
        ])->save();

        $next = $request->boolean('advance') ? WizardStep::next($step) : $step;

        return redirect()->route('admin.projects.wizard.show', [
            'draft' => $draft->id,
            'step' => $next ?? $step,
        ]);
    }

    /**
     * Step navigation rule, enforced server-side.
     *
     * A visitor may open any step they have already completed, the first
     * incomplete required step, and any optional step. They may NOT jump to a
     * later required step by typing its URL — the comments claimed this and
     * the server accepted any valid step name, so a direct URL advanced the
     * draft's `current_step` past work that had never been done.
     */
    private function assertStepReachable(ProjectDraft $draft, string $step): void
    {
        if ($draft->hasCompleted($step) || ! in_array($step, WizardStep::required(), true)) {
            return;
        }

        foreach (WizardStep::required() as $required) {
            if ($required === $step) {
                return;   // this is the first outstanding required step
            }

            abort_unless($draft->hasCompleted($required), 403);
        }
    }

    /**
     * Nearby places and the suggested area for a candidate location.
     *
     * Area resolution delegates to the shared AreaResolver so the suggestion
     * and the value submission stores cannot disagree — a bbox suggestion
     * with a polygon save meant the wizard offered one area and kept another.
     *
     * Kilometres, straight-line, labelled (§10.5). No travel time: there is no
     * routing provider, and a duration derived from a straight line reads as a
     * measurement while being a guess.
     */
    public function nearby(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'latitude' => ['required', 'numeric', 'between:-90,90'],
            'longitude' => ['required', 'numeric', 'between:-180,180'],
        ]);

        $centre = Coordinates::make((float) $validated['latitude'], (float) $validated['longitude']);

        // Degree box first, so the haversine runs over a small candidate set
        // rather than the whole places table.
        $latPad = self::NEARBY_RADIUS_KM / 111.0;
        $lngPad = $latPad / max(cos(deg2rad($centre->latitude)), 0.01);

        $places = Place::query()
            ->where('publication_status', 'published')
            ->where('is_public', true)
            ->where('is_duplicate_primary', true)
            ->where('operational_status', 'operating')
            ->whereBetween('latitude', [$centre->latitude - $latPad, $centre->latitude + $latPad])
            ->whereBetween('longitude', [$centre->longitude - $lngPad, $centre->longitude + $lngPad])
            ->with('category')
            ->limit(200)
            ->get()
            ->map(static function (Place $place) use ($centre): array {
                $distance = Geodesy::distanceKm(
                    $centre,
                    Coordinates::make((float) $place->latitude, (float) $place->longitude),
                );

                return [
                    'name' => $place->name(),
                    'category' => $place->category?->key,
                    'distance_km' => round($distance, 2),
                    'distance_method' => 'straight_line',
                    'travel_time_minutes' => null,
                ];
            })
            ->filter(static fn (array $row): bool => $row['distance_km'] <= self::NEARBY_RADIUS_KM)
            ->sortBy('distance_km')
            ->take(self::NEARBY_LIMIT)
            ->values()
            ->all();

        $area = $this->areas->resolve($centre);

        return response()->json([
            'places' => $places,
            'suggested_area' => $area === null ? null : ['id' => $area->id, 'name' => $area->name()],
            // Null reported honestly: coordinates in no published area need
            // review, and silence would look like agreement.
            'area_unresolved' => $area === null,
            'radius_km' => self::NEARBY_RADIUS_KM,
            'distance' => ['unit' => 'km', 'method' => 'straight_line', 'travel_time_available' => false],
        ]);
    }

    /**
     * Upload media onto a draft.
     *
     * MediaUploader validates dimensions and returns width, height, checksum
     * and mime read from the file itself — a renamed executable does not
     * become an image by having .jpg on the end.
     *
     * The row is bound to the draft, the uploader AND the draft's stored
     * acting company at insert time, so there is no id to craft.
     */
    public function uploadMedia(Request $request, ProjectDraft $draft, MediaUploader $uploader): RedirectResponse
    {
        $this->authoriseDraft($request, $draft);

        abort_if($draft->isSubmitted(), 409);

        $request->validate([
            'file' => [
                'required', 'file',
                'mimes:jpg,jpeg,png,webp',
                'mimetypes:image/jpeg,image/png,image/webp',
                'max:8192',
            ],
            'alt_ckb' => ['nullable', 'string', 'max:255'],
            'alt_ar' => ['nullable', 'string', 'max:255'],
            'alt_en' => ['nullable', 'string', 'max:255'],
        ]);

        $file = $request->file('file');
        // PRIVATE disk. A draft is an unpublished commercial plan.
        $result = $uploader->storeImage($file, 'project-drafts/'.$draft->id, 'draft-media');

        /*
         * MediaUploader reports failure through `ok`, not an exception. Not
         * checking it meant a rejected upload — wrong MIME sniffed from the
         * bytes, a corrupt image, unreadable dimensions, a storage error —
         * fell straight through to an INSERT with null path, mime, size and
         * checksum. The gallery then held a row pointing at nothing, and the
         * duplicate check compared null against null forever after.
         */
        // The uploader always reports `ok`; `reason` is what may be absent.
        if ($result['ok'] !== true) {
            $reason = (string) ($result['reason'] ?? 'unknown');

            // Some failures happen after bytes are written; remove them rather
            // than leaving an orphan on a shared host.
            if (! empty($result['path'])) {
                OrphanedFile::removeOrRecord(
                    'draft-media',
                    (string) $result['path'],
                    'upload_rejected_cleanup_failed',
                    ['project_draft_id' => $draft->id],
                );
            }

            return back()->withErrors([
                'file' => __('media.errors.'.$reason, [], null) !== 'media.errors.'.$reason
                    ? __('media.errors.'.$reason)
                    : __('projects.wizard.creation.error_media_upload'),
            ]);
        }

        /*
         * The duplicate check lives in the SERVICE, under the draft lock.
         * Doing it here was a second, unlocked implementation: two identical
         * simultaneous uploads both passed it and both inserted.
         */

        /*
         * COMPENSATION for a write that succeeded followed by a database step
         * that did not.
         *
         * Bytes land on disk before the row exists, so a failure in attach() —
         * the draft locked and now submitted, a constraint, a deadlock — used
         * to leave a file nothing referenced. Removing it here keeps the two
         * in step; if the removal itself fails the sweep has no row to find,
         * so it is reported rather than swallowed.
         */
        try {
            $this->draftMedia->attach((int) $draft->id, [
                'uploaded_by' => $request->user()->id,
                'acting_company_id' => $draft->scopedCompanyId(),
                'kind' => 'image',
                'disk' => 'draft-media',
                'path' => $result['path'],
                'original_name' => $file->getClientOriginalName(),
                'mime_type' => $result['mime'],
                'size_bytes' => $result['size'],
                'width' => $result['width'],
                'height' => $result['height'],
                'checksum' => $result['checksum'],
                'alt_ckb' => $request->string('alt_ckb')->toString() ?: null,
                'alt_ar' => $request->string('alt_ar')->toString() ?: null,
                'alt_en' => $request->string('alt_en')->toString() ?: null,
                'expires_at' => now()->addDays((int) config('mulkihawler.wizard.draft_retention_days', 30)),
            ]);
        } catch (DuplicateMediaException) {
            // Ordinary outcome, told plainly; the redundant bytes still go.
            OrphanedFile::removeOrRecord(
                'draft-media',
                (string) $result['path'],
                'duplicate_upload_cleanup_failed',
                ['project_draft_id' => $draft->id, 'user_id' => $request->user()?->id],
            );

            return back()->withErrors(['file' => __('media.errors.duplicate')]);
        } catch (Throwable $e) {
            /*
             * A DURABLE record when removal fails. There is no media row to
             * flag — the insert is what failed — so without this the file has
             * nothing referencing it and no sweep can find it.
             */
            OrphanedFile::removeOrRecord(
                'draft-media',
                (string) $result['path'],
                'upload_compensation_failed',
                [
                    'project_draft_id' => $draft->id,
                    'user_id' => $request->user()?->id,
                    'last_error' => SafeText::truncate($e->getMessage(), 255),
                ],
            );

            return back()->withErrors([
                'file' => __('projects.wizard.creation.error_media_upload'),
            ]);
        }

        return back();
    }

    /**
     * Reorder, retitle, set the cover, or delete a draft upload.
     *
     * Ids resolve through `ownedBy`, so one belonging to another draft or
     * another uploader matches nothing rather than being rejected after the
     * fact. Deleting removes the bytes too — an orphaned file on a shared host
     * is storage nobody is accounting for.
     */
    public function updateMedia(Request $request, ProjectDraft $draft, MediaUploader $uploader): RedirectResponse
    {
        $this->authoriseDraft($request, $draft);

        abort_if($draft->isSubmitted(), 409);

        $validated = $request->validate([
            'order' => ['nullable', 'array'],
            'order.*' => ['integer'],
            'cover_id' => ['nullable', 'integer'],
            'delete_id' => ['nullable', 'integer'],
            'alt' => ['nullable', 'array'],
            'alt.*.ckb' => ['nullable', 'string', 'max:255'],
            'alt.*.ar' => ['nullable', 'string', 'max:255'],
            'alt.*.en' => ['nullable', 'string', 'max:255'],
        ]);

        $userId = (int) $request->user()->id;
        $draftId = (int) $draft->id;

        /*
         * ONE writer. Ordering, cover and deletion were raw updates here with
         * no lock and no invariant — two concurrent uploads could both become
         * the cover, and deleting the cover left none.
         */
        if (! empty($validated['delete_id'])) {
            if (! $this->draftMedia->delete($draftId, $userId, (int) $validated['delete_id'])) {
                return back()->withErrors([
                    'media' => __('projects.wizard.creation.error_media_cleanup'),
                ]);
            }
        }

        if (($validated['order'] ?? []) !== []) {
            $this->draftMedia->reorder($draftId, $userId, array_map('intval', $validated['order']));
        }

        foreach ($validated['alt'] ?? [] as $id => $texts) {
            $this->draftMedia->updateAlt($draftId, $userId, (int) $id, $texts);
        }

        if (! empty($validated['cover_id'])
            && ! $this->draftMedia->setCover($draftId, $userId, (int) $validated['cover_id'])) {
            return back()->withErrors(['cover_id' => __('projects.wizard.creation.error_cover_media')]);
        }

        return back();
    }

    /**
     * Discard a draft.
     *
     * A SUBMITTED draft is an audit record: the only link between a created
     * project and what its author actually typed. The ordinary discard
     * endpoint therefore refuses it.
     *
     * Uploaded bytes are removed with the draft; database rows cascade, files
     * do not.
     */
    /**
     * Push the retention clock out without changing content.
     *
     * A draft silently deleted after a month is indistinguishable from one
     * that was lost, so somebody part-way through a slow project needs a way
     * to say "still working on this" that is not a fake edit.
     */
    public function touch(Request $request, ProjectDraft $draft): RedirectResponse
    {
        $this->authoriseDraft($request, $draft);

        abort_if($draft->isSubmitted(), 409);

        // last_touched_at ONLY. The version is deliberately not bumped:
        // nothing changed, and bumping it would invalidate a tab that is
        // legitimately open.
        $draft->forceFill(['last_touched_at' => now()])->save();

        return back();
    }

    /**
     * Stream one draft upload for preview.
     *
     * An editor cannot order images or choose a cover without seeing them, and
     * the private disk has no public URL by design. The bytes are therefore
     * served through the application, where the SAME authorisation that guards
     * the draft guards its media — rather than by moving uploads to a public
     * disk, which would expose every in-progress project to anyone with a path.
     *
     * `ownedBy` scopes the lookup, so an id from another draft matches nothing
     * rather than being rejected after loading.
     */
    public function previewMedia(Request $request, ProjectDraft $draft, int $media): StreamedResponse
    {
        $this->authoriseDraft($request, $draft);

        $item = ProjectDraftMedia::query()
            ->ownedBy((int) $draft->id, (int) $request->user()->id)
            ->whereKey($media)
            ->firstOrFail();

        $disk = Storage::disk((string) ($item->disk ?: 'public'));

        abort_unless($disk->exists((string) $item->path), 404);

        return $disk->response((string) $item->path, null, [
            // Private: a shared cache must never hold another draft's image.
            'Cache-Control' => 'private, max-age=300',
            'Content-Type' => (string) $item->mime_type,
        ]);
    }

    public function destroy(Request $request, ProjectDraft $draft, MediaUploader $uploader): RedirectResponse
    {
        $this->authoriseDraft($request, $draft);

        abort_if($draft->isSubmitted(), 409);

        // Bytes first, through the one service that knows how to stage a
        // failure. A non-empty result means files survive and the draft must
        // not be deleted around them.
        $stuck = $this->draftMedia->purgeDraft((int) $draft->id);

        if ($stuck !== []) {
            // The rows are the last reference to bytes still on disk.
            return back()->withErrors([
                'media' => __('projects.wizard.creation.error_media_cleanup'),
            ]);
        }

        /*
         * completePurge() is the ONLY thing that deletes a draft.
         *
         * It re-locks, confirms the purge state and proves no media survives.
         * Calling it and then deleting anyway — which this did — threw that
         * proof away and deleted the draft around files that had not gone.
         */
        if (! $this->draftMedia->completePurge((int) $draft->id)) {
            return back()->withErrors([
                'media' => __('projects.wizard.creation.error_media_cleanup'),
            ]);
        }

        return redirect()->route('admin.projects.index');
    }

    /**
     * Turn a complete draft into a project.
     *
     * Every required step is re-validated here from the stored payload. The
     * draft's own `completed_steps` is a hint about what happened earlier, not
     * a guarantee about now.
     */
    public function submit(Request $request, ProjectDraft $draft): RedirectResponse
    {
        $this->authoriseDraft($request, $draft);

        /*
         * IDEMPOTENT AND CONCURRENCY-SAFE.
         *
         * The whole submission runs inside one transaction with the draft row
         * locked. Without the lock, a double-click — or a replayed request on
         * a flaky mobile connection, which is routine here — creates two
         * projects from one draft, and nothing downstream can tell they are
         * duplicates.
         *
         * The lock is taken BEFORE the project_id check, because checking
         * outside the lock is the classic check-then-act race: both requests
         * read null, both proceed.
         */
        $outcome = DB::transaction(function () use ($draft, $request): array {
            $locked = ProjectDraft::query()->lockForUpdate()->find($draft->id);

            if ($locked === null) {
                return ['status' => 'gone', 'project' => null];
            }

            // Already submitted: return the existing project rather than
            // making another. A replay is not an error to the visitor.
            if ($locked->project_id !== null) {
                return ['status' => 'duplicate', 'project' => Project::query()->find($locked->project_id)];
            }

            $errors = [];

            /*
             * EVERY required step, and every OPTIONAL step that contains data.
             *
             * Validating required steps alone meant a corrupted pricing or
             * association payload was persisted unchecked — optional means
             * "you need not fill this in", not "whatever is in here is
             * acceptable". A stored draft is stored data, and stored data is
             * not trusted.
             */
            foreach (WizardStep::all() as $step) {
                $values = $locked->step($step);
                $isRequired = in_array($step, WizardStep::required(), true);
                $hasData = array_filter(
                    $values,
                    static fn ($value): bool => $value !== null && $value !== '' && $value !== [],
                ) !== [];

                if (! $isRequired && ! $hasData) {
                    continue;   // genuinely empty optional step
                }

                $validator = validator($values, WizardStep::rules($step));

                if ($validator->fails()) {
                    $errors = array_merge($errors, $validator->errors()->toArray());
                }

                if ($isRequired && ! WizardStep::isMeaningful($step, $values)) {
                    $errors['_step'][] = __('projects.wizard.creation.error_step_empty');
                }
            }

            $scopeErrors = $this->scopeViolations($request, $locked->flattened(), $locked);

            if ($errors !== [] || $scopeErrors !== []) {
                return ['status' => 'invalid', 'errors' => array_merge($errors, $scopeErrors), 'project' => null];
            }

            /*
             * ORDER MATTERS HERE TOO.
             *
             * The draft must reach its SUBMITTED state before the association
             * evidence is written, because recordCreationEvidence() verifies
             * the exact draft→project correlation: same creator, same company,
             * submitted, and pointing at this project. Writing evidence first
             * meant company-scoped submission threw every time — the draft had
             * neither project_id nor submitted_at yet.
             *
             * All of it is one transaction under one lock, so an intermediate
             * submitted draft is never visible if a later step fails.
             */
            $project = $this->persist($locked, $request);

            $locked->forceFill([
                'project_id' => $project->id,
                'submitted_at' => now(),
                'version' => (int) $locked->version + 1,
            ])->save();

            // Now the draft can vouch for what it created.
            $this->persistAssociation($project, $locked->flattened(), $locked);

            $this->persistPricing($project, $locked->flattened(), $request);
            $this->media->promoteDraftMedia($project->id, (int) $locked->id);

            return ['status' => 'created', 'project' => $project];
        });

        return match ($outcome['status']) {
            'gone' => redirect()->route('admin.projects.index'),
            'invalid' => back()->withErrors($outcome['errors']),
            /*
             * A COMPLETION PAGE, not a jump straight to the edit form.
             *
             * Redirecting to edit made the review screen's ratings and
             * publication hand-offs unreachable: they only rendered once a
             * project existed, and by then the draft could no longer be
             * opened. Dead conditional UI is worse than none, because it
             * looks like the feature is there.
             */
            'duplicate' => redirect()
                ->route('admin.projects.wizard.done', $outcome['project']->id)
                ->with('success', __('projects.wizard.creation.already_submitted')),
            default => redirect()
                ->route('admin.projects.wizard.done', $outcome['project']->id)
                ->with('success', __('projects.wizard.creation.submitted')),
        };
    }

    /**
     * Write everything the wizard collected.
     *
     * The previous version accepted pricing, association role, media and
     * provenance and then wrote none of it — data a person typed, confirmed
     * on a review screen, and lost. Silently discarding entered input is
     * worse than refusing it, because nothing tells them it happened.
     */
    private function persist(ProjectDraft $draft, Request $request): Project
    {
        $values = $draft->flattened();

        $latitude = isset($values['latitude']) ? (float) $values['latitude'] : null;
        $longitude = isset($values['longitude']) ? (float) $values['longitude'] : null;

        // Area: explicit choice wins; otherwise resolved by point-in-polygon.
        $areaId = $values['area_id'] ?? null;
        $matchType = null;
        $unresolved = false;

        if ($areaId !== null) {
            // `exists:areas,id` does not mean publishable. Selecting an
            // unpublished area would file a project under a name no visitor
            // can see, and leak that name through the project page.
            $selected = Area::query()->where('publication_status', 'published')->find($areaId);

            if ($selected === null) {
                throw ValidationException::withMessages([
                    'area_id' => __('projects.wizard.creation.error_area_unpublished'),
                ]);
            }

            // Distinguishes a deliberate choice from one the wizard offered
            // and the person merely accepted.
            $matchType = ($values['area_was_suggested'] ?? false) ? 'suggested' : 'manual';
        }

        if ($areaId === null && $latitude !== null && $longitude !== null) {
            // The SAME service the geometry observer and recalculation jobs
            // use. A manual assignment above is never reached by this branch,
            // so automatic resolution can never overwrite a human decision.
            $area = $this->areas->resolve(Coordinates::make($latitude, $longitude));

            if ($area === null) {
                // Honest: coordinates were given and matched no published
                // area. Flagged for review rather than left blank.
                $unresolved = true;
            } else {
                $areaId = $area->id;
                $matchType = 'boundary';
            }
        }

        $project = Project::query()->create([
            'slug' => $this->slugFor($values),
            'name_ckb' => $values['name_ckb'],
            'name_ar' => $values['name_ar'] ?? null,
            'name_en' => $values['name_en'] ?? null,
            'project_type' => $values['project_type'],
            'construction_status' => $values['construction_status'],
            'delivery_status' => $values['delivery_status'],
            'completion_percent' => $values['completion_percent'] ?? null,
            'developer_id' => $values['developer_id'] ?? null,
            'area_id' => $areaId,
            'area_is_manual' => $matchType === 'manual',
            'area_assigned_at' => $areaId !== null ? now() : null,
            'area_match_type' => $matchType,
            'area_unresolved' => $unresolved,
            'latitude' => $latitude,
            'longitude' => $longitude,
            'boundary_wkt' => $values['boundary_wkt'] ?? null,
            'unit_count' => $values['unit_count'] ?? null,
            'expected_delivery' => $values['expected_delivery'] ?? null,
            // Creator provenance (§5): who entered a record is part of being
            // able to check it.
            'created_by' => $request->user()?->id,
            // A new project is NEVER born published, whatever the author's
            // permissions. Publication is its own reviewed transition.
            'publication_status' => PublicationStatus::Draft->value,
        ]);

        // Association, pricing and media promotion happen in submit(), AFTER
        // the draft is marked submitted — evidence depends on that state.
        $this->persistDeveloperLink($project, $values, $draft);

        return $project;
    }

    /**
     * Record the company↔developer link this submission asserts.
     *
     * Created as PENDING: a company naming a developer is a claim, and a claim
     * a company can approve for itself is not a control. It confers nothing
     * until reviewed — but it exists, so the relationship is visible to a
     * reviewer instead of being invisible until somebody notices the field is
     * unusable.
     *
     * @param  array<string, mixed>  $values
     */
    private function persistDeveloperLink(Project $project, array $values, ProjectDraft $draft): void
    {
        $developerId = $values['developer_id'] ?? null;
        $companyId = $draft->scopedCompanyId();

        if ($developerId === null || $companyId === null) {
            return;
        }

        CompanyDeveloperAssociation::query()->firstOrCreate(
            ['company_id' => $companyId, 'developer_id' => (int) $developerId],
            [
                'management_status' => AssociationManagementStatus::Pending->value,
                'is_approved' => false,
                'created_by' => $draft->user_id,
                'status_changed_at' => now(),
                'notes' => 'Asserted during Wizard submission; awaiting review.',
            ],
        );
    }

    /** @param  array<string, mixed>  $values */
    private function persistPricing(Project $project, array $values, Request $request): void
    {
        if (! isset($values['price_from']) || $values['price_from'] === '') {
            return;
        }

        /*
         * Written to `project_prices`, NOT `price_records`. A developer's
         * advertised range is not a market observation; putting it in the
         * observation table would feed asking prices into indices as sampled
         * data, which is the contamination §15 exists to prevent.
         */
        ProjectPrice::query()->create([
            'project_id' => $project->id,
            'price_from' => $values['price_from'],
            'price_to' => $values['price_to'] ?? null,
            'currency' => $values['currency'] ?? 'USD',
            'price_type' => $values['price_type'],
            'period' => $values['price_period'] ?? null,
            'effective_date' => $values['price_effective_date'] ?? null,
            'source' => $values['price_source'] ?? null,
            'confidence' => $values['price_confidence'] ?? 'medium',
            'created_by' => $request->user()?->id,
        ]);
    }

    /** @param  array<string, mixed>  $values */
    private function persistAssociation(Project $project, array $values, ProjectDraft $draft): void
    {
        $companyId = $values['company_id'] ?? $draft->scopedCompanyId();

        if ($companyId === null) {
            return;
        }

        // The membership that authorises this association, captured now.
        $membership = CompanyStaff::query()
            ->where('company_id', $companyId)
            ->where('user_id', $draft->user_id)
            ->where('is_active', true)
            ->where('may_manage_projects', true)
            ->first();

        $role = $values['association_role'] ?? null;

        /*
         * NO SILENT DEFAULT. Falling back to `official_developer` invented a
         * commercial relationship nobody asserted — the difference between an
         * official developer and an advertising partner is a legal one, and
         * guessing it wrong is a claim the platform cannot support.
         *
         * The role is required alongside the company at validation time, so
         * reaching here without one means the payload was tampered with after
         * validation. Refusing is the only safe answer.
         */
        if ($role === null || AssociationRole::tryFrom((string) $role) === null) {
            throw ValidationException::withMessages([
                'association_role' => __('projects.wizard.creation.error_association_role'),
            ]);
        }

        $association = CompanyProjectAssociation::query()->create([
            'company_id' => $companyId,
            'project_id' => $project->id,
            'role' => $role,
            'is_approved' => false,
            // Pending, not rejected: the company that created this through the
            // Wizard keeps editing it while review is outstanding.
            'management_status' => AssociationManagementStatus::Pending->value,
            'created_by' => $draft->user_id,
            // The provenance that makes a pending association trustworthy:
            // without it ProjectScope grants nothing.
            'created_via_project_draft_id' => $draft->id,
            'status_changed_at' => now(),
        ]);

        /*
         * Evidence is written through the model's guarded writer, not by mass
         * assignment. It is not fillable precisely because a request that can
         * assign it can forge the proof authorising itself.
         */
        if ($membership !== null) {
            $association->recordCreationEvidence($membership);
        }
    }

    /**
     * Ownership and company scoping.
     *
     * 404 rather than 403 for another user's draft: confirming that a draft id
     * exists tells an attacker how many projects are being entered and by
     * whom, which is commercial information.
     */
    private function authoriseDraft(Request $request, ProjectDraft $draft): void
    {
        $user = $request->user();

        abort_unless($user !== null && (int) $draft->user_id === (int) $user->id, 404);

        /*
         * Scope is re-checked on EVERY request, not trusted from creation.
         * A membership can be deactivated between one request and the next,
         * and when that happens a scoped draft must become inaccessible — not
         * quietly become an unscoped platform draft, which is what a
         * creation-time-only check produces.
         */
        /*
         * The DRAFT's stored scope is authoritative, not a fresh resolution
         * from the current request. Re-resolving meant a POST carrying no
         * acting_company_id fell back to "whichever membership", so a draft
         * scoped to company A could be operated on while acting for B by a
         * user who belongs to both.
         */
        $scoped = $draft->scopedCompanyId();

        /*
         * THE STORED MODE decides, on every request.
         *
         * A coarse route gate cannot know whether this particular draft
         * matches the mode the session is in — so a company user must not
         * reach an unscoped draft merely because they hold a create
         * permission, and a platform operator must not silently edit a
         * company's scoped draft.
         */
        if ($scoped === null) {
            /*
             * An UNSCOPED draft belongs to platform mode.
             *
             * `ActingCompany::stillPermits($user, null)` answered "does this
             * user have no memberships", which refused a dual-role operator
             * their own unscoped draft the moment they belonged to any
             * company. Platform mode is now an explicit session choice, so
             * that is the thing to ask.
             */
            abort_unless(ActingCompanyContext::isPlatformMode($request), 404);

            // ...and platform mode requires the unscoped permission, not just
            // the absence of a company.
            abort_unless(
                $user->hasPermission(ActingCompany::PLATFORM_PERMISSION),
                403,
            );

            return;
        }

        // A scoped draft requires the session to be acting for that company —
        // not merely that the user could act for it.
        abort_unless(ActingCompanyContext::current($request) === $scoped, 404);
        abort_unless(ActingCompany::stillPermits($user, $scoped), 404);

        // Company-scoped work requires the scoped permission specifically.
        abort_unless($user->hasPermission(ActingCompany::SCOPED_PERMISSION), 403);
    }

    /**
     * Reject a posted company or developer outside the acting scope.
     *
     * A company user can craft any integer; the select they were shown is not
     * a constraint. Both are checked against the association table, which is
     * the only record of what a company is actually connected to.
     *
     * @param  array<string, mixed>  $input
     * @return array<string, string> field => message key
     */
    private function scopeViolations(Request $request, array $input, ProjectDraft $draft): array
    {
        $errors = [];
        $scoped = $draft->scopedCompanyId();

        if ($scoped === null) {
            // An unscoped draft belongs to a platform operator; authoriseDraft
            // has already confirmed they are entitled to one.
            return $errors;
        }

        /*
         * Exactly ONE permitted company: the draft's. Validating against every
         * membership the user holds meant somebody in companies A and B could
         * attach B's developer to a draft scoped to A.
         */
        $permitted = [$scoped];

        $companyId = $input['company_id'] ?? null;

        if ($companyId !== null && ! in_array((int) $companyId, $permitted, true)) {
            $errors['company_id'] = __('projects.wizard.creation.error_company_scope');
        }

        $developerId = $input['developer_id'] ?? null;

        /*
         * ONE authority. This derived its own permitted set from every
         * association row, ignoring management status, provenance and dates —
         * so a rejected or expired association still made a developer
         * selectable here while ProjectScope refused it elsewhere.
         *
         * Null means platform: unrestricted.
         */
        if ($developerId !== null) {
            $allowed = ProjectScope::permittedDeveloperIds($request);

            if ($allowed !== null && ! in_array((int) $developerId, $allowed, true)) {
                $errors['developer_id'] = __('projects.wizard.creation.error_developer_scope');
            }
        }

        return $errors;
    }

    /**
     * Selectable values for a step, already scoped.
     *
     * Sent per step rather than all at once: shipping every area, developer
     * and company on every wizard page is a payload nobody on mobile data
     * should pay for six times.
     *
     * @return array<string, mixed>
     */
    private function optionsFor(Request $request, string $step, ProjectDraft $draft): array
    {
        // The draft's stored scope, never a fresh resolution. Options a
        // visitor is shown must match the scope their posts are validated
        // against, or the interface offers choices the server will reject.
        $companyId = $draft->scopedCompanyId();

        $enums = [
            'project_types' => array_column(ProjectType::cases(), 'value'),
            'construction_statuses' => array_column(ConstructionStatus::cases(), 'value'),
            'delivery_statuses' => array_column(DeliveryStatus::cases(), 'value'),
            'price_types' => array_column(PriceType::cases(), 'value'),
            'association_roles' => array_column(AssociationRole::cases(), 'value'),
        ];

        return match ($step) {
            WizardStep::IDENTITY => ['project_types' => $enums['project_types']],
            WizardStep::DETAILS => [
                'construction_statuses' => $enums['construction_statuses'],
                'delivery_statuses' => $enums['delivery_statuses'],
            ],
            WizardStep::PRICING => ['price_types' => $enums['price_types']],
            WizardStep::REVIEW => $enums,
            WizardStep::DEVELOPER => ['association_roles' => $enums['association_roles']] + [
                /*
                 * `developers` has no company_id. The link is
                 * `company_project_associations`, so a company user sees the
                 * developers their company is actually associated with rather
                 * than every developer in Erbil.
                 */
                // Same service the legacy form and ProjectRequest use, so the
                // options offered and the values accepted cannot diverge.
                'developers' => Developer::query()
                    ->when(
                        ProjectScope::permittedDeveloperIds($request) !== null,
                        static fn ($q) => $q->whereIn('id', ProjectScope::permittedDeveloperIds($request) ?? []),
                    )
                    ->orderBy('name_ckb')
                    ->limit(200)
                    ->get(['id', 'name_ckb', 'name_ar', 'name_en'])
                    ->map(static fn (Developer $d): array => ['id' => $d->id, 'name' => $d->name()])
                    ->all(),
                'companies' => $companyId !== null
                    ? Company::query()->where('id', $companyId)->get(['id', 'name_ckb', 'name_ar', 'name_en'])
                        ->map(static fn (Company $c): array => ['id' => $c->id, 'name' => $c->name()])->all()
                    : Company::query()->published()->orderBy('name_ckb')->limit(200)
                        ->get(['id', 'name_ckb', 'name_ar', 'name_en'])
                        ->map(static fn (Company $c): array => ['id' => $c->id, 'name' => $c->name()])->all(),
            ],
            WizardStep::LOCATION => [
                /*
                 * Publishable only. Sending a company editor a published
                 * neighbourhood whose district is in review shows them a name
                 * the platform will not disclose, and the choice is refused on
                 * save.
                 */
                // publication_status must be selected: ancestryIsPublished()
                // reads it, and strict mode 500s the step for the omission
                // as soon as one area row exists (found in Phase 12).
                'areas' => Area::query()
                    ->where('publication_status', 'published')
                    ->get(['id', 'name_ckb', 'name_ar', 'name_en', 'depth', 'path', 'parent_id', 'publication_status'])
                    ->filter(fn (Area $a): bool => $this->areas->ancestryIsPublished($a))
                    ->sortBy('depth')
                    ->take(500)
                    ->map(static fn (Area $a): array => [
                        'id' => $a->id, 'name' => $a->name(), 'depth' => $a->depth,
                    ])
                    ->values()
                    ->all(),
            ],
            default => [],
        };
    }

    /**
     * A unique slug, derived from the English name where one exists.
     *
     * @param  array<string, mixed>  $values  one wizard step's captured values
     */
    private function slugFor(array $values): string
    {
        $base = Str::slug((string) ($values['slug'] ?? $values['name_en'] ?? ''));

        if ($base === '') {
            $base = 'project';
        }

        $slug = $base;
        $suffix = 2;

        while (Project::query()->where('slug', $slug)->exists()) {
            $slug = $base.'-'.$suffix;
            $suffix++;
        }

        return $slug;
    }
}
