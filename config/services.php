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

    /*
     * Bird (bird.com) — WhatsApp OTP delivery for account verification.
     *
     * The verification code travels as a WhatsApp TEMPLATE message through
     * Bird's official Channels API: POST {base_url}/workspaces/{id}/channels/
     * {id}/messages, authenticated by `Authorization: AccessKey …`, answered
     * with HTTP 202 Accepted. The raw endpoint references the approved
     * template by its TEMPLATE-PROJECT ID plus a version and a locale — not
     * by the slug the TypeScript SDK exposes — and the code is passed as one
     * typed parameter `{type:"string", key:<otp_parameter_key>, value:<code>}`.
     * The exact Bird objects each value comes from are documented in
     * docs/BIRD_WHATSAPP_OTP.md.
     *
     * WhatsApp verification is OFF until api_key, workspace_id, channel_id
     * and otp_template_project_id are all present; the verification-choice
     * page then offers Telegram alone, so a deployment that never configures
     * Bird behaves exactly as before this shipped.
     */
    'bird' => [
        'api_key' => env('BIRD_API_KEY'),
        'workspace_id' => env('BIRD_WORKSPACE_ID'),
        'channel_id' => env('BIRD_WHATSAPP_CHANNEL_ID'),
        // The template PROJECT id from the Bird workspace (a UUID), plus
        // which published version and locale of it to render.
        'otp_template_project_id' => env('BIRD_OTP_TEMPLATE_PROJECT_ID'),
        'otp_template_version' => env('BIRD_OTP_TEMPLATE_VERSION', 'latest'),
        'otp_template_locale' => env('BIRD_OTP_TEMPLATE_LOCALE', 'en'),
        // The template variable's KEY that carries the digits.
        'otp_parameter_key' => env('BIRD_OTP_TEMPLATE_PARAMETER_KEY', 'code'),
        'base_url' => env('BIRD_BASE_URL', 'https://api.bird.com'),
    ],
];
