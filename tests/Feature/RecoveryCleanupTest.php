<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use RuntimeException;
use Symfony\Component\Process\Process;
use Tests\Support\PlanningFixture;
use Tests\Support\QueuedRunFixture;
use Tests\Support\RecoveryCleanup;
use Tests\Support\RecoveryCleanupFailure;
use Tests\Support\RecoveryEnvironment;
use Tests\TestCase;

/** 实际PostgreSQL重置与后续夹具验证；注入的Redis故障不接触任何开发服务。 */
class RecoveryCleanupTest extends TestCase
{
    public function test_redis_cleanup_failure_preserves_primary_and_allows_the_next_outbox_fixture(): void
    {
        RecoveryEnvironment::resetDatabase();
        $original = new RuntimeException('NF_UI16_PRIMARY');
        $cleanupError = new RuntimeException('NF_UI16_INJECTED_REDIS_FAILURE');
        $children = [];
        $cache = config('cache.default');
        $cleanup = new RecoveryCleanup(fn () => null);
        try {
            try {
                $cleanup->run(function () use ($original, &$children) {
                    $worker = new Process([PHP_BINARY, '-r', 'fwrite(STDOUT, "ready\\n"); fflush(STDOUT); sleep(30);']);
                    $children[] = $worker;
                    $worker->start();
                    $this->assertTrue($worker->waitUntil(fn ($type, $output) => str_contains($output, 'ready')));
                    User::factory()->create(['is_admin' => true]);
                    config(['cache.default' => 'file']);
                    throw $original;
                }, [
                    'workers' => function () use (&$children) {
                        RecoveryCleanup::stopProcesses($children);
                    },
                    'generation_queue' => fn () => throw $cleanupError,
                    'cache_config' => fn () => config(['cache.default' => $cache]),
                    'database_reset' => fn () => RecoveryEnvironment::resetDatabase(),
                ]);
                $this->fail('应保留本体失败');
            } catch (RuntimeException $caught) {
                $this->assertSame($original, $caught);
            }
            $this->assertSame($cleanupError, $cleanup->failures()['generation_queue']);
            $this->assertFalse($children[0]->isRunning());
            $this->assertSame($cache, config('cache.default'));
            $this->assertDatabaseCount('users', 0);
            QueuedRunFixture::create();
            $this->assertDatabaseCount('users', 1);
            $this->assertDatabaseCount('generation_runs', 1);
            Http::assertNothingSent();
        } finally {
            RecoveryCleanup::stopProcesses($children);
            config(['cache.default' => $cache]);
            RecoveryEnvironment::resetDatabase();
        }
    }

    public function test_cleanup_only_error_is_visible_and_allows_the_next_planning_fixture(): void
    {
        RecoveryEnvironment::resetDatabase();
        $cleanupError = new RuntimeException('NF_UI16_INJECTED_REDIS_FAILURE');
        try {
            try {
                (new RecoveryCleanup)->run(fn () => User::factory()->create(['is_admin' => true]), [
                    'memory_queue' => fn () => throw $cleanupError,
                    'database_reset' => fn () => RecoveryEnvironment::resetDatabase(),
                ]);
                $this->fail('收尾失败不能报告成功');
            } catch (RecoveryCleanupFailure $caught) {
                $this->assertSame($cleanupError, $caught->getPrevious());
            }
            $this->assertDatabaseCount('users', 0);
            PlanningFixture::create();
            $this->assertDatabaseCount('users', 1);
            Http::assertNothingSent();
        } finally {
            RecoveryEnvironment::resetDatabase();
        }
    }

    public function test_database_and_redis_redirects_are_rejected_before_resetting_fixtures(): void
    {
        RecoveryEnvironment::resetDatabase();
        User::factory()->create(['is_admin' => true]);
        try {
            foreach (['database.connections.pgsql.database' => 'not_allowed', 'database.redis.default.path' => '/not_allowed'] as $key => $unsafe) {
                $before = config($key);
                try {
                    config([$key => $unsafe]);
                    try {
                        $key === 'database.connections.pgsql.database' ? RecoveryEnvironment::resetDatabase() : RecoveryEnvironment::assertReady();
                        $this->fail('必须拒绝未经批准的目标');
                    } catch (RuntimeException $caught) {
                        $this->assertMatchesRegularExpression('/unsafe|unapproved/', $caught->getMessage());
                    }
                } finally {
                    config([$key => $before]);
                }
                $this->assertDatabaseCount('users', 1);
            }
        } finally {
            RecoveryEnvironment::resetDatabase();
        }
    }

    /** 子PHPUnit证明断言仍分类为failure；纯收尾失败则为error，均非零退出。 */
    public function test_phpunit_preserves_original_failure_classification_and_reports_cleanup_only_errors(): void
    {
        foreach (['test_original_assertion_remains_a_failure' => 'failure', 'test_cleanup_only_failure_is_an_error' => 'error'] as $method => $kind) {
            $report = storage_path('app/private/ui16-'.Str::uuid().'.xml');
            try {
                $process = new Process([PHP_BINARY, base_path('vendor/bin/phpunit'), '--no-configuration', '--bootstrap', base_path('vendor/autoload.php'), base_path('tests/Support/Probes/RecoveryFailureProbeTest.php'), '--filter='.$method, '--log-junit='.$report], base_path());
                $process->setTimeout(15)->run();
                $this->assertNotSame(0, $process->getExitCode());
                $dom = new \DOMDocument;
                $dom->load($report);
                $this->assertSame(1, $dom->getElementsByTagName($kind)->length);
                if ($kind === 'failure') {
                    $this->assertStringContainsString('NF_UI16_PRIMARY_ASSERTION', $dom->textContent);
                    $output = $process->getOutput().$process->getErrorOutput();
                    $this->assertStringContainsString('[NF-UI16 cleanup] redis:RuntimeException', $output);
                    $this->assertStringNotContainsString('NF_UI16_REDIS_DIAGNOSTIC_SECRET', $output);
                }
            } finally {
                if (file_exists($report)) {
                    unlink($report);
                }
            }
        }
    }
}
