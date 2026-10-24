<?php

use App\Exceptions\NovelProblem;
use App\Models\User;
use App\Services\RecoveryService;
use Illuminate\Contracts\Console\Kernel;

require dirname(__DIR__, 2).'/vendor/autoload.php';
$app = require dirname(__DIR__, 2).'/bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();
// 独立进程必须再次核对实际数据库，不继承父进程的口头保证。
if (! $app->environment('testing') || $app['db']->selectOne('SELECT current_database() AS name')->name !== 'novel_forge_test') {
    exit(2);
}
try {
    $result = app(RecoveryService::class)->execute(User::findOrFail($argv[1]), $argv[2], $argv[3], $argv[4], json_decode($argv[5], true, flags: JSON_THROW_ON_ERROR), $argv[6]);
    echo json_encode(['ok' => true, 'result' => $result], JSON_THROW_ON_ERROR);
} catch (NovelProblem $e) {
    echo json_encode(['ok' => false, 'code' => $e->errorCode], JSON_THROW_ON_ERROR);
}
