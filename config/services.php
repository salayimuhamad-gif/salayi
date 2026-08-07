<?php

declare(strict_types=1);

/*
 * Third-party credentials (spec 28.2).
 *
 * Every value here comes from .env, never from the database. Spec 17.1 and
 * 37.5: "Normal admin does not see system secrets." An administrator
 * configures WHICH provider is active through site settings; the key itself
 * is only ever readable by whoever has filesystem access.
 */
return [
    'ai' => [
        'provider' => env('AI_PROVIDER', 'null'),
        'model' => env('AI_MODEL'),
        'fallback_model' => env('AI_FALLBACK_MODEL'),
        'key' => env('AI_API_KEY'),
        'base_url' => env('AI_BASE_URL'),
        'timeout' => (int) env('AI_TIMEOUT', 30),
        'monthly_cost_limit_usd' => (float) env('AI_MONTHLY_COST_LIMIT_USD', 0),
    ],

    'maps' => [
        'provider' => env('MAP_PROVIDER', 'maplibre'),
        'maplibre_style_url' => env('MAPLIBRE_STYLE_URL'),
        'google_key' => env('GOOGLE_MAPS_API_KEY'),
    ],

    'telegram' => [
        'bot_token' => env('TELEGRAM_BOT_TOKEN'),
        'bot_username' => env('TELEGRAM_BOT_USERNAME'),
        'webhook_secret' => env('TELEGRAM_WEBHOOK_SECRET'),
    ],
];
