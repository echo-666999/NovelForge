<?php

namespace App\ProvidersGateway;

/** 仅供隔离测试的Responses原始响应夹具；不接收端点、密钥或网络传输。 */
final class OpenAiResponsesFixtureAdapter implements ResponsesAdapter
{
    public int $calls = 0;

    public ?array $sentRequest = null;

    public function __construct(private ProviderReply $reply) {}

    public function send(array $request): ProviderReply
    {
        if (! app()->environment('testing')) {
            throw new \LogicException('Responses夹具仅允许测试环境执行。');
        }
        $this->calls++;
        $this->sentRequest = $request;

        return $this->reply;
    }
}
