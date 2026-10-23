<?php

use App\Models\User;
use App\Services\NovelIntentService;
use Illuminate\Contracts\Console\Kernel;

require dirname(__DIR__, 2).'/vendor/autoload.php';
$app = require dirname(__DIR__, 2).'/bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();
if (! $app->environment('testing') || $app['db']->selectOne('SELECT current_database() AS name')->name !== 'novel_forge_test') {
    exit(2);
}
$actor = User::findOrFail($argv[1]);
$result = app(NovelIntentService::class)->execute($actor, 'create', null, [
    'title' => '并发验收夹具', 'genre' => '测试', 'background' => '只在测试库使用', 'premise_raw' => '同一请求不得重复建书',
    'target_chapters' => 10, 'length_policy' => ['min' => 100, 'max' => 200],
], $argv[2]);
echo json_encode($result, JSON_THROW_ON_ERROR);
