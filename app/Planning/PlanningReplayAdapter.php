<?php

namespace App\Planning;

use App\ProvidersGateway\ProviderAdapter;
use App\ProvidersGateway\ProviderReply;
use App\Services\NovelIntentService;
use Illuminate\Support\Str;

/** 显式离线响应回放；无 HTTP、DNS 或凭据读取，绝不冒充真实 AI 生成。 */
final class PlanningReplayAdapter implements ProviderAdapter
{
    public function __construct(private array $output) {}

    public function outputHash(): string
    {
        return hash('sha256', NovelIntentService::canonical($this->output));
    }

    public function send(array $request): ProviderReply
    {
        return new ProviderReply(200, json_encode(['output' => $this->output, 'request_id' => 'planning-replay-'.Str::uuid(), 'usage' => ['input_tokens' => 1, 'output_tokens' => 1]], JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR));
    }
}
