<?php

namespace Tests\Support;

use App\Services\GenerationService;
use App\Services\ProviderConfigurationService;

/** 供应商数据均为本地离线夹具，不读取环境中的真实密钥。 */
final class ProviderFixture
{
    public static function create(): array
    {
        $f = QueuedRunFixture::create();
        $service = app(ProviderConfigurationService::class);
        $f['provider_payload'] = ['name' => '离线供应商', 'expected_version' => 0, 'interface_type' => 'fake', 'endpoint' => 'https://api.example.com/v1/generate', 'allowed_hosts' => ['api.example.com'], 'api_key' => 'fixture-secret-key-123', 'timeout_seconds' => 20, 'connect_timeout_seconds' => 5, 'requests_per_minute' => 20, 'max_concurrency' => 2, 'enabled' => true, 'reason' => '离线测试'];
        $f['provider'] = $service->execute($f['actor'], 'provider', $f['provider_payload'], 'provider');
        $f['model_payload'] = ['provider_id' => $f['provider']['id'], 'provider_model_id' => 'fixture-model', 'expected_version' => 0,
            'declared_capabilities' => ['context_tokens' => 8192, 'max_output_tokens' => 1000, 'structured_output' => 'supported', 'streaming' => 'unknown', 'reasoning_metering' => 'unknown', 'source_note' => '仅测试声明，无实际供应商验证'], 'reason' => '离线模型'];
        $f['model'] = $service->execute($f['actor'], 'model', $f['model_payload'], 'model');
        $f['claim'] = app(GenerationService::class)->claim($f['actor'], $f['novelId'], $f['runId'], 'first');
        $f['input'] = ['prompt' => '仅离线验证', 'output_schema' => ['type' => 'object', 'properties' => ['text' => ['type' => 'string', 'maxLength' => 200]], 'required' => ['text'], 'additionalProperties' => false]];

        return $f;
    }
}
