<?php

declare(strict_types=1);

namespace App\Modules\Core\Support;

use App\Modules\Identity\Models\User;

/**
 * The administrative navigation tree (spec 24.1, 24.2).
 *
 * Spec 24.1: "Only relevant admin sections appear, by permission." So this is
 * a filter, not a stylesheet — a section the user cannot reach is absent from
 * the payload entirely, not hidden with CSS. Shipping a nav item to the browser
 * and hiding it tells a curious user exactly which URLs to try.
 *
 * Each item declares the permission that gates it and, where relevant, the
 * feature flag that must be on. Both are checked server-side; the Vue layer
 * renders whatever it is given and makes no authorisation decision of its own.
 */
final class AdminNavigation
{
    /**
     * @return list<array{
     *     key: string, route: string, icon: string, permission: string|null,
     *     flag: string|null, children: list<array<string, mixed>>
     * }>
     */
    public static function tree(): array
    {
        return [
            self::item('overview', 'admin.dashboard', 'gauge', null),

            // Permission `null`: notifications are the recipient's own, so
            // there is no role that grants or denies reading them. The
            // controller scopes every query to the authenticated id instead.
            self::item('notifications', 'admin.notifications.index', 'bell', null),

            /*
             * No ratings entry: admin.projects.ratings.index is a PER-PROJECT
             * route (admin/projects/{project}/ratings), so a sidebar link can
             * never generate a URL for it — the old entry threw on render for
             * everyone who held the permission. Ratings are reached from the
             * project they belong to, which is the only place they mean
             * anything.
             */
            self::item('projects', 'admin.projects.index', 'building-2', 'projects.view', children: [
                self::item('projects.all', 'admin.projects.index', 'list', 'projects.view'),
                self::item('projects.developers', 'admin.developers.index', 'hard-hat', 'projects.view'),
            ]),

            self::item('geography', 'admin.areas.index', 'map', 'geography.areas.view', children: [
                self::item('geography.areas', 'admin.areas.index', 'shapes', 'geography.areas.view'),
                self::item('geography.places', 'admin.places.index', 'map-pin', 'geography.places.view', flag: 'places.database'),
                // Implemented and routed since the places module shipped, but
                // never linked — reachable only by typing the URL, which is
                // not a workflow.
                self::item('geography.place_categories', 'admin.places.categories.index', 'folder', 'geography.places.view', flag: 'places.database'),
            ]),

            self::item('market', 'admin.dashboard', 'trending-up', 'market.prices.view', flag: 'market.intelligence', children: [
                self::item('market.prices', 'admin.market.prices.index', 'coins', 'market.prices.view'),
                // Its own flag: a site may review prices long before it publishes
                // an index, and the route is gated by market.indices, so the entry
                // must be too or it links to a 403.
                self::item('market.indices', 'admin.market.indices.index', 'line-chart', 'market.indices.view', flag: 'market.indices'),
            ]),

            /*
             * Wave 6: the portfolio valuation rule sets. Top level — there
             * is no other portfolio administration surface to parent it
             * under — and gated exactly as its routes are: the dedicated
             * flag plus the view permission.
             */
            self::item('valuation_rules', 'admin.portfolio.valuation-rules.index', 'calculator', 'portfolio.valuation_rules.view', flag: 'portfolio.valuation_rules'),

            /*
             * Top-level, NOT a child of Market. The import routes carry no
             * feature flag and no market permission, so parking the entry
             * under a parent gated by BOTH made a reachable, implemented page
             * invisible to exactly the people it exists for: a GIS/Places
             * Manager holds imports.view and no market permission, and a
             * Super Admin loses the entry whenever market.intelligence is
             * off — while the route answers either way. The entry now
             * mirrors the route: permission imports.view, no flag.
             */
            self::item('imports', 'admin.imports.prices.index', 'upload', 'imports.view'),

            self::item('marketplace', 'admin.offers.index', 'store', 'marketplace.offers.view', flag: 'marketplace.offers', children: [
                self::item('marketplace.offers', 'admin.offers.index', 'tag', 'marketplace.offers.view'),
                self::item('marketplace.moderation', 'admin.offers.index', 'shield-check', 'marketplace.offers.moderate'),
                self::item('marketplace.media_queue', 'admin.offers.media.queue', 'image', 'marketplace.offers.moderate'),
            ]),

            self::item('companies', 'admin.companies.index', 'briefcase', 'companies.view', flag: 'companies.portal'),
            /*
             * The company↔developer review queue. Platform-only, matching the
             * routes: a company approving its own claim to represent a
             * developer is not a control.
             *
             * Without a nav entry the queue was reachable only by knowing the
             * URL, which is not a workflow.
             */
            self::item('company_developers', 'admin.company-developers.index', 'link', 'projects.create_unscoped'),
            // Administrator draft listing (spec 12.1, 26.1).
            self::item('project_drafts', 'admin.project-drafts.index', 'document', 'projects.view'),
            self::item('leads', 'admin.leads.index', 'users', 'leads.view'),
            self::item('content', 'admin.content.index', 'newspaper', 'content.view'),
            self::item('knowledge', 'admin.knowledge.index', 'calendar-clock', 'knowledge.events.view'),

            self::item('branding', 'admin.branding.edit', 'palette', 'branding.settings.view'),
            self::item('features', 'admin.features.index', 'toggle-right', 'system.features.view'),

            /*
             * The parent's own permission is the LOOSEST of its children, not
             * system.settings.view: a System Admin or Security Auditor who
             * holds identity or audit permissions without the settings one
             * must still find the section their permissions open.
             */
            self::item('system', 'admin.dashboard', 'settings', null, children: [
                self::item('system.settings', 'admin.system.settings', 'sliders', 'system.settings.view'),
                self::item('system.users', 'admin.users.index', 'user-cog', 'identity.users.view'),
                // The operators surface: who holds which role (spec 3.2).
                self::item('system.roles', 'admin.administrators.index', 'shield', 'identity.roles.view'),
                self::item('system.audit', 'admin.operations.audit', 'scroll-text', 'audit.logs.view'),
                self::item('system.health', 'admin.operations.health', 'activity', 'system.settings.view'),
            ]),
        ];
    }

    /**
     * Filter the tree for one user.
     *
     * A parent with no reachable children is dropped even when the parent's own
     * permission passes — a "Market" heading that expands to nothing is worse
     * than no heading, because it reads as a broken page rather than an absent
     * feature.
     *
     * @return list<array<string, mixed>>
     */
    public static function for(User $user, callable $featureEnabled): array
    {
        return self::filter(self::tree(), $user, $featureEnabled);
    }

    /**
     * @param  list<array<string, mixed>>  $items
     * @return list<array<string, mixed>>
     */
    private static function filter(array $items, User $user, callable $featureEnabled): array
    {
        $visible = [];

        foreach ($items as $item) {
            /*
             * Flags hide sections from ordinary administrators, never from a
             * Super Admin: the admin routes behind them admit a Super Admin
             * while disabled (audited preview — see EnsureFeatureEnabled), so
             * hiding the entry here would recreate the "why is half my panel
             * missing" defect this navigation was repaired for. The flag
             * still darkens the PUBLIC surface for everyone.
             */
            if ($item['flag'] !== null && ! $featureEnabled($item['flag']) && ! $user->isSuperAdmin()) {
                continue;
            }

            if ($item['permission'] !== null && ! $user->hasPermission($item['permission'])) {
                continue;
            }

            $children = self::filter($item['children'], $user, $featureEnabled);

            if ($item['children'] !== [] && $children === []) {
                continue;
            }

            $item['children'] = $children;
            $visible[] = $item;
        }

        return $visible;
    }

    /**
     * @param  list<array<string, mixed>>  $children
     * @return array<string, mixed>
     */
    private static function item(
        string $key,
        string $route,
        string $icon,
        ?string $permission,
        ?string $flag = null,
        array $children = [],
    ): array {
        return [
            'key' => $key,
            'route' => $route,
            'icon' => $icon,
            'permission' => $permission,
            'flag' => $flag,
            'children' => $children,
        ];
    }
}
