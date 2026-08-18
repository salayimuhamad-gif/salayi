<?php

declare(strict_types=1);

return [
    'audit' => [
        'append_only_notice' => 'This log is append-only — it cannot be edited or deleted',
        'action' => 'Action',
        'severity' => 'Severity',
        'subject' => 'Subject',
        'actor' => 'Actor',
        'system' => 'System',
        'context' => 'Context',
        'request_id' => 'Request ID',
        'empty' => 'No audit entries',
        'empty_hint' => 'Administrative actions appear here',
    ],
    'health' => [
        'queue' => 'Queue',
        'queue_empty' => 'Queue is empty',
        'queue_backed_up' => 'Queue is backing up — the worker may not be running',
        'failed_jobs' => 'Failed jobs',
        'failures_notice' => 'A job has failed. That work never happened',
        'no_heartbeat' => 'No heartbeat recorded',
        'data_quality' => 'Data quality gaps',
        'data_quality_hint' => 'These do not raise errors; they silently do not work',
    ],
    'gaps' => [
        'projects_published_without_source' => 'Published projects with no source',
        'projects_without_geometry' => 'Projects with no location',
        'projects_never_verified' => 'Published projects never verified',
        'places_without_source' => 'Places with no source',
        'areas_without_boundary' => 'Areas with no boundary',
        'stale_nearby_snapshots' => 'Stale nearby-place snapshots',
    ],
];
