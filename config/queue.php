<?php

declare(strict_types=1);

return [
    'default' => env('QUEUE_CONNECTION', 'database'),

    'connections' => [
        'sync' => ['driver' => 'sync'],

        'database' => [
            'driver' => 'database',
            'connection' => env('DB_QUEUE_CONNECTION'),
            'table' => env('DB_QUEUE_TABLE', 'jobs'),
            'queue' => env('DB_QUEUE', 'default'),
            'retry_after' => 90,
            'after_commit' => true,
        ],
    ],

    /*
     * Named queues, highest urgency first. A cron-driven worker on shared
     * hosting processes them in this order so a 20k-row import can never
     * delay a login notification.
     */
    'priorities' => ['critical', 'notifications', 'default', 'imports', 'ai', 'maintenance'],

    'batching' => [
        'database' => env('DB_CONNECTION', 'mysql'),
        'table' => 'job_batches',
    ],

    'failed' => [
        'driver' => env('QUEUE_FAILED_DRIVER', 'database-uuids'),
        'database' => env('DB_CONNECTION', 'mysql'),
        'table' => 'failed_jobs',
    ],
];
