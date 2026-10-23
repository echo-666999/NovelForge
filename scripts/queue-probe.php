<?php

use App\Jobs\InfrastructureProbe;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;

// 基础设施验收：使用独立维护队列处理一条无业务副作用的探针任务。
require __DIR__.'/../vendor/autoload.php';
$app = require __DIR__.'/../bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();
$token = Str::uuid()->toString();
$queue = 'infrastructure-probe-'.$token;
Bus::dispatch((new InfrastructureProbe($token))->onConnection('redis')->onQueue($queue));
Artisan::call('queue:work', ['connection' => 'redis', '--queue' => $queue, '--once' => true, '--tries' => 1, '--timeout' => 15]);
$result = Cache::pull('novelforge-probe:'.$token);
if ($result !== 'ok') {
    fwrite(STDERR, "Queue probe failed.\n");
    exit(1);
}
echo "Redis dispatch -> worker -> cache acknowledgement: PASS\n";
