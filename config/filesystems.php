<?php

declare(strict_types=1);

return [
    'default' => env('FILESYSTEM_DISK', 'local'),

    'disks' => [
        // Spec 26.1: personal documents never appear in public storage.
        // `private` has no public URL by design; access is via signed route.
        'private' => [
            'driver' => 'local',
            'root' => storage_path('app/private'),
            'serve' => false,
            'throw' => false,
            'visibility' => 'private',
        ],

        /*
         * Draft uploads. PRIVATE by construction: a local disk with no
         * symlink into public/, so nothing under /storage can reach it.
         *
         * In-progress projects are commercially sensitive — a competitor who
         * guessed a path could see what a company is about to launch — and
         * the public disk offered exactly that. Previews go through the
         * authenticated, draft-scoped endpoint instead.
         */
        'draft-media' => [
            'driver' => 'local',
            'root' => storage_path('app/draft-media'),
            'throw' => false,
        ],

        'local' => [
            'driver' => 'local',
            'root' => storage_path('app/private'),
            'serve' => true,
            'throw' => false,
            'visibility' => 'private',
        ],

        'public' => [
            'driver' => 'local',
            'root' => storage_path('app/public'),
            'url' => env('APP_URL').'/storage',
            'visibility' => 'public',
            'throw' => false,
        ],

        'backups' => [
            'driver' => 'local',
            'root' => storage_path('backups'),
            'serve' => false,
            'throw' => true,
            'visibility' => 'private',
        ],
    ],

    'links' => [
        public_path('storage') => storage_path('app/public'),
    ],

    'uploads' => [
        'max_image_kb' => 8192,
        'max_document_kb' => 20480,
        'image_mimes' => ['image/jpeg', 'image/png', 'image/webp', 'image/avif'],
        'document_mimes' => ['application/pdf'],
        'spreadsheet_mimes' => [
            'text/csv',
            'application/vnd.ms-excel',
            'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
        ],
        // Extensions rejected regardless of reported MIME (spec 30.1).
        'blocked_extensions' => [
            'php', 'phtml', 'phar', 'php3', 'php4', 'php5', 'php7', 'php8',
            'htaccess', 'htpasswd', 'sh', 'bash', 'exe', 'bat', 'cmd', 'js', 'svg',
        ],
    ],
];
