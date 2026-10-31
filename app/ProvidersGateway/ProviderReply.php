<?php

namespace App\ProvidersGateway;

/** 原始响应字节与状态码，不包含认证头；状态0仅表示未收到响应头，入库转为null。 */
final readonly class ProviderReply
{
    public function __construct(public int $status, public string $body, public ?int $retryAfterSeconds = null, public ?string $transportError = null) {}
}
