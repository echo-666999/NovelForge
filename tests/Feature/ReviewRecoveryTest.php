<?php

namespace Tests\Feature;

use App\Review\ReviewExecutor;
use App\Services\GenerationService;
use App\Services\OutboxService;
use App\Services\ReviewPipelineService;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Symfony\Component\Process\Process;
use Tests\Support\ReviewFixture as F;
use Tests\TestCase;

/** 独立进程恢复已核销评审响应，只写一次评审，不重复请求。 */
class ReviewRecoveryTest extends TestCase
{
    public function test_registered_review_recovers_saved_response_after_worker_lease_expires(): void
    {
        $this->assertSame('novel_forge_test', DB::selectOne('select current_database() as n')->n);
        Artisan::call('migrate:fresh', ['--force' => true]);
        try {
            $f = F::create();
            $p = F::output($f);
            $route = F::route($f, $p);
            $d = F::payload($f, $p);
            unset($d['reviewer_type']);
            $d += ['route_version_id' => $route, 'billing' => array_replace($f['billing'], ['category' => 'review'])];
            $run = app(ReviewPipelineService::class)->start($f['actor'], $f['novel'], $d, 'recovery');
            $claim = app(GenerationService::class)->claim($f['actor'], $f['novel'], $run['run_id'], 'review');
            $result = (new ReviewExecutor)->execute($claim['input']);
            $this->assertSame('succeeded', $result->status);
            $this->assertDatabaseCount('reviews', 0);
            $this->assertDatabaseCount('ai_requests', 2);
            DB::table('generation_steps')->where('id', $claim['id'])->update(['lease_until' => DB::raw("clock_timestamp() - interval '1 second'")]);
            $event = (string) DB::table('outbox_events')->where('run_id', $run['run_id'])->value('id');
            $worker = new Process([PHP_BINARY, base_path('tests/Support/outbox-worker.php'), 'consume', $event], base_path(), ['APP_ENV' => 'testing']);
            $worker->setTimeout(30)->run();
            $this->assertSame(0, $worker->getExitCode(), $worker->getOutput().$worker->getErrorOutput());
            $state = app(GenerationService::class)->read($f['actor'], $f['novel'], $run['run_id']);
            $this->assertSame('succeeded', $state['run']['status'], json_encode($state['steps'], JSON_UNESCAPED_UNICODE));
            $this->assertDatabaseCount('reviews', 1);
            $this->assertDatabaseCount('ai_requests', 2);
            $this->assertSame('succeeded', DB::table('generation_runs')->where('id', $run['run_id'])->value('status'));
            app(OutboxService::class)->consume($event);
            $this->assertDatabaseCount('reviews', 1);
            $this->assertSame(1, DB::table('consumer_receipts')->where('event_id', $event)->count());
            $this->assertDatabaseCount('story_events', 0);
        } finally {
            Artisan::call('migrate:fresh', ['--force' => true]);
        }
    }
}
