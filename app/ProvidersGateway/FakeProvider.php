<?php

namespace App\ProvidersGateway;

use Illuminate\Support\Str;

/** 确定性离线协议支架，与真实供应商能力和质量证据分离。 */
final class FakeProvider implements ProviderAdapter
{
    public int $calls = 0;

    public function __construct(private ?ProviderReply $reply = null, private bool $timeout = false) {}

    public function send(array $request): ProviderReply
    {
        if (! app()->environment('testing')) {
            throw new \LogicException('离线响应支架只能在测试环境执行。');
        }
        $this->calls++;
        if ($this->timeout) {
            throw new \RuntimeException('模拟响应结果未知');
        }

        return $this->reply ?? new ProviderReply(200, json_encode(['output' => ['text' => '离线生成结果'], 'request_id' => 'fake-'.Str::uuid(), 'usage' => ['input_tokens' => 3, 'output_tokens' => 4]], JSON_UNESCAPED_UNICODE));
    }
}
