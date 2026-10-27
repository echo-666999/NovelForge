<?php

namespace Tests\Support;

use App\Support\TestIsolation;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use RuntimeException;
use Symfony\Component\Process\Process;
use Throwable;

/** 仅校验批准测试目标；不自动启动Redis，也不回退开发连接。 */
final class RecoveryEnvironment
{
    public static function assertDatabase(): void
    {
        if (! app()->environment('testing') || config('database.default') !== 'pgsql') {
            throw new RuntimeException('Recovery tests require the isolated testing environment.');
        }
        TestIsolation::database(config('database.connections.pgsql'));
        if (DB::connection('pgsql')->selectOne('SELECT current_database() AS name')->name !== 'novel_forge_test') {
            throw new RuntimeException('Recovery cleanup refuses an unapproved database.');
        }
    }

    public static function assertReady(): void
    {
        self::assertDatabase();
        $socket = base_path('.local/redis-testing.sock');
        foreach (['default' => 0, 'cache' => 1] as $connection => $database) {
            $config = config('database.redis.'.$connection);
            if (($config['scheme'] ?? null) !== 'unix' || ($config['path'] ?? null) !== $socket
                || ($config['database'] ?? null) !== $database || ! empty($config['url']) || ! empty($config['host'])) {
                throw new RuntimeException('Recovery tests refuse an unapproved Redis connection.');
            }
        }
        if (config('queue.connections.redis.connection') !== 'default' || is_link($socket)
            || realpath(dirname($socket)) !== dirname($socket) || ! file_exists($socket)) {
            throw new RuntimeException('Recovery tests require scripts/start-testing-redis.sh before fixtures or migrations.');
        }
        // 固定私有socket且限定超时，健康检查只执行PING。
        try {
            $ping = new Process(['redis-cli', '-s', $socket, 'PING']);
            $ping->setTimeout(3)->mustRun();
            if (trim($ping->getOutput()) !== 'PONG') {
                throw new RuntimeException('Unexpected private Redis health response.');
            }
        } catch (Throwable) {
            throw new RuntimeException('Recovery tests require a healthy private Redis socket; run scripts/start-testing-redis.sh.');
        }
    }

    /** 收尾只依赖PostgreSQL校验，不能因Redis离线而拒绝测试库重置。 */
    public static function resetDatabase(): void
    {
        self::assertDatabase();
        if (Artisan::call('migrate:fresh', ['--database' => 'pgsql', '--force' => true]) !== 0) {
            throw new RuntimeException('Recovery testing database reset failed.');
        }
    }
}
