<?php

namespace Tests\Feature;

use App\Exceptions\NovelProblem;
use App\Generation\StageResult;
use App\ProvidersGateway\FakeProvider;
use App\ProvidersGateway\OpenAiResponsesFixtureAdapter;
use App\ProvidersGateway\ProviderReply;
use App\Services\GenerationService;
use App\Services\ProviderGateway;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Tests\Support\ProviderFixture;
use Tests\TestCase;

/** 使用测试库和内存响应贯穿协议边界，禁止真实HTTP请求。 */
class ResponsesGatewayTest extends TestCase
{
    use RefreshDatabase;

    private function raw(string $text, string $status = 'completed'): string
    {
        return json_encode(['id' => 'resp_offline', 'model' => 'fixture-model', 'status' => $status,
            'output' => [['type' => 'message', 'role' => 'assistant', 'status' => 'completed', 'content' => [['type' => 'output_text', 'text' => $text]]]],
            'usage' => ['input_tokens' => 10, 'output_tokens' => 5, 'input_tokens_details' => ['cached_tokens' => 0, 'cache_write_tokens' => 0], 'output_tokens_details' => ['reasoning_tokens' => 0]]], JSON_THROW_ON_ERROR);
    }

    private function problem(string $code, callable $action): void
    {
        try {
            $action();
            $this->fail('应拒绝不匹配的请求或业务结果');
        } catch (NovelProblem $e) {
            $this->assertSame($code, $e->errorCode);
        }
    }

    public function test_wire_evidence_and_business_output_are_separate_and_replay_is_bound(): void
    {
        Http::fake();
        $f = ProviderFixture::create();
        // 缺省字段必须还原为不存在，不能写入null或协议包装对象。
        $f['input']['output_schema']['properties']['note'] = ['type' => 'string'];
        $raw = $this->raw('{"text":"离线结果","note":{"state":"absent"}}');
        $adapter = new OpenAiResponsesFixtureAdapter(new ProviderReply(200, $raw));
        $g = app(ProviderGateway::class);
        $send = fn ($a) => $g->execute($f['actor'], $f['novelId'], $f['claim']['id'], $f['model']['version_id'], $f['input'], 'responses', $a);
        $r = $send($adapter);
        $this->assertSame('succeeded', $r['status']);
        $this->assertSame($r, $send($adapter));
        $this->assertSame(1, $adapter->calls);
        $this->assertSame($raw, $g->evidenceText($f['actor'], $f['novelId'], $r['id'], 'response'));
        $wire = json_decode($g->evidenceText($f['actor'], $f['novelId'], $r['id'], 'request'), true);
        $this->assertSame(['text', 'note'], $wire['text']['format']['schema']['required']);
        $this->assertFalse($wire['store']);
        $this->assertSame(['text' => '离线结果'], $g->businessOutput($f['actor'], $f['novelId'], $r['id'], $f['input']));
        $this->problem('IDEMPOTENCY_CONFLICT', fn () => $send(new FakeProvider));
        $wrong = array_replace($f['input'], ['prompt' => '不同输入']);
        $this->problem('CONFIGURATION_MISMATCH', fn () => $g->businessOutput($f['actor'], $f['novelId'], $r['id'], $wrong));
        $this->assertDatabaseCount('ai_requests', 1);
        $this->assertDatabaseCount('ai_request_evidence', 2);
        // 阶段产物只写入还原后的业务对象，响应证据保持独立不变。
        $output = $g->businessOutput($f['actor'], $f['novelId'], $r['id'], $f['input']);
        $this->assertTrue(app(GenerationService::class)->complete($f['actor'], $f['novelId'], $f['runId'], $f['claim'], new StageResult('succeeded', $output)));
        $this->assertSame($output, json_decode(DB::table('generation_artifacts')->where('step_id', $f['claim']['id'])->value('content'), true));
        $this->assertSame($raw, $g->evidenceText($f['actor'], $f['novelId'], $r['id'], 'response'));
        Http::assertNothingSent();
    }

    public function test_incomplete_response_keeps_evidence_and_usage_but_cannot_be_consumed(): void
    {
        $f = ProviderFixture::create();
        $raw = $this->raw('{"text":"截断结果"}', 'incomplete');
        $g = app(ProviderGateway::class);
        $r = $g->execute($f['actor'], $f['novelId'], $f['claim']['id'], $f['model']['version_id'], $f['input'], 'incomplete', new OpenAiResponsesFixtureAdapter(new ProviderReply(200, $raw)));
        $this->assertSame('failed', $r['status']);
        $this->assertSame('PROVIDER_INCOMPLETE', $r['error_code']);
        $this->assertSame(5, $r['usage']['output_tokens']);
        $this->assertSame($raw, $g->evidenceText($f['actor'], $f['novelId'], $r['id'], 'response'));
        $this->problem('REQUEST_NOT_SUCCEEDED', fn () => $g->businessOutput($f['actor'], $f['novelId'], $r['id'], $f['input']));
    }

    public function test_responses_fixture_cannot_run_outside_testing(): void
    {
        $f = ProviderFixture::create();
        $adapter = new OpenAiResponsesFixtureAdapter(new ProviderReply(200, $this->raw('{"text":"样例"}')));
        $this->app->instance('env', 'local');
        try {
            $this->problem('BUDGET_GATE_REQUIRED', fn () => app(ProviderGateway::class)->execute($f['actor'], $f['novelId'], $f['claim']['id'], $f['model']['version_id'], $f['input'], 'blocked', $adapter));
            $this->assertSame(0, $adapter->calls);
            $this->assertDatabaseCount('ai_requests', 0);
        } finally {
            $this->app->instance('env', 'testing');
        }
    }
}
