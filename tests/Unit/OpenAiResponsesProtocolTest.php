<?php

namespace Tests\Unit;

use App\Billing\TokenMeter;
use App\Exceptions\NovelProblem;
use App\ProvidersGateway\OpenAiResponsesProtocol;
use App\ProvidersGateway\ProviderReply;
use PHPUnit\Framework\TestCase;

/** 协议夹具为人工合成，不启动Laravel、数据库或网络，不能冒充真实模型证据。 */
class OpenAiResponsesProtocolTest extends TestCase
{
    private function schema(): array
    {
        return ['type' => 'object', 'properties' => ['body' => ['type' => 'string', 'maxLength' => 30]], 'required' => ['body'], 'additionalProperties' => false];
    }

    private function fixture(): array
    {
        return ['id' => 'resp_fixture', 'model' => 'gpt-6.1-terra', 'status' => 'completed',
            'output' => [['type' => 'reasoning', 'summary' => []], ['type' => 'message', 'role' => 'assistant', 'status' => 'completed', 'content' => [['type' => 'output_text', 'text' => '{"body":"离线样例"}']]]],
            'usage' => ['input_tokens' => 12000, 'input_tokens_details' => ['cached_tokens' => 4000, 'cache_write_tokens' => 7000, 'cache_write_tokens_details' => ['ttl_30m_tokens' => 7000, 'ttl_12h_tokens' => 0]], 'output_tokens' => 100, 'output_tokens_details' => ['reasoning_tokens' => 80]]];
    }

    private function parse(array $body, int $http = 200): array
    {
        return (new OpenAiResponsesProtocol)->response(new ProviderReply($http, json_encode($body, JSON_THROW_ON_ERROR), 5), $this->schema());
    }

    public function test_request_preserves_model_contract_and_disables_implicit_context_storage(): void
    {
        $body = (new OpenAiResponsesProtocol)->request(['model' => 'gpt-6.1-sol', 'prompt' => '冻结输入', 'output_schema' => $this->schema(), 'parameters' => ['max_output_tokens' => 300, 'max_reasoning_tokens' => 0]]);
        $this->assertSame('gpt-6.1-sol', $body['model']);
        $this->assertSame('冻结输入', $body['input']);
        $this->assertSame(300, $body['max_output_tokens']);
        foreach (['store', 'stream', 'background'] as $key) {
            $this->assertFalse($body[$key]);
        }
        $this->assertSame('disabled', $body['truncation']);
        $this->assertSame('default', $body['service_tier']);
        $this->assertSame($this->schema(), json_decode(json_encode($body['text']['format']['schema']), true));
        $this->assertArrayNotHasKey('max_reasoning_tokens', $body);
    }

    public function test_unsupported_parameters_fail_without_silent_rewriting(): void
    {
        $base = ['model' => 'model_fixture', 'prompt' => '输入', 'output_schema' => $this->schema(), 'parameters' => ['max_output_tokens' => 10]];
        $cases = [$base + ['tools' => []], array_replace($base, ['parameters' => ['max_output_tokens' => 10, 'temperature' => 0.8]]), array_replace($base, ['parameters' => ['max_output_tokens' => 10, 'max_reasoning_tokens' => 2]])];
        foreach ($cases as $case) {
            try {
                (new OpenAiResponsesProtocol)->request($case);
                $this->fail('必须拒绝不支持的输入');
            } catch (NovelProblem $e) {
                $this->assertContains($e->errorCode, ['INVALID_PROVIDER_REQUEST', 'UNSUPPORTED_PROVIDER_PARAMETERS', 'UNSUPPORTED_PROVIDER_SCHEMA']);
            }
        }
    }

    public function test_empty_properties_are_encoded_as_object(): void
    {
        $r = (new OpenAiResponsesProtocol)->request(['model' => 'fixture', 'prompt' => '输入', 'output_schema' => ['type' => 'object', 'properties' => [], 'required' => [], 'additionalProperties' => false], 'parameters' => ['max_output_tokens' => 10]]);
        $this->assertStringContainsString('"properties":{}', json_encode($r));
    }

    public function test_success_retains_raw_evidence_and_maps_usage_without_double_counting(): void
    {
        $raw = $this->fixture();
        $r = $this->parse($raw);
        $this->assertSame('succeeded', $r['status']);
        $this->assertSame('离线样例', $r['output']->body);
        $this->assertSame('resp_fixture', $r['response_id']);
        $this->assertSame(json_encode($raw), $r['raw_body']);
        $this->assertSame(5, $r['retry_after_seconds']);
        // 本方法只归一化整数，不调用TokenMeter的数据库金额计算。
        $tokens = (new TokenMeter)->normalize($r['usage'], (object) ['cache_mode' => 'included', 'reasoning_mode' => 'included']);
        $this->assertSame(['input' => 1000, 'output' => 100, 'cache_read' => 4000, 'cache_write' => 7000, 'reasoning' => 0], $tokens);
    }

    public function test_refusal_incomplete_and_http_failure_keep_usage_but_never_accept_body(): void
    {
        $base = $this->fixture();
        $incomplete = array_replace($base, ['status' => 'incomplete', 'incomplete_details' => ['reason' => 'max_output_tokens']]);
        $refusal = $base;
        $refusal['output'][1]['content'] = [['type' => 'refusal', 'refusal' => '拒绝样例']];
        foreach ([[$incomplete, 200, 'PROVIDER_INCOMPLETE'], [$refusal, 200, 'PROVIDER_REFUSAL'], [$base, 429, 'PROVIDER_HTTP_429'], [$base, 302, 'REDIRECT_BLOCKED']] as [$body, $http, $error]) {
            $r = $this->parse($body, $http);
            $this->assertSame('failed', $r['status']);
            $this->assertSame($error, $r['error_code']);
            $this->assertNull($r['output']);
            $this->assertSame(100, $r['usage']['output_tokens']);
        }
    }

    public function test_missing_or_invalid_usage_never_becomes_zero_cost(): void
    {
        $base = $this->fixture();
        $missing = $base;
        unset($missing['usage']['input_tokens_details']['cache_write_tokens']);
        $invalid = $base;
        $invalid['usage']['input_tokens'] = '12000';
        $overflow = $base;
        $overflow['usage']['output_tokens_details']['reasoning_tokens'] = 101;
        $tier = $base;
        $tier['usage']['input_tokens_details']['cache_write_tokens_details'] = ['ttl_30m_tokens' => 3000, 'ttl_12h_tokens' => 4000];
        foreach ([[$missing, 'USAGE_MISSING'], [$invalid, 'INVALID_USAGE'], [$overflow, 'INVALID_USAGE'], [$tier, 'UNSUPPORTED_CACHE_TARIFF']] as [$body, $error]) {
            $r = $this->parse($body);
            $this->assertSame('succeeded', $r['status']);
            $this->assertNull($r['usage']);
            $this->assertSame($error, $r['usage_error']);
        }
    }

    public function test_malformed_multiple_tool_and_schema_outputs_are_rejected(): void
    {
        $base = $this->fixture();
        foreach (['{', '{"body":7}', '{"body":"合法","extra":1}'] as $text) {
            $bad = $base;
            $bad['output'][1]['content'][0]['text'] = $text;
            $this->assertSame('INVALID_STRUCTURED_OUTPUT', $this->parse($bad)['error_code']);
        }
        $base['output'][] = $base['output'][1];
        $this->assertSame('AMBIGUOUS_RESPONSE_OUTPUT', $this->parse($base)['error_code']);
        $base['output'] = [['type' => 'function_call']];
        $this->assertSame('UNSUPPORTED_RESPONSE_OUTPUT', $this->parse($base)['error_code']);
    }

    public function test_invalid_json_and_oversized_raw_response_are_bounded(): void
    {
        foreach (['{' => 'INVALID_JSON', '[]' => 'INVALID_RESPONSE', str_repeat('x', 1048577) => 'RESPONSE_TOO_LARGE'] as $raw => $error) {
            $r = (new OpenAiResponsesProtocol)->response(new ProviderReply(200, $raw), $this->schema());
            $this->assertSame($error, $r['error_code']);
            $this->assertSame($raw, $r['raw_body']);
            $this->assertNull($r['usage']);
        }
    }
}
