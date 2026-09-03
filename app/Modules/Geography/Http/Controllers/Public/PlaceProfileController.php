<?php

declare(strict_types=1);

namespace App\Modules\Geography\Http\Controllers\Public;

use App\Modules\Core\Support\LocalizedRoutes;
use App\Modules\Geography\Models\Area;
use App\Modules\Geography\Models\Place;
use App\Modules\Geography\Models\PlaceCategory;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Public Place profiles (spec 10.3, 12.2).
 *
 * Three constraints shape this controller, and all three are about what is
 * NOT emitted.
 *
 * PHONE NUMBERS NEVER LEAVE. `Place::$hidden` already excludes
 * `phone_encrypted`, and `phone()` decrypts on demand. Spec 32.2 records why:
 * a place phone in Erbil is frequently somebody's personal mobile, entered by
 * a surveyor who did not imagine it appearing on a public page. Nothing here
 * calls `phone()`. If a public contact number is ever wanted it needs its own
 * consented column, not this one.
 *
 * `is_public` IS A SECOND GATE, NOT A SYNONYM for publication status. A place
 * can be published — meaning reviewed and usable for nearby-amenity scoring —
 * while still not being something to give its own public page. Both are
 * required here.
 *
 * DUPLICATES RESOLVE TO ONE PAGE. `Place::published()` already requires
 * `is_duplicate_primary`, so a deduplicated cluster has exactly one public
 * URL rather than five that disagree about opening hours.
 */
final class PlaceProfileController extends Controller
{
    private const PER_PAGE = 24;

    /**
     * The place directory, optionally filtered by category.
     *
     * Filtering is by category key rather than id: a key is stable across
     * environments and readable in a shared URL, and the installer seeds
     * categories by key.
     */
    public function index(Request $request): Response
    {
        $categoryKey = $request->string('category')->toString();

        $categories = PlaceCategory::query()
            ->orderBy('key')
            ->get()
            ->map(static fn (PlaceCategory $category): array => [
                'key' => $category->key,
                'name' => $category->name(),
            ])
            ->all();

        $selected = $categoryKey !== ''
            ? PlaceCategory::query()->where('key', $categoryKey)->first()
            : null;

        // An unknown category filter returns an empty result with the filter
        // shown as rejected, rather than silently listing everything. Silently
        // ignoring a filter makes a visitor believe they are looking at a
        // filtered set when they are not.
        $unknownCategory = $categoryKey !== '' && $selected === null;

        $places = Place::query()
            ->published()
            ->where('is_public', true)
            ->when($selected !== null, static fn ($query) => $query->where('place_category_id', $selected->id))
            ->when($unknownCategory, static fn ($query) => $query->whereRaw('1 = 0'))
            ->with(['category', 'area'])
            ->orderBy('name_ckb')
            ->paginate(self::PER_PAGE)
            ->withQueryString();

        return Inertia::render('Public/Places/Index', [
            'places' => [
                'items' => collect($places->items())
                    ->map(fn (Place $place): array => $this->summary($place))
                    ->all(),
                'total' => $places->total(),
                'current_page' => $places->currentPage(),
                'last_page' => $places->lastPage(),
            ],
            'categories' => $categories,
            'filters' => [
                'category' => $categoryKey,
                'unknown_category' => $unknownCategory,
            ],
            'seo' => [
                'title' => __('geography.public.places_title'),
                'canonical' => LocalizedRoutes::canonical('/places'),
                'alternates' => LocalizedRoutes::alternates('/places'),
            ],
        ]);
    }

    public function show(Request $request, string $slug): Response
    {
        $place = Place::query()
            ->published()
            ->where('is_public', true)
            ->where('slug', $slug)
            ->with(['category', 'area'])
            ->first();

        abort_if($place === null, 404);

        // A place whose area is unpublished still gets a page — the place is
        // published in its own right — but the area is not named or linked.
        // Naming it would disclose an area still in review, which is the same
        // leak the Area breadcrumb check exists to prevent.
        $area = $place->area;
        $areaIsPublic = $area !== null
            && $area->publication_status->value === 'published'
            && $this->ancestorsArePublished($area);

        return Inertia::render('Public/Places/Show', [
            'place' => [
                'slug' => $place->slug,
                'name' => $place->name(),
                'name_is_fallback' => $place->nameIsFallback(),
                'category' => $place->category?->name(),
                'category_key' => $place->category?->key,
                'subcategory' => $place->subcategory,
                'address' => $place->address(),
                'website' => $place->website,
                // Opening hours are shown only when structured. A free-text
                // blob from an import is unparseable and reads as authoritative
                // when it is not.
                'opening_hours' => is_array($place->opening_hours) ? $place->opening_hours : null,
                'accessibility_notes' => $place->accessibility_notes,
                'operational_status' => $place->operational_status,
                // `places.latitude` and `places.longitude` are both NOT NULL —
                // a place without a position cannot be stored — so a place
                // always has coordinates to publish.
                'coordinates' => [
                    'latitude' => (float) $place->latitude,
                    'longitude' => (float) $place->longitude,
                ],
                // Provenance travels with the record, as it does with every
                // market figure: a place nobody has verified should not read
                // the same as one somebody has.
                'source' => $place->source,
                'verified_at' => $place->verified_at?->toDateString(),
                'tags' => is_array($place->tags) ? $place->tags : [],
            ],
            'area' => $areaIsPublic ? [
                // Canonical lowercase: this feeds the "containing area" href,
                // and the area route refuses any other casing.
                'slug' => $area->publicSlug(),
                'name' => $area->name(),
            ] : null,
            'nearby' => $this->nearbyInSameArea($place),
            'seo' => [
                'title' => $place->name(),
                'canonical' => LocalizedRoutes::canonical('/places/'.$place->slug),
                'alternates' => LocalizedRoutes::alternates('/places/'.$place->slug),
            ],
        ]);
    }

    /**
     * Other public places in the same area.
     *
     * Deliberately not a radius query. `NearbyPlaceCalculator` owns distance,
     * it is not yet backed by a database implementation, and a second
     * half-correct distance calculation living here is exactly the drift the
     * WKT writer comment warns about. Same-area is honest and cheap.
     *
     * @return list<array<string, mixed>>
     */
    private function nearbyInSameArea(Place $place): array
    {
        if ($place->area_id === null) {
            return [];
        }

        return Place::query()
            ->published()
            ->where('is_public', true)
            ->where('area_id', $place->area_id)
            ->where('id', '!=', $place->id)
            ->with('category')
            ->orderBy('name_ckb')
            ->limit(8)
            ->get()
            ->map(fn (Place $other): array => $this->summary($other))
            ->all();
    }

    /** @return array<string, mixed> */
    private function summary(Place $place): array
    {
        return [
            'slug' => $place->slug,
            'name' => $place->name(),
            'category' => $place->category?->name(),
            'area' => $place->area?->name(),
            'operational_status' => $place->operational_status,
        ];
    }

    private function ancestorsArePublished(Area $area): bool
    {
        $ids = $area->ancestorIds();

        if ($ids === []) {
            return true;
        }

        return Area::query()->published()->whereIn('id', $ids)->count() === count($ids);
    }
}
