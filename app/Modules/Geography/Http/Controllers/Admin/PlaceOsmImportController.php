<?php

declare(strict_types=1);

namespace App\Modules\Geography\Http\Controllers\Admin;

use App\Modules\Geography\Models\Area;
use App\Modules\Geography\Services\AreaResolver;
use App\Modules\Geography\Services\Osm\OsmPlaceImporter;
use App\Modules\Geography\Services\Osm\OsmPlaceMapper;
use App\Modules\Geography\Services\Osm\OverpassClient;
use App\Modules\Geography\Services\Osm\OverpassUnavailable;
use App\Modules\Geography\ValueObjects\BoundingBox;
use App\Modules\Geography\ValueObjects\Coordinates;
use App\Modules\Operations\Services\AuditLogger;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Admin OpenStreetMap place import (Map Phase 2).
 *
 * The same shape the price import taught this codebase: PREVIEW WRITES
 * NOTHING — the fetched, mapped, partitioned plan is summarised into the
 * session, and only an explicit confirm turns it into rows. Between the two
 * steps the heavy artifacts live in the Overpass response cache (24h), so
 * the confirm re-derives the same plan from the same cached answers instead
 * of trusting a stale session blob or re-hammering the external service.
 *
 * The confirm is BOUNDED: at most IMPORT_CAP candidates are written per run,
 * in chunked transactions, and the summary says how many remain. The
 * importer is idempotent by external_id, so "run it again for the rest" is
 * the whole continuation story — no job table, no migration, no unbounded
 * request on shared hosting.
 */
final class PlaceOsmImportController extends Controller
{
    private const SESSION_KEY = 'places.osm_import';

    /** Candidates written per confirm run (create + refresh combined). */
    private const IMPORT_CAP = 1000;

    /** Sample rows shown in the preview table. */
    private const SAMPLE_LIMIT = 60;

    /**
     * Fetch bboxes are padded so an object standing exactly on an area's
     * edge is not lost to rounding; the exact polygon post-filter decides.
     */
    private const BBOX_PADDING_M = 300.0;

    public function __construct(
        private readonly OverpassClient $overpass,
        private readonly OsmPlaceMapper $mapper,
        private readonly OsmPlaceImporter $importer,
        private readonly AreaResolver $areas,
        private readonly AuditLogger $audit,
    ) {}

    public function index(Request $request): Response
    {
        return Inertia::render('Admin/Places/OsmImport', [
            'groups' => array_map(static fn (string $group): array => [
                'key' => $group,
                'label' => __('geography.osm.groups.'.$group),
            ], OsmPlaceMapper::groups()),
            /*
             * Only areas that CAN scope an import: a boundary is what makes
             * "places in this area" answerable, and the cached bbox columns
             * are how the Overpass query gets its box.
             */
            'areas' => Area::query()
                ->whereNotNull('boundary_wkt')
                ->whereNotNull('bbox_min_lat')
                ->orderBy('path')
                ->get(['id', 'name_ckb', 'name_ar', 'name_en', 'depth'])
                ->map(static fn (Area $area): array => [
                    'value' => $area->id,
                    'label' => $area->name(),
                    'depth' => $area->depth,
                ])
                ->all(),
            'preview' => $request->session()->get(self::SESSION_KEY),
            'import_cap' => self::IMPORT_CAP,
        ]);
    }

    public function preview(Request $request): RedirectResponse
    {
        $criteria = $this->validateCriteria($request);

        try {
            $plan = $this->plan($criteria);
        } catch (OverpassUnavailable $exception) {
            return back()->withErrors(['overpass' => $this->overpassMessage($exception)]);
        }

        $request->session()->put(self::SESSION_KEY, [
            'criteria' => $criteria,
            'counts' => $plan['counts'],
            'categories' => $plan['categories'],
            'sample' => $plan['sample'],
            'previewed_at' => now()->toIso8601String(),
        ]);

        return back();
    }

    public function run(Request $request): RedirectResponse
    {
        /** @var array{criteria: array<string, mixed>}|null $preview */
        $preview = $request->session()->get(self::SESSION_KEY);

        if (! is_array($preview) || ! is_array($preview['criteria'] ?? null)) {
            return back()->withErrors(['preview' => __('geography.osm.errors.preview_expired')]);
        }

        try {
            // Re-derived from the cached Overpass answers — the criteria come
            // from the session the preview wrote, never from this request.
            $plan = $this->plan($preview['criteria']);
        } catch (OverpassUnavailable $exception) {
            return back()->withErrors(['overpass' => $this->overpassMessage($exception)]);
        }

        $writable = [...$plan['new'], ...$plan['refreshable']];
        $batch = array_slice($writable, 0, self::IMPORT_CAP);
        $remaining = max(0, count($writable) - self::IMPORT_CAP);

        $summary = $this->importer->import($batch, $request->user()?->id);
        $summary['remaining'] = $remaining;

        $this->audit->record('places.osm_imported', null, [], [
            'criteria' => $preview['criteria'],
            'summary' => $summary,
        ]);

        $request->session()->forget(self::SESSION_KEY);

        $message = __('geography.osm.imported', [
            'created' => $summary['created'],
            'refreshed' => $summary['refreshed'],
        ]);

        // The shared flash carries one sentence, so the continuation hint
        // rides in it rather than in a prop no layout exposes.
        if ($remaining > 0) {
            $message .= ' — '.__('geography.osm.remaining_notice', ['count' => $remaining]);
        }

        return redirect()->route('admin.places.osm.index')->with('success', $message);
    }

    public function discard(Request $request): RedirectResponse
    {
        $request->session()->forget(self::SESSION_KEY);

        return back();
    }

    /** @return array{scope: string, area_id: ?int, groups: list<string>} */
    private function validateCriteria(Request $request): array
    {
        $validated = $request->validate([
            'scope' => ['required', 'string', Rule::in(['operating_area', 'area'])],
            'area_id' => ['required_if:scope,area', 'nullable', 'integer', 'exists:areas,id'],
            'groups' => ['required', 'array', 'min:1'],
            'groups.*' => ['string', Rule::in(OsmPlaceMapper::groups())],
        ]);

        return [
            'scope' => (string) $validated['scope'],
            'area_id' => $validated['scope'] === 'area' ? (int) $validated['area_id'] : null,
            'groups' => array_values(array_unique($validated['groups'])),
        ];
    }

    /**
     * The whole read-only pipeline: fetch (cached) -> map -> scope filter ->
     * partition. Both preview and confirm run exactly this, which is what
     * makes the preview's numbers the import's plan.
     *
     * @param  array<string, mixed>  $criteria
     * @return array{
     *     new: list<array<string, mixed>>,
     *     refreshable: list<array<string, mixed>>,
     *     counts: array<string, int|bool>,
     *     categories: list<array{key: string, count: int}>,
     *     sample: list<array<string, string|null>>,
     * }
     *
     * @throws OverpassUnavailable
     */
    private function plan(array $criteria): array
    {
        $scopeArea = null;

        if (($criteria['scope'] ?? null) === 'area') {
            $scopeArea = Area::query()->find((int) ($criteria['area_id'] ?? 0));

            if ($scopeArea === null || $scopeArea->boundary_wkt === null || $scopeArea->bbox_min_lat === null) {
                abort(422, __('geography.osm.errors.area_without_boundary'));
            }
        }

        $box = $scopeArea === null
            ? BoundingBox::operatingArea()
            : BoundingBox::make(
                (float) $scopeArea->bbox_min_lat,
                (float) $scopeArea->bbox_min_lng,
                (float) $scopeArea->bbox_max_lat,
                (float) $scopeArea->bbox_max_lng,
            )->padded(self::BBOX_PADDING_M);

        $skips = [
            OsmPlaceMapper::SKIP_UNMAPPED => 0,
            OsmPlaceMapper::SKIP_UNNAMED => 0,
            OsmPlaceMapper::SKIP_OUT_OF_BOUNDS => 0,
            OsmPlaceMapper::SKIP_INVALID_COORDS => 0,
        ];
        $outsideArea = 0;
        $truncated = false;
        $found = 0;

        $candidates = [];

        /** @var list<string> $groups */
        $groups = $criteria['groups'] ?? [];

        // One bounded request per group, sequentially — cached answers cost
        // nothing, cold ones stay polite to a shared community service.
        foreach ($groups as $group) {
            $response = $this->overpass->fetch($group, OsmPlaceMapper::selectorsFor($group), $box);
            $truncated = $truncated || $response['truncated'];
            $found += count($response['elements']);

            foreach ($response['elements'] as $element) {
                $mapped = $this->mapper->map($element);
                $candidate = $mapped['candidate'];

                if ($candidate === null) {
                    $reason = $mapped['reason'] ?? OsmPlaceMapper::SKIP_UNMAPPED;
                    $skips[$reason] = ($skips[$reason] ?? 0) + 1;

                    continue;
                }

                if ($scopeArea !== null && ! $this->insideArea($scopeArea, $candidate)) {
                    $outsideArea++;

                    continue;
                }

                $candidates[] = $candidate;
            }
        }

        $plan = $this->importer->partition($candidates);

        $categoryCounts = [];

        foreach ([...$plan['new'], ...$plan['refreshable']] as $candidate) {
            $key = (string) $candidate['category_key'];
            $categoryCounts[$key] = ($categoryCounts[$key] ?? 0) + 1;
        }

        arsort($categoryCounts);

        $sample = [];

        foreach ([...$plan['new'], ...$plan['refreshable']] as $index => $candidate) {
            if ($index >= self::SAMPLE_LIMIT) {
                break;
            }

            $sample[] = [
                'name' => (string) $candidate['name_ckb'],
                'category' => (string) $candidate['category_key'],
                'external_id' => (string) $candidate['external_id'],
                'status' => $index < count($plan['new']) ? 'new' : 'refresh',
                'name_fallback' => $candidate['tags']['name_fallback'] ?? null,
            ];
        }

        return [
            'new' => $plan['new'],
            'refreshable' => $plan['refreshable'],
            'counts' => [
                'found' => $found,
                'new' => count($plan['new']),
                'refreshable' => count($plan['refreshable']),
                'protected' => $plan['protected'],
                'deleted_protected' => $plan['deleted_protected'],
                'foreign_source' => $plan['foreign_source'],
                'missing_category' => $plan['missing_category'],
                'skipped_unmapped' => $skips[OsmPlaceMapper::SKIP_UNMAPPED],
                'skipped_unnamed' => $skips[OsmPlaceMapper::SKIP_UNNAMED],
                'skipped_out_of_bounds' => $skips[OsmPlaceMapper::SKIP_OUT_OF_BOUNDS]
                    + $skips[OsmPlaceMapper::SKIP_INVALID_COORDS],
                'outside_area' => $outsideArea,
                'truncated' => $truncated,
            ],
            'categories' => array_map(
                static fn (string $key, int $count): array => ['key' => $key, 'count' => $count],
                array_keys($categoryCounts),
                array_values($categoryCounts),
            ),
            'sample' => $sample,
        ];
    }

    /**
     * Exact scope check through the EXISTING polygon policy — the resolver's
     * own containment, not a second point-in-polygon implementation.
     *
     * @param  array<string, mixed>  $candidate
     */
    private function insideArea(Area $area, array $candidate): bool
    {
        $point = Coordinates::tryMake((string) $candidate['lat'], (string) $candidate['lng']);

        return $point !== null && $this->areas->contains($area, $point);
    }

    private function overpassMessage(OverpassUnavailable $exception): string
    {
        if ($exception->reason === OverpassUnavailable::RATE_LIMITED) {
            return __('geography.osm.errors.rate_limited', [
                'seconds' => $exception->retryAfterSeconds ?? 60,
            ]);
        }

        return __('geography.osm.errors.'.$exception->reason);
    }
}
