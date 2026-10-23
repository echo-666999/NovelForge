<?php

use App\Exceptions\NovelProblem;
use App\Models\User;
use App\Services\WorldService;
use Illuminate\Contracts\Console\Kernel;

require dirname(__DIR__, 2).'/vendor/autoload.php';
$app = require dirname(__DIR__, 2).'/bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();
if (! $app->environment('testing') || $app['db']->selectOne('SELECT current_database() AS name')->name !== 'novel_forge_test') {
    exit(2);
}
$actor = User::findOrFail($argv[1]);
try {
    $result = app(WorldService::class)->execute($actor, $argv[2], $argv[3], json_decode($argv[4], true, flags: JSON_THROW_ON_ERROR), $argv[5]);
    echo json_encode(['ok' => true, 'result' => $result], JSON_THROW_ON_ERROR);
} catch (NovelProblem $e) {
    echo json_encode(['ok' => false, 'code' => $e->errorCode], JSON_THROW_ON_ERROR);
}
