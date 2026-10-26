<?php

namespace App\Jobs;

use App\Services\CanonicalMemoryService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/** 消息仅带持久任务ID，重投/崩溃由数据库租约和隔离令牌恢复。 */
final class ConsumeCanonicalMemory implements ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    public int $timeout = 60;

    public function __construct(public string $taskId) {}

    public function handle(CanonicalMemoryService $service): void
    {
        $service->consume($this->taskId);
    }
}
