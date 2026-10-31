<?php

namespace App\Services;

use App\Exceptions\NovelProblem;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;

/** 真实发送资格以冻结运行、精确请求和已持久预算为依据；开关本身不授予资格。 */
final class RealExecutionAdmission
{
    public function configuration(array $frozen, string $model, string $provider): void
    {
        if (! config('novelforge.real_execution_enabled', false) || ($frozen['execution_mode'] ?? null) !== 'production') {
            throw new NovelProblem('BUDGET_GATE_REQUIRED', '真实运行开关或冻结模式未满足。', 403);
        }
        $probe = ($frozen['capability_probe'] ?? false) === true
            && ($frozen['schema_version'] ?? null) === 'planning-v1'
            && ($frozen['nodes'][0]['stage'] ?? null) === 'planning.intake';
        if (! $probe && ! DB::table('model_capability_evidence')->where('model_profile_version_id', $model)->where('provider_version_id', $provider)->exists()) {
            throw new NovelProblem('CAPABILITY_UNVERIFIED', '当前供应商与模型版本尚无真实成功能力证据。');
        }
    }

    public function request(string $id, array $body, array $transport): void
    {
        $q = DB::table('ai_requests')->find($id);
        if (! $q || $q->status !== 'pending' || $q->evidence_kind !== 'real_provider') {
            throw new NovelProblem('REAL_REQUEST_NOT_PENDING', '真实传输必须对应待处理的持久请求。');
        }
        $run = DB::table('generation_runs')->find($q->run_id);
        $this->configuration(json_decode($run->frozen_config, true), (string) $q->model_profile_version_id, (string) $q->provider_version_id);
        $provider = DB::table('provider_versions')->find($q->provider_version_id);
        $latest = DB::table('provider_versions')->where('provider_id', $q->provider_id)->orderByDesc('version')->first();
        $step = DB::table('generation_steps')->find($q->step_id);
        if (! $latest->enabled || $provider->endpoint !== $transport['endpoint']
            || json_decode($provider->allowed_hosts, true) !== $transport['allowed_hosts']
            || $step->status !== 'running' || $step->fencing_token !== $q->lease_token
            || ! DB::table('generation_steps')->where('id', $step->id)->whereRaw('lease_until > clock_timestamp()')->exists()
            || DB::table('novels')->where('id', $q->novel_id)->value('state_version') !== $run->expected_state_version
            || in_array($run->status, ['paused', 'canceled', 'needs_attention'], true)
            || ! DB::table('budget_reservations')->where('request_id', $id)->where('status', 'reserved')->exists()) {
            throw new NovelProblem('REAL_TRANSPORT_NOT_READY', '供应商、租约、运行或预算已改变，禁止发送。');
        }
        // 首轮真实验收只接受小文本请求，避免进入尚未配置价格的长上下文档位。
        if (strlen(NovelIntentService::canonical($body)) > 128000) {
            throw new NovelProblem('REAL_INPUT_LIMIT', '真实验收请求超过128000字节边界。');
        }
        $e = DB::table('ai_request_evidence')->where('request_id', $id)->where('kind', 'request')->first();
        $saved = $e ? Crypt::decryptString($e->ciphertext) : null;
        if ($saved === null || ! hash_equals($e->content_hash, hash('sha256', $saved))
            || $this->wireHash($saved) !== $this->wireHash(json_encode($body, JSON_THROW_ON_ERROR))) {
            throw new NovelProblem('CONFIGURATION_MISMATCH', '发送内容必须逐项匹配持久请求证据。');
        }
    }

    /** 仅归一化传输对象键顺序；保留数组顺序、空对象与空数组及标量类型，旧证据摘要不改写。 */
    private function wireHash(string $json): string
    {
        $sort = function ($value) use (&$sort) {
            if ($value instanceof \stdClass) {
                $fields = get_object_vars($value);
                ksort($fields, SORT_STRING);

                return (object) array_map($sort, $fields);
            }

            return is_array($value) ? array_map($sort, $value) : $value;
        };

        return hash('sha256', json_encode($sort(json_decode($json, false, 32, JSON_THROW_ON_ERROR)), JSON_THROW_ON_ERROR | JSON_PRESERVE_ZERO_FRACTION));
    }
}
