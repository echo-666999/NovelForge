<?php

namespace App\Services;

use App\Exceptions\NovelProblem;
use App\Models\User;
use App\ProvidersGateway\FakeProvider;
use App\ProvidersGateway\ProviderAdapter;
use App\ProvidersGateway\StructuredOutput;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Throwable;

/** 单次网关请求：不重试、不核算价格、不触发正式状态提交。 */
final class ProviderGateway
{
    public function execute(User $actor, string $novelId, string $stepId, string $modelVersionId, array $input, string $key, ?ProviderAdapter $adapter = null): array
    {
        app(NovelIntentService::class)->novel($actor, $novelId);
        Validator::make(['key' => $key, 'step' => $stepId, 'model' => $modelVersionId, 'input' => $input], [
            'key' => ['required', 'string', 'max:128', 'regex:/^[\x20-\x7E]+$/D'], 'step' => 'required|integer|min:1', 'model' => 'required|integer|min:1',
            'input' => 'required|array:prompt,output_schema', 'input.prompt' => 'required|string|max:50000', 'input.output_schema' => 'required|array',
        ])->validate();
        if (strlen(NovelIntentService::canonical($input)) > 200000) {
            throw new NovelProblem('REQUEST_TOO_LARGE', '请求结构超过本轮支持的大小。', 422);
        }
        app(StructuredOutput::class)->schema($input['output_schema']);
        $hash = hash('sha256', NovelIntentService::canonical([$modelVersionId, $input]));
        $admission = DB::transaction(function () use ($actor, $novelId, $stepId, $modelVersionId, $input, $key, $hash) {
            // 与编排保持相同锁顺序，外部执行不持有这些锁。
            app(NovelIntentService::class)->novel($actor, $novelId, true);
            $step = DB::table('generation_steps')->where('novel_id', $novelId)->where('id', $stepId)->first();
            if (! $step) {
                throw new NovelProblem('NOT_FOUND', '阶段不存在。', 404);
            }
            DB::table('generation_runs')->where('id', $step->run_id)->lockForUpdate()->first();
            if ($old = DB::table('ai_requests')->where('step_id', $stepId)->where('logical_key', $key)->first()) {
                if (! hash_equals($old->request_hash, $hash)) {
                    throw new NovelProblem('IDEMPOTENCY_CONFLICT', '逻辑请求键已对应不同输入。');
                }

                return ['existing' => (string) $old->id];
            }
            $model = DB::table('model_profile_versions')->find($modelVersionId);
            if (! $model) {
                throw new NovelProblem('NOT_FOUND', '模型版本不存在。', 404);
            }
            DB::table('providers')->where('id', $model->provider_id)->lockForUpdate()->first();
            $provider = DB::table('provider_versions')->where('provider_id', $model->provider_id)->orderByDesc('version')->first();
            if (! $provider->enabled) {
                throw new NovelProblem('PROVIDER_DISABLED', '供应商已停用。');
            }
            if ($provider->interface_type !== 'fake' || ! app()->environment('testing')) {
                throw new NovelProblem('BUDGET_GATE_REQUIRED', '真实调用及能力探测尚未获准，需部署条件和预算门禁。', 403);
            }
            if ($step->status !== 'running' || ! DB::table('generation_steps')->where('id', $stepId)->whereRaw('lease_until > clock_timestamp()')->exists()) {
                throw new NovelProblem('LEASE_LOST', '阶段没有有效租约。');
            }
            $run = DB::table('generation_runs')->find($step->run_id);
            if (DB::table('novels')->where('id', $novelId)->value('state_version') !== $run->expected_state_version) {
                throw new NovelProblem('DEPENDENCY_MISMATCH', '正式状态已经变化。');
            }
            if (in_array($run->status, ['paused', 'canceled', 'needs_attention'], true)) {
                throw new NovelProblem('RUN_NOT_EXECUTABLE', '运行不可继续请求。');
            }
            $recent = DB::table('ai_requests')->where('provider_id', $provider->provider_id)->whereRaw("created_at > clock_timestamp() - interval '60 seconds'")->count();
            $active = DB::table('ai_requests')->where('provider_id', $provider->provider_id)->whereIn('status', ['pending', 'unknown'])->count();
            if ($recent >= $provider->requests_per_minute || $active >= $provider->max_concurrency) {
                throw new NovelProblem('RATE_LIMITED', '供应商速率或并发额度不足。', 429);
            }
            $secret = $provider->secret_id ? Crypt::decryptString(DB::table('provider_secrets')->where('id', $provider->secret_id)->value('ciphertext')) : '';
            $id = DB::table('ai_requests')->insertGetId(['novel_id' => $novelId, 'run_id' => $step->run_id, 'step_id' => $stepId,
                'provider_id' => $provider->provider_id, 'provider_version_id' => $provider->id, 'model_profile_version_id' => $modelVersionId,
                'logical_key' => $key, 'request_hash' => $hash, 'lease_token' => $step->fencing_token, 'status' => 'pending']);
            $body = ['model' => DB::table('model_profiles')->where('id', $model->model_profile_id)->value('provider_model_id')] + $input;
            $this->evidence((string) $id, 'request', NovelIntentService::canonical($body), $secret);

            return ['id' => (string) $id, 'body' => $body, 'secret' => $secret, 'token' => $step->fencing_token];
        });
        if (isset($admission['existing'])) {
            return $this->read($actor, $novelId, $admission['existing']);
        }
        $reply = null;
        $error = null;
        $status = 'failed';
        $issues = [];
        $requestId = null;
        $usage = null;
        try {
            $reply = ($adapter ?? new FakeProvider)->send($admission['body']);
            if (strlen($reply->body) > 1048576) {
                $error = 'RESPONSE_TOO_LARGE';
            } elseif ($reply->status >= 300 && $reply->status < 400) {
                $error = 'REDIRECT_BLOCKED';
            } elseif ($reply->status !== 200) {
                $error = match ($reply->status) {
                    401, 403 => 'PROVIDER_AUTH_FAILED', 429 => 'PROVIDER_RATE_LIMITED', default => 'PROVIDER_HTTP_ERROR'
                };
            } else {
                $decoded = json_decode($reply->body, false, 32, JSON_THROW_ON_ERROR);
                if (! $decoded instanceof \stdClass || ! property_exists($decoded, 'output')) {
                    $issues = [['path' => '$.output', 'code' => 'REQUIRED']];
                } else {
                    $issues = app(StructuredOutput::class)->issues($decoded->output, $input['output_schema']);
                }
                $error = $issues ? 'INVALID_STRUCTURED_OUTPUT' : null;
                $status = $issues ? 'failed' : 'succeeded';
                $requestId = isset($decoded->request_id) && is_string($decoded->request_id) ? mb_substr($this->redact($decoded->request_id, $admission['secret']), 0, 200) : null;
                $usage = isset($decoded->usage) && $decoded->usage instanceof \stdClass ? json_decode($this->redact(json_encode($decoded->usage, JSON_THROW_ON_ERROR), $admission['secret']), true) : null;
            }
        } catch (\JsonException) {
            $error = 'INVALID_JSON';
            $issues = [['path' => '$', 'code' => 'INVALID_JSON']];
        } catch (NovelProblem $e) {
            $error = $e->errorCode;
        } catch (Throwable) {
            // 不记录传输异常原文，它可能包含认证头；未知结果绝不自动重发。
            $status = 'unknown';
            $error = 'PROVIDER_OUTCOME_UNKNOWN';
        }
        DB::transaction(function () use ($actor, $novelId, $stepId, $admission, $reply, &$status, &$error, $issues, $requestId, $usage) {
            app(NovelIntentService::class)->novel($actor, $novelId, true);
            $step = DB::table('generation_steps')->find($stepId);
            $run = DB::table('generation_runs')->where('id', $step->run_id)->lockForUpdate()->first();
            $novel = DB::table('novels')->find($novelId);
            if ($step->status !== 'running' || $step->fencing_token !== $admission['token'] || ! DB::table('generation_steps')->where('id', $stepId)->whereRaw('lease_until > clock_timestamp()')->exists()
                || in_array($run->status, ['paused', 'canceled', 'needs_attention'], true) || $novel->state_version !== $run->expected_state_version) {
                $status = 'quarantined';
                $error = 'STALE_REQUEST_RESULT';
            }
            if ($reply) {
                $this->evidence($admission['id'], 'response', $reply->body, $admission['secret']);
            }
            DB::table('ai_requests')->where('id', $admission['id'])->update(['status' => $status, 'error_code' => $error,
                'issues' => $this->redact(json_encode($issues, JSON_UNESCAPED_UNICODE), $admission['secret']), 'provider_request_id' => $requestId, 'http_status' => $reply?->status, 'usage' => $usage === null ? null : json_encode($usage), 'completed_at' => now()]);
        });

        return $this->read($actor, $novelId, $admission['id']);
    }

    private function redact(string $text, #[\SensitiveParameter] string $secret): string
    {
        if ($secret === '') {
            return $text;
        }
        $needles = [$secret, substr(json_encode($secret), 1, -1)];
        $safe = str_replace($needles, '[REDACTED]', $text);
        // 供应商可能使用Unicode转义回显密钥，先语义解码再检查，避免绕过脱敏。
        try {
            $normalized = json_encode(json_decode($text, false, 64, JSON_THROW_ON_ERROR), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
            $redacted = str_replace($needles, '[REDACTED]', $normalized);
            if ($redacted !== $normalized) {
                return $redacted;
            }
        } catch (\JsonException) {
            // 非JSON原始错误响应也保留原字节，只替换可识别密钥。
        }

        return $safe;
    }

    private function evidence(string $id, string $kind, string $raw, #[\SensitiveParameter] string $secret): void
    {
        $safe = $this->redact($raw, $secret);
        DB::table('ai_request_evidence')->insert(['request_id' => $id, 'kind' => $kind, 'ciphertext' => Crypt::encryptString($safe), 'content_hash' => hash('sha256', $safe), 'redacted' => $safe !== $raw]);
    }

    public function read(User $actor, string $novelId, string $requestId): array
    {
        app(NovelIntentService::class)->novel($actor, $novelId);
        $r = DB::table('ai_requests')->where('novel_id', $novelId)->where('id', $requestId)->first();
        if (! $r) {
            throw new NovelProblem('NOT_FOUND', '请求不存在。', 404);
        }

        return ['id' => (string) $r->id, 'step_id' => (string) $r->step_id, 'provider_version_id' => (string) $r->provider_version_id, 'model_profile_version_id' => (string) $r->model_profile_version_id,
            'status' => $r->status, 'error_code' => $r->error_code, 'issues' => json_decode($r->issues ?? '[]', true), 'usage' => $r->usage === null ? null : json_decode($r->usage, true),
            'provider_request_id' => $r->provider_request_id, 'cost_status' => $r->cost_status, 'evidence_kind' => 'fake', 'capability_status' => 'unverified',
            'evidence' => DB::table('ai_request_evidence')->where('request_id', $r->id)->select('id', 'kind', 'content_hash', 'redacted')->get()->map(fn ($e) => ['id' => (string) $e->id, 'kind' => $e->kind, 'content_hash' => $e->content_hash, 'redacted' => $e->redacted])->all()];
    }

    /** 原始脱敏证据仅供有书归属权限的内部审计读取，不挂公开下载路由。 */
    public function evidenceText(User $actor, string $novelId, string $requestId, string $kind): ?string
    {
        $this->read($actor, $novelId, $requestId);
        $cipher = DB::table('ai_request_evidence')->where('request_id', $requestId)->where('kind', $kind)->value('ciphertext');

        return $cipher === null ? null : Crypt::decryptString($cipher);
    }
}
