<?php

use App\Planning\PlanningContract;

// 超时 < 租约 < Redis 可见性超时；扫描器每分钟运行，通知丢失可据库补投。
return [
    'connection' => 'redis',
    'worker_timeout' => 60,
    'lease_seconds' => 90,
    'retry_after' => 120,
    'redelivery_seconds' => 60,
    'max_claims' => 3,
    'queues' => ['planning', 'generation', 'review', 'memory', 'export', 'maintenance'],
    // E01–E05 仅注册离线规划、正文、提取、评审及受限修订，其他业务阶段仍不可执行。
    'stage_queues' => ['test.echo' => 'generation', 'drafting.scene' => 'generation', 'extraction.events' => 'generation', 'review.chapter' => 'review', 'revision.propose' => 'generation', ...array_fill_keys(array_map(fn ($phase) => 'planning.'.$phase, PlanningContract::PHASES), 'planning')],
];
