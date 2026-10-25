<?php

namespace Tests\Feature;

use App\Extraction\ExtractionExecutor;
use App\Services\ExtractionService;
use App\Services\GenerationService;
use App\Services\OutboxService;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Symfony\Component\Process\Process;
use Tests\Support\ExtractionFixture as F;
use Tests\Support\ExtractionPipelineFixture;
use Tests\TestCase;

/** 独立PHP进程修正误提取及重放响应，验证持久幂等，不重新生成正文或模型请求。 */
class ExtractionRecoveryTest extends TestCase
{
    public function test_another_process_reextracts_original_body_and_replays_committed_response_once(): void
    {
        $this->assertSame('novel_forge_test', DB::selectOne('SELECT current_database() AS n')->n);
        Artisan::call('migrate:fresh', ['--force' => true]);
        $path = tempnam(sys_get_temp_dir(), 'nf-e03-test-');
        try {
            $f = F::create();
            $bad = F::event($f);
            $bad['evidence']['quote'] = '错误引文';
            $old = F::extract($f, F::output([$bad]));
            $this->assertFalse($old['current_eligible']);
            $request = ['actor' => $f['actor']->id, 'novel' => $f['novel'], 'key' => 'separate-process-reextract', 'payload' => F::payload($f, F::output([F::event($f)]), ['parent_id' => $old['id']])];
            file_put_contents($path, json_encode($request, JSON_THROW_ON_ERROR));
            chmod($path, 0600);
            $last = null;
            for ($i = 0; $i < 2; $i++) {
                $worker = new Process([PHP_BINARY, base_path('tests/Support/extraction-worker.php'), $path], base_path(), ['APP_ENV' => 'testing']);
                $worker->setTimeout(30)->run();
                $this->assertSame(0, $worker->getExitCode(), $worker->getOutput().$worker->getErrorOutput());
                $result = json_decode($worker->getOutput(), true, flags: JSON_THROW_ON_ERROR);
                $this->assertTrue($result['eligible']);
                if ($last) {
                    $this->assertSame($last, $result);
                }
                $last = $result;
            }
            $this->assertDatabaseCount('extraction_attempts', 2);
            $this->assertDatabaseCount('proposed_events', 1);
            $this->assertDatabaseCount('chapter_versions', 1);
            $this->assertDatabaseCount('scene_versions', 1);
            $this->assertDatabaseCount('story_events', 0);
            $this->assertSame(1, DB::table('ai_requests')->where('novel_id', $f['novel'])->count());
            $this->assertSame($f['body']['body_hash'], app(ExtractionService::class)->read($f['actor'], $f['novel'], $last['id'])['body_hash']);
        } finally {
            unlink($path);
            Artisan::call('migrate:fresh', ['--force' => true]);
        }
    }

    /** 已核销响应后失去租约，新Worker只完成一次提取产物和候选事件。 */
    public function test_registered_worker_recovers_saved_gateway_response_without_duplicate_request(): void
    {
        $this->assertSame('novel_forge_test', DB::selectOne('SELECT current_database() AS n')->n);
        Artisan::call('migrate:fresh', ['--force' => true]);
        try {
            $f = F::create();
            $p = F::output([F::event($f)]);
            $route = ExtractionPipelineFixture::route($f, $p);
            $run = ExtractionPipelineFixture::start($f, $p, $route)['run_id'];
            $claim = app(GenerationService::class)->claim($f['actor'], $f['novel'], $run, 'extract');
            $result = (new ExtractionExecutor)->execute($claim['input']);
            $this->assertSame('succeeded', $result->status);
            $this->assertDatabaseCount('extraction_attempts', 0);
            $this->assertSame(2, DB::table('ai_requests')->where('novel_id', $f['novel'])->count());
            DB::table('generation_steps')->where('id', $claim['id'])->update(['lease_until' => DB::raw("clock_timestamp() - interval '1 second'")]);
            $event = (string) DB::table('outbox_events')->where('run_id', $run)->value('id');
            $worker = new Process([PHP_BINARY, base_path('tests/Support/outbox-worker.php'), 'consume', $event], base_path(), ['APP_ENV' => 'testing']);
            $worker->setTimeout(30)->run();
            $this->assertSame(0, $worker->getExitCode(), $worker->getOutput().$worker->getErrorOutput());
            $this->assertDatabaseCount('extraction_attempts', 1);
            $this->assertDatabaseCount('proposed_events', 1);
            $this->assertSame(2, DB::table('ai_requests')->where('novel_id', $f['novel'])->count());
            $this->assertSame('succeeded', DB::table('generation_runs')->where('id', $run)->value('status'));
            app(OutboxService::class)->consume($event);
            $this->assertDatabaseCount('extraction_attempts', 1);
            $this->assertSame(1, DB::table('consumer_receipts')->where('event_id', $event)->count());
        } finally {
            Artisan::call('migrate:fresh', ['--force' => true]);
        }
    }
}
