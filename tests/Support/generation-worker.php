<?php

use App\Models\User;
use App\Services\GenerationService;
use Illuminate\Contracts\Console\Kernel;
use Tests\Support\FakeStageExecutor;

require dirname(__DIR__, 2).'/vendor/autoload.php';
$app = require dirname(__DIR__, 2).'/bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();
// 子进程自行核对真实数据库，避免并发测试越过测试隔离。
if (! $app->environment('testing') || $app['db']->selectOne('SELECT current_database() AS name')->name !== 'novel_forge_test') {
    exit(2);
}
$actor = User::findOrFail($argv[1]);
$service = app(GenerationService::class);
if ($argv[3] === 'start') {
    $result = $service->start($actor, $argv[2], json_decode($argv[4], true, flags: JSON_THROW_ON_ERROR), 'concurrent-start');
} else {
    $fake = new FakeStageExecutor;
    $result = $service->advance($actor, $argv[2], $argv[4], $argv[5], $fake, isset($argv[6]) ? (int) $argv[6] : null);
    $result['calls'] = $fake->calls;
}
echo json_encode($result, JSON_THROW_ON_ERROR);
