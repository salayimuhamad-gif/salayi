<?php

declare(strict_types=1);

return [
    'create' => 'دروستکردنی پێشنیار',
    'empty' => 'هیچ پێشنیارێک نییە',
    'empty_hint' => 'پێشنیارەکان لێرە دەردەکەون',
    'awaiting_moderation' => 'چاوەڕوانی پێداچوونەوە',
    'awaiting_hint' => 'ناتوانرێت بڵاو بکرێتەوە بەبێ پەسەندکردنی پێداچوونەوەر',
    'published_offers' => 'بڵاوکراوە',
    'queue_only' => 'تەنها ڕیزی پێداچوونەوە',
    'no_company' => 'بێ کۆمپانیا',
    'history' => 'مێژووی دۆخ',
    'as_moderator' => 'وەک پێداچوونەوەر',

    'public' => [
        'title' => 'پێشنیارەکان',
        'search' => 'گەڕان بە ناو...',
        'budget' => 'بەرزترین بودجە',
        'none' => 'هیچ پێشنیارێک نەدۆزرایەوە',
        'none_hint' => 'فلتەرەکان بگۆڕە',
        'terms' => 'مەرجەکان',
    ],

    'fields' => [
        'title_ckb' => 'ناونیشان (کوردی)',
        'title_en' => 'ناونیشان (ئینگلیزی)',
        'offer_type' => 'جۆری پێشنیار',
        'company_hint' => '⚠ واتای کۆمپانیای پشتڕاستنەکراوە',
        'precision' => 'وردیی شوێن',
        'details' => 'وردەکاری',
        'price' => 'نرخ',
        'rooms' => 'ژمارەی ژوور',
        'sponsored_hint' => 'پارەدان شوێنی سروشتی ناکڕێت',
    ],
    'precision' => [
        'exact' => 'وردی',
        'approximate' => 'نزیکەیی',
        'area_only' => 'تەنها ناوچە',
    ],

    'statuses' => [
        'draft' => 'ڕەشنووس',
        'submitted' => 'نێردراو',
        'under_review' => 'لە پێداچوونەوەدا',
        'changes_requested' => 'گۆڕانکاری داواکراوە',
        'approved' => 'پەسەندکراو',
        'scheduled' => 'خشتەکراو',
        'published' => 'بڵاوکراوە',
        'paused' => 'ڕاگیراو',
        'expired' => 'بەسەرچووە',
        'rejected' => 'ڕەتکراوە',
        'archived' => 'ئەرشیفکراو',
    ],
    'offer_types' => [
        'sale' => 'فرۆشتن',
        'rent' => 'بەکرێدان',
        'investment' => 'وەبەرهێنان',
        'pre_launch' => 'پێش دەستپێکردن',
    ],
    'sponsorship' => [
        'sponsored' => 'ڕیکلامکراو',
        'paid_placement' => 'شوێنی پارەدراو',
        'organic_results' => 'ئەنجامە سروشتییەکان',
        'sponsored_section' => 'بەشی ڕیکلام',
        'disclosure_notice' => 'ئەم ئەنجامانە پارەیان بۆ دراوە و جیاکراونەتەوە لە ئەنجامە سروشتییەکان',
        'not_ranked_by_payment' => 'ئەنجامە سروشتییەکان بە پارە ڕیزبەندی ناکرێن',
    ],
    'location_precision' => [
        'exact' => 'شوێنی وردی',
        'approximate' => 'شوێنی نزیکەیی',
        'area_only' => 'تەنها ناوچە',
    ],
    'errors' => [
        'project_not_eligible' => 'ئەم پڕۆژەیە بۆ کۆمپانیاکەت نییە',
        'owner_required' => 'پێویستە کۆمپانیایەک هەبێت',
        'exact_needs_coordinates' => 'وردی پێویستی بە خاڵی شوێنە',
        'illegal_transition' => 'ناتوانرێت ئەم گۆڕانکارییە بکرێت',
        'moderator_required' => 'تەنها پێداچوونەوەر دەتوانێت ئەمە بکات',
        'missing_disclosure' => 'پێویستە ڕیکلام ئاشکرا بکرێت',
        'offer_under_moderation' => 'ئەم پێشنیارە لە پێداچوونەوەدایە و ناتوانرێت دەستکاری بکرێت',
    ],
];
