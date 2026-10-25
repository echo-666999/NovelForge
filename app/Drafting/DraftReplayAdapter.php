<?php

namespace App\Drafting;

use App\ProvidersGateway\ProviderAdapter;
use App\ProvidersGateway\ProviderReply;
use App\Services\NovelIntentService;
use Illuminate\Support\Str;

/** 仅回放启动时冻结的正文夹具，不读取凭据或访问网络。 */
final class DraftReplayAdapter implements ProviderAdapter
{
    public function __construct(private array $output) {}

    public function outputHash(): string
    {
        return hash('sha256', NovelIntentService::canonical($this->output));
    }

    public function send(array $request): ProviderReply
    {
        return new ProviderReply(200, json_encode(['output' => $this->output, 'request_id' => 'draft-replay-'.Str::uuid(), 'usage' => ['input_tokens' => 1, 'output_tokens' => 1]], JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR));
    }
}
