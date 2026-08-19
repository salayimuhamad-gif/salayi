<?php

declare(strict_types=1);

namespace App\Modules\Geography\Http\Controllers\Admin;

use App\Modules\Geography\Models\PlaceCategory;
use App\Modules\Operations\Services\AuditLogger;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Place category administration (File two §5.1).
 *
 * §5.1 ends with a requirement that is easy to read past: *"the admin must be
 * able to add new types and change the icon and name without modifying the
 * code."* The `place_categories` table was built for exactly that — its own
 * migration says so — with `key`, `icon`, `colour`, `sort_order`, `is_active`
 * and trilingual names all editable. And there was no controller, no route and
 * no screen. Categories could only be changed by editing the seeder and
 * redeploying, which is precisely what the requirement forbids.
 *
 * The shipped set is protected rather than frozen. A system category's KEY is
 * immutable because `PlaceCategoryKey`, the ranker's per-category radii and the
 * nearby-place snapshots all reference it — renaming `hospital` to `clinic`
 * would silently repoint every stored distance. Its name, icon, colour, radius
 * and weight remain fully editable, which is what an operator actually wants:
 * "call it نەخۆشخانە, show a red cross, search 8 km".
 */
final class PlaceCategoryController extends Controller
{
    public function __construct(private readonly AuditLogger $audit) {}

    public function index(): Response
    {
        $categories = PlaceCategory::query()
            ->orderBy('group')
            ->orderBy('sort_order')
            ->orderBy('key')
            ->withCount('places')
            ->get()
            ->map(fn (PlaceCategory $category): array => [
                'id' => $category->id,
                'key' => $category->key,
                'group' => $category->group,
                'name_ckb' => $category->name_ckb,
                'name_ar' => $category->name_ar,
                'name_en' => $category->name_en,
                'icon' => $category->icon,
                'colour' => $category->colour,
                'default_radius_m' => $category->default_radius_m,
                'amenity_weight' => (string) $category->amenity_weight,
                'is_system' => (bool) $category->is_system,
                'is_active' => (bool) $category->is_active,
                'sort_order' => $category->sort_order,
                // Shown so an administrator can see what deactivating costs
                // before they do it.
                'places_count' => $category->places_count,
            ]);

        return Inertia::render('Admin/Places/Categories', [
            'categories' => $categories,
            'groups' => ['education', 'health', 'retail', 'leisure', 'civic', 'transport', 'work', 'other'],
            'can' => [
                'manage' => request()->user()?->hasPermission('geography.places.create') ?? false,
            ],
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        /**
         * Declaring the key type makes the analyser check every validated key
         * against PlaceCategory's real columns, so a rule added for a field the model
         * does not have fails here rather than silently never persisting.
         *
         * @var array<model-property<PlaceCategory>, mixed> $validated
         */
        $validated = $request->validate([
            // Lowercase snake_case: the key becomes a translation key and an
            // icon lookup, and a space or capital in it breaks both.
            'key' => ['required', 'string', 'max:64', 'regex:/^[a-z][a-z0-9_]*$/', Rule::unique('place_categories', 'key')],
            'group' => ['required', 'string', 'max:32'],
            'name_ckb' => ['required', 'string', 'max:191'],
            'name_ar' => ['nullable', 'string', 'max:191'],
            'name_en' => ['nullable', 'string', 'max:191'],
            'icon' => ['nullable', 'string', 'max:64'],
            'colour' => ['nullable', 'string', 'max:16'],
            'default_radius_m' => ['required', 'integer', 'between:100,50000'],
            'amenity_weight' => ['required', 'numeric', 'between:0,1'],
            'sort_order' => ['nullable', 'integer', 'between:0,9999'],
        ]);

        $category = PlaceCategory::query()->create($validated + [
            // Only the seeded set is system. Anything created here is the
            // operator's own and stays fully editable, including its key.
            'is_system' => false,
            'is_active' => true,
        ]);

        $this->audit->record('place_category.created', $category, $validated);

        return back()->with('success', __('geography.categories.created'));
    }

    public function update(Request $request, PlaceCategory $category): RedirectResponse
    {
        $rules = [
            'group' => ['required', 'string', 'max:32'],
            'name_ckb' => ['required', 'string', 'max:191'],
            'name_ar' => ['nullable', 'string', 'max:191'],
            'name_en' => ['nullable', 'string', 'max:191'],
            'icon' => ['nullable', 'string', 'max:64'],
            'colour' => ['nullable', 'string', 'max:16'],
            'default_radius_m' => ['required', 'integer', 'between:100,50000'],
            'amenity_weight' => ['required', 'numeric', 'between:0,1'],
            'sort_order' => ['nullable', 'integer', 'between:0,9999'],
            'is_active' => ['required', 'boolean'],
        ];

        // A system category's key is referenced by PlaceCategoryKey, by the
        // ranker's radii and by every stored nearby-place snapshot. Renaming it
        // would silently repoint distances that have already been published.
        if (! $category->is_system) {
            $rules['key'] = [
                'required', 'string', 'max:64', 'regex:/^[a-z][a-z0-9_]*$/',
                Rule::unique('place_categories', 'key')->ignore($category->id),
            ];
        }

        $validated = $request->validate($rules);

        $category->fill($validated);
        $category->save();

        $this->audit->recordModelChange('place_category.updated', $category);

        return back()->with('success', __('geography.categories.updated'));
    }

    /**
     * Deactivate rather than delete.
     *
     * A category with places attached cannot be removed without either
     * orphaning them or cascading a delete through published map data, and the
     * schema already refuses it (`restrictOnDelete`). Deactivating hides the
     * category from pickers and public layers while leaving every existing
     * place, and every distance computed from it, intact and auditable.
     */
    public function deactivate(PlaceCategory $category): RedirectResponse
    {
        $category->forceFill(['is_active' => false])->save();

        $this->audit->record('place_category.deactivated', $category, [], [
            'places_affected' => $category->places()->count(),
        ], severity: 'warning');

        return back()->with('success', __('geography.categories.deactivated'));
    }
}
