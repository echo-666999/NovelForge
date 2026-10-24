<?php

// 超时 < 租约 < Redis 可见性超时；扫描器每分钟运行，通知丢失可据库补投。
return [
    'connection' => 'redis',
    'worker_timeout' => 60,
    'lease_seconds' => 90,
    'retry_after' => 120,
    'redelivery_seconds' => 60,
    'max_claims' => 3,
    'queues' => ['planning', 'generation', 'review', 'memory', 'export', 'maintenance'],
    'stage_queues' => ['test.echo' => 'generation'],
];
