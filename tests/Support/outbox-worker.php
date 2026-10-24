<?php

use App\Models\User;
use App\Services\GenerationService;
use App\Services\OutboxService;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\DB;

require dirname(__DIR__, 2).'/vendor/autoload.php';
$app = require dirname(__DIR__, 2).'/bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();
// 任何子进程动作前都核对实际连接，避免恢复测试触及开发库。
if (! $app->environment('testing') || DB::selectOne('SELECT current_database() AS name')->name !== 'novel_forge_test') {
    exit(2);
}
if ($argv[1] === 'consume') {
    app(OutboxService::class)->consume($argv[2]);
    echo 'done';
} else {
    $event = DB::table('outbox_events')->find($argv[2]);
    $actor = User::findOrFail(DB::table('generation_runs')->where('id', $event->run_id)->value('actor_id'));
    $claim = app(GenerationService::class)->claim($actor, (string) $event->novel_id, (string) $event->run_id, $event->scope_key);
    echo json_encode($claim, JSON_THROW_ON_ERROR).PHP_EOL;
    flush();
    // 保持旧 Worker 存活，测试进程将向它发送真实终止信号。
    sleep(30);
}
