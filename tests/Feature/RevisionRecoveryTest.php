<?php

namespace Tests\Feature;

use App\Revision\RevisionExecutor;
use App\Services\GenerationService;
use App\Services\OutboxService;
use App\Services\RevisionPipelineService;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Symfony\Component\Process\Process;
use Tests\Support\RevisionFixture as F;
use Tests\TestCase;

/** 进程重启复用已核销响应，文学轮次、提案和请求均不重复。 */
class RevisionRecoveryTest extends TestCase
{
    public function test_another_worker_recovers_saved_revision_response_once_without_adopting_or_spending_another_round(): void
    {
        $this->assertSame('novel_forge_test', DB::selectOne('SELECT current_database() AS n')->n);
        Artisan::call('migrate:fresh', ['--force' => true]);
        try {
            $f = F::create();
            $t = F::task($f);
            $p = F::output($f);
            $route = F::route($f, $p);
            $run = app(RevisionPipelineService::class)->start($f['actor'], $f['novel'], $t['id'], ['route_version_id' => $route, 'billing' => $f['billing'], 'offline_output' => $p, 'reason' => '进程中断后恢复补丁响应'], 'recover');
            $claim = app(GenerationService::class)->claim($f['actor'], $f['novel'], $run['run_id'], 'revise');
            $result = (new RevisionExecutor)->execute($claim['input']);
            $this->assertSame('succeeded', $result->status);
            $this->assertDatabaseCount('ai_requests', 2);
            $this->assertDatabaseCount('revision_proposals', 0);
            DB::table('generation_steps')->where('id', $claim['id'])->update(['lease_until' => DB::raw("clock_timestamp() - interval '1 second'")]);
            $event = (string) DB::table('outbox_events')->where('run_id', $run['run_id'])->value('id');
            $w = new Process([PHP_BINARY, base_path('tests/Support/outbox-worker.php'), 'consume', $event], base_path(), ['APP_ENV' => 'testing']);
            $w->setTimeout(30)->run();
            $this->assertSame(0, $w->getExitCode(), $w->getOutput().$w->getErrorOutput());
            $data = app(GenerationService::class)->read($f['actor'], $f['novel'], $run['run_id']);
            $this->assertSame('succeeded', $data['run']['status'], json_encode($data['steps']));
            app(OutboxService::class)->consume($event);
            $this->assertDatabaseCount('revision_proposals', 1);
            $this->assertDatabaseCount('revision_tasks', 1);
            $this->assertDatabaseCount('chapter_versions', 1);
            $this->assertDatabaseCount('ai_requests', 2);
            $this->assertSame(1, DB::table('consumer_receipts')->where('event_id', $event)->count());
            $this->assertSame(1, DB::table('revision_tasks')->where('id', $t['id'])->value('automatic_round'));
            $this->assertDatabaseCount('story_events', 0);
        } finally {
            Artisan::call('migrate:fresh', ['--force' => true]);
        }
    }
}
