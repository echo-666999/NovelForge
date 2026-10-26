<?php

use App\Services\CanonicalMemoryService;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\DB;

// 独立进程严格限制专用测试库，不继承开发配置运行派生消费者。
putenv('APP_ENV=testing');
$_ENV['APP_ENV'] = 'testing';
$_SERVER['APP_ENV'] = 'testing';
require dirname(__DIR__, 2).'/vendor/autoload.php';
$app = require dirname(__DIR__, 2).'/bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();
if (! $app->environment('testing') || DB::selectOne('SELECT current_database() AS db')->db !== 'novel_forge_test') {
    throw new RuntimeException('Test database required');
}
echo json_encode(['processed' => app(CanonicalMemoryService::class)->consume($argv[1])]).PHP_EOL;
