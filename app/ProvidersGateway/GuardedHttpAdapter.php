<?php

namespace App\ProvidersGateway;

use App\Exceptions\NovelProblem;
use GuzzleHttp\Handler\CurlHandler;
use Illuminate\Support\Facades\Http;

/** 通用JSON协议的离线HTTP支架；真实传输入口在预算门禁完成前关闭。 */
final class GuardedHttpAdapter implements ProviderAdapter
{
    public function __construct(private array $config, private PublicDnsResolver $resolver, #[\SensitiveParameter] private string $key) {}

    public function send(array $request): ProviderReply
    {
        if (! app()->environment('testing')) {
            throw new NovelProblem('BUDGET_GATE_REQUIRED', '真实传输尚未开放。', 403);
        }
        $policy = new EndpointPolicy;
        $host = $policy->host($this->config['endpoint'], $this->config['allowed_hosts']);
        $options = $policy->pinnedOptions($this->config['endpoint'], $this->config['allowed_hosts'], $this->resolver->resolve($host));
        // 强制curl使用已验证IP，保留原主机TLS校验；禁用代理与自动重定向。
        Http::preventStrayRequests();
        $response = Http::withOptions($options)->setHandler(new CurlHandler)
            ->timeout($this->config['timeout_seconds'])->connectTimeout($this->config['connect_timeout_seconds'])
            ->withToken($this->key)->post($this->config['endpoint'], $request);
        // 不跟随重定向，但把原状态/正文交给网关分类并保存证据。

        return new ProviderReply($response->status(), $response->body());
    }
}
