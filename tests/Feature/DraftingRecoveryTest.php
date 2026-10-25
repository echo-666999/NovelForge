<?php

namespace Tests\Feature;

use App\Drafting\DraftExecutor;
use App\Services\DraftingService;
use App\Services\GenerationService;
use App\Services\OutboxService;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Symfony\Component\Process\Process;
use Tests\Support\DraftingFixture as F;
use Tests\TestCase;

/** 已提交网关响应后的中断由独立 Worker 重领，不重复请求或重复产物。 */
class DraftingRecoveryTest extends TestCase
{
    public function test_new_worker_recovers_saved_response_after_lease_loss_once(): void
    {
        $this->assertSame('novel_forge_test', DB::selectOne('select current_database() as n')->n);
        Artisan::call('migrate:fresh', ['--force' => true]);
        try {
            $f = F::create();
            $run = app(DraftingService::class)->start($f['actor'], $f['novel'], F::payload($f), 'worker-recovery')['run_id'];
            $claim = app(GenerationService::class)->claim($f['actor'], $f['novel'], $run, 'scene');
            $result = (new DraftExecutor)->execute($claim['input']);
            $this->assertSame('succeeded', $result->status);
            $this->assertDatabaseCount('generation_artifacts', 0);
            $this->assertDatabaseCount('scene_versions', 0);
            $this->assertDatabaseCount('ai_requests', 1);
            DB::table('generation_steps')->where('id', $claim['id'])->update(['lease_until' => DB::raw("clock_timestamp() - interval '1 second'")]);
            $event = (string) DB::table('outbox_events')->where('run_id', $run)->value('id');
            $process = new Process([PHP_BINARY, base_path('tests/Support/outbox-worker.php'), 'consume', $event], base_path(), ['APP_ENV' => 'testing']);
            $process->setTimeout(30)->run();
            $this->assertSame(0, $process->getExitCode(), $process->getOutput().$process->getErrorOutput());
            $this->assertDatabaseCount('ai_requests', 1);
            $this->assertDatabaseCount('generation_artifacts', 1);
            $this->assertDatabaseCount('scene_versions', 1);
            app(OutboxService::class)->consume($event);
            $this->assertDatabaseCount('scene_versions', 1);
            $this->assertDatabaseCount('consumer_receipts', 1);
            $this->assertSame('succeeded', DB::table('generation_runs')->where('id', $run)->value('status'));
        } finally {
            Artisan::call('migrate:fresh', ['--force' => true]);
        }
    }
}
