<?php

declare(strict_types=1);

/*
 * Localisation contract for Mulkihawler (spec 7).
 *
 * `supported` is the closed set the application understands. `enabled` is the
 * subset an administrator has switched on for this deployment. ckb can never
 * be disabled — it is the product's default authoring language, not a
 * translation target.
 */
return [
    'default' => 'ckb',

    'immutable_default' => 'ckb',

    'enabled' => array_values(array_filter(array_map(
        'trim',
        explode(',', (string) env('APP_ENABLED_LOCALES', 'ckb,ar,en'))
    ))),

    'supported' => [
        'ckb' => [
            'name' => 'کوردیی ناوەندی',
            'native' => 'کوردیی ناوەندی',
            'english' => 'Kurdish Sorani',
            'direction' => 'rtl',
            'script' => 'Arab',
            'html_lang' => 'ckb',
            'intl' => 'ckb_IQ',
            // Latin digits are the default even in RTL because Erbil price
            // data is read alongside English/Arabic sources. Admin can flip
            // this per deployment via site settings.
            'numerals' => 'latn',
            'date_format' => 'Y-m-d',
            'font_stack' => 'Noto Kufi Arabic',
        ],
        'ar' => [
            'name' => 'العربية',
            'native' => 'العربية',
            'english' => 'Arabic',
            'direction' => 'rtl',
            'script' => 'Arab',
            'html_lang' => 'ar',
            'intl' => 'ar_IQ',
            'numerals' => 'latn',
            'date_format' => 'Y-m-d',
            'font_stack' => 'Noto Kufi Arabic',
        ],
        'en' => [
            'name' => 'English',
            'native' => 'English',
            'english' => 'English',
            'direction' => 'ltr',
            'script' => 'Latn',
            'html_lang' => 'en',
            'intl' => 'en_GB',
            'numerals' => 'latn',
            'date_format' => 'd M Y',
            'font_stack' => 'Noto Sans',
        ],
    ],

    /*
     * How a request's locale is resolved, first match wins.
     * `session` is above `browser` so an explicit switch is never overridden
     * by an Accept-Language header on the next page load.
     */
    'resolution_order' => ['url', 'user', 'session', 'browser', 'default'],

    'url_strategy' => 'prefix_except_default',

    'parity' => [
        'reference' => 'ckb',
        'compare' => ['ar', 'en'],
        // Keys allowed to exist in ckb without ar/en counterparts.
        'ignore_keys' => [],
    ],
];
