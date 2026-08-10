<?php

declare(strict_types=1);

return [
    'title' => 'System settings',
    'intro' => 'Manage the application, mail transport, maps, Telegram and AI provider from one protected screen.',
    'environment_not_writable' => 'The .env file is not writable. No setting can be saved until its permissions are corrected.',
    'general' => [
        'title' => 'Application',
        'description' => 'Changes are written to .env and the Laravel configuration cache is rebuilt automatically.',
        'app_name' => 'Application name',
        'app_url' => 'Application URL',
        'timezone' => 'Timezone',
        'default_locale' => 'Default language',
        'enabled_locales' => 'Enabled languages',
        'queue_connection' => 'Queue connection',
        'save' => 'Save application settings',
    ],
    'integrations' => [
        'title' => 'Integrations and API keys',
        'description' => 'Only a Super Admin can see this section. Existing secrets are never returned to the browser.',
        'secret_hint' => 'Leave a secret field empty to keep the current value. Use the clear switch only when you intend to remove it.',
        'configured' => 'Configured',
        'not_configured' => 'Not configured',
        'new_secret' => 'New value',
        'clear_secret' => 'Remove the stored value',
        'save' => 'Save integrations',
    ],
    'mail' => [
        'title' => 'SMTP mail',
        'description' => 'Outgoing email transport used for account and system messages.',
        'host' => 'SMTP host',
        'port' => 'SMTP port',
        'username' => 'SMTP username',
        'password' => 'SMTP password',
        'scheme' => 'Encryption scheme',
        'from_address' => 'From address',
        'from_name' => 'From name',
    ],
    'maps' => [
        'title' => 'Maps',
        'description' => 'Select MapLibre or Google Maps and configure the matching provider.',
        'provider' => 'Map provider',
        'style_url' => 'MapLibre style URL',
        'google_key' => 'Google Maps API key',
    ],
    'telegram' => [
        'title' => 'Telegram',
        'description' => 'Bot credentials for login, notifications and webhook verification.',
        'username' => 'Bot username',
        'token' => 'Bot token',
        'webhook_secret' => 'Webhook secret',
    ],
    'ai' => [
        'title' => 'AI provider',
        'description' => 'OpenAI-compatible endpoint used by optional AI features.',
        'provider' => 'Provider',
        'base_url' => 'Base URL',
        'api_key' => 'API key',
        'model' => 'Model',
        'fallback_model' => 'Fallback model',
        'timeout' => 'Timeout in seconds',
        'monthly_limit' => 'Monthly cost limit (USD)',
    ],
    'messages' => [
        'saved' => 'Settings saved and configuration cache rebuilt.',
        'no_changes' => 'No settings changed.',
        'write_failed' => 'The settings could not be saved. Check the server log and .env permissions.',
    ],
    'validation' => [
        'default_locale_enabled' => 'The default language must also be enabled.',
        'maplibre_style_required' => 'A MapLibre style URL is required for the MapLibre provider.',
        'google_key_required' => 'A Google Maps API key is required for the Google provider.',
        'ai_base_url_required' => 'The AI base URL is required when the AI provider is enabled.',
        'ai_model_required' => 'An AI model is required when the AI provider is enabled.',
        'ai_key_required' => 'An AI API key is required when the AI provider is enabled.',
    ],
    'flags' => [
        'market.intelligence' => [
            'label' => 'Market intelligence',
            'description' => 'Public market prices and statistics pages',
        ],
        'market.indices' => [
            'label' => 'Market indices',
            'description' => 'Published price indices on the public market pages',
        ],
        'map.explorer' => [
            'label' => 'Map Explorer',
            'description' => 'The public map at /map: projects, areas and places',
        ],
        'map.investment' => [
            'label' => 'Investment Map',
            'description' => 'The public investment map at /invest: approved projects with prices and trends',
        ],
        'places.database' => [
            'label' => 'Places database',
            'description' => 'Places and place categories, on the public map and in the admin panel',
        ],
        'geography.areas' => [
            'label' => 'Area profiles',
            'description' => 'Public area profile pages at /areas',
        ],
        'projects.wizard' => [
            'label' => 'Project wizard',
            'description' => 'The step-by-step project creation wizard in the admin panel',
        ],
        'content.news' => [
            'label' => 'News',
            'description' => 'The public news section at /news',
        ],
        'advisor.residential' => [
            'label' => 'Advisor — residential',
            'description' => 'The public AI advisor for residential searches',
        ],
        'advisor.investment' => [
            'label' => 'Advisor — investment',
            'description' => 'Investment guidance in the AI advisor',
        ],
        'advisor.market' => [
            'label' => 'Advisor — market',
            'description' => 'Market questions in the AI advisor',
        ],
        'advisor.voice' => [
            'label' => 'Advisor — voice',
            'description' => 'Voice input for the AI advisor',
        ],
        'lifestyle.matching' => [
            'label' => 'Lifestyle matching',
            'description' => 'Lifestyle-based project matching in the advisor',
        ],
        'companies.portal' => [
            'label' => 'Company portal',
            'description' => 'Company accounts, company pages and the admin companies section',
        ],
        'companies.branches' => [
            'label' => 'Company branches',
            'description' => 'Branch management inside the company portal',
        ],
        'marketplace.offers' => [
            'label' => 'Marketplace',
            'description' => 'Public offers at /offers and marketplace administration',
        ],
        'marketplace.owner_listings' => [
            'label' => 'Owner listings',
            'description' => 'Listings created by property owners themselves',
        ],
        'advertising' => [
            'label' => 'Advertising',
            'description' => 'Advertising campaigns and placements',
        ],
        'portfolio' => [
            'label' => 'Portfolio',
            'description' => 'Members\' personal property portfolios',
        ],
        'alerts.telegram' => [
            'label' => 'Telegram alerts',
            'description' => 'Outbound alert notifications over Telegram',
        ],
        'alerts.email' => [
            'label' => 'Email alerts',
            'description' => 'Outbound alert notifications over email',
        ],
        'alerts.push' => [
            'label' => 'Push alerts',
            'description' => 'Outbound web push notifications',
        ],
        'pwa' => [
            'label' => 'PWA',
            'description' => 'Installable app behaviour and offline support',
        ],
        'analytics.product' => [
            'label' => 'Product analytics',
            'description' => 'Anonymous product usage analytics',
        ],
        'imports.ai_assist' => [
            'label' => 'Import AI assist',
            'description' => 'AI assistance during price imports',
        ],
        'translations.ai_suggest' => [
            'label' => 'Translation AI suggestions',
            'description' => 'AI-suggested translations for reviewers',
        ],
        'public.reviews' => [
            'label' => 'Public reviews',
            'description' => 'Public ratings and reviews on projects',
        ],
        'partner.api' => [
            'label' => 'Partner API',
            'description' => 'The external partner API surface',
        ],
    ],
];
