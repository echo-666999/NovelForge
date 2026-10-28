<?php

namespace App\Jobs;

use App\Services\ExportService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/** 仅唤醒持久导出意图；身份冻结、并发和重复消息由数据库处理。 */
final class BuildFrozenExport implements ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    public int $timeout = 60;

    public function __construct(public string $exportId) {}

    public function handle(ExportService $service): void
    {
        $service->process($this->exportId);
    }
}
