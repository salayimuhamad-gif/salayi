<?php

declare(strict_types=1);

return [
    'hero_title' => 'Erbil property market intelligence',
    'hero_sub' => 'Verified data, with its evidence',
    'no_market_data' => 'No verified prices have been published yet',
    'no_projects' => 'No projects have been published yet',
    'no_areas' => 'No areas yet',
    'indices' => 'Indices',
    'projects' => 'Projects',
    'areas' => 'Areas',
    'sample' => 'Sample',
    'advisor_cta' => 'Property advisor',
    'lifestyle_cta' => 'Lifestyle search',
    'portfolio_cta' => 'My properties',
    'feature_off' => 'This section is disabled',
    /*
     * Added for the §8 homepage redesign. Every one of these labels a REAL
     * surface: a flag-gated action, an honest empty condition, or the
     * explanatory copy §8.1 asks for. None of them describes data.
     */
    'advisor_lede' => 'The advisor answers only from data published on this platform',
    'advisor_disabled' => 'The property advisor is not enabled at the moment',
    'examples' => 'For example, you can ask',
    'example_budget' => 'Which area matches my budget?',
    'example_compare' => 'Compare the available project types',
    'example_organise' => 'Help me organise my requirements',
    'live_map' => [
        'title' => 'Projects on the map, live',
        'open_full' => 'Open the full map',
        'empty' => 'No mapped projects yet — published projects appear here once they carry coordinates.',
    ],
    'invest_map' => [
        'title' => 'Discover investment projects across Hawler',
        'sub' => 'An interactive map of approved residential and investment projects — locations, boundaries, prices and their movement.',
        'cta' => 'Open Map',
    ],
    'quick_actions' => 'Quick actions',
    'map_cta' => 'Open the map',
    'offers_cta' => 'Browse offers',
    'area_projects' => 'projects',

    /*
     * Redesign additions — parity with ckb (lang-parity --strict).
     */
    'eyebrow' => 'Erbil property intelligence',
    'search_label' => 'Search projects',
    'search_placeholder' => 'Search projects by name…',
    'search_action' => 'Search',
    'insight_title' => 'AI market advisor',
    'insight_body' => 'Grounded answers from verified Erbil market data — with the evidence shown.',
    'nav' => [
        'home' => 'Home',
        'account' => 'Account',
    ],
    'pricing_map' => [
        'title' => 'Live pricing map',
        'lead' => 'Every pinned project shows its latest recorded price and its movement.',
        'pricing_unavailable' => 'The pricing layer is currently unavailable — projects are shown without prices.',
        'derived_note' => 'Derived from recorded prices',
        'last_update' => 'Last update',
        'latest_price' => 'Latest recorded price',
        'previous_price' => 'Previous (derived)',
        'price_history' => 'Full price history',
        'movement' => 'Price movement',
        'movement_up' => 'Rising',
        'movement_down' => 'Falling',
        'movement_flat' => 'Steady',
        'movement_unknown' => 'No comparable data',
        'updated_within' => 'Updated within',
        'updated_any' => 'Any time',
        'days_7' => '7 days',
        'days_30' => '30 days',
        'days_90' => '90 days',
        'price_range' => 'Price range',
        'all_areas' => 'All areas',
        'all_types' => 'All types',
        'area_label' => 'Area',
        'type_label' => 'Type',
        'reset' => 'Reset filters',
    ],

    /*
     * Wave 3 — the location intelligence card. Every string labels a real
     * state of the resolve endpoint or a real action; none of them describes
     * data the server did not send.
     */
    'location' => [
        'title' => 'Know the Price Where You Are',
        'lede' => 'Real published price intelligence for the area you are standing in.',
        'use_my_location' => 'Use My Location',
        'choose_manually' => 'Choose Area Manually',
        'privacy_hint' => 'Your location is used only to identify the mapped area.',
        'locating' => 'Finding your area…',
        'your_area' => 'Your area',
        'chosen_area' => 'Chosen area',
        'prices_for' => 'Price intelligence — :area',
        'no_price_data' => 'No published price intelligence for this area yet',
        'denied' => 'Location permission was declined — you can choose your area manually instead.',
        'unavailable' => 'Your browser could not provide a location — you can choose your area manually instead.',
        'outside' => 'This location is outside the areas mapped so far.',
        'rate_limited' => 'Too many attempts — please wait a moment and try again.',
        'error' => 'Something went wrong — please try again.',
        'try_again' => 'Try again',
        'select_area_label' => 'Choose an area',
        'select_placeholder' => 'Select an area…',
        'loading_areas' => 'Loading areas…',
        'areas_unavailable' => 'The area list could not be loaded — please try again.',
        'valuation_lede' => 'Add your property to your portfolio and request a valuation grounded in real market data.',
        'valuation_cta' => 'Know Your Property Value',
    ],
];
