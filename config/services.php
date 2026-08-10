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
    /*
     * AI_PROVIDER selects the primary provider — really selects it, since the
     * multi-provider repair — and AI_FALLBACK_PROVIDER optionally names one
     * fallback. 'null' means OFF regardless of stored credentials.
     *
     * Legacy compatibility: the historical single-provider keys (AI_BASE_URL,
     * AI_API_KEY, AI_MODEL) remain readable as defaults for the
     * openai_compatible block, so an existing production .env keeps working
     * unchanged. AI_FALLBACK_MODEL is RETIRED: it was stored and displayed
     * but never executed (audit finding H); the fallback model is now simply
     * the fallback provider's own configured model.
     */
    'ai' => [
        'provider' => env('AI_PROVIDER', 'null'),
        'fallback_provider' => env('AI_FALLBACK_PROVIDER'),
        'timeout' => (int) env('AI_TIMEOUT', 30),
        'monthly_cost_limit_usd' => (float) env('AI_MONTHLY_COST_LIMIT_USD', 0),
        // USD per one million tokens, applied to every provider's usage
        // figures. Left at zero, completions are costed at 0.000000 and the
        // monthly ceiling cannot trip — the admin diagnostics say so.
        'rates' => [
            'prompt' => (float) env('AI_PROMPT_COST_PER_MTOK', 0),
            'completion' => (float) env('AI_COMPLETION_COST_PER_MTOK', 0),
        ],
        'providers' => [
            'openai' => [
                'key' => env('OPENAI_API_KEY'),
                'model' => env('OPENAI_MODEL'),
                'base_url' => env('OPENAI_BASE_URL', 'https://api.openai.com/v1'),
            ],
            'gemini' => [
                'key' => env('GEMINI_API_KEY'),
                'model' => env('GEMINI_MODEL'),
                'base_url' => env('GEMINI_BASE_URL', 'https://generativelanguage.googleapis.com/v1beta'),
            ],
            'openai_compatible' => [
                'key' => env('OPENAI_COMPATIBLE_API_KEY', env('AI_API_KEY')),
                'model' => env('OPENAI_COMPATIBLE_MODEL', env('AI_MODEL')),
                'base_url' => env('OPENAI_COMPATIBLE_BASE_URL', env('AI_BASE_URL')),
            ],
        ],
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
