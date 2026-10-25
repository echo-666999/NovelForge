<?php

namespace Tests\Feature;

use App\Exceptions\NovelProblem;
use App\Extraction\ExtractionContract;
use App\Extraction\ExtractionReplayAdapter;
use App\Filament\Pages\ExtractionLedger;
use App\Services\BillingConfigurationService;
use App\Services\GenerationService;
use App\Services\OutboxService;
use App\Services\ProviderGateway;
use App\Services\RecoveryService;
use Filament\Facades\Filament;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Livewire\Livewire;
use Tests\Support\ExtractionFixture as F;
use Tests\Support\ExtractionPipelineFixture as P;
use Tests\TestCase;

/** E03 网关与预算、冻结响应、持久阶段及硬门禁的真实数据库接线测试。 */
class ExtractionPipelineTest extends TestCase
{
    use RefreshDatabase;

    public function test_registered_offline_stage_creates_bound_report_and_blocks_semantic_failure_without_body_change(): void
    {
        $f = F::create();
        $p = F::output([F::event($f)]);
        $route = P::route($f, $p);
        $url = '/api/v1/novels/'.$f['novel'].'/extractions/runs';
        $request = F::payload($f, $p) + ['route_version_id' => $route, 'billing' => $f['billing']];
        $this->postJson($url, $request)->assertUnauthorized();
        $run = $this->actingAs($f['actor'])->withHeader('Idempotency-Key', 'start')->postJson($url, $request)->assertAccepted()->json('data');
        $event = (string) DB::table('outbox_events')->where('run_id', $run['run_id'])->value('id');
        app()->detectEnvironment(fn () => 'local');
        try {
            app(OutboxService::class)->consume($event);
        } finally {
            app()->detectEnvironment(fn () => 'testing');
        }
        app(OutboxService::class)->consume($event);
        $this->assertEquals($run, P::start($f, $p, $route, key: 'start'));
        $data = app(GenerationService::class)->read($f['actor'], $f['novel'], $run['run_id']);
        $this->assertSame('succeeded', $data['run']['status'], json_encode($data['steps']));
        $attempt = DB::table('extraction_attempts')->where('run_id', $run['run_id'])->first();
        $this->assertNotNull($attempt);
        $this->assertSame((string) $data['artifacts'][0]['id'], (string) $attempt->artifact_id);
        $this->assertSame(1, DB::table('extraction_attempts')->count());
        // 数据库也必须阻止借用真实产物引用却替换提取内容。
        $copy = (array) $attempt;
        unset($copy['id']);
        $copy['output'] = json_encode(['forged' => '伪造响应']);
        $copy['output_hash'] = str_repeat('0', 64);
        try {
            DB::transaction(fn () => DB::table('extraction_attempts')->insert($copy));
            $this->fail('不能用原产物绑定另一份响应');
        } catch (QueryException $e) {
            $this->assertStringContainsString('E03 generation origin mismatch', $e->getMessage());
        }
        $this->assertSame(2, DB::table('ai_requests')->where('novel_id', $f['novel'])->count());
        $cfg = $data['run']['frozen_config'];
        $this->assertSame($cfg['context']['final_prompt'], $cfg['configuration']['rendered_prompt']);
        $this->assertSame(hash('sha256', $cfg['configuration']['rendered_prompt']), $cfg['context']['final_prompt_hash']);
        $variables = json_decode($cfg['configuration']['variables']['context'], true, flags: JSON_THROW_ON_ERROR);
        $this->assertSame($f['body']['body'], $variables['body']);
        $this->assertStringContainsString($f['body']['body_hash'], $cfg['context']['final_prompt']);
        $this->assertNotEmpty($variables['approved_definitions']);
        $bad = $p;
        $bad['events'][0]['evidence']['quote'] = '伪造引用';
        Filament::setCurrentPanel(Filament::getPanel('admin'));
        $page = Livewire::test(ExtractionLedger::class)->set('novel', $f['novel'])->set('version', $f['body']['id'])->set('parent', (string) $attempt->id)->set('route', $route)->set('policy', (string) $f['billing']['policy_id'])->set('prices', implode(',', $f['billing']['price_version_ids']))->set('output', json_encode($bad, JSON_THROW_ON_ERROR))->set('reason', '验证误提取只阻塞不重写正文')->call('startPipeline');
        $badRun = ['run_id' => $page->get('run')];
        $this->assertNotEmpty($badRun['run_id'], $page->get('notice'));
        $page->call('replayPipeline')->assertSet('detail.report.eligible', false)->assertSee('运行状态：blocked');
        $badData = app(GenerationService::class)->read($f['actor'], $f['novel'], $badRun['run_id']);
        $this->assertSame('blocked', $badData['run']['status']);
        $this->assertSame('HARD_CONSTRAINT_BLOCKED', $badData['steps'][0]['error_code']);
        $this->assertFalse(json_decode(DB::table('extraction_attempts')->where('run_id', $badRun['run_id'])->value('report'), true)['eligible']);
        $this->assertSame(1, DB::table('outbox_events')->where('run_id', $badRun['run_id'])->count());
        $preview = app(RecoveryService::class)->preview($f['actor'], $f['novel'], $badRun['run_id']);
        $this->assertFalse($preview['rows'][0]['retry_allowed']);
        $decision = app(RecoveryService::class)->execute($f['actor'], $f['novel'], $badRun['run_id'], 'reextract', ['scope' => 'extract', 'reason' => '只修正误提取，不重写正文', 'preview_hash' => $preview['preview_hash']], 'prepare-reextract');
        $this->assertSame('awaiting_offline_response', $decision['outcome']);
        $this->assertSame($f['body']['id'], $decision['source']['chapter_version_id']);
        $this->assertFalse($decision['source']['rewrite_source']);
        $this->assertDatabaseCount('chapter_versions', 1);
        $this->assertDatabaseCount('story_events', 0);
        Http::assertNothingSent();
    }

    public function test_response_substitution_and_zero_budget_are_rejected_before_any_extraction_request(): void
    {
        $f = F::create();
        $p = F::output([F::event($f)]);
        $route = P::route($f, $p);
        $policy = app(BillingConfigurationService::class)->execute($f['actor'], 'policy', ['key' => 'e03-zero', 'expected_version' => 0, 'currency' => 'USD', 'limits' => array_fill_keys(['request', 'chapter', 'run', 'book', 'day'], '0'), 'warning_percent' => 80, 'stop_percent' => 100, 'timezone' => 'UTC', 'reason' => '零预算不能调用'], 'e03-zero');
        $f['billing']['policy_id'] = $policy['id'];
        $run = P::start($f, $p, $route);
        $claim = app(GenerationService::class)->claim($f['actor'], $f['novel'], $run['run_id'], 'extract');
        $cfg = app(GenerationService::class)->read($f['actor'], $f['novel'], $run['run_id'])['run']['frozen_config']['configuration'];
        try {
            app(ProviderGateway::class)->execute($f['actor'], $f['novel'], $claim['id'], $cfg['models'][0]['model_version_id'], ['prompt' => $cfg['rendered_prompt'], 'output_schema' => ExtractionContract::schema()], 'extract-events', new ExtractionReplayAdapter(['events' => []]));
            $this->fail('不能替换离线响应');
        } catch (NovelProblem $e) {
            $this->assertSame('EXTRACTION_REPLAY_MISMATCH', $e->errorCode);
        }
        DB::table('generation_steps')->where('id', $claim['id'])->update(['lease_until' => DB::raw("clock_timestamp() - interval '1 second'")]);
        app(OutboxService::class)->consume((string) DB::table('outbox_events')->where('run_id', $run['run_id'])->value('id'));
        $data = app(GenerationService::class)->read($f['actor'], $f['novel'], $run['run_id']);
        $this->assertSame('deferred', $data['run']['status']);
        $this->assertSame('DEFERRED_BUDGET', $data['steps'][0]['error_code']);
        $this->assertSame(1, DB::table('ai_requests')->where('novel_id', $f['novel'])->count());
        $this->assertDatabaseCount('extraction_attempts', 0);
        $preview = app(RecoveryService::class)->preview($f['actor'], $f['novel'], $run['run_id']);
        $this->assertSame('redo', $preview['rows'][0]['disposition']);
        Http::assertNothingSent();
    }
}
