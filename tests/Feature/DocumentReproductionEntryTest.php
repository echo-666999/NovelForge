<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Component\Process\Process;
use Tests\Support\RecoveryCleanup;
use Tests\Support\RecoveryEnvironment;
use Tests\TestCase;

/** UI19真实子入口拒绝测试；只使用批准测试库的独立cache标记。 */
class DocumentReproductionEntryTest extends TestCase
{
    private function entry(array $environment = []): Process
    {
        $process = new Process([PHP_BINARY, base_path('docs/ui/NF-UI16/scripts/before-repro.php')], base_path(), array_replace(['APP_ENV' => 'testing'], $environment));
        $process->setTimeout(15)->run();

        return $process;
    }

    /** 标记不参与应用缓存读取；若发生migrate:fresh就会消失，不能只凭错误文案判断。 */
    private function withMarker(callable $action): void
    {
        RecoveryEnvironment::assertDatabase();
        $key = 'ui19-'.Str::uuid();
        $users = DB::table('users')->count();
        DB::table('cache')->insert(['key' => $key, 'value' => 'preserve', 'expiration' => 2000000000]);
        (new RecoveryCleanup)->run(function () use ($action, $key, $users) {
            $action();
            $this->assertSame('preserve', DB::table('cache')->where('key', $key)->value('value'));
            $this->assertSame($users, DB::table('users')->count());
        }, ['marker' => fn () => DB::table('cache')->where('key', $key)->delete()]);
    }

    private function assertRefused(Process $process, string $code, array $secrets = []): void
    {
        $this->assertSame(1, $process->getExitCode());
        $this->assertSame('', $process->getOutput());
        $error = $process->getErrorOutput();
        $this->assertStringStartsWith($code.'：', $error);
        foreach (['Stack trace', 'SQLSTATE', 'postgresql://', base_path(), ...$secrets] as $forbidden) {
            $this->assertStringNotContainsString($forbidden, $error);
        }
    }

    /** 如已有私有实例则只借用；否则仅启动本测试拥有的port0实例并自行停止。 */
    public function test_private_redis_presence_refuses_with_nonzero_exit_without_resetting_database(): void
    {
        $owned = null;
        try {
            if (! file_exists(base_path('.local/redis-testing.sock'))) {
                $owned = new Process(['bash', base_path('scripts/start-testing-redis.sh')], base_path());
                $owned->setTimeout(10)->start();
                $this->assertTrue($owned->waitUntil(fn ($type, $output) => str_contains($output, 'Ready to accept connections unix')));
            }
            RecoveryEnvironment::assertReady();
            $this->withMarker(fn () => $this->assertRefused($this->entry(), 'NF_REPRO_REFUSED'));
        } finally {
            if ($owned?->isRunning()) {
                $owned->stop();
            }
        }
    }

    public static function unsafeUrls(): array
    {
        return [
            'wrong database' => ['postgresql://probe:UI19-secret-marker@127.0.0.1:5432/not_allowed'],
            'query overrides database' => ['postgresql://probe:UI19-secret-marker@127.0.0.1:5432/novel_forge_test?database=not_allowed'],
            'foreign port' => ['postgresql://probe:UI19-secret-marker@127.0.0.1:1/novel_forge_test'],
        ];
    }

    #[DataProvider('unsafeUrls')]
    public function test_unsafe_database_url_is_rejected_without_parameters_or_trace(string $url): void
    {
        $this->withMarker(fn () => $this->assertRefused($this->entry(['DB_URL' => $url]), 'NF_REPRO_FAILED', ['UI19-secret-marker', 'not_allowed']));
    }

    public function test_failed_authentication_on_approved_database_does_not_expose_credentials(): void
    {
        $this->withMarker(fn () => $this->assertRefused($this->entry([
            'DB_URL' => '', 'DB_USERNAME' => 'ui19_nonexistent_probe', 'DB_PASSWORD' => 'UI19-auth-secret-marker',
        ]), 'NF_REPRO_FAILED', ['ui19_nonexistent_probe', 'UI19-auth-secret-marker']));
    }

    /** 非testing分支显式固定数据库和array资源，不能误触开发库或开发Redis。 */
    public function test_non_testing_environment_refuses_without_mutating_test_database(): void
    {
        $this->withMarker(fn () => $this->assertRefused($this->entry([
            'APP_ENV' => 'local', 'DB_CONNECTION' => 'pgsql', 'DB_DATABASE' => 'novel_forge_test', 'DB_URL' => '',
            'CACHE_STORE' => 'array', 'SESSION_DRIVER' => 'array', 'QUEUE_CONNECTION' => 'sync',
        ]), 'NF_REPRO_REFUSED'));
    }

    public function test_cached_config_is_rejected_without_executing_or_echoing_it(): void
    {
        $token = (string) Str::uuid();
        $file = storage_path('app/private/ui19-config-'.$token.'.php');
        $marker = storage_path('app/private/ui19-executed-'.$token);
        file_put_contents($file, '<?php /* 此配置拒绝探针绝不能被执行。 */ file_put_contents('.var_export($marker, true).', "executed"); echo "UI19-cache-secret-marker";');
        try {
            $this->withMarker(fn () => $this->assertRefused($this->entry(['APP_CONFIG_CACHE' => $file]), 'NF_REPRO_FAILED', ['UI19-cache-secret-marker', $file]));
            $this->assertFileDoesNotExist($marker);
        } finally {
            foreach ([$file, $marker] as $path) {
                if (file_exists($path)) {
                    unlink($path);
                }
            }
        }
    }
}
