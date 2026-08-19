<?php

declare(strict_types=1);

/*
 * Guided web installer (spec 33).
 * The step list is data, not code branches, so the wizard, the progress bar,
 * the resume logic and the tests all read one source of truth.
 */
return [
    'enabled' => ! (bool) env('MULKIHAWLER_INSTALLED', false),

    'lock_file' => storage_path('installer/installed.lock'),
    'state_file' => storage_path('installer/state.json'),
    'log_file' => storage_path('installer/install.log'),

    'reset_token' => env('MULKIHAWLER_INSTALL_RESET_TOKEN'),

    'minimum_php' => '8.3.0',

    'required_extensions' => [
        'bcmath', 'ctype', 'curl', 'dom', 'fileinfo', 'intl', 'json',
        'mbstring', 'openssl', 'pdo', 'pdo_mysql', 'tokenizer', 'xml', 'zip',
    ],

    'recommended_extensions' => ['gd', 'exif', 'zlib'],

    'writable_paths' => [
        'storage/app',
        'storage/framework/cache',
        'storage/framework/sessions',
        'storage/framework/views',
        'storage/logs',
        'storage/installer',
        'bootstrap/cache',
    ],

    /*
     * 24 steps, spec 33.1 and File two §18. `resumable` marks a step that is safe to re-enter
     * after a failure; `destructive` marks one that must never auto-retry.
     */
    'steps' => [
        ['key' => 'welcome', 'resumable' => true, 'destructive' => false],
        ['key' => 'license', 'resumable' => true, 'destructive' => false],
        ['key' => 'requirements', 'resumable' => true, 'destructive' => false],
        ['key' => 'extensions', 'resumable' => true, 'destructive' => false],
        ['key' => 'permissions', 'resumable' => true, 'destructive' => false],
        ['key' => 'database', 'resumable' => true, 'destructive' => false],
        ['key' => 'app_url', 'resumable' => true, 'destructive' => false],
        ['key' => 'mail', 'resumable' => true, 'destructive' => false],
        ['key' => 'queue', 'resumable' => true, 'destructive' => false],
        ['key' => 'map_provider', 'resumable' => true, 'destructive' => false],
        ['key' => 'telegram', 'resumable' => true, 'destructive' => false],
        ['key' => 'ai_provider', 'resumable' => true, 'destructive' => false],
        ['key' => 'default_language', 'resumable' => true, 'destructive' => false],
        ['key' => 'enabled_languages', 'resumable' => true, 'destructive' => false],
        ['key' => 'branding', 'resumable' => true, 'destructive' => false],
        ['key' => 'super_admin', 'resumable' => true, 'destructive' => false],
        ['key' => 'migrate', 'resumable' => true, 'destructive' => true],
        ['key' => 'seed', 'resumable' => true, 'destructive' => true],
        ['key' => 'storage_link', 'resumable' => true, 'destructive' => false],
        // File two §18 step 21. Absent until now: an operator who uploaded a
        // package without public/build got a working installer and a site
        // that renders nothing, with no step that would have said so.
        ['key' => 'assets', 'resumable' => true, 'destructive' => false],
        ['key' => 'cache', 'resumable' => true, 'destructive' => false],
        ['key' => 'health_check', 'resumable' => true, 'destructive' => false],
        ['key' => 'complete', 'resumable' => false, 'destructive' => false],
        ['key' => 'lock', 'resumable' => false, 'destructive' => true],
    ],

    'upgrade' => [
        // Spec 33.3: an upgrade must never regenerate APP_KEY or touch uploads.
        'preserve' => ['APP_KEY', 'MULKIHAWLER_PII_KEY', 'MULKIHAWLER_BLIND_INDEX_KEY'],
        'preserve_paths' => ['storage/app/public', 'storage/app/private', '.env'],
        'backup_before_migrate' => true,
        'maintenance_mode' => true,
        'rollback_on_health_failure' => true,
    ],
];
