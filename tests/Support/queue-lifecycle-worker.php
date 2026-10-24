<?php

use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;

require dirname(__DIR__, 2).'/vendor/autoload.php';
$app = require dirname(__DIR__, 2).'/bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();
if (! $app->environment('testing') || DB::selectOne('SELECT current_database() AS name')->name !== 'novel_forge_test') {
    exit(2);
}
// 使用隔离目录中的共享文件缓存，让跨进程 queue:restart 信号真实可见。
config(['cache.default' => 'file']);
exit(Artisan::call('queue:work', ['connection' => 'redis', '--queue' => 'generation', '--sleep' => 1, '--tries' => 1, '--timeout' => 60, '--max-time' => 20]));
