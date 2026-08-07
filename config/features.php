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
        'places.database' => false,

        /*
         * Public Area and News surfaces. Both are UNBUILT — there is no
         * public route for /areas or /news, only the admin CRUD behind
         * /admin/areas and /admin/content. These flags exist so the
         * navigation has something to gate on rather than rendering a
         * permanent 404; do not enable either until the public controller,
         * route and Inertia page exist and have been requested successfully
         * in all three locales.
         */
        'geography.areas' => false,

        /*
         * The Project Creation Wizard. Optional: ProjectController's single
         * form remains the always-available path, so switching this off
         * removes the wizard without removing project creation.
         */
        'projects.wizard' => true,
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
