<?php

namespace Tests\Feature;

use App\Services\RunCenterService;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Symfony\Component\Process\Process;
use Tests\Support\QueuedRunFixture;
use Tests\TestCase;

/** 实际独立进程在页面读取之外推进，返回页面仍从数据库看见进度和同一追踪链。 */
class RunCenterConcurrencyTest extends TestCase
{
    public function test_independent_worker_progress_and_honest_historical_trace_backfill(): void
    {
        $this->assertSame('novel_forge_test', DB::selectOne('SELECT current_database() AS name')->name);
        Artisan::call('migrate:fresh', ['--force' => true]);
        $process = null;
        try {
            $f = QueuedRunFixture::create();
            $url = '/api/v1/novels/'.$f['novelId'].'/runs/'.$f['runId'];
            $before = $this->actingAs($f['actor'])->getJson($url)->assertOk()->json('data');
            $this->assertCount(0, $before['steps']);
            $process = new Process([PHP_BINARY, base_path('tests/Support/generation-worker.php'), (string) $f['actor']->id, $f['novelId'], 'advance', $f['runId'], 'first'], base_path(), ['APP_ENV' => 'testing']);
            $process->run();
            $this->assertSame(0, $process->getExitCode(), $process->getErrorOutput());
            $after = $this->getJson($url)->assertOk()->json('data');
            $this->assertSame('succeeded', $after['steps'][0]['status']);
            $this->assertSame($before['trace_id'], $after['artifacts'][0]['trace_id']);
            $this->assertTrue(DB::table('audit_events')->where('trace_id', $before['trace_id'])->where('object_type', 'generation_artifacts')->exists());
            // 只在隔离测试库验证有历史运行时的迁移；不伪造升级前审计时间。
            // 回滚到 C07 之前，而不是假设 C07 永远是最后一条迁移。
            $steps = DB::table('migrations')->where('migration', '>=', '2026_10_24_000012_create_run_observability')->count();
            $this->assertGreaterThanOrEqual(1, $steps);
            $this->assertSame(0, Artisan::call('migrate:rollback', ['--step' => $steps, '--force' => true]));
            $this->assertSame(0, Artisan::call('migrate', ['--force' => true]));
            $backfill = app(RunCenterService::class)->detail($f['actor'], $f['novelId'], $f['runId']);
            $this->assertSame('historical_backfill', $backfill['trace_origin']);
            $this->assertDatabaseCount('audit_events', 0);
            $this->assertSame($after['artifacts'][0]['content_hash'], $backfill['artifacts'][0]['content_hash']);
        } finally {
            if ($process?->isRunning()) {
                $process->stop();
            }
            Artisan::call('migrate:fresh', ['--force' => true]);
        }
    }
}
