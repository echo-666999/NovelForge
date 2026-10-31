<?php

namespace App\ProvidersGateway;

use App\Exceptions\NovelProblem;
use JsonException;
use stdClass;

/** Responses非流式协议转换；纯函数，不持有密钥、不联网、不改变运行门禁。 */
final class OpenAiResponsesProtocol
{
    /** 输入必须已由上层完成授权、冻结和预算预留；此方法本身不授予发送资格。 */
    public function request(array $input): array
    {
        if (array_diff(array_keys($input), ['model', 'prompt', 'output_schema', 'parameters'])
            || ! is_string($input['model'] ?? null) || trim($input['model']) === ''
            || ! is_string($input['prompt'] ?? null) || trim($input['prompt']) === ''
            || ! is_array($input['output_schema'] ?? null) || ! is_array($input['parameters'] ?? null)) {
            throw new NovelProblem('INVALID_PROVIDER_REQUEST', 'Responses请求字段无效。', 422);
        }
        $parameters = $input['parameters'];
        // 独立推理上限不是此接口参数；不能悄悄相加或丢弃冻结的额外参数。
        if (array_diff(array_keys($parameters), ['max_output_tokens', 'max_reasoning_tokens'])
            || ! is_int($parameters['max_output_tokens'] ?? null) || $parameters['max_output_tokens'] < 1
            || $parameters['max_output_tokens'] > 1000000000
            || (array_key_exists('max_reasoning_tokens', $parameters) && $parameters['max_reasoning_tokens'] !== 0)) {
            throw new NovelProblem('UNSUPPORTED_PROVIDER_PARAMETERS', '仅支持包含推理在内的总输出上限；其他参数需显式适配。', 422);
        }
        $schema = (new OpenAiTransportSchema)->compile($input['output_schema']);

        return ['model' => $input['model'], 'input' => $input['prompt'], 'max_output_tokens' => $parameters['max_output_tokens'],
            'store' => false, 'stream' => false, 'background' => false, 'truncation' => 'disabled', 'service_tier' => 'default',
            'text' => ['format' => ['type' => 'json_schema', 'name' => 'novelforge_output', 'strict' => true, 'schema' => $schema]]];
    }

    /** 原始字节独立保留，不能用归一化结果覆盖供应商证据；失败响应仍提取计量。 */
    public function response(ProviderReply $reply, array $schema): array
    {
        (new StructuredOutput)->schema($schema);
        $result = ['raw_body' => $reply->body, 'http_status' => $reply->status, 'retry_after_seconds' => $reply->retryAfterSeconds,
            'response_id' => null, 'model' => null, 'service_tier' => null, 'output' => null, 'usage' => null, 'usage_error' => 'USAGE_MISSING',
            'transport_schema_version' => OpenAiTransportSchema::VERSION, 'status' => 'failed', 'error_code' => null];
        // 传输不完整时不得把可解析前缀误判为成功；仍保留原始字节供对账。
        if ($reply->transportError !== null) {
            return array_replace($result, ['status' => $reply->transportError === 'PROVIDER_OUTCOME_UNKNOWN' ? 'unknown' : 'failed', 'error_code' => $reply->transportError]);
        }
        if (strlen($reply->body) > 1048576) {
            return array_replace($result, ['error_code' => 'RESPONSE_TOO_LARGE']);
        }
        // HTTP错误优先于错误页格式；HTML或空正文也须保留正确故障类别。
        $httpError = $reply->status === 200 ? null : ($reply->status >= 300 && $reply->status < 400 ? 'REDIRECT_BLOCKED' : 'PROVIDER_HTTP_'.$reply->status);
        try {
            $body = json_decode($reply->body, false, 32, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            return array_replace($result, ['error_code' => $httpError ?? 'INVALID_JSON']);
        }
        if (! $body instanceof stdClass) {
            return array_replace($result, ['error_code' => $httpError ?? 'INVALID_RESPONSE']);
        }
        $result['response_id'] = is_string($body->id ?? null) ? $body->id : null;
        $result['model'] = is_string($body->model ?? null) ? $body->model : null;
        $result['service_tier'] = is_string($body->service_tier ?? null) ? $body->service_tier : null;
        [$result['usage'], $result['usage_error']] = $this->usage($body->usage ?? null);
        if ($reply->status !== 200) {
            return array_replace($result, ['error_code' => $reply->status >= 300 && $reply->status < 400 ? 'REDIRECT_BLOCKED' : 'PROVIDER_HTTP_'.$reply->status]);
        }
        if (($body->status ?? null) !== 'completed') {
            return array_replace($result, ['error_code' => ($body->status ?? null) === 'incomplete' ? 'PROVIDER_INCOMPLETE' : 'PROVIDER_NOT_COMPLETED']);
        }
        if (! is_array($body->output ?? null)) {
            return array_replace($result, ['error_code' => 'INVALID_RESPONSE']);
        }
        $texts = [];
        foreach ($body->output as $item) {
            // 推理摘要不是正文；工具结果和未知输出类型不能当作成功输出。
            if (($item->type ?? null) === 'reasoning') {
                continue;
            }
            if (($item->type ?? null) !== 'message' || ($item->role ?? null) !== 'assistant'
                || ($item->status ?? null) !== 'completed' || ! is_array($item->content ?? null)) {
                return array_replace($result, ['error_code' => 'UNSUPPORTED_RESPONSE_OUTPUT']);
            }
            foreach ($item->content as $part) {
                if (($part->type ?? null) === 'refusal') {
                    return array_replace($result, ['error_code' => 'PROVIDER_REFUSAL']);
                }
                if (($part->type ?? null) !== 'output_text' || ! is_string($part->text ?? null)) {
                    return array_replace($result, ['error_code' => 'INVALID_RESPONSE']);
                }
                $texts[] = $part->text;
            }
        }
        // 不拼接多个独立输出来制造一个看似合法的JSON对象。
        if (count($texts) !== 1) {
            return array_replace($result, ['error_code' => 'AMBIGUOUS_RESPONSE_OUTPUT']);
        }
        try {
            $output = json_decode($texts[0], false, 32, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            return array_replace($result, ['error_code' => 'INVALID_STRUCTURED_OUTPUT']);
        }
        // 仅转换业务产物；raw_body始终保留收到的原始传输字节。
        try {
            $output = (new OpenAiTransportSchema)->decode($output, $schema);
        } catch (NovelProblem $e) {
            return array_replace($result, ['error_code' => $e->errorCode]);
        }

        return array_replace($result, ['status' => 'succeeded', 'output' => $output]);
    }

    /** 未提供缓存写入不等于零；当前单一写入费率不支持混入12小时写入。 */
    private function usage(mixed $raw): array
    {
        if (! $raw instanceof stdClass) {
            return [null, 'USAGE_MISSING'];
        }
        $usage = ['input_tokens' => $raw->input_tokens ?? null, 'output_tokens' => $raw->output_tokens ?? null,
            'cache_read_tokens' => $raw->input_tokens_details->cached_tokens ?? null,
            'cache_write_tokens' => $raw->input_tokens_details->cache_write_tokens ?? null,
            'reasoning_tokens' => $raw->output_tokens_details->reasoning_tokens ?? null];
        foreach ($usage as $value) {
            if ($value === null) {
                return [null, 'USAGE_MISSING'];
            }
            if (! is_int($value) || $value < 0 || $value > 1000000000) {
                return [null, 'INVALID_USAGE'];
            }
        }
        if ($usage['input_tokens'] < $usage['cache_read_tokens'] + $usage['cache_write_tokens'] || $usage['reasoning_tokens'] > $usage['output_tokens']) {
            return [null, 'INVALID_USAGE'];
        }
        // 若供应商提供总量，必须与输入输出相符；矛盾计量不能结算。
        if (isset($raw->total_tokens) && (! is_int($raw->total_tokens) || $raw->total_tokens !== $usage['input_tokens'] + $usage['output_tokens'])) {
            return [null, 'INVALID_USAGE'];
        }
        $write = $raw->input_tokens_details->cache_write_tokens_details ?? null;
        if ($usage['cache_write_tokens'] > 0 || $write !== null) {
            if (! $write instanceof stdClass || ! is_int($write->ttl_30m_tokens ?? null) || ! is_int($write->ttl_12h_tokens ?? null)
                || $write->ttl_30m_tokens < 0 || $write->ttl_12h_tokens < 0
                || $write->ttl_30m_tokens + $write->ttl_12h_tokens !== $usage['cache_write_tokens']) {
                return [null, 'INVALID_USAGE'];
            }
            if ($write->ttl_12h_tokens !== 0) {
                return [null, 'UNSUPPORTED_CACHE_TARIFF'];
            }
        }

        return [$usage, null];
    }
}
