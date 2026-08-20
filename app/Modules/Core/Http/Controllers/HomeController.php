<?php

declare(strict_types=1);

namespace App\Modules\Core\Http\Controllers;

use App\Modules\Geography\Models\Area;
use App\Modules\Market\Models\MarketIndex;
use App\Modules\Projects\Models\Project;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Routing\Controller;
use Inertia\Inertia;
use Inertia\Response;

/**
 * The Erbil market homepage (File one §4, File two §3).
 *
 * §4 ends with a sentence that governs this whole class: **"Do not display
 * invented or demo market data."** File two §22 says the same thing harder.
 *
 * So every section below returns real published rows or an empty array, and the
 * page renders an honest empty state rather than a plausible-looking placeholder.
 * That is a deliberate product decision with a cost: a fresh installation's
 * homepage looks bare. The alternative is worse — a homepage showing invented
 * Erbil prices is indistinguishable, to a visitor, from one showing real ones,
 * and the first time somebody acts on a fabricated figure the platform's entire
 * claim to be market intelligence is gone.
 *
 * `has_data` is therefore reported per section, so the interface can say "no
 * verified prices have been published yet" instead of drawing an empty chart
 * that reads as a market with no movement.
 */
final class HomeController extends Controller
{
    public function __invoke(): Response
    {
        return Inertia::render('Public/Home', [
            'market' => $this->market(),
            'projects' => $this->projects(),
            'areas' => $this->areas(),
            // Feature flags decide which calls-to-action exist at all. A
            // homepage inviting somebody into a switched-off advisor is a
            // broken promise made on the first screen they see.
            'cta' => [
                'advisor' => (bool) feature('advisor.residential'),
                'lifestyle' => (bool) feature('lifestyle.matching'),
                'portfolio' => (bool) feature('portfolio'),
                'map' => (bool) feature('map.explorer'),
                'invest' => (bool) feature('map.investment'),
                'offers' => (bool) feature('marketplace.offers'),
            ],
            /*
             * Wave 3: the location card's "Choose Area Manually" fallback.
             * An OPTIONAL prop, absent from the initial page load and served
             * by the SAME route through an Inertia partial reload only when
             * the visitor actually opens manual selection — the whole
             * directory has no business travelling with every homepage view.
             */
            'location_areas' => Inertia::optional(fn (): array => $this->locationAreas()),
        ]);
    }

    /**
     * Published index values only.
     *
     * §4 wants the overall and sector indices, the latest verified period, and
     * the sample and confidence state. Those last two are not decoration: an
     * index built on eleven observations and one built on eleven hundred look
     * identical as a number, and only one of them should move a purchase
     * decision.
     *
     * @return array<string, mixed>
     */
    private function market(): array
    {
        if (! feature('market.intelligence')) {
            return ['has_data' => false, 'reason' => 'feature_disabled', 'indices' => []];
        }

        $indices = MarketIndex::query()
            ->where('publication_status', 'published')
            ->with(['values' => static fn ($q) => $q
                ->where('publication_status', 'published')
                ->latest('period')
                ->limit(1)])
            ->orderBy('key')
            ->limit(6)
            ->get()
            ->map(static function (MarketIndex $index): ?array {
                $value = $index->values->first();

                // An index with no published value is not shown at all. A named
                // index with a blank figure invites the reader to assume zero
                // or to assume the market did not move.
                if ($value === null) {
                    return null;
                }

                return [
                    'key' => $index->key,
                    // Sorani first, falling back through the locales the index
                    // actually has rather than to an empty label.
                    'name' => $index->name_ckb ?: ($index->name_ar ?: $index->name_en),
                    /*
                     * The price family travels with every figure. §14.1 keeps
                     * verified, official and asking prices strictly apart, and
                     * a homepage index with no family attached is exactly the
                     * unlabelled number that lets an asking price be read as a
                     * transaction price.
                     */
                    'price_type' => $index->price_type,
                    'basis' => $index->basis,
                    'value' => (string) $value->value,
                    'change_percent' => $value->change_percent === null
                        ? null
                        : (string) $value->change_percent,
                    'period' => $value->period,
                    'currency' => $index->currency,
                    // The evidence that makes the number readable.
                    'sample_size' => $value->sample_size,
                    'confidence' => $value->confidence,
                    'is_limited' => (bool) $value->is_limited,
                ];
            })
            ->filter()
            ->values();

        return [
            'has_data' => $indices->isNotEmpty(),
            'reason' => $indices->isEmpty() ? 'no_published_values' : null,
            'indices' => $indices->all(),
            'latest_period' => $indices->first()['period'] ?? null,
        ];
    }

    /**
     * Published projects, newest first.
     *
     * No price is attached here. A project card carrying a price on the
     * homepage would be quoting a figure without its period, sample and
     * confidence — the same unqualified number §4 spends a paragraph forbidding
     * elsewhere on the page.
     *
     * @return array<string, mixed>
     */
    private function projects(): array
    {
        $projects = Project::query()
            ->published()
            ->with(['area:id,name_ckb,name_ar,name_en'])
            ->latest('id')
            ->limit(6)
            ->get()
            ->map(static fn (Project $project): array => [
                'slug' => $project->slug,
                'name' => $project->name(),
                'area' => $project->area?->name(),
                'project_type' => $project->project_type->value,
                'construction_status' => $project->construction_status->value,
            ]);

        return [
            'has_data' => $projects->isNotEmpty(),
            'items' => $projects->values()->all(),
        ];
    }

    /**
     * Areas with published projects.
     *
     * Filtered on the project count rather than listed wholesale: an area page
     * with nothing in it is a dead end, and linking to one from the homepage
     * teaches a visitor that the platform's links are not worth following.
     *
     * @return array<string, mixed>
     */
    private function areas(): array
    {
        // `published()` was missing. An unpublished area with published
        // projects under it was listed on the homepage — the area's own
        // review state ignored because the count was taken from its children.
        // That is a disclosure, not a display bug: the name of an area still
        // in review appeared on the most public page in the product.
        /*
         * ONE callback for BOTH the filter and the count.
         *
         * `having('projects_count', '>', 0)` filtered on a correlated subquery
         * alias with no GROUP BY, which SQLite rejects outright — "HAVING
         * clause on a non-aggregate query" — so the most public page in the
         * product returned a 500 on any SQLite deployment. `whereHas` states
         * the same rule as an EXISTS clause every engine accepts.
         *
         * The constraint is shared rather than written twice: a `whereHas` that
         * accepted any project while `withCount` counted only published ones
         * would list an area on the strength of work the visitor cannot see,
         * and show it a count of zero. Duplicated conditions drift; one does
         * not.
         */
        $eligibleProjects = $this->constrainToPubliclyVisibleProjects(...);

        $areas = Area::query()
            ->published()
            ->withCount(['projects' => $eligibleProjects])
            ->whereHas('projects', $eligibleProjects)
            ->orderByDesc('projects_count')
            ->limit(8)
            ->get()
            ->map(static fn (Area $area): array => [
                // Slug rather than id: the profile route is /areas/{slug},
                // and an id in a URL is stable but unreadable and unshareable.
                'slug' => $area->slug,
                'name' => $area->name(),
                'type' => $area->type->value,
                'project_count' => $area->projects_count,
            ]);

        return [
            'has_data' => $areas->isNotEmpty(),
            // The chips link only when the public profile is switched on.
            // Otherwise they render as plain text — real information, no
            // destination the server cannot serve.
            'linkable' => (bool) feature('geography.areas'),
            'items' => $areas->values()->all(),
        ];
    }

    /**
     * Every area the public may choose by hand (Wave 3 manual fallback).
     *
     * REAL published areas only, under the area directory's own visibility
     * rule: an area appears only when it and every ancestor is published —
     * the same hierarchical-disclosure check AreaProfileController::index
     * applies, with the same bound. Selecting one goes back through the
     * location-resolve endpoint, so manual choice and geolocation answer
     * under identical publication and price rules.
     *
     * @return list<array{slug: string, name: string, type: string}>
     */
    private function locationAreas(): array
    {
        $areas = Area::query()
            ->published()
            ->orderBy('depth')
            ->orderBy('name_ckb')
            ->limit(200)
            ->get(['id', 'path', 'depth', 'slug', 'type', 'name_ckb', 'name_ar', 'name_en']);

        $publishedIds = $areas->pluck('id')->flip();

        return $areas
            ->filter(static function (Area $area) use ($publishedIds): bool {
                foreach ($area->ancestorIds() as $ancestorId) {
                    if (! $publishedIds->has($ancestorId)) {
                        return false;
                    }
                }

                return true;
            })
            ->map(static fn (Area $area): array => [
                'slug' => $area->slug,
                'name' => $area->name(),
                'type' => $area->type->value,
            ])
            ->values()
            ->all();
    }

    /**
     * The projects a visitor is allowed to know about.
     *
     * Used by BOTH the existence filter and the count on the home page, so the
     * number shown can never describe a different set from the one that earned
     * the area its place in the list.
     *
     * @param  Builder<Project>  $query
     */
    private function constrainToPubliclyVisibleProjects(Builder $query): void
    {
        $query->where('publication_status', 'published');
    }
}
