<?php

namespace Tests\Feature;

use App\Exceptions\NovelProblem;
use App\Review\ReviewContract;
use App\Review\ReviewReplayAdapter;
use App\Services\BillingConfigurationService;
use App\Services\GenerationService;
use App\Services\OutboxService;
use App\Services\ProviderGateway;
use App\Services\RecoveryService;
use App\Services\ReviewPipelineService;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Tests\Support\ExtractionFixture;
use Tests\Support\ReviewFixture as F;
use Tests\TestCase;

/** 注册评审阶段接线、冻结证据、预算与失败分类的真实数据库验证。 */
class ReviewPipelineTest extends TestCase
{
    use RefreshDatabase;

    private function request(array $f, array $p, string $route): array
    {
        $d = F::payload($f, $p);
        unset($d['reviewer_type']);

        return $d + ['route_version_id' => $route, 'billing' => array_replace($f['billing'], ['category' => 'review'])];
    }

    public function test_registered_stage_uses_same_style_frozen_prompt_exact_artifact_and_no_technical_retry_for_low_scores(): void
    {
        $f = F::create();
        $p = F::output($f);
        $route = F::route($f, $p);
        $d = $this->request($f, $p, $route);
        $s = app(ReviewPipelineService::class);
        $url = '/api/v1/novels/'.$f['novel'].'/reviews/runs';
        $run = $this->actingAs($f['actor'])->withHeader('Idempotency-Key', 'start')->postJson($url, $d)->assertAccepted()->json('data');
        $event = (string) DB::table('outbox_events')->where('run_id', $run['run_id'])->value('id');
        app()->detectEnvironment(fn () => 'local');
        try {
            app(OutboxService::class)->consume($event);
        } finally {
            app()->detectEnvironment(fn () => 'testing');
        }
        app(OutboxService::class)->consume($event);
        $this->assertSame($run, $s->start($f['actor'], $f['novel'], $d, 'start'));
        $data = app(GenerationService::class)->read($f['actor'], $f['novel'], $run['run_id']);
        $this->assertSame('succeeded', $data['run']['status'], json_encode($data['steps']));
        $cfg = $data['run']['frozen_config'];
        $this->assertSame($f['style'], $cfg['configuration']['style']['id']);
        $this->assertSame($cfg['context']['final_prompt'], $cfg['configuration']['rendered_prompt']);
        $this->assertSame(hash('sha256', $cfg['context']['final_prompt']), $cfg['context']['final_prompt_hash']);
        $variables = json_decode($cfg['configuration']['variables']['context'], true);
        $this->assertSame($f['body']['body'], $variables['context']['body']);
        $this->assertSame($f['extraction']['id'], $variables['extraction_id']);
        $this->assertDatabaseCount('reviews', 1);
        $row = DB::table('reviews')->first();
        $this->assertSame((string) $data['artifacts'][0]['id'], (string) $row->artifact_id);
        $copy = (array) $row;
        unset($copy['id']);
        $copy['output'] = '{}';
        $copy['output_hash'] = str_repeat('0', 64);
        try {
            DB::transaction(fn () => DB::table('reviews')->insert($copy));
            $this->fail('不能借用真实产物伪造评审');
        } catch (QueryException $e) {
            $this->assertStringContainsString('E04 generation origin mismatch', $e->getMessage());
        }
        $p = F::output($f, 2);
        $blocked = $s->start($f['actor'], $f['novel'], $this->request($f, $p, $route), 'low');
        app(OutboxService::class)->consume((string) DB::table('outbox_events')->where('run_id', $blocked['run_id'])->value('id'));
        $bad = app(GenerationService::class)->read($f['actor'], $f['novel'], $blocked['run_id']);
        $this->assertSame('blocked', $bad['run']['status']);
        $this->assertSame('LITERARY_REVIEW_BLOCKED', $bad['steps'][0]['error_code']);
        $preview = app(RecoveryService::class)->preview($f['actor'], $f['novel'], $blocked['run_id']);
        $this->assertFalse($preview['rows'][0]['retry_allowed']);
        try {
            app(GenerationService::class)->claim($f['actor'], $f['novel'], $blocked['run_id'], 'review', 1);
            $this->fail('不得技术重试凑分');
        } catch (NovelProblem $e) {
            $this->assertSame('REVIEW_RETRY_FORBIDDEN', $e->errorCode);
        }
        $this->assertDatabaseCount('chapter_versions', 1);
        $this->assertDatabaseCount('story_events', 0);
        Http::assertNothingSent();
    }

    public function test_response_substitution_zero_budget_and_stale_extraction_keep_evidence_without_fake_checkpoint(): void
    {
        $f = F::create();
        $p = F::output($f);
        $route = F::route($f, $p);
        $d = $this->request($f, $p, $route);
        $policy = app(BillingConfigurationService::class)->execute($f['actor'], 'policy', ['key' => 'review-zero', 'expected_version' => 0, 'currency' => 'USD', 'limits' => array_fill_keys(['request', 'chapter', 'run', 'book', 'day'], '0'), 'warning_percent' => 80, 'stop_percent' => 100, 'timezone' => 'UTC', 'reason' => '零预算不能执行评审请求'], 'zero');
        $d['billing']['policy_id'] = $policy['id'];
        $run = app(ReviewPipelineService::class)->start($f['actor'], $f['novel'], $d, 'zero-run');
        $claim = app(GenerationService::class)->claim($f['actor'], $f['novel'], $run['run_id'], 'review');
        $cfg = app(GenerationService::class)->read($f['actor'], $f['novel'], $run['run_id'])['run']['frozen_config']['configuration'];
        try {
            app(ProviderGateway::class)->execute($f['actor'], $f['novel'], $claim['id'], $cfg['models'][0]['model_version_id'], ['prompt' => $cfg['rendered_prompt'], 'output_schema' => ReviewContract::schema()], 'review-chapter', new ReviewReplayAdapter([]));
            $this->fail('不能替换冻结响应');
        } catch (NovelProblem $e) {
            $this->assertSame('REVIEW_REPLAY_MISMATCH', $e->errorCode);
        }
        // 零预算由持久编排分类为deferred，不伪造成功响应或事件检查点。
        DB::table('generation_steps')->where('id', $claim['id'])->update(['lease_until' => DB::raw("clock_timestamp() - interval '1 second'")]);
        app(OutboxService::class)->consume((string) DB::table('outbox_events')->where('run_id', $run['run_id'])->value('id'));
        $data = app(GenerationService::class)->read($f['actor'], $f['novel'], $run['run_id']);
        $this->assertSame('deferred', $data['run']['status']);
        $this->assertNull($data['steps'][0]['checkpoint_artifact_id']);
        $this->assertDatabaseCount('reviews', 0);
        $this->assertSame(1, DB::table('ai_requests')->count());
        ExtractionFixture::extract($f, ExtractionFixture::output([ExtractionFixture::event($f)]));
        $preview = app(RecoveryService::class)->preview($f['actor'], $f['novel'], $run['run_id']);
        $this->assertSame('stale', $preview['rows'][0]['disposition']);
        Http::assertNothingSent();
    }
}
