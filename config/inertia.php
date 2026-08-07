<?php

declare(strict_types=1);

return [
    'ssr' => [
        // Off by default: SSR needs a long-running Node process, which shared
        // hosting does not provide. SEO is handled by server-rendered meta
        // tags plus structured data instead (spec 31.2).
        'enabled' => false,
        'url' => 'http://127.0.0.1:13714',
    ],
    'testing' => [
        'ensure_pages_exist' => true,
        'page_paths' => [resource_path('js/Pages')],
        'page_extensions' => ['vue'],
    ],
];
