<?php

namespace Tests\Feature;

use App\Jobs\InfrastructureProbe;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Redis;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Symfony\Component\Process\Process;
use Tests\TestCase;

class TestIsolationTest extends TestCase
{
    public function test_actual_database_and_all_resource_routes_are_isolated(): void
    {
        $this->assertSame('novel_forge_test', DB::selectOne('SELECT current_database() AS name')->name);
        $this->assertSame(['pgsql'], array_keys(config('database.connections')));
        $this->assertSame('array', config('cache.default'));
        $this->assertSame('sync', config('queue.default'));
        $this->assertSame('pgsql', config('queue.failed.database'));
        $this->assertSame('pgsql', config('queue.batching.database'));
        $this->assertSame('pgsql', config('queue.connections.database.connection'));
        $this->assertSame('array', config('session.driver'));
        $this->assertSame(base_path('storage/testing'), storage_path());
        $this->assertSame([], config('filesystems.links'));
        $this->assertArrayNotHasKey('s3', config('filesystems.disks'));
        $this->assertArrayNotHasKey('sqs', config('queue.connections'));
        $this->assertSame(base_path('.local/redis-testing.sock'), config('database.redis.default.path'));
        $this->assertArrayNotHasKey('url', config('database.redis.default'));
    }

    public function test_files_and_file_cache_use_only_testing_storage(): void
    {
        $name = 'isolation-'.Str::uuid().'.txt';
        try {
            foreach (['local' => 'private', 'public' => 'public'] as $disk => $directory) {
                Storage::disk($disk)->put($name, 'test');
                $this->assertSame('test', Storage::disk($disk)->get($name));
                $this->assertFileExists(base_path('storage/testing/app/'.$directory.'/'.$name));
                $this->assertFileDoesNotExist(base_path('storage/app/'.$directory.'/'.$name));
            }
            Cache::store('file')->put($name, 'isolated', 60);
            $this->assertSame('isolated', Cache::store('file')->get($name));
            $this->assertSame(storage_path('framework/cache/data'), config('cache.stores.file.path'));
        } finally {
            Storage::disk('local')->delete($name);
            Storage::disk('public')->delete($name);
            Cache::store('file')->forget($name);
        }
    }

    public function test_artisan_rejects_url_bypass_before_any_database_connection(): void
    {
        $process = new Process([PHP_BINARY, 'artisan', 'list', '--env=testing'], base_path(), [
            'APP_ENV' => 'testing', 'DB_CONNECTION' => 'pgsql', 'DB_DATABASE' => 'novel_forge_test',
            'DB_URL' => 'postgresql://probe:dummy@127.0.0.1:1/not_allowed',
        ]);
        $process->run();
        $output = $process->getOutput().$process->getErrorOutput();
        $this->assertFalse($process->isSuccessful());
        $this->assertStringContainsString('Refusing unsafe testing database configuration', $output);
        $this->assertStringNotContainsString('dummy', $output);
        $this->assertStringNotContainsString('SQLSTATE', $output);
    }

    public function test_unverifiable_database_fails_closed_without_credentials_in_output(): void
    {
        $process = new Process([PHP_BINARY, 'artisan', 'list', '--env=testing'], base_path(), [
            'APP_ENV' => 'testing', 'DB_URL' => '',
            'DB_USERNAME' => 'novelforge_nonexistent_isolation_probe', 'DB_PASSWORD' => 'probe_not_a_secret',
        ]);
        $process->run();
        $output = $process->getOutput().$process->getErrorOutput();
        $this->assertFalse($process->isSuccessful());
        $this->assertStringContainsString('Cannot verify the isolated testing database', $output);
        $this->assertStringNotContainsString('probe_not_a_secret', $output);
    }

    public function test_conflicting_environment_is_rejected(): void
    {
        $process = new Process([PHP_BINARY, 'artisan', 'list', '--env=testing'], base_path(), [
            'APP_ENV' => 'local',
        ]);
        $process->run();
        $this->assertFalse($process->isSuccessful());
        $this->assertStringContainsString('Testing environment conflicts', $process->getOutput().$process->getErrorOutput());
    }

    public function test_cached_configuration_is_rejected_without_loading_it(): void
    {
        $file = storage_path('cached-config-probe.php');
        file_put_contents($file, '<?php throw new RuntimeException("CACHE_WAS_LOADED");');
        try {
            $process = new Process([PHP_BINARY, 'artisan', 'list', '--env', 'testing'], base_path(), [
                'APP_CONFIG_CACHE' => $file, 'APP_ENV' => 'local',
            ]);
            $process->run();
            $output = $process->getOutput().$process->getErrorOutput();
            $this->assertFalse($process->isSuccessful());
            $this->assertStringContainsString('Testing refuses cached configuration', $output);
            $this->assertStringNotContainsString('CACHE_WAS_LOADED', $output);
        } finally {
            unlink($file);
        }
    }

    public function test_external_resource_settings_cannot_redirect_testing(): void
    {
        $code = <<<'PHP'
        require 'vendor/autoload.php';
        $app = require 'bootstrap/app.php';
        $app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
        echo json_encode([
            config('queue.default'), config('cache.default'), config('filesystems.default'),
            config('database.redis.default.path'), config('database.redis.default.url'),
            config('session.driver'), config('view.compiled'),
        ]);
        PHP;
        $process = new Process([PHP_BINARY, '-r', $code], base_path(), [
            'APP_ENV' => 'testing', 'DB_URL' => '',
            'REDIS_URL' => 'tcp://127.0.0.1:1?database=0',
            'QUEUE_CONNECTION' => 'sqs', 'CACHE_STORE' => 'redis', 'FILESYSTEM_DISK' => 's3',
            'SESSION_DRIVER' => 'redis', 'VIEW_COMPILED_PATH' => '/not_allowed',
        ]);
        $process->mustRun();
        $this->assertSame([
            'sync', 'array', 'local', base_path('.local/redis-testing.sock'), null,
            'array', storage_path('framework/views'),
        ], json_decode($process->getOutput(), true, flags: JSON_THROW_ON_ERROR));
    }

    public function test_real_redis_and_explicit_queue_use_dedicated_process(): void
    {
        if (! is_file(base_path('.local/redis-testing.sock')) && ! file_exists(base_path('.local/redis-testing.sock'))) {
            $this->markTestSkipped('Start scripts/start-testing-redis.sh for Redis integration.');
        }
        $token = (string) Str::uuid();
        $queue = 'isolation-'.$token;
        try {
            Redis::set('isolation-'.$token, 'ok');
            $this->assertSame('ok', Redis::get('isolation-'.$token));
            Cache::store('redis')->put('isolation-'.$token, 'cached', 60);
            $this->assertSame('cached', Cache::store('redis')->get('isolation-'.$token));
            Queue::connection('redis')->push((new InfrastructureProbe($token))->onQueue($queue), '', $queue);
            $this->assertSame(1, Queue::connection('redis')->size($queue));
            $this->assertSame(0, Artisan::call('queue:work', ['connection' => 'redis', '--queue' => $queue, '--once' => true, '--tries' => 1]));
            $this->assertSame('ok', Cache::pull('novelforge-probe:'.$token));
            $this->assertSame(0, Queue::connection('redis')->size($queue));
        } finally {
            Redis::del('isolation-'.$token);
            Cache::store('redis')->forget('isolation-'.$token);
            Queue::connection('redis')->clear($queue);
        }
    }
}
