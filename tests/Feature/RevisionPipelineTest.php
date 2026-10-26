<?php

namespace Tests\Feature;

use App\Exceptions\NovelProblem;
use App\Revision\RevisionContract;
use App\Revision\RevisionReplayAdapter;
use App\Services\BillingConfigurationService;
use App\Services\GenerationService;
use App\Services\OutboxService;
use App\Services\ProviderGateway;
use App\Services\RecoveryService;
use App\Services\RevisionPipelineService;
use App\Services\RevisionService;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Tests\Support\RevisionFixture as F;
use Tests\TestCase;

/** 离线补丁的真实预算、持久阶段与明确选择边界验证。 */
class RevisionPipelineTest extends TestCase
{
    use RefreshDatabase;

    public function test_registered_pipeline_freezes_exact_scope_and_produces_one_proposal_without_adopting_body(): void
    {
        $f = F::create();
        $t = F::task($f);
        $p = F::output($f);
        $route = F::route($f, $p);
        $d = ['route_version_id' => $route, 'billing' => $f['billing'], 'offline_output' => $p, 'reason' => '注册受限补丁离线回放'];
        $url = '/api/v1/novels/'.$f['novel'].'/revisions/'.$t['id'].'/runs';
        $run = $this->actingAs($f['actor'])->withHeader('Idempotency-Key', 'start')->postJson($url, $d)->assertAccepted()->json('data');
        $event = (string) DB::table('outbox_events')->where('run_id', $run['run_id'])->value('id');
        app()->detectEnvironment(fn () => 'local');
        try {
            app(OutboxService::class)->consume($event);
        } finally {
            app()->detectEnvironment(fn () => 'testing');
        }app(OutboxService::class)->consume($event);
        $this->assertSame($run, app(RevisionPipelineService::class)->start($f['actor'], $f['novel'], $t['id'], $d, 'start'));
        $data = app(GenerationService::class)->read($f['actor'], $f['novel'], $run['run_id']);
        $this->assertSame('succeeded', $data['run']['status'], json_encode($data['steps']));
        $cfg = $data['run']['frozen_config'];
        $vars = json_decode($cfg['configuration']['variables']['context'], true);
        $this->assertSame($t['input']['allowed_scope'], $vars['allowed_scope']);
        $this->assertSame($t['input']['protected'], $vars['protected']);
        $this->assertSame($f['style'], $cfg['configuration']['style']['id']);
        $this->assertSame($cfg['context']['final_prompt'], $cfg['configuration']['rendered_prompt']);
        $this->assertSame(hash('sha256', $cfg['context']['final_prompt']), $cfg['context']['final_prompt_hash']);
        $this->assertDatabaseCount('revision_proposals', 1);
        $this->assertDatabaseCount('chapter_versions', 1);
        $this->assertDatabaseCount('revision_decisions', 0);
        $this->assertDatabaseCount('ai_requests', 2);
        $now = app(RevisionService::class)->read($f['actor'], $f['novel'], $t['id']);
        $this->assertSame('awaiting_selection', $now['status']);
        $this->assertSame(1, $now['automatic_round']);
        $preview = app(RecoveryService::class)->preview($f['actor'], $f['novel'], $run['run_id']);
        $this->assertSame(1, $preview['rows'][0]['automatic_revision_rounds']);
        $this->assertTrue($preview['rows'][0]['revision_executor_available']);
        $row = (array) DB::table('revision_proposals')->first();
        unset($row['id']);
        $row['output'] = '{}';
        $row['output_hash'] = str_repeat('0', 64);
        try {
            DB::transaction(fn () => DB::table('revision_proposals')->insert($row));
            $this->fail('不能借用产物伪造补丁');
        } catch (QueryException $e) {
            $this->assertStringContainsString('E05 proposal origin mismatch', $e->getMessage());
        }Http::assertNothingSent();
    }

    public function test_zero_budget_requires_manual_and_frozen_response_cannot_be_substituted(): void
    {
        $f = F::create();
        $t = F::task($f);
        $p = F::output($f);
        $route = F::route($f, $p);
        $zero = app(BillingConfigurationService::class)->execute($f['actor'], 'policy', ['key' => 'revision-zero', 'expected_version' => 0, 'currency' => 'USD', 'limits' => array_fill_keys(['request', 'chapter', 'run', 'book', 'day'], '0'), 'warning_percent' => 80, 'stop_percent' => 100, 'timezone' => 'UTC', 'reason' => '零预算必须人工处理'], 'zero');
        $billing = $f['billing'];
        $billing['policy_id'] = $zero['id'];
        $run = app(RevisionPipelineService::class)->start($f['actor'], $f['novel'], $t['id'], ['route_version_id' => $route, 'billing' => $billing, 'offline_output' => $p, 'reason' => '预算不足不得自动修订'], 'run');
        $claim = app(GenerationService::class)->claim($f['actor'], $f['novel'], $run['run_id'], 'revise');
        $cfg = app(GenerationService::class)->read($f['actor'], $f['novel'], $run['run_id'])['run']['frozen_config']['configuration'];
        try {
            app(ProviderGateway::class)->execute($f['actor'], $f['novel'], $claim['id'], $cfg['models'][0]['model_version_id'], ['prompt' => $cfg['rendered_prompt'], 'output_schema' => RevisionContract::schema()], 'revision-propose', new RevisionReplayAdapter([]));
            $this->fail('不能替换冻结响应');
        } catch (NovelProblem $e) {
            $this->assertSame('REVISION_REPLAY_MISMATCH', $e->errorCode);
        }
        DB::table('generation_steps')->where('id', $claim['id'])->update(['lease_until' => DB::raw("clock_timestamp() - interval '1 second'")]);
        app(OutboxService::class)->consume((string) DB::table('outbox_events')->where('run_id', $run['run_id'])->value('id'));
        $data = app(GenerationService::class)->read($f['actor'], $f['novel'], $run['run_id']);
        $this->assertSame('deferred', $data['run']['status']);
        $this->assertNull($data['steps'][0]['checkpoint_artifact_id']);
        $this->assertSame('manual_required', app(RevisionService::class)->read($f['actor'], $f['novel'], $t['id'])['status']);
        $this->assertDatabaseCount('revision_proposals', 0);
        $this->assertDatabaseCount('ai_requests', 1);
        try {
            app(RevisionService::class)->propose($f['actor'], $f['novel'], $t['id'], ['offline_output' => $p, 'reason' => '不能用旁路提案覆盖预算运行'], 'bypass');
            $this->fail('不得绕过预算运行');
        } catch (NovelProblem $e) {
            $this->assertSame('REVISION_RUN_EXISTS', $e->errorCode);
        }Http::assertNothingSent();
    }
}
