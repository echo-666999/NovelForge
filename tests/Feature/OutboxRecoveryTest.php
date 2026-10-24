<?php

namespace Tests\Feature;

use App\Jobs\ConsumeGenerationIntent;
use App\Services\OutboxService;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Redis;
use Symfony\Component\Process\Process;
use Tests\Support\QueuedRunFixture;
use Tests\TestCase;

/** 独立进程及真实 Redis 重启；不以清空队列模拟服务重启。 */
class OutboxRecoveryTest extends TestCase
{
    public function test_killed_worker_is_reclaimed_once_by_two_processes(): void
    {
        $this->assertSame('novel_forge_test', DB::selectOne('SELECT current_database() AS name')->name);
        Artisan::call('migrate:fresh', ['--force' => true]);
        $children = [];
        try {
            $f = QueuedRunFixture::create();
            $event = (string) DB::table('outbox_events')->where('scope_key', 'first')->value('id');
            $old = new Process([PHP_BINARY, base_path('tests/Support/outbox-worker.php'), 'claim', $event], base_path(), ['APP_ENV' => 'testing']);
            $children[] = $old;
            $old->start();
            $this->assertTrue($old->waitUntil(fn ($type, $output) => str_contains($output, '"token"')));
            $oldClaim = json_decode(trim($old->getOutput()), true, flags: JSON_THROW_ON_ERROR);
            $old->stop(0, 9);
            $this->assertFalse($old->isRunning());
            $this->assertDatabaseCount('generation_artifacts', 0);
            // 注入租约到期，不等待真实90秒；Worker 的死亡是真实进程信号。
            DB::table('generation_steps')->where('id', $oldClaim['id'])->update(['lease_until' => DB::raw("clock_timestamp() - interval '1 second'")]);
            $this->assertSame(1, app(OutboxService::class)->recover()['expired_steps']);
            for ($i = 0; $i < 2; $i++) {
                $p = new Process([PHP_BINARY, base_path('tests/Support/outbox-worker.php'), 'consume', $event], base_path(), ['APP_ENV' => 'testing']);
                $p->start();
                $children[] = $p;
            }
            foreach (array_slice($children, 1) as $p) {
                $p->wait();
                $this->assertSame(0, $p->getExitCode(), $p->getErrorOutput());
            }
            $this->assertDatabaseCount('generation_steps', 1);
            $this->assertDatabaseCount('generation_artifacts', 1);
            $this->assertDatabaseCount('consumer_receipts', 1);
            $this->assertSame(2, DB::table('generation_steps')->value('fencing_token'));
        } finally {
            foreach ($children as $p) {
                if ($p->isRunning()) {
                    $p->stop();
                }
            }
            Artisan::call('migrate:fresh', ['--force' => true]);
        }
    }

    public function test_worker_restart_signal_releases_old_process_and_new_worker_continues(): void
    {
        $this->assertSame('novel_forge_test', DB::selectOne('SELECT current_database() AS name')->name);
        Artisan::call('migrate:fresh', ['--force' => true]);
        $worker = null;
        $cacheDefault = config('cache.default');
        config(['cache.default' => 'file']);
        Cache::forget('illuminate:queue:restart');
        try {
            $f = QueuedRunFixture::create();
            Queue::connection('redis')->clear('generation');
            app(OutboxService::class)->publish();
            $worker = new Process([PHP_BINARY, base_path('tests/Support/queue-lifecycle-worker.php')], base_path(), ['APP_ENV' => 'testing']);
            $worker->setTimeout(15)->start();
            for ($i = 0; $i < 100 && DB::table('consumer_receipts')->count() < 2; $i++) {
                usleep(50000);
            }
            $this->assertDatabaseCount('consumer_receipts', 2);
            $this->assertTrue($worker->isRunning());
            $this->assertSame(0, Artisan::call('queue:restart'));
            $worker->wait();
            $this->assertSame(0, $worker->getExitCode(), $worker->getErrorOutput());
            app(OutboxService::class)->startQueued($f['actor'], $f['novelId'], $f['payload'], 'after-update');
            app(OutboxService::class)->publish();
            $next = new Process([PHP_BINARY, 'artisan', 'queue:work', 'redis', '--queue=generation', '--stop-when-empty', '--tries=1', '--timeout=60'], base_path(), ['APP_ENV' => 'testing']);
            $next->setTimeout(15)->run();
            $this->assertSame(0, $next->getExitCode(), $next->getErrorOutput());
            $this->assertDatabaseCount('consumer_receipts', 4);
            $this->assertDatabaseCount('generation_artifacts', 4);
        } finally {
            if ($worker?->isRunning()) {
                $worker->stop();
            }
            Cache::forget('illuminate:queue:restart');
            config(['cache.default' => $cacheDefault]);
            Queue::connection('redis')->clear('generation');
            Artisan::call('migrate:fresh', ['--force' => true]);
        }
    }

    public function test_redis_restart_loses_notification_but_database_recovers(): void
    {
        $this->assertSame('novel_forge_test', DB::selectOne('SELECT current_database() AS name')->name);
        $socket = base_path('.local/redis-testing.sock');
        $this->assertSame($socket, config('database.redis.default.path'));
        Artisan::call('migrate:fresh', ['--force' => true]);
        $stopped = false;
        $start = function () use ($socket) {
            // 仅重启专用测试 socket，禁用 TCP 与持久化，不修改开发服务。
            (new Process(['redis-server', '--port', '0', '--unixsocket', $socket, '--unixsocketperm', '700', '--dir', base_path('.local/redis-testing'), '--save', '', '--appendonly', 'no', '--daemonize', 'yes']))->mustRun();
            Redis::purge('default');
            // daemonize 返回不表示 socket 已就绪，使用有界健康检查而非猜测延迟。
            for ($i = 0; $i < 100; $i++) {
                $ping = new Process(['redis-cli', '-s', $socket, 'ping']);
                $ping->run();
                if ($ping->isSuccessful() && trim($ping->getOutput()) === 'PONG') {
                    return;
                }
                usleep(10000);
            }
            throw new \RuntimeException('专用测试 Redis 未在限定时间内就绪。');
        };
        try {
            QueuedRunFixture::create();
            Queue::connection('redis')->clear('generation');
            $this->assertSame(2, app(OutboxService::class)->publish()['published']);
            $this->assertSame(2, Queue::connection('redis')->size('generation'));
            (new Process(['redis-cli', '-s', $socket, 'shutdown', 'nosave']))->mustRun();
            $stopped = true;
            $start();
            $stopped = false;
            $this->assertSame(0, Queue::connection('redis')->size('generation'));
            $this->assertDatabaseCount('outbox_events', 2);
            $this->assertDatabaseCount('consumer_receipts', 0);
            DB::table('outbox_events')->update(['next_delivery_at' => DB::raw('clock_timestamp()')]);
            $this->assertSame(0, Artisan::call('novelforge:dispatch'));
            $this->assertSame(2, Queue::connection('redis')->size('generation'));
            // 消费者使用真实独立 PHP Worker，读取已提交的 PostgreSQL 意图。
            $worker = new Process([PHP_BINARY, 'artisan', 'queue:work', 'redis', '--queue=generation', '--stop-when-empty', '--tries=1', '--timeout=60'], base_path(), ['APP_ENV' => 'testing']);
            $worker->setTimeout(30)->run();
            $this->assertSame(0, $worker->getExitCode(), $worker->getErrorOutput());
            $this->assertDatabaseCount('consumer_receipts', 2);
            $this->assertDatabaseCount('generation_artifacts', 2);
            $this->assertSame('succeeded', DB::table('generation_runs')->value('status'));
            $this->assertSame(60, (new ConsumeGenerationIntent('1'))->timeout);
        } finally {
            if ($stopped) {
                $start();
            }
            Queue::connection('redis')->clear('generation');
            Artisan::call('migrate:fresh', ['--force' => true]);
        }
    }
}
