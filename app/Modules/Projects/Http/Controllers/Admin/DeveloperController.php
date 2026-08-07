<?php

declare(strict_types=1);

namespace App\Modules\Projects\Http\Controllers\Admin;

use App\Modules\Operations\Services\AuditLogger;
use App\Modules\Projects\Enums\PublicationStatus;
use App\Modules\Projects\Models\Developer;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Developer administration (spec 12.1 "official developer").
 *
 * `is_verified` is deliberately separate from `publication_status`. Publishing
 * a developer profile makes it visible; verifying it asserts the platform
 * checked who they are. A buyer reading "verified developer" on a project page
 * is relying on the second claim, so it cannot be a side effect of the first.
 */
final class DeveloperController extends Controller
{
    public function __construct(private readonly AuditLogger $audit) {}

    public function index(Request $request): Response
    {
        $developers = Developer::query()
            ->when($request->string('q')->toString() !== '', fn ($q) => $q->where(
                'search_key', 'like', '%'.sorani_search_key($request->string('q')->toString()).'%',
            ))
            ->withCount('projects')
            ->orderBy('name_ckb')
            ->paginate(20)
            ->withQueryString()
            ->through(fn (Developer $d): array => [
                'id' => $d->id,
                'name' => $d->name(),
                'slug' => $d->slug,
                'status' => $d->publication_status->value,
                'is_verified' => $d->is_verified,
                'project_count' => $d->projects_count,
                'missing_translations' => $d->missingTranslations('name'),
            ]);

        return Inertia::render('Admin/Developers/Index', [
            'developers' => $developers,
            'filters' => ['q' => $request->string('q')->toString()],
            'can' => [
                'create' => $request->user()?->hasPermission('projects.create') ?? false,
                'verify' => $request->user()?->hasPermission('companies.verify') ?? false,
            ],
        ]);
    }

    public function create(): Response
    {
        return Inertia::render('Admin/Developers/Form', ['developer' => null]);
    }

    public function store(Request $request): RedirectResponse
    {
        $developer = Developer::query()->create($this->validated($request) + [
            'publication_status' => PublicationStatus::Draft,
        ]);

        $this->audit->record('developer.created', $developer);

        return redirect()->route('admin.developers.edit', $developer)->with('success', __('app.states.saved'));
    }

    public function edit(Developer $developer): Response
    {
        return Inertia::render('Admin/Developers/Form', [
            'developer' => [
                'id' => $developer->id,
                ...$developer->only([
                    'slug', 'name_ckb', 'name_ar', 'name_en',
                    'description_ckb', 'description_ar', 'description_en',
                    'website', 'founded_year', 'country', 'source',
                ]),
                'is_verified' => $developer->is_verified,
                'publication_status' => $developer->publication_status->value,
                'missing_translations' => $developer->missingTranslations('name'),
            ],
        ]);
    }

    public function update(Request $request, Developer $developer): RedirectResponse
    {
        $developer->fill($this->validated($request, $developer->id));

        // Verification is a separate, audited act — never a side effect of a
        // routine profile edit.
        if ($request->boolean('is_verified') !== $developer->is_verified) {
            if (! ($request->user()?->hasPermission('companies.verify') ?? false)) {
                return back()->withErrors(['is_verified' => __('identity.errors.missing_permission')]);
            }

            $developer->is_verified = $request->boolean('is_verified');
            $developer->verified_at = $developer->is_verified ? now() : null;

            $this->audit->record('developer.verification_changed', $developer, [
                'is_verified' => $developer->is_verified,
            ], severity: 'warning');
        }

        $developer->save();
        $this->audit->recordModelChange('developer.updated', $developer);

        return back()->with('success', __('app.states.saved'));
    }

    /**
     * The validated payload, keyed by columns Developer really has.
     *
     * Declaring the key type lets the analyser check every rule key against the
     * model, so a rule written for a field that does not exist fails here
     * rather than silently never persisting.
     *
     * @return array<model-property<Developer>, mixed>
     */
    private function validated(Request $request, ?int $id = null): array
    {
        return $request->validate([
            'name_ckb' => ['required', 'string', 'max:191'],
            'name_ar' => ['nullable', 'string', 'max:191'],
            'name_en' => ['nullable', 'string', 'max:191'],
            'slug' => ['required', 'string', 'max:191', 'regex:/^[\p{L}\p{N}\-]+$/u', 'unique:developers,slug'.($id ? ','.$id : '')],
            'description_ckb' => ['nullable', 'string', 'max:20000'],
            'description_ar' => ['nullable', 'string', 'max:20000'],
            'description_en' => ['nullable', 'string', 'max:20000'],
            'website' => ['nullable', 'url', 'max:255'],
            'founded_year' => ['nullable', 'integer', 'between:1900,'.(int) date('Y')],
            'country' => ['nullable', 'string', 'size:2'],
            'source' => ['nullable', 'string', 'max:191'],
        ]);
    }
}
