<?php

use App\Jobs\InfrastructureProbe;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Queue;
use Tests\Support\RecoveryEnvironment;

// 测试托管仅消费唯一探针队列，启动守卫限制数据库、Redis 与文件目录。
require __DIR__.'/../../vendor/autoload.php';
$app = require __DIR__.'/../../bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();
RecoveryEnvironment::assertReady();
$action = $argv[1] ?? '';
$token = $argv[2] ?? '';
if (! preg_match('/\Aa02-[a-f0-9]{24}\z/', $token)) {
    exit(2);
}
// 独立文件缓存使跨进程确认和 queue:restart 信号可见。
config(['cache.default' => 'file']);
switch ($action) {
    case 'worker':
        exit(Artisan::call('queue:work', ['connection' => 'redis', '--queue' => $token, '--sleep' => 1, '--tries' => 1, '--timeout' => 60]));
    case 'enqueue':
        Cache::forget('novelforge-probe:'.$token);
        Bus::dispatch((new InfrastructureProbe($token))->onConnection('redis')->onQueue($token));
        break;
    case 'ack':
        exit(Cache::get('novelforge-probe:'.$token) === 'ok' ? 0 : 1);
    case 'restart':
        exit(Artisan::call('queue:restart'));
    case 'clear':
        // 只清理随机探针队列，不能使用 flushall 或清空其他任务队列。
        Queue::connection('redis')->clear($token);
        Cache::forget('novelforge-probe:'.$token);
        Cache::forget('illuminate:queue:restart');
        break;
    default:
        exit(2);
}
