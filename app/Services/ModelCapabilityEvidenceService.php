<?php

namespace App\Services;

use App\Exceptions\NovelProblem;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/** 能力观察追加保存，不能把单次小请求提升为未测试容量的证明。 */
final class ModelCapabilityEvidenceService
{
    public function capture(User $actor, string $novel, string $request): array
    {
        $gateway = app(ProviderGateway::class);
        $gateway->read($actor, $novel, $request);

        return DB::transaction(function () use ($actor, $novel, $request, $gateway) {
            $q = DB::table('ai_requests')->where('id', $request)->lockForUpdate()->first();
            if ($q->status !== 'succeeded' || $q->evidence_kind !== 'real_provider') {
                throw new NovelProblem('REAL_CAPABILITY_EVIDENCE_REQUIRED', '离线、失败或未知请求不能作为真实能力证据。');
            }
            $raw = $gateway->evidenceText($actor, $novel, $request, 'response');
            $body = json_decode($raw ?? '', true, 32, JSON_THROW_ON_ERROR);
            if (! is_string($body['model'] ?? null) || $body['model'] === '' || strlen($body['model']) > 200 || ($body['status'] ?? null) !== 'completed') {
                throw new NovelProblem('MODEL_ID_UNCONFIRMED', '响应未报告完整的实际模型身份。');
            }
            // 不推测别名或快照映射；模型身份不一致时保留请求证据并等待明确核对。
            $requested = DB::table('model_profile_versions as v')->join('model_profiles as m', 'm.id', '=', 'v.model_profile_id')->where('v.id', $q->model_profile_version_id)->value('m.provider_model_id');
            if ($body['model'] !== $requested) {
                throw new NovelProblem('MODEL_ID_MISMATCH', '响应模型与所选模型标识不同，不能自动确认能力。');
            }
            if ($old = DB::table('model_capability_evidence')->where('request_id', $request)->first()) {
                return (array) $old;
            }
            $e = DB::table('ai_request_evidence')->where('request_id', $request)->where('kind', 'response')->first();
            $id = DB::table('model_capability_evidence')->insertGetId([
                'model_profile_version_id' => $q->model_profile_version_id, 'provider_version_id' => $q->provider_version_id,
                'request_id' => $request, 'response_evidence_id' => $e->id, 'response_hash' => $e->content_hash,
                'observed_model' => $body['model'], 'actor_id' => $actor->id,
                'observations' => NovelIntentService::canonical(['structured_output' => 'observed', 'scope' => 'single_successful_response', 'capacity_verified' => false, 'usage_observed' => $q->usage !== null]),
            ]);

            return (array) DB::table('model_capability_evidence')->find($id);
        });
    }
}
