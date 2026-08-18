<?php

declare(strict_types=1);

/*
 * Sorani is the authoring language (spec 7.1). Every key is written here
 * FIRST; ar and en are translations of this file, not the other way round.
 * scripts/lang-parity.php fails the build if a key here has no counterpart.
 */

return [
    'name' => 'مولکی هەولێر',
    'tagline' => 'زانیاریی بازاڕی خانووبەرە لە هەولێر',

    'nav' => [
        'home' => 'سەرەکی',
        'projects' => 'پڕۆژەکان',
        'market' => 'بازاڕ',
        'map' => 'نەخشە',
        'companies' => 'کۆمپانیاکان',
        'news' => 'هەواڵ',
        'about' => 'دەربارە',
        'contact' => 'پەیوەندی',
        'account' => 'هەژمار',
        'admin' => 'بەڕێوەبردن',
    ],

    'actions' => [
        'add' => 'زیادکردن',
        'save' => 'پاشەکەوت',
        'create' => 'دروستکردن',
        'disable' => 'ناچالاککردن',
        'cancel' => 'هەڵوەشاندنەوە',
        'delete' => 'سڕینەوە',
        'edit' => 'دەستکاری',
        'search' => 'گەڕان',
        'filter' => 'پاڵاوتن',
        'reset' => 'ڕێکخستنەوە',
        'back' => 'گەڕانەوە',
        'next' => 'دواتر',
        'previous' => 'پێشتر',
        'confirm' => 'دڵنیاکردنەوە',
        'close' => 'داخستن',
        'retry' => 'دووبارە هەوڵدانەوە',
    ],

    'states' => [
        'loading' => 'بارکردن...',
        'empty' => 'هیچ زانیارییەک نییە',
        'error' => 'هەڵەیەک ڕوویدا',
        'offline' => 'بێ ئینتەرنێت',
        'saved' => 'پاشەکەوت کرا',
        'not_found' => 'نەدۆزرایەوە',
    ],

    'meta' => [
        'source' => 'سەرچاوە',
        'last_updated' => 'دوایین نوێکردنەوە',
        'freshness' => 'تازەیی زانیاری',
        'confidence' => 'ئاستی متمانە',
        'method' => 'ڕێبازی ژمێریاری',
        'no_source' => 'سەرچاوە تۆمار نەکراوە',
        'never_verified' => 'هەرگیز پشتڕاست نەکراوەتەوە',
        'ai_generated' => 'ئەم دەقە بە یارمەتیی AI دروستکراوە و پێویستی بە پێداچوونەوەی مرۆڤ هەیە',
    ],

    'errors' => [
        '401' => 'پێویستە بچیتە ژوورەوە',
        '403' => 'ڕێگەپێدانت نییە',
        '404' => 'ئەم پەڕەیە نەدۆزرایەوە',
        '419' => 'دانیشتنەکەت بەسەرچووە، تکایە دووبارە هەوڵبدەرەوە',
        '429' => 'داواکاریی زۆر زۆرە، تکایە کەمێک چاوەڕێ بکە',
        '500' => 'هەڵەیەکی ناوخۆیی ڕوویدا',
        '503' => 'سیستەم لە دۆخی چاککردندایە',
        // Shown to an administrator who reaches a switched-off module.
        'feature_disabled' => 'ئەم تایبەتمەندییە لە ئێستادا ناچالاککراوە',
    ],
];
