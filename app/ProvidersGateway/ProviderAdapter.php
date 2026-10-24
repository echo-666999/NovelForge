<?php

namespace App\ProvidersGateway;

/** 供应商协议边界；传输实现不允许自行重试、记录密钥或写业务状态。 */
interface ProviderAdapter
{
    public function send(array $request): ProviderReply;
}
