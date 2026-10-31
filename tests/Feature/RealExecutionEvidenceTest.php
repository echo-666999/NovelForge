<?php

namespace Tests\Feature;

use App\Exceptions\NovelProblem;
use App\Models\Novel;
use App\Planning\PlanningContract;
use App\ProvidersGateway\FakeProvider;
use App\ProvidersGateway\FrozenResponsesAdapterFactory;
use App\ProvidersGateway\OpenAiResponsesHttpAdapter;
use App\ProvidersGateway\OpenAiTransportSchema;
use App\ProvidersGateway\PublicDnsResolver;
use App\Review\ReviewContract;
use App\Revision\RevisionContract;
use App\Services\AuthoringConfigurationService;
use App\Services\BillingConfigurationService;
use App\Services\ModelCapabilityEvidenceService;
use App\Services\OutboxService;
use App\Services\PlanningPipelineService;
use App\Services\ProviderConfigurationService;
use App\Services\ProviderGateway;
use App\Services\RealExecutionAdmission;
use App\Services\ReviewPipelineService;
use App\Services\RevisionPipelineService;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\Psr7\Response;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Support\PlanningFixture;
use Tests\Support\ProviderFixture;
use Tests\Support\ReviewFixture;
use Tests\Support\RevisionFixture;
use Tests\TestCase;

/** 真实来源契约使用内存HTTP响应验证；测试环境绝不访问外网。 */
class RealExecutionEvidenceTest extends TestCase
{
    use RefreshDatabase;

    public function test_probe_persists_real_origin_cost_and_immutable_capability_without_adopting(): void
    {
        [$f, $route, $mock, $raw, $result] = $this->probe(PlanningFixture::create());
        $service = app(AuthoringConfigurationService::class);
        $this->assertSame('succeeded', $result['data']['run']['status'], json_encode($result));
        $q = DB::table('ai_requests')->sole();
        $this->assertSame('real_provider', $q->evidence_kind);
        $this->assertSame('real_billable', $q->cost_status);
        $this->assertSame('settled', DB::table('budget_reservations')->where('request_id', $q->id)->value('status'));
        $cap = app(ModelCapabilityEvidenceService::class)->capture($f['actor'], $f['novel'], (string) $q->id);
        $this->assertSame($cap, app(ModelCapabilityEvidenceService::class)->capture($f['actor'], $f['novel'], (string) $q->id));
        $this->assertSame(hash('sha256', $raw), $cap['response_hash']);
        $this->assertFalse(json_decode($cap['observations'], true)['capacity_verified']);
        $this->assertSame('unverified', DB::table('model_profile_versions')->where('id', $f['model'])->value('verification_status'));
        $this->assertSame(0, $mock->count());
        $this->assertDatabaseCount('model_capability_evidence', 1);
        // 能力证据不会发布路由或采纳规划候选；后续发布仍是显式命令。
        $this->assertSame('draft', $service->version('route', $route['id'])['status']);
        $this->assertSame(0, DB::table('planning_adoptions')->count());
        $test = $service->execute($f['actor'], 'route', 'test', ['version_id' => $route['id']], 'probe-ready');
        $this->assertTrue($test['regression']['passed']);
        $this->expectException(QueryException::class);
        DB::table('model_capability_evidence')->where('id', $cap['id'])->update(['observed_model' => 'forged']);
    }

    /** 以完整规划阶段产生测试能力证据，不直接伪造能力表记录。 */
    private function probe(array $f, array $replyChanges = []): array
    {
        $output = PlanningFixture::intake($f);
        $service = app(AuthoringConfigurationService::class);
        $old = $service->version('route', PlanningFixture::route($f, 'intake', $output));
        $content = array_replace($old['content'], ['mode' => 'production']);
        $route = $service->execute($f['actor'], 'route', 'save', ['key' => 'probe.route', 'stage' => 'planning.intake', 'language' => 'zh-CN', 'expected_version' => 0, 'reason' => '探测测试', 'content' => $content], 'probe-route');
        $model = DB::table('model_profile_versions')->find($f['model']);
        $provider = app(ProviderConfigurationService::class)->execute($f['actor'], 'provider', [
            'provider_id' => $model->provider_id, 'name' => '离线供应商', 'expected_version' => 1,
            'interface_type' => 'json_http', 'endpoint' => 'https://api.example.com/v1/responses',
            'allowed_hosts' => ['api.example.com'], 'timeout_seconds' => 20, 'connect_timeout_seconds' => 5,
            'requests_per_minute' => 20, 'max_concurrency' => 2, 'enabled' => true, 'reason' => '仅内存传输验证',
        ], 'probe-provider');
        config(['novelforge.real_execution_enabled' => true]);
        $wireOutput = (new OpenAiTransportSchema)->encode(json_decode(json_encode($output)), PlanningContract::schema('intake'));
        $raw = json_encode(['service_tier' => 'default', 'id' => 'resp_test', 'model' => DB::table('model_profiles')->where('id', $model->model_profile_id)->value('provider_model_id'), 'status' => 'completed',
            'output' => [['type' => 'message', 'role' => 'assistant', 'status' => 'completed', 'content' => [['type' => 'output_text', 'text' => json_encode($wireOutput)]]]],
            'usage' => ['input_tokens' => 10, 'output_tokens' => 5, 'input_tokens_details' => ['cached_tokens' => 0, 'cache_write_tokens' => 0], 'output_tokens_details' => ['reasoning_tokens' => 0]]]);
        $raw = json_encode(array_replace(json_decode($raw, true), $replyChanges));
        // 在内存传输回调中验证真实发送前的精确证据门禁，仍然不外发。
        $mock = new MockHandler([function ($request) use ($provider, $raw) {
            $pending = DB::table('ai_requests')->where('status', 'pending')->where('evidence_kind', 'real_provider')->sole();
            $body = (array) json_decode((string) $request->getBody());
            $admission = app(RealExecutionAdmission::class);
            $admission->request((string) $pending->id, $body, $provider);
            try {
                $admission->request((string) $pending->id, array_replace($body, ['input' => '替换内容']), $provider);
                $this->fail('不能发送与原始证据不同的请求');
            } catch (NovelProblem $e) {
                $this->assertSame('CONFIGURATION_MISMATCH', $e->errorCode);
            }

            return new Response(200, [], $raw);
        }]);
        $resolver = new class extends PublicDnsResolver
        {
            public function resolve(string $host): array
            {
                return ['8.8.8.8'];
            }
        };
        $adapter = new OpenAiResponsesHttpAdapter($provider, $resolver, 'fixture-secret-key-123', $mock);
        $this->mock(FrozenResponsesAdapterFactory::class, fn ($m) => $m->shouldReceive('make')->once()->andReturn($adapter));
        $run = app(PlanningPipelineService::class)->start($f['actor'], $f['novel'], [
            'execution_mode' => 'production', 'capability_probe' => true, 'phase' => 'intake',
            'route_version_id' => $route['id'], 'billing' => array_replace($f['billing'], ['category' => 'planning']),
            'expected_version' => Novel::find($f['novel'])->row_version, 'reason' => '受限能力探测',
        ], 'probe-start');
        $frozen = $run['data']['run']['frozen_config'];
        $context = json_decode($frozen['configuration']['variables']['context'], true);
        $this->assertNull($context['structure']);
        $this->assertSame([], $context['approved_entities']);
        $this->assertNotEmpty($context['requirements']);
        $result = PlanningFixture::execute($f, $run);

        return [$f, $route, $mock, $raw, $result];
    }

    public function test_real_review_and_revision_bind_successful_requests_without_adoption(): void
    {
        $f = RevisionFixture::create();
        $reviewOutput = ReviewFixture::output($f);
        $revisionOutput = RevisionFixture::output($f);
        $s = app(AuthoringConfigurationService::class);
        $reviewRoute = $s->version('route', ReviewFixture::route($f, $reviewOutput));
        $revisionRoute = $s->version('route', RevisionFixture::route($f, $revisionOutput));
        $f['model'] = $reviewRoute['content']['primary_model_version_id'];
        $this->probe($f);
        $probe = DB::table('ai_requests')->where('evidence_kind', 'real_provider')->sole();
        app(ModelCapabilityEvidenceService::class)->capture($f['actor'], $f['novel'], (string) $probe->id);
        $routes = [];
        foreach ([$reviewRoute, $revisionRoute] as $old) {
            $v = $s->execute($f['actor'], 'route', 'save', ['key' => $old['key'].'.real', 'stage' => $old['stage'], 'language' => 'zh-CN', 'expected_version' => 0, 'reason' => '真实来源契约测试', 'content' => array_replace($old['content'], ['mode' => 'production'])], $old['stage'].'save');
            $s->execute($f['actor'], 'route', 'test', ['version_id' => $v['id']], $old['stage'].'test');
            $routes[] = $s->execute($f['actor'], 'route', 'publish', ['version_id' => $v['id']], $old['stage'].'publish')['id'];
        }
        $d = ReviewFixture::payload($f);
        unset($d['offline_output'], $d['reviewer_type']);
        $this->respond($reviewOutput, ReviewContract::schema());
        $run = app(ReviewPipelineService::class)->start($f['actor'], $f['novel'], $d + ['execution_mode' => 'production', 'route_version_id' => $routes[0], 'billing' => array_replace($f['billing'], ['category' => 'review'])], 'real-review');
        $this->consume($run['run_id']);
        $review = DB::table('reviews')->where('run_id', $run['run_id'])->sole();
        $this->assertSame('model', $review->reviewer_type);
        $this->assertSame('real_provider', DB::table('ai_requests')->where('id', $review->source_request_id)->value('evidence_kind'));
        $task = RevisionFixture::task($f, 'production');
        $this->respond($revisionOutput, RevisionContract::schema());
        $run = app(RevisionPipelineService::class)->start($f['actor'], $f['novel'], $task['id'], ['execution_mode' => 'production', 'route_version_id' => $routes[1], 'billing' => $f['billing'], 'reason' => '真实修订仅产生提案'], 'real-revise');
        $this->consume($run['run_id']);
        $proposal = DB::table('revision_proposals')->where('task_id', $task['id'])->sole();
        $this->assertNotNull($proposal->source_request_id);
        $this->assertSame('real_provider', DB::table('ai_requests')->where('id', $proposal->source_request_id)->value('evidence_kind'));
        $this->assertDatabaseCount('chapter_versions', 1);
        $this->assertDatabaseCount('revision_decisions', 0);
        $ledger = app(BillingConfigurationService::class)->listing($f['actor'], ['novel_id' => $f['novel']]);
        $this->assertEqualsCanonicalizing(['offline_simulation', 'real_provider'], array_column($ledger['totals'], 'evidence_kind'));
        $csv = app(BillingConfigurationService::class)->export($f['actor'], ['novel_id' => $f['novel']]);
        $this->assertStringContainsString('证据来源', $csv);
        $this->assertStringContainsString('real_provider', $csv);
        // 关联到别的成功请求也必须被数据库拒绝，不能仅检查请求存在。
        $forged = (array) $proposal;
        unset($forged['id']);
        $forged['source_request_id'] = $probe->id;
        $this->expectException(QueryException::class);
        $this->expectExceptionMessage('F01 result request mismatch');
        DB::table('revision_proposals')->insert($forged);
    }

    /** 为下一次真实模式调用提供协议响应，仍不允许任何真实网络。 */
    private function respond(array $output, array $schema): void
    {
        $raw = json_encode(['service_tier' => 'default', 'model' => 'draft-offline-fixture', 'id' => 'resp_'.bin2hex(random_bytes(5)), 'status' => 'completed', 'output' => [['type' => 'message', 'role' => 'assistant', 'status' => 'completed', 'content' => [['type' => 'output_text', 'text' => json_encode((new OpenAiTransportSchema)->encode(json_decode(json_encode($output)), $schema))]]]], 'usage' => ['input_tokens' => 10, 'output_tokens' => 5, 'input_tokens_details' => ['cached_tokens' => 0, 'cache_write_tokens' => 0], 'output_tokens_details' => ['reasoning_tokens' => 0]]]);
        $resolver = new class extends PublicDnsResolver
        {
            public function resolve(string $host): array
            {
                return ['8.8.8.8'];
            }
        };
        $adapter = new OpenAiResponsesHttpAdapter(['endpoint' => 'https://api.example.com/v1/responses', 'allowed_hosts' => ['api.example.com'], 'timeout_seconds' => 20, 'connect_timeout_seconds' => 5], $resolver, 'fixture-secret-key-123', new MockHandler([new Response(200, [], $raw)]));
        $this->mock(FrozenResponsesAdapterFactory::class, fn ($m) => $m->shouldReceive('make')->once()->andReturn($adapter));
    }

    private function consume(string $run): void
    {
        $event = DB::table('outbox_events')->where('run_id', $run)->value('id');
        app(OutboxService::class)->consume((string) $event);
        $this->assertSame('succeeded', DB::table('generation_runs')->where('id', $run)->value('status'), json_encode(DB::table('generation_steps')->where('run_id', $run)->get(['status', 'error_code'])));
    }

    public function test_empty_migration_can_rollback_and_reapply_without_losing_offline_history(): void
    {
        $f = ProviderFixture::create();
        $before = DB::table('generation_runs')->count();
        $migration = require database_path('migrations/2026_10_30_000027_add_real_execution_evidence.php');
        $migration->down();
        $this->assertFalse(DB::getSchemaBuilder()->hasColumn('reviews', 'source_request_id'));
        $migration->up();
        $this->assertSame($before, DB::table('generation_runs')->count());
        $this->assertTrue(DB::getSchemaBuilder()->hasColumn('reviews', 'source_request_id'));
    }

    public function test_offline_success_cannot_be_promoted_to_real_capability(): void
    {
        $f = ProviderFixture::create();
        $q = app(ProviderGateway::class)->execute($f['actor'], $f['novelId'], $f['claim']['id'], $f['model']['version_id'], $f['input'], 'fake-evidence', new FakeProvider);
        try {
            app(ModelCapabilityEvidenceService::class)->capture($f['actor'], $f['novelId'], $q['id']);
            $this->fail('离线响应不能提升为真实能力证据');
        } catch (NovelProblem $e) {
            $this->assertSame('REAL_CAPABILITY_EVIDENCE_REQUIRED', $e->errorCode);
        }
        $this->assertDatabaseCount('model_capability_evidence', 0);
    }

    public function test_wrong_model_keeps_raw_evidence_and_unknown_cost_without_capability(): void
    {
        [$f, $route, $mock, $raw, $result] = $this->probe(PlanningFixture::create(), ['model' => 'unexpected-model']);
        $q = DB::table('ai_requests')->sole();
        $this->assertSame('failed', $q->status);
        $this->assertSame('MODEL_ID_MISMATCH', $q->error_code);
        $budget = DB::table('budget_reservations')->where('request_id', $q->id)->sole();
        $this->assertSame('unknown', $budget->status);
        $this->assertNull($budget->actual_cost);
        $this->assertSame($raw, app(ProviderGateway::class)->evidenceText($f['actor'], $f['novel'], (string) $q->id, 'response'));
        $this->assertDatabaseCount('model_capability_evidence', 0);
        $this->assertSame(0, $mock->count());
    }

    public function test_new_persistence_columns_have_chinese_comments(): void
    {
        $rows = DB::select("SELECT c.relname,a.attname,col_description(c.oid,a.attnum) AS comment FROM pg_class c JOIN pg_attribute a ON a.attrelid=c.oid WHERE a.attnum>0 AND NOT a.attisdropped AND (c.relname='model_capability_evidence' OR (c.relname='ai_requests' AND a.attname='evidence_kind') OR (c.relname IN ('reviews','revision_proposals') AND a.attname='source_request_id'))");
        $this->assertCount(13, $rows);
        foreach ($rows as $row) {
            $this->assertMatchesRegularExpression('/\p{Han}/u', $row->comment ?? '', $row->relname.'.'.$row->attname);
        }
    }
}
