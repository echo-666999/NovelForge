<?php

use App\Services\CanonicalMemoryService;
use App\Services\OutboxService;
use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

// 调度器只修复通知与租约，不实现供应商调用或正式提交。
Artisan::command('novelforge:dispatch {--limit=100}', function () {
    $service = app(OutboxService::class);
    $this->line(json_encode($service->recover() + $service->publish((int) $this->option('limit')), JSON_UNESCAPED_UNICODE));
})->purpose('补投持久任务并检查过期租约');

Schedule::command('novelforge:dispatch')->everyMinute();

// 提交意图独立补投到memory队列，不伪造generation_run或执行外部模型。
Artisan::command('novelforge:canonical-memory {--limit=100}', function () {
    $this->line(json_encode(app(CanonicalMemoryService::class)->publish((int) $this->option('limit')), JSON_UNESCAPED_UNICODE));
})->purpose('补投正式提交后的记忆索引任务');
Schedule::command('novelforge:canonical-memory')->everyMinute();
