<?php

namespace App\Services;

use App\Drafting\DraftReplayAdapter;
use App\Exceptions\NovelProblem;
use App\Extraction\ExtractionReplayAdapter;
use App\Models\User;
use App\Planning\PlanningReplayAdapter;
use App\ProvidersGateway\FakeProvider;
use App\ProvidersGateway\ProviderAdapter;
use App\ProvidersGateway\StructuredOutput;
use App\Review\ReviewReplayAdapter;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Throwable;

/** 单次网关请求：统一预算预留与Usage核销，不自动重试或触发正式状态提交。 */
final class ProviderGateway
{
    public function execute(User $actor, string $novelId, string $stepId, string $modelVersionId, array $input, string $key, ?ProviderAdapter $adapter = null): array
    {
        app(NovelIntentService::class)->novel($actor, $novelId);
        // 规划包含完整原始输入和结构证据，单独采用有界容量；旧网关限制保持原值。
        $planningInput = ctype_digit($stepId) && strlen($stepId) < 19 && DB::table('generation_steps as s')->join('generation_runs as r', 'r.id', '=', 's.run_id')->where('s.id', $stepId)->where('s.novel_id', $novelId)->whereIn('r.frozen_config->schema_version', ['planning-v1', 'drafting-v1', 'extraction-v1', 'review-v1'])->exists();
        Validator::make(['key' => $key, 'step' => $stepId, 'model' => $modelVersionId, 'input' => $input], [
            'key' => ['required', 'string', 'max:128', 'regex:/^[\x20-\x7E]+$/D'], 'step' => 'required|integer|min:1', 'model' => 'required|integer|min:1',
            'input' => 'required|array:prompt,output_schema', 'input.prompt' => 'required|string|max:'.($planningInput ? 200000 : 50000), 'input.output_schema' => 'required|array',
        ])->validate();
        if (strlen(NovelIntentService::canonical($input)) > ($planningInput ? 400000 : 200000)) {
            throw new NovelProblem('REQUEST_TOO_LARGE', '请求结构超过本轮支持的大小。', 422);
        }
        app(StructuredOutput::class)->schema($input['output_schema']);
        $hash = hash('sha256', NovelIntentService::canonical([$modelVersionId, $input]));
        $admission = DB::transaction(function () use ($actor, $novelId, $stepId, $modelVersionId, $input, $key, $hash, $adapter) {
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
            // 已绑定运行禁止调用者绕过冻结模板、模型与输出契约。
            $runConfig = json_decode(DB::table('generation_runs')->where('id', $step->run_id)->value('frozen_config'), true)['configuration'] ?? null;
            $selectedConfig = null;
            if ($runConfig) {
                $selection = json_decode($step->frozen_input, true)['configuration_selection'] ?? [];
                if (($selection['model_version_id'] ?? null) !== $modelVersionId || ($selection['configuration_hash'] ?? null) !== app(AuthoringConfigurationService::class)->hash($runConfig) || app(AuthoringConfigurationService::class)->hash($input) !== app(AuthoringConfigurationService::class)->hash(['prompt' => $runConfig['rendered_prompt'], 'output_schema' => $runConfig['prompt']['content']['output_schema']])) {
                    throw new NovelProblem('CONFIGURATION_MISMATCH', '请求必须符合当前尝试冻结配置。');
                }
                $selectedConfig = collect($runConfig['models'])->firstWhere('model_version_id', $modelVersionId);
                if (! $selectedConfig) {
                    throw new NovelProblem('UNAPPROVED_FALLBACK', '实际模型未获冻结路由批准。');
                }
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
            // 最新版本仅提供停用开关；实际请求仍使用启动时冻结的供应商版本。
            if ($selectedConfig) {
                $provider = DB::table('provider_versions')->find($selectedConfig['provider_version_id']);
            }
            $planningReplay = $adapter ?? null;
            if ($planningReplay instanceof PlanningReplayAdapter) {
                $planningFrozen = json_decode(DB::table('generation_runs')->where('id', $step->run_id)->value('frozen_config'), true);
                if (($planningFrozen['schema_version'] ?? null) !== 'planning-v1' || $planningReplay->outputHash() !== hash('sha256', NovelIntentService::canonical($planningFrozen['nodes'][0]['input']['offline_output']))) {
                    throw new NovelProblem('PLANNING_REPLAY_MISMATCH', '离线响应必须是启动时冻结的回放资料。');
                }
            }
            // 正文回放也必须逐字匹配启动快照，不接受调用者替换响应。
            $draftReplay = $adapter instanceof DraftReplayAdapter;
            if ($draftReplay) {
                $draftFrozen = json_decode(DB::table('generation_runs')->where('id', $step->run_id)->value('frozen_config'), true);
                if (($draftFrozen['schema_version'] ?? null) !== 'drafting-v1' || $adapter->outputHash() !== hash('sha256', NovelIntentService::canonical($draftFrozen['nodes'][0]['input']['offline_output']))) {
                    throw new NovelProblem('DRAFT_REPLAY_MISMATCH', '正文响应必须匹配冻结离线资料。');
                }
            }
            // E03 也只允许精确冻结响应的离线适配器；真实供应商门禁不变。
            $extractionReplay = $adapter instanceof ExtractionReplayAdapter;
            if ($extractionReplay) {
                $frozen = json_decode(DB::table('generation_runs')->where('id', $step->run_id)->value('frozen_config'), true);
                if (($frozen['schema_version'] ?? null) !== 'extraction-v1' || $adapter->outputHash() !== hash('sha256', NovelIntentService::canonical($frozen['nodes'][0]['input']['offline_output']))) {
                    throw new NovelProblem('EXTRACTION_REPLAY_MISMATCH', '提取响应必须匹配启动时冻结资料。');
                }
            }
            // 评审也只能执行与启动快照一致的离线响应。
            $reviewReplay = $adapter instanceof ReviewReplayAdapter;
            if ($reviewReplay) {
                $frozen = json_decode(DB::table('generation_runs')->where('id', $step->run_id)->value('frozen_config'), true);
                if (($frozen['schema_version'] ?? null) !== 'review-v1' || $adapter->outputHash() !== hash('sha256', NovelIntentService::canonical($frozen['nodes'][0]['input']['offline_output']))) {
                    throw new NovelProblem('REVIEW_REPLAY_MISMATCH', '评审响应必须精确匹配启动快照。');
                }
            }
            if ($provider->interface_type !== 'fake' || (! app()->environment('testing') && ! $draftReplay && ! $extractionReplay && ! $reviewReplay && ! ($planningReplay instanceof PlanningReplayAdapter && DB::table('generation_runs')->where('id', $step->run_id)->where('frozen_config->schema_version', 'planning-v1')->exists()))) {
                throw new NovelProblem('BUDGET_GATE_REQUIRED', '真实调用及能力探测尚未获准；仍需部署条件、能力验证及外部调用授权，当前仅允许离线测试。', 403);
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
            $billing = json_decode($run->frozen_config, true)['billing'] ?? null;
            if (! $billing || ! isset($billing['prices'][$modelVersionId])) {
                throw new NovelProblem('BUDGET_CONFIGURATION_REQUIRED', '请求缺少冻结的预算与价格。');
            }
            $id = DB::table('ai_requests')->insertGetId(['novel_id' => $novelId, 'run_id' => $step->run_id, 'step_id' => $stepId,
                'provider_id' => $provider->provider_id, 'provider_version_id' => $provider->id, 'model_profile_version_id' => $modelVersionId,
                'route_version_id' => $runConfig['route']['id'] ?? null, 'prompt_version_id' => $runConfig['prompt']['id'] ?? null, 'style_version_id' => $runConfig['style']['id'] ?? null, 'configuration_hash' => $runConfig ? app(AuthoringConfigurationService::class)->hash($runConfig) : null,
                'price_version_id' => $billing['prices'][$modelVersionId]['id'],
                'logical_key' => $key, 'request_hash' => $hash, 'lease_token' => $step->fencing_token, 'status' => 'pending']);
            $body = ['model' => DB::table('model_profiles')->where('id', $model->model_profile_id)->value('provider_model_id')] + $input;
            if ($runConfig) {
                $body['parameters'] = $runConfig['parameters'];
            }
            // 预留、请求和请求证据在同一事务提交；失败即整体回滚，网络尚未执行。
            $budget = app(BudgetService::class)->reserve(DB::table('ai_requests')->find($id), $run, $modelVersionId);
            $body['parameters'] = ($body['parameters'] ?? []) + ['max_output_tokens' => $budget['max_output_tokens'], 'max_reasoning_tokens' => $budget['max_reasoning_tokens']];
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
            // HTTP错误或结构错误仍可能收费；从任何有界JSON响应独立提取计量。
            if (strlen($reply->body) <= 1048576) {
                $metered = json_decode($reply->body);
                if ($metered instanceof \stdClass) {
                    $requestId = isset($metered->request_id) && is_string($metered->request_id) ? mb_substr($this->redact($metered->request_id, $admission['secret']), 0, 200) : null;
                    $usage = isset($metered->usage) && $metered->usage instanceof \stdClass ? json_decode($this->redact(json_encode($metered->usage, JSON_THROW_ON_ERROR), $admission['secret']), true) : null;
                }
            }
            if (strlen($reply->body) > 1048576) {
                $error = 'RESPONSE_TOO_LARGE';
            } elseif ($reply->status >= 300 && $reply->status < 400) {
                $error = 'REDIRECT_BLOCKED';
            } elseif ($reply->status !== 200) {
                $error = match ($reply->status) {
                    401, 403 => 'PROVIDER_AUTH_FAILED', 429 => 'PROVIDER_RATE_LIMITED', 500, 502, 503, 504 => 'PROVIDER_HTTP_'.$reply->status, default => 'PROVIDER_HTTP_ERROR'
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
                'issues' => $this->redact(json_encode($issues, JSON_UNESCAPED_UNICODE), $admission['secret']), 'provider_request_id' => $requestId, 'http_status' => $reply?->status, 'retry_after_seconds' => $reply?->retryAfterSeconds === null ? null : max(0, min(2147483647, $reply->retryAfterSeconds)), 'usage' => $usage === null ? null : json_encode($usage), 'completed_at' => now()]);
            // 隔离或失败响应仍结算费用；缺失Usage转待对账并保留全部预留。
            app(BudgetService::class)->settle($admission['id'], $usage, $requestId, 'gateway-response:'.$admission['id']);
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
            // 对外只返回不可变引用，不暴露配置中的密钥资料。
            'route_version_id' => $r->route_version_id === null ? null : (string) $r->route_version_id,
            'prompt_version_id' => $r->prompt_version_id === null ? null : (string) $r->prompt_version_id,
            'style_version_id' => $r->style_version_id === null ? null : (string) $r->style_version_id,
            'configuration_hash' => $r->configuration_hash,
            'price_version_id' => $r->price_version_id === null ? null : (string) $r->price_version_id,
            'billing' => app(BudgetService::class)->read($requestId),
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
