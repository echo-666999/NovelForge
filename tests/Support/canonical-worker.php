<?php

use App\Exceptions\NovelProblem;
use App\Models\User;
use App\Services\CanonicalCommitService;
use Illuminate\Contracts\Console\Kernel;

// 并发进程只允许隔离测试库，不能把进程参数用于正式环境。
require dirname(__DIR__, 2).'/vendor/autoload.php';
$app = require dirname(__DIR__, 2).'/bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();
if (! $app->environment('testing') || $app['db']->selectOne('SELECT current_database() AS db')->db !== 'novel_forge_test') {
    exit(2);
}
try {
    $r = app(CanonicalCommitService::class)->commit(User::findOrFail($argv[1]), $argv[2], json_decode($argv[3], true, flags: JSON_THROW_ON_ERROR), $argv[4]);
    echo json_encode(['ok' => true, 'result' => $r], JSON_THROW_ON_ERROR);
} catch (NovelProblem $e) {
    echo json_encode(['ok' => false, 'code' => $e->errorCode], JSON_THROW_ON_ERROR);
}
