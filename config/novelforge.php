<?php

return [
    'search' => [
        'driver' => env('NOVELFORGE_SEARCH_DRIVER', 'pg_jieba'),
        'configuration' => env('NOVELFORGE_SEARCH_CONFIG', 'novelforge_zh'),
    ],
    'queues' => ['planning', 'generation', 'review', 'memory', 'export', 'maintenance'],
];
