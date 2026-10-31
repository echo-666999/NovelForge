<?php

return [
    // 仅部署明确启用后开放真实执行，仍须逐请求校验预算和冻结来源。
    'real_execution_enabled' => (bool) env('NF_REAL_EXECUTION_ENABLED', false),
    'search' => [
        'driver' => env('NOVELFORGE_SEARCH_DRIVER', 'pg_jieba'),
        'configuration' => env('NOVELFORGE_SEARCH_CONFIG', 'novelforge_zh'),
    ],
    // 仅控制本地字符向量可用性，开启也不会调用真实嵌入模型。
    'canonical_vectors' => true,
    'queues' => ['planning', 'generation', 'review', 'memory', 'export', 'maintenance'],
];
