<?php

declare(strict_types=1);

/*
 * Feature flag defaults (spec Appendix D).
 *
 * These are DEFAULTS ONLY. The live value is read from the `feature_flags`
 * table so a Super Admin can toggle a module without a deploy. A flag absent
 * from the database falls back to the value here; a flag absent from BOTH is
 * treated as OFF, never ON — an unknown flag must not silently enable a
 * commercial or experimental surface.
 */
return [
    'defaults' => [
        // Step 2–3
        'market.intelligence' => false,
        'market.indices' => false,
        'map.explorer' => false,

        /*
         * The public Investment Map (/invest): the map core restricted to
         * approved investment/project content. Independent of map.explorer
         * so an operator can expose the curated surface without the full
         * explorer, or the reverse.
         */
        'map.investment' => false,
        'places.database' => false,

        /*
         * The public Area profiles surface (/areas and /areas/{slug}) IS
         * built — Geography/Routes/web.php registers it per locale and
         * PublicAreaProfileTest exercises it. It stays off by default like
         * every public-surface flag: enabling is an operator decision, not
         * a deploy side effect.
         */
        'geography.areas' => false,

        /*
         * The Project Creation Wizard. Optional: ProjectController's single
         * form remains the always-available path, so switching this off
         * removes the wizard without removing project creation.
         */
        'projects.wizard' => true,

        /*
         * The public News surface is UNBUILT — no public route or page
         * exists for /news, only the admin CRUD behind /admin/content. The
         * flag exists so navigation has something to gate on rather than
         * rendering a permanent 404; do not enable it until the public
         * controller, route and Inertia page exist and have been requested
         * successfully in all three locales.
         */
        'content.news' => false,

        // Step 4
        'advisor.residential' => false,
        'advisor.investment' => false,
        'advisor.market' => false,
        'advisor.voice' => false,
        'lifestyle.matching' => false,

        // Step 5
        'companies.portal' => false,
        'companies.branches' => false,
        'marketplace.offers' => false,
        'marketplace.owner_listings' => false,
        'advertising' => false,

        // Step 6
        'portfolio' => false,

        /*
         * Wave 6: question-driven valuation adjustments on top of the
         * portfolio valuation baseline. Independent of `portfolio` (which
         * gates the whole surface) so the rule engine can be introduced —
         * and, if needed, withdrawn — without touching the portfolio
         * itself. OFF means the valuation path is byte-identical to the
         * pre-Wave-6 behaviour: no questions shown, no answers consumed,
         * no adjustment applied.
         */
        'portfolio.valuation_rules' => false,
        'alerts.telegram' => false,
        'alerts.email' => false,
        'alerts.push' => false,

        // Step 7–8
        'pwa' => false,
        'analytics.product' => false,
        'imports.ai_assist' => false,
        'translations.ai_suggest' => false,
        'public.reviews' => false,
        'partner.api' => false,
    ],

    /*
     * Flags that may never be enabled without an explicit Super Admin action
     * recorded in the audit log, regardless of seed or config state. These
     * are the ones with commercial, privacy or legal consequences.
     */
    'requires_super_admin' => [
        'advertising',
        'marketplace.owner_listings',
        'partner.api',
        'public.reviews',
        'translations.ai_suggest',
        'advisor.voice',
    ],

    'cache_ttl_seconds' => 300,
];
