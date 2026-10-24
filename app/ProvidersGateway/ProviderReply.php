<?php

namespace App\ProvidersGateway;

/** 原始响应字节与状态码，不包含认证头。 */
final readonly class ProviderReply
{
    public function __construct(public int $status, public string $body) {}
}
