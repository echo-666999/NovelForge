<?php

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
