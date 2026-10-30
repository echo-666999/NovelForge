<?php

use App\Jobs\InfrastructureProbe;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Redis;

// 运行迁移辅助入口：连接信息只写私有文件，输出只含状态、数量和摘要。
require __DIR__.'/../../vendor/autoload.php';
$app = require __DIR__.'/../../bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();
umask(0077);
$directory = base_path('.local/runtime');
if (is_link(base_path('.local')) || is_link($directory) || ! is_dir($directory)) {
    throw new RuntimeException('需要已准备的项目私有运行目录。');
}
if ($app->environment('testing') || DB::getDriverName() !== 'pgsql'
    || DB::selectOne('SELECT current_database() AS name')->name !== 'novel_forge') {
    throw new RuntimeException('运行切换只允许开发库 novel_forge，不能用于测试环境。');
}
$action = $argv[1] ?? '';
if ($action === 'capture-source') {
    $destination = $directory.'/source-connection.json';
    // 独占创建，重跑不能覆盖已保存的切换前连接；绝不输出口令。
    $handle = fopen($destination, 'x');
    if ($handle === false) {
        throw new RuntimeException('已有来源连接，拒绝覆盖。');
    }
    fwrite($handle, json_encode(DB::connection()->getConfig(), JSON_THROW_ON_ERROR));
    fclose($handle);
    $redis = Redis::connection();
    $paths = $redis->command('config', ['get', 'dir', 'pidfile', 'appendonly']);
    $counts = [];
    foreach (config('novelforge.queues') as $queue) {
        $counts[$queue] = (int) $redis->llen('queues:'.$queue)
            + (int) $redis->zcard('queues:'.$queue.':reserved')
            + (int) $redis->zcard('queues:'.$queue.':delayed');
    }
    // 将 Redis 认证信息另存私有文件，供实例归属核对与平滑接管使用。
    file_put_contents($directory.'/source-redis.json', json_encode(config('database.redis.default'), JSON_THROW_ON_ERROR));
    echo json_encode(['source_database' => 'novel_forge', 'size_bytes' => DB::selectOne('SELECT pg_database_size(current_database()) AS size')->size,
        'redis_paths' => $paths, 'queue_counts' => $counts, 'cached_configuration' => $app->configurationIsCached()], JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE).PHP_EOL;
    exit;
}
// 探针只能连接项目专用实例，以免在开发连接尚未切换时触发旧队列。
if (DB::selectOne('SELECT current_user AS name')->name !== 'novel_forge_app'
    || DB::selectOne("SELECT current_setting('cluster_name') AS name")->name !== 'novelforge-private') {
    throw new RuntimeException('需要最小权限应用账户与项目专用实例。');
}
if ($action === 'health') {
    $role = DB::selectOne('SELECT rolsuper, rolcreatedb, rolcreaterole, rolreplication, rolbypassrls FROM pg_roles WHERE rolname=current_user');
    foreach ((array) $role as $privileged) {
        if ($privileged) {
            throw new RuntimeException('运行账户拥有额外权限。');
        }
    }
    Redis::connection()->ping();
    echo json_encode(['database' => 'novel_forge', 'role' => 'novel_forge_app', 'redis' => 'ready', 'cache' => config('cache.default'), 'queue' => config('queue.default')], JSON_THROW_ON_ERROR).PHP_EOL;
    exit;
}
$token = $argv[2] ?? '';
if (! preg_match('/\Aa02-runtime-[a-f0-9]{24}\z/', $token)) {
    exit(2);
}
switch ($action) {
    case 'probe-send':
        // 使用现有无业务副作用探针验证长期维护队列，不伪造小说或生成任务。
        Bus::dispatch((new InfrastructureProbe($token))->onConnection('redis')->onQueue('maintenance'));
        break;
    case 'probe-check':
        exit(Cache::get('novelforge-probe:'.$token) === 'ok' ? 0 : 1);
    case 'probe-clear':
        Cache::forget('novelforge-probe:'.$token);
        break;
    default:
        exit(2);
}
