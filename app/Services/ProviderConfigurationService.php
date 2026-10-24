<?php

namespace App\Services;

use App\Exceptions\NovelProblem;
use App\Models\User;
use App\ProvidersGateway\EndpointPolicy;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;

/** 全局供应商配置：只追加版本，所有响应使用不包含密钥引用的安全投影。 */
final class ProviderConfigurationService
{
    public function execute(User $actor, string $command, #[\SensitiveParameter] array $payload, string $key): array
    {
        app(NovelIntentService::class)->authorize($actor);
        Validator::make(['key' => $key], ['key' => ['required', 'string', 'max:128', 'regex:/^[\x20-\x7E]+$/D']])->validate();
        $digest = hash_hmac('sha256', NovelIntentService::canonical([$command, $payload]), config('app.key'));

        return DB::transaction(function () use ($actor, $command, $payload, $key, $digest) {
            DB::select("SELECT pg_advisory_xact_lock(hashtextextended('provider-config',0))");
            $identity = ['actor_id' => $actor->id, 'command_key' => $key];
            if ($prior = DB::table('provider_commands')->where($identity)->first()) {
                if (! hash_equals($prior->request_hmac, $digest)) {
                    throw new NovelProblem('IDEMPOTENCY_CONFLICT', '此操作键已用于不同配置。');
                }

                return json_decode($prior->response, true, flags: JSON_THROW_ON_ERROR);
            }
            $result = match ($command) {
                'provider' => $this->provider($actor, $payload),
                'model' => $this->model($actor, $payload),
                default => throw new NovelProblem('INVALID_COMMAND', '未知配置操作。', 422),
            };
            DB::table('provider_commands')->insert($identity + ['request_hmac' => $digest, 'response' => NovelIntentService::canonical($result)]);

            return $result;
        });
    }

    private function provider(User $actor, #[\SensitiveParameter] array $p): array
    {
        if (array_diff(array_keys($p), ['provider_id', 'expected_version', 'name', 'interface_type', 'endpoint', 'allowed_hosts', 'api_key', 'timeout_seconds', 'connect_timeout_seconds', 'requests_per_minute', 'max_concurrency', 'enabled', 'reason'])) {
            throw new NovelProblem('INVALID_CONFIG', '供应商配置包含未支持字段。', 422);
        }
        $p = Validator::make($p, [
            'provider_id' => 'nullable|integer|min:1', 'expected_version' => 'required|integer|min:0',
            'name' => 'required|string|max:100', 'interface_type' => 'required|in:fake,json_http',
            'endpoint' => 'required|string|max:2048', 'allowed_hosts' => 'required|array|min:1|max:20',
            'allowed_hosts.*' => 'required|string|max:253|distinct', 'api_key' => ['nullable', 'string', 'min:8', 'max:4096', 'regex:/^[\x21-\x7E]+$/D'],
            'timeout_seconds' => 'required|integer|min:1|max:30', 'connect_timeout_seconds' => 'required|integer|min:1|lte:timeout_seconds',
            'requests_per_minute' => 'required|integer|min:1|max:600', 'max_concurrency' => 'required|integer|min:1|max:20',
            'enabled' => 'required|boolean', 'reason' => 'required|string|max:2000',
        ])->validate();
        app(EndpointPolicy::class)->host($p['endpoint'], $p['allowed_hosts']);
        $provider = isset($p['provider_id']) ? DB::table('providers')->where('id', $p['provider_id'])->lockForUpdate()->first() : null;
        if (isset($p['provider_id']) && ! $provider) {
            throw new NovelProblem('NOT_FOUND', '供应商不存在。', 404);
        }
        $latest = $provider ? DB::table('provider_versions')->where('provider_id', $provider->id)->orderByDesc('version')->first() : null;
        if ((int) $p['expected_version'] !== ($latest?->version ?? 0)) {
            throw new NovelProblem('STALE_STATE', '配置已更新，请刷新版本。');
        }
        if (! $provider && DB::table('providers')->where('name', $p['name'])->exists()) {
            throw new NovelProblem('DUPLICATE_PROVIDER', '供应商名称已存在。');
        }
        if ($provider && $p['name'] !== $provider->name) {
            throw new NovelProblem('IMMUTABLE_NAME', '供应商稳定名称不能通过配置版本改写。');
        }
        $id = $provider?->id ?? DB::table('providers')->insertGetId(['name' => $p['name']]);
        $secret = $latest?->secret_id;
        if (! empty($p['api_key'])) {
            $secret = DB::table('provider_secrets')->insertGetId(['provider_id' => $id, 'ciphertext' => Crypt::encryptString($p['api_key'])]);
        }
        $versionId = DB::table('provider_versions')->insertGetId([
            'provider_id' => $id, 'version' => ($latest?->version ?? 0) + 1,
            'interface_type' => $p['interface_type'], 'endpoint' => $p['endpoint'], 'allowed_hosts' => json_encode($p['allowed_hosts']), 'secret_id' => $secret,
            'timeout_seconds' => $p['timeout_seconds'], 'connect_timeout_seconds' => $p['connect_timeout_seconds'],
            'requests_per_minute' => $p['requests_per_minute'], 'max_concurrency' => $p['max_concurrency'],
            'enabled' => $p['enabled'], 'reason' => $p['reason'], 'actor_id' => $actor->id,
        ]);

        return $this->safeProvider(DB::table('provider_versions')->find($versionId), $p['name']);
    }

    private function model(User $actor, array $p): array
    {
        if (array_diff(array_keys($p), ['provider_id', 'provider_model_id', 'expected_version', 'declared_capabilities', 'reason'])) {
            throw new NovelProblem('INVALID_CONFIG', '不能通过配置写入已验证状态或未支持字段。', 422);
        }
        $p = Validator::make($p, ['provider_id' => 'required|integer|min:1', 'provider_model_id' => 'required|string|max:200',
            'expected_version' => 'required|integer|min:0', 'declared_capabilities' => 'required|array:context_tokens,max_output_tokens,structured_output,streaming,reasoning_metering,source_note',
            'declared_capabilities.context_tokens' => 'nullable|integer|min:1|max:10000000', 'declared_capabilities.max_output_tokens' => 'nullable|integer|min:1|max:10000000',
            'declared_capabilities.structured_output' => 'required|in:unknown,supported,unsupported', 'declared_capabilities.streaming' => 'required|in:unknown,supported,unsupported',
            'declared_capabilities.reasoning_metering' => 'required|in:unknown,included,separate', 'declared_capabilities.source_note' => 'required|string|max:2000',
            'reason' => 'required|string|max:2000'])->validate();
        if (isset($p['declared_capabilities']['context_tokens'], $p['declared_capabilities']['max_output_tokens']) && $p['declared_capabilities']['max_output_tokens'] > $p['declared_capabilities']['context_tokens']) {
            throw new NovelProblem('INVALID_CONFIG', '声明的输出容量不能大于上下文容量。', 422);
        }
        if (! DB::table('providers')->where('id', $p['provider_id'])->exists()) {
            throw new NovelProblem('NOT_FOUND', '供应商不存在。', 404);
        }
        $identity = ['provider_id' => $p['provider_id'], 'provider_model_id' => $p['provider_model_id']];
        $model = DB::table('model_profiles')->where($identity)->first();
        $latest = $model ? DB::table('model_profile_versions')->where('model_profile_id', $model->id)->max('version') : 0;
        if ((int) $p['expected_version'] !== (int) $latest) {
            throw new NovelProblem('STALE_STATE', '模型档案已更新。');
        }
        $id = $model?->id ?? DB::table('model_profiles')->insertGetId($identity);
        $version = DB::table('model_profile_versions')->insertGetId(['provider_id' => $p['provider_id'], 'model_profile_id' => $id, 'version' => $latest + 1, 'declared_capabilities' => NovelIntentService::canonical($p['declared_capabilities']), 'actor_id' => $actor->id, 'reason' => $p['reason']]);

        return ['id' => (string) $id, 'version_id' => (string) $version, 'version' => $latest + 1, 'verification_status' => 'unverified', 'effective_capabilities' => null];
    }

    private function safeProvider(object $v, string $name): array
    {
        return ['id' => (string) $v->provider_id, 'version_id' => (string) $v->id, 'version' => $v->version, 'name' => $name,
            'interface_type' => $v->interface_type, 'endpoint' => $v->endpoint, 'allowed_hosts' => json_decode($v->allowed_hosts, true), 'has_key' => $v->secret_id !== null,
            'timeout_seconds' => $v->timeout_seconds, 'connect_timeout_seconds' => $v->connect_timeout_seconds, 'requests_per_minute' => $v->requests_per_minute,
            'max_concurrency' => $v->max_concurrency, 'enabled' => $v->enabled, 'reason' => $v->reason];
    }

    public function listing(User $actor, array $filters = []): array
    {
        app(NovelIntentService::class)->authorize($actor);
        $v = Validator::make($filters, ['q' => 'nullable|string|max:100', 'interface_type' => 'nullable|in:fake,json_http', 'enabled' => 'nullable|boolean', 'declared_structured_output' => 'nullable|in:unknown,supported,unsupported', 'page' => 'nullable|integer|min:1|max:100000'])->validate();
        $query = DB::table('provider_versions as v')->join('providers as p', 'p.id', '=', 'v.provider_id')->whereRaw('v.id=(SELECT max(v2.id) FROM provider_versions v2 WHERE v2.provider_id=v.provider_id)');
        if (! empty($v['q'])) {
            $query->where('p.name', 'ilike', '%'.$v['q'].'%');
        }
        if (! empty($v['interface_type'])) {
            $query->where('v.interface_type', $v['interface_type']);
        }
        if (isset($v['enabled'])) {
            $query->where('v.enabled', $v['enabled']);
        }
        $total = (clone $query)->count();
        $rows = $query->select('v.*', 'p.name')->orderBy('p.id')->offset((($v['page'] ?? 1) - 1) * 25)->limit(25)->get();

        return ['providers' => $rows->map(fn ($row) => $this->safeProvider($row, $row->name))->all(), 'total' => $total, 'page' => $v['page'] ?? 1,
            'models' => DB::table('model_profiles as m')->join('model_profile_versions as v', 'v.model_profile_id', '=', 'm.id')->whereIn('m.provider_id', $rows->pluck('provider_id'))->whereRaw('v.id=(SELECT max(v2.id) FROM model_profile_versions v2 WHERE v2.model_profile_id=m.id)')->when(! empty($v['declared_structured_output']), fn ($q) => $q->whereRaw("v.declared_capabilities->>'structured_output' = ?", [$v['declared_structured_output']]))->select('m.provider_model_id', 'v.id', 'v.provider_id', 'v.version', 'v.verification_status', 'v.declared_capabilities')->orderBy('v.id')->limit(100)->get()->map(fn ($m) => ['version_id' => (string) $m->id, 'provider_id' => (string) $m->provider_id, 'model' => $m->provider_model_id, 'version' => $m->version, 'verification_status' => 'unverified', 'declared_capabilities' => json_decode($m->declared_capabilities, true), 'effective_capabilities' => null])->all()];
    }
}
