<?php

declare(strict_types=1);

return [
    'audit' => [
        'append_only_notice' => 'ئەم تۆمارە تەنها زیاد دەکرێت — ناتوانرێت دەستکاری یان بسڕدرێتەوە',
        'action' => 'کردار',
        'severity' => 'گرنگی',
        'subject' => 'بابەت',
        'actor' => 'کردەکار',
        'system' => 'سیستەم',
        'context' => 'وردەکاری',
        'request_id' => 'ناسنامەی داواکاری',
        'empty' => 'هیچ تۆمارێک نییە',
        'empty_hint' => 'کردارەکانی بەڕێوەبردن لێرە دەردەکەون',
    ],
    'health' => [
        'queue' => 'ڕیزی کارەکان',
        'queue_empty' => 'ڕیز بەتاڵە',
        'queue_backed_up' => 'ڕیز کۆبووەتەوە — کارکەر لەوانەیە ڕانەوەستێت',
        'failed_jobs' => 'کارە سەرکەوتوونەبووەکان',
        'failures_notice' => 'کارێک سەرکەوتوو نەبووە. ئەو کارە هەرگیز ئەنجام نەدراوە',
        'no_heartbeat' => 'هیچ لێدانێکی دڵ تۆمار نەکراوە',
        'data_quality' => 'کەلێنەکانی جۆریی داتا',
        'data_quality_hint' => 'ئەمانە هەڵە دەرناخەن، بەڵام بێدەنگ کار ناکەن',
    ],
    'gaps' => [
        'projects_published_without_source' => 'پڕۆژەی بڵاوکراوە بەبێ سەرچاوە',
        'projects_without_geometry' => 'پڕۆژە بەبێ شوێن',
        'projects_never_verified' => 'پڕۆژەی بڵاوکراوەی پشتڕاستنەکراو',
        'places_without_source' => 'شوێن بەبێ سەرچاوە',
        'areas_without_boundary' => 'ناوچە بەبێ سنوور',
        'stale_nearby_snapshots' => 'ژماردنی کۆنی شوێنە نزیکەکان',
    ],
];
