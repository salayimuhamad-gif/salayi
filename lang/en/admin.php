<?php

declare(strict_types=1);

return [
    'scheduler' => [
        'title' => 'Scheduler',
        'last_success' => 'Last successful run',
        'failures' => 'Consecutive failures',
        'stale' => 'The scheduler is not running. Check the cron entry — without it no alert is ever delivered',
    ],
    'build' => [
        'title' => 'Build',
        'version' => 'Version',
        'schema' => 'Schema version',
        'environment' => 'Environment',
    ],
    'warnings' => [
        'debug_in_production' => 'Debug mode is on in production. This exposes internal detail',
    ],
    'slice' => [
        'title' => 'This slice',
        'description' => 'Admin shell, navigation and authentication. Projects and the public page come next',
    ],
    'branding' => [
        'identity' => 'Identity',
        'identity_hint' => 'The site name and tagline',
        'site_name' => 'Site name',
        'tagline_ckb' => 'Tagline (Sorani)',
        'tagline_ar' => 'Tagline (Arabic)',
        'tagline_en' => 'Tagline (English)',
        'palette' => 'Palette',
        'palette_hint' => 'Colours apply immediately with no rebuild',
        'color_brand' => 'Brand colour',
        'color_accent' => 'Accent colour',
        'color_surface' => 'Surface colour',
        'color_ink' => 'Text colour',
        'rgb_hint' => 'Three space-separated numbers, e.g. 15 62 89',
        'pwa' => 'PWA identity',
        'pwa_hint' => 'What appears on a phone home screen',
        'pwa_name' => 'Full name',
        'pwa_short_name' => 'Short name',
        'dark_mode' => 'Dark mode',
        'dark_mode_hint' => 'Let users choose a dark appearance',
        'assets' => 'Assets',
        'assets_hint' => 'Every upload is a new version; the previous one is kept',
    ],
    'features' => [
        'explanation' => 'A disabled feature is absent entirely, not merely hidden',
        'super_admin_only' => 'Only a Super Admin may change this',
        'unknown_flag' => 'This flag is not known to the configuration',
        'none' => 'No feature flags',
        'none_hint' => 'They appear after installation',
    ],
];
