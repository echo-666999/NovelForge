<?php

namespace App\ProvidersGateway;

use App\Exceptions\NovelProblem;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;

/** 只按运行冻结的模型和供应商版本组装传输，不读取可变的默认路由。 */
class FrozenResponsesAdapterFactory
{
    public function make(array $frozen, string $model): ResponsesAdapter
    {
        $selected = collect($frozen['configuration']['models'])->firstWhere('model_version_id', $model);
        if (! $selected) {
            throw new NovelProblem('UNAPPROVED_FALLBACK', '模型不属于冻结路由。');
        }
        $provider = DB::table('provider_versions')->find($selected['provider_version_id']);
        if (! $provider || (string) $provider->provider_id !== $selected['provider_id'] || $provider->interface_type !== 'json_http') {
            throw new NovelProblem('PROVIDER_PROTOCOL_MISMATCH', '冻结供应商不支持Responses传输。');
        }
        // 能力与发布门禁仍由冻结服务和网关独立复核；这里不修改任何运行资格。
        $secret = $provider->secret_id ? DB::table('provider_secrets')->where('id', $provider->secret_id)->where('provider_id', $provider->provider_id)->value('ciphertext') : null;
        if ($secret === null) {
            throw new NovelProblem('PROVIDER_KEY_REQUIRED', '冻结供应商缺少密钥。');
        }

        return new OpenAiResponsesHttpAdapter([
            'endpoint' => $provider->endpoint, 'allowed_hosts' => json_decode($provider->allowed_hosts, true),
            'timeout_seconds' => $provider->timeout_seconds, 'connect_timeout_seconds' => $provider->connect_timeout_seconds,
        ], new PublicDnsResolver, Crypt::decryptString($secret));
    }
}
