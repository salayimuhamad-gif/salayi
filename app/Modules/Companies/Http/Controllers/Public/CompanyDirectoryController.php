<?php

declare(strict_types=1);

namespace App\Modules\Companies\Http\Controllers\Public;

use App\Modules\Companies\Models\Company;
use App\Modules\Companies\Models\CompanyBranch;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Public company directory and profiles (File one §8.1, File two §11).
 *
 * The Companies module had a complete schema and an admin screen, and no way
 * for the public to see any of it. §11 wants a directory a buyer can browse and
 * a profile they can judge a developer by.
 *
 * Every query here goes through `published()`, which requires
 * `verification_status = 'verified'`. That is stricter than it may look and it
 * is deliberate: a company profile on this platform is an implicit endorsement.
 * A visitor reads "listed on Mulkihawler" as "checked by Mulkihawler", so an
 * unverified company must be invisible rather than merely unlabelled.
 *
 * Contact details are the sensitive part. `phones_encrypted` and
 * `whatsapp_encrypted` exist because spec 32.2 treats them as PII, and they are
 * NOT decrypted into a public payload. A visitor gets the branch, the address
 * and a request-contact path; the number itself is a lead event with consent
 * attached (Phase 10), not a field on a page a scraper can walk.
 */
final class CompanyDirectoryController extends Controller
{
    public function index(Request $request): Response
    {
        $filters = $request->validate([
            'q' => ['nullable', 'string', 'max:120'],
            'specialty' => ['nullable', 'string', 'max:40'],
            'area_id' => ['nullable', 'integer'],
        ]);

        $query = Company::query()->published();

        if (($filters['q'] ?? '') !== '') {
            /*
             * Matched against `search_key`, the normalised column, rather than
             * the display names. Sorani has several ways to write the same
             * word (ی/ي, ک/ك), so a LIKE against the raw name silently misses
             * companies the visitor can see spelled differently elsewhere on
             * the site.
             */
            $needle = $this->normalise($filters['q']);
            $query->where('search_key', 'like', '%'.$needle.'%');
        }

        if (($filters['specialty'] ?? '') !== '') {
            $query->whereJsonContains('specialties', $filters['specialty']);
        }

        if (($filters['area_id'] ?? null) !== null) {
            $query->whereJsonContains('operating_area_ids', (int) $filters['area_id']);
        }

        $companies = $query
            ->withCount(['branches', 'projectAssociations' => fn ($q) => $q->where('is_approved', true)])
            ->orderBy('brand_name')
            ->paginate(24)
            ->withQueryString();

        return Inertia::render('Public/Companies/Index', [
            'companies' => [
                'data' => $companies->getCollection()->map(fn (Company $company): array => [
                    'slug' => $company->slug,
                    'name' => $company->name(),
                    'brand_name' => $company->brand_name,
                    'logo_path' => $company->logo_path,
                    'specialties' => $company->specialties ?? [],
                    'languages' => $company->languages ?? [],
                    'branches_count' => $company->branches_count,
                    'projects_count' => $company->project_associations_count,
                    // Always true here — the scope guarantees it — but stated
                    // in the payload so the badge is data-driven rather than
                    // an assumption baked into a template.
                    'is_verified' => $company->isVerified(),
                ])->values()->all(),
                'links' => $companies->linkCollection()->toArray(),
                'last_page' => $companies->lastPage(),
            ],
            'filters' => $filters,
        ]);
    }

    public function show(string $slug): Response
    {
        $company = Company::query()
            ->published()
            ->where('slug', $slug)
            ->with([
                'branches' => fn ($q) => $q->where('is_active', true)->orderBy('name_ckb'),
                // Only approved associations. An association is a claim about
                // who built what, and an unapproved one is an unverified claim.
                'projectAssociations' => fn ($q) => $q->where('is_approved', true)
                    ->orderByDesc('display_priority')
                    ->with('project:id,slug,name_ckb,name_ar,name_en,publication_status'),
            ])
            ->firstOrFail();

        return Inertia::render('Public/Companies/Show', [
            'company' => [
                'slug' => $company->slug,
                'name' => $company->name(),
                'brand_name' => $company->brand_name,
                'description' => $company->description(),
                'logo_path' => $company->logo_path,
                'website' => $company->website,
                'specialties' => $company->specialties ?? [],
                'languages' => $company->languages ?? [],
                'is_verified' => $company->isVerified(),
                'verified_at' => $company->verified_at?->toDateString(),
                // The licence number is public information a buyer is entitled
                // to check independently; the evidence documents behind it are
                // not, and are never sent.
                'license_number' => $company->license_number,
                'license_authority' => $company->license_authority,
                'telegram_username' => $company->telegram_username,
                'social_links' => $company->social_links ?? [],
            ],
            'branches' => $company->branches->map(fn (CompanyBranch $branch): array => [
                'id' => $branch->id,
                'name' => $branch->name(),
                // The schema is trilingual per field; the trait resolves the
                // active locale with a Sorani fallback.
                'address' => $branch->addressForLocale(),
                'latitude' => $branch->latitude === null ? null : (float) $branch->latitude,
                'longitude' => $branch->longitude === null ? null : (float) $branch->longitude,
                'hours' => $branch->opening_hours ?? [],
                'languages' => $branch->languages ?? [],
                // No phone. See the class docblock: a number is released
                // through a consented lead event, not printed on a page.
                'has_contact' => true,
            ])->values()->all(),
            'projects' => $company->projectAssociations
                // A published company may be associated with a project that is
                // itself still a draft. Filtering here rather than in the query
                // keeps the association count honest while hiding the link.
                ->filter(fn ($a): bool => $a->project !== null
                    && $a->project->publication_status->isPubliclyVisible())
                ->map(fn ($a): array => [
                    'slug' => $a->project->slug,
                    'name' => $a->project->name(),
                    'role' => $a->role,
                    // §12.2: a paid association is labelled as one wherever it
                    // appears, including here.
                    'is_sponsored' => (bool) $a->is_sponsored,
                    'disclosure_label' => $a->disclosure_label,
                ])->values()->all(),
        ]);
    }

    /**
     * Sorani-aware normalisation for search.
     *
     * The same letters are written differently by different keyboards; without
     * folding them a visitor searching one spelling misses companies stored
     * under another.
     */
    private function normalise(string $value): string
    {
        return str_replace(
            ['ي', 'ك', 'ة', 'أ', 'إ', 'آ', '‌'],
            ['ی', 'ک', 'ه', 'ا', 'ا', 'ا', ''],
            mb_strtolower(trim($value)),
        );
    }
}
