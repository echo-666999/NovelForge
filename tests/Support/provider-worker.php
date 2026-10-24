<?php

use App\Exceptions\NovelProblem;
use App\Models\User;
use App\ProvidersGateway\FakeProvider;
use App\Services\ProviderGateway;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\DB;

require dirname(__DIR__, 2).'/vendor/autoload.php';
$app = require dirname(__DIR__, 2).'/bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();
// 子进程再次核实真实数据库，不能仅信任父进程声明。
if (! $app->environment('testing') || DB::selectOne('SELECT current_database() AS name')->name !== 'novel_forge_test') {
    exit(2);
}
$fake = new FakeProvider(timeout: true);
try {
    $r = app(ProviderGateway::class)->execute(User::findOrFail($argv[1]), $argv[2], $argv[3], $argv[4], json_decode($argv[5], true, flags: JSON_THROW_ON_ERROR), $argv[6], $fake);
    echo json_encode(['result' => $r, 'calls' => $fake->calls], JSON_THROW_ON_ERROR);
} catch (NovelProblem $e) {
    echo json_encode(['error' => $e->errorCode, 'calls' => $fake->calls], JSON_THROW_ON_ERROR);
}
