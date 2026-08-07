<?php

declare(strict_types=1);

return [
    'name' => env('APP_NAME', 'Mulkihawler'),
    'env' => env('APP_ENV', 'production'),
    'debug' => (bool) env('APP_DEBUG', false),
    'url' => env('APP_URL', 'http://localhost'),
    'timezone' => env('APP_TIMEZONE', 'Asia/Baghdad'),

    /*
     * Sorani-first (spec 7.1). The fallback is ckb, not en, on purpose:
     * an untranslated key must surface as a Sorani-key defect caught by
     * scripts/lang-parity.php, never as English text on a Sorani page.
     */
    'locale' => env('APP_LOCALE', 'ckb'),
    'fallback_locale' => env('APP_FALLBACK_LOCALE', 'ckb'),
    'faker_locale' => env('APP_FAKER_LOCALE', 'ar_IQ'),

    'cipher' => 'AES-256-CBC',
    'key' => env('APP_KEY'),
    'previous_keys' => array_filter(explode(',', (string) env('APP_PREVIOUS_KEYS', ''))),

    'maintenance' => [
        'driver' => env('APP_MAINTENANCE_DRIVER', 'file'),
        'store' => env('APP_MAINTENANCE_STORE', 'database'),
    ],
];
