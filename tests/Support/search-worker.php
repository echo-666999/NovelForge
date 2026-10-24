<?php

use App\Exceptions\NovelProblem;
use App\Models\User;
use App\Services\HybridSearchService;
use Illuminate\Contracts\Console\Kernel;

// 独立测试进程强制校验隔离库；任何环境误配立即退出。
require dirname(__DIR__, 2).'/vendor/autoload.php';
$app = require dirname(__DIR__, 2).'/bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();
if (! $app->environment('testing') || $app['db']->selectOne('SELECT current_database() AS name')->name !== 'novel_forge_test') {
    exit(2);
}
try {
    $r = app(HybridSearchService::class)->execute(User::findOrFail($argv[1]), $argv[2], $argv[5], json_decode($argv[3], true, flags: JSON_THROW_ON_ERROR), $argv[4]);
    echo json_encode(['ok' => true, 'id' => $r['build_id']], JSON_THROW_ON_ERROR);
} catch (NovelProblem $e) {
    echo json_encode(['ok' => false, 'code' => $e->errorCode], JSON_THROW_ON_ERROR);
}
