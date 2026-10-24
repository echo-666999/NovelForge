<?php

namespace App\Services;

use App\Exceptions\NovelProblem;
use App\Generation\StageExecutor;
use App\Generation\StageResult;
use App\Models\OutlineVersion;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Throwable;

/** C01 持久编排基础；目前只有测试支架可驱动，尚未开放生产调度。 */
final class GenerationService
{
    private function harness(): void
    {
        if (! app()->environment('testing')) {
            throw new NovelProblem('STAGE_NOT_AVAILABLE', '测试阶段仅允许在测试环境执行。', 403);
        }
    }

    private function hash(array $value): string
    {
        return hash('sha256', NovelIntentService::canonical($value));
    }

    private function json(string $value): array
    {
        return json_decode($value, true, flags: JSON_THROW_ON_ERROR);
    }

    public function start(User $actor, string $novelId, array $payload, string $key): array
    {
        $this->harness();
        Validator::make($payload + ['key' => $key], [
            'key' => ['required', 'string', 'max:128', 'regex:/^[\x20-\x7E]+$/D'],
            'outline_version_id' => 'required|string|regex:/^[1-9][0-9]{0,18}$/D',
            'expected_version' => 'required|integer|min:1',
            'configuration' => 'sometimes|array:route_version_id,route_key,variables',
            'nodes' => 'required|array|min:1|max:100',
            'nodes.*' => 'required|array:key,stage,depends_on,input',
            'nodes.*.key' => 'required|string|regex:/^[a-z][a-z0-9_]{0,79}$/D|distinct',
            'nodes.*.stage' => 'required|in:test.echo',
            'nodes.*.depends_on' => 'present|array|max:100',
            'nodes.*.depends_on.*' => 'required|string',
            'nodes.*.input' => 'required|array:text',
            'nodes.*.input.text' => 'required|string|max:10000',
        ])->validate();
        if (array_diff(array_keys($payload), ['outline_version_id', 'expected_version', 'nodes', 'configuration'])) {
            throw new NovelProblem('INVALID_GRAPH', '测试运行不接受未知配置。', 422);
        }
        // 图必须显式拓扑排序；拒绝循环、未知依赖及重复边，不默默改写调用者顺序。
        $seen = [];
        foreach ($payload['nodes'] as $node) {
            if (array_diff($node['depends_on'], $seen) || count(array_unique($node['depends_on'])) !== count($node['depends_on'])) {
                throw new NovelProblem('INVALID_GRAPH', '依赖必须指向此前声明的不同节点。', 422);
            }
            $seen[] = $node['key'];
        }

        return DB::transaction(function () use ($actor, $novelId, $payload, $key) {
            $novel = app(NovelIntentService::class)->novel($actor, $novelId, true);
            $identity = ['actor_id' => $actor->id, 'novel_id' => $novelId, 'command_key' => $key];
            $hash = $this->hash($payload);
            if ($previous = DB::table('generation_runs')->where($identity)->first()) {
                if (! hash_equals($previous->request_hash, $hash)) {
                    throw new NovelProblem('IDEMPOTENCY_CONFLICT', '操作键已用于不同内容。');
                }
                // 重放原始响应，不能把已推进的运行状态冒充首次创建响应。
                $response = DB::table('novel_commands')->where(['actor_id' => $actor->id, 'novel_scope' => $novelId, 'command' => 'generation.test.start', 'key' => $key])->value('response');

                return $this->json($response);
            }
            if ((int) $novel->row_version !== (int) $payload['expected_version'] || $novel->status === 'archived') {
                throw new NovelProblem('STALE_STATE', '小说已改变或归档。');
            }
            if (strlen($payload['outline_version_id']) === 19 && strcmp($payload['outline_version_id'], '9223372036854775807') > 0) {
                throw new NovelProblem('INVALID_SOURCE', '规划版本标识超出范围。', 422);
            }
            $outline = OutlineVersion::where('novel_id', $novelId)->whereKey($payload['outline_version_id'])->where('status', 'sealed')->first();
            if (! $outline || ! hash_equals($outline->content_hash, $this->hash($outline->content))) {
                throw new NovelProblem('INVALID_SOURCE', '必须使用本书完整封存的规划版本。');
            }
            $frozen = ['schema_version' => 'test-dag-v1', 'executor_version' => 'test.echo-v1', 'goal' => 'all_nodes_succeeded',
                'outline_version_id' => $outline->id, 'outline_hash' => $outline->content_hash,
                'expected_state_version' => (string) $novel->state_version, 'nodes' => $payload['nodes']];
            // C04 配置只在启动时解析一次；后续默认版本变化不参与既有运行。
            if (isset($payload['configuration'])) {
                $frozen['configuration'] = app(AuthoringConfigurationService::class)->freeze($actor, $payload['configuration'], 'test.echo');
            }
            $id = DB::table('generation_runs')->insertGetId($identity + [
                'configuration_route_version_id' => $frozen['configuration']['route']['id'] ?? null,
                'outline_version_id' => $outline->id, 'expected_state_version' => $novel->state_version,
                'request_hash' => $hash, 'frozen_config' => NovelIntentService::canonical($frozen), 'input_hash' => $this->hash($frozen),
                'created_at' => now(), 'updated_at' => now(),
            ]);
            $response = $this->read($actor, $novelId, (string) $id);
            DB::table('novel_commands')->insert(['actor_id' => $actor->id, 'novel_scope' => $novelId, 'command' => 'generation.test.start', 'key' => $key, 'request_hash' => $hash, 'response' => NovelIntentService::canonical($response), 'created_at' => now()]);

            return $response;
        });
    }

    private function run(User $actor, string $novelId, string $runId, bool $lock = false): object
    {
        app(NovelIntentService::class)->novel($actor, $novelId);
        if (! preg_match('/^[1-9][0-9]{0,18}$/D', $runId) || (strlen($runId) === 19 && strcmp($runId, '9223372036854775807') > 0)) {
            throw new NovelProblem('NOT_FOUND', '运行不存在。', 404);
        }
        // 与规划/正式状态写入保持小说在前、运行在后的统一锁顺序。
        if ($lock) {
            DB::table('novels')->where('id', $novelId)->lockForUpdate()->first();
        }
        $query = DB::table('generation_runs')->where('novel_id', $novelId)->where('id', $runId);
        $run = ($lock ? $query->lockForUpdate() : $query)->first();
        if (! $run) {
            throw new NovelProblem('NOT_FOUND', '运行不存在。', 404);
        }

        return $run;
    }

    public function read(User $actor, string $novelId, string $runId): array
    {
        $run = $this->run($actor, $novelId, $runId);
        $serialize = function (object $row): array {
            $data = (array) $row;
            foreach ($data as $key => $value) {
                if ($value !== null && ($key === 'id' || str_ends_with($key, '_id') || in_array($key, ['expected_state_version', 'fencing_token'], true))) {
                    $data[$key] = (string) $value;
                }
                if (in_array($key, ['frozen_config', 'frozen_input', 'content'], true)) {
                    $data[$key] = $this->json($value);
                }
            }

            return $data;
        };

        return ['run' => $serialize($run),
            'steps' => DB::table('generation_steps')->where('run_id', $runId)->orderBy('id')->get()->map($serialize)->all(),
            'artifacts' => DB::table('generation_artifacts')->where('run_id', $runId)->orderBy('id')->get()->map($serialize)->all()];
    }

    private function verifyRun(object $run): array
    {
        $frozen = $this->json($run->frozen_config);
        $outline = OutlineVersion::find($run->outline_version_id);
        $novel = DB::table('novels')->find($run->novel_id);
        if (! hash_equals($run->input_hash, $this->hash($frozen)) || ! $outline
            || ! hash_equals($frozen['outline_hash'], $this->hash($outline->content))
            || (string) $novel->state_version !== (string) $run->expected_state_version || $novel->status === 'archived') {
            throw new NovelProblem('DEPENDENCY_MISMATCH', '冻结输入或正式状态不匹配，禁止复用。');
        }

        return $frozen;
    }

    private function artifact(object $step): object
    {
        $artifact = DB::table('generation_artifacts')->where('step_id', $step->id)->first();
        if (! $artifact || ! hash_equals($artifact->source_hash, $step->dependency_hash)
            || ! hash_equals($artifact->content_hash, $this->hash($this->json($artifact->content)))
            || ! hash_equals($step->dependency_hash, $this->hash($this->json($step->frozen_input)))
            || $artifact->schema_version !== 'test.echo-v1') {
            throw new NovelProblem('DEPENDENCY_MISMATCH', '产物来源或内容哈希不匹配。');
        }

        return $artifact;
    }

    /** 认领可恢复的测试阶段；令牌及时间均来自持久数据库。 */
    public function claim(User $actor, string $novelId, string $runId, string $scopeKey, ?int $retryOf = null, ?string $modelVersionId = null, ?string $switchReason = null): ?array
    {
        $this->harness();

        return DB::transaction(function () use ($actor, $novelId, $runId, $scopeKey, $retryOf, $modelVersionId, $switchReason) {
            $run = $this->run($actor, $novelId, $runId, true);
            $frozen = $this->verifyRun($run);
            if (in_array($run->status, ['canceled', 'paused', 'needs_attention'], true)) {
                throw new NovelProblem('RUN_NOT_EXECUTABLE', '运行当前不可执行。');
            }
            $node = collect($frozen['nodes'])->firstWhere('key', $scopeKey);
            if (! $node) {
                throw new NovelProblem('INVALID_STAGE', '阶段不在冻结依赖图中。', 422);
            }
            $dependencies = [];
            foreach ($node['depends_on'] as $key) {
                $source = DB::table('generation_steps')->where('run_id', $runId)->where('scope_key', $key)->orderByDesc('attempt_no')->first();
                if (! $source || $source->status !== 'succeeded') {
                    $this->aggregate($run);

                    return null;
                }
                $artifact = $this->artifact($source);
                $dependencies[] = ['step_id' => (string) $source->id, 'artifact_id' => (string) $artifact->id, 'content_hash' => $artifact->content_hash];
            }
            $previous = DB::table('generation_steps')->where('run_id', $runId)->where('scope_key', $scopeKey)->orderByDesc('attempt_no')->first();
            // 重试通知不能在首尝试出现前偷偷创建首尝试。
            if (! $previous && $retryOf !== null) {
                return null;
            }
            // 显式切换必须新建尝试；有效租约或未知结果不能被静默替换。
            if ($modelVersionId !== null && (! $previous || ! in_array($previous->status, ['failed', 'deferred', 'blocked'], true) || $retryOf !== (int) $previous->attempt_no || ! is_string($switchReason) || trim($switchReason) === '' || mb_strlen($switchReason) > 1000)) {
                throw new NovelProblem('INVALID_MODEL_SWITCH', '模型切换须引用已结束的旧尝试并说明原因。');
            }
            if ($previous && $retryOf !== null && isset($frozen['configuration']) && DB::table('ai_requests')->where('step_id', $previous->id)->whereIn('status', ['pending', 'unknown'])->exists()) {
                throw new NovelProblem('UNKNOWN_REQUEST_OUTCOME', '旧请求结果未知，禁止创建替代请求。');
            }
            $input = ['run_input_hash' => $run->input_hash, 'node' => $node, 'dependencies' => $dependencies];
            if ($previous) {
                $previousInput = $this->json($previous->frozen_input);
                unset($previousInput['resume_checkpoint'], $previousInput['configuration_selection']);
                if ($this->hash($previousInput) !== $this->hash($input)) {
                    throw new NovelProblem('DEPENDENCY_MISMATCH', '依赖身份改变，禁止复用既有尝试。');
                }
                if ($previous->status === 'succeeded') {
                    $this->artifact($previous);

                    return null;
                }
                if (in_array($previous->status, ['queued', 'running'], true)) {
                    if ((int) $previous->attempt_no !== ($retryOf ?? 0) + 1) {
                        return null;
                    }
                    if (DB::table('generation_steps')->where('id', $previous->id)->whereRaw('lease_until > clock_timestamp()')->exists()) {
                        return null;
                    }
                    if ($previous->fencing_token >= config('orchestration.max_claims')) {
                        DB::table('generation_steps')->where('id', $previous->id)->update(['status' => 'failed', 'error_code' => 'LEASE_EXHAUSTED', 'updated_at' => now()]);
                        $this->aggregate($run);

                        return null;
                    }
                    // 仅测试阶段允许自动重领；真实请求未知结果不能自动重复调用。
                    $saved = $this->json($previous->frozen_input);
                    $resume = $previous->resume_artifact_id ? DB::table('generation_artifacts')->find($previous->resume_artifact_id) : null;
                    $saved['checkpoint'] = $resume ? ['artifact_id' => (string) $resume->id, 'content' => $this->json($resume->content)] : null;

                    return $this->reserveQueuedRun($novelId, $runId) ? $this->lease((string) $previous->id, $saved) : null;
                }
                // 重试必须指明旧尝试号，重复调度不会再创建下一次尝试。
                if ($retryOf !== (int) $previous->attempt_no) {
                    return null;
                }
                if (! in_array($previous->status, ['failed', 'deferred', 'blocked'], true) || $previous->attempt_no >= 3) {
                    throw new NovelProblem('RETRY_LIMIT', '当前尝试不可重试或已达到三次上限。');
                }
            }
            if (in_array($run->status, ['canceled', 'paused', 'needs_attention'], true)) {
                throw new NovelProblem('RUN_NOT_EXECUTABLE', '运行当前不可执行。');
            }
            if (! $this->reserveQueuedRun($novelId, $runId)) {
                return null;
            }
            // 选择只能来自运行启动时冻结的主备清单，重试默认继续原模型。
            if (isset($frozen['configuration'])) {
                $cfg = $frozen['configuration'];
                $priorSelection = $previous ? ($this->json($previous->frozen_input)['configuration_selection'] ?? null) : null;
                $selected = $modelVersionId ?? ($priorSelection['model_version_id'] ?? $cfg['models'][0]['model_version_id']);
                if (! in_array($selected, array_column($cfg['models'], 'model_version_id'), true)) {
                    throw new NovelProblem('UNAPPROVED_FALLBACK', '模型不在冻结批准清单中。');
                }
                $input['configuration_selection'] = ['model_version_id' => $selected, 'reason' => $switchReason ?? '沿用冻结主模型或上一尝试', 'configuration_hash' => $this->hash($cfg)];
            } elseif ($modelVersionId !== null) {
                throw new NovelProblem('UNAPPROVED_FALLBACK', '无路由快照的旧支架不能切换模型。');
            }
            $checkpoint = $modelVersionId === null && $previous?->checkpoint_artifact_id ? $this->artifact($previous) : null;
            // 续跑来源也冻结到尝试输入，重启后仍能追溯具体检查点。
            $input['resume_checkpoint'] = $checkpoint ? ['artifact_id' => (string) $checkpoint->id, 'content_hash' => $checkpoint->content_hash] : null;
            $stepId = DB::table('generation_steps')->insertGetId([
                'novel_id' => $novelId, 'run_id' => $runId, 'stage' => $node['stage'], 'scope_key' => $scopeKey,
                'resume_artifact_id' => $checkpoint?->id, 'attempt_no' => ($previous?->attempt_no ?? 0) + 1, 'frozen_input' => NovelIntentService::canonical($input),
                'dependency_hash' => $this->hash($input), 'status' => 'running', 'created_at' => now(), 'updated_at' => now(),
            ]);
            foreach ($dependencies as $dependency) {
                DB::table('generation_dependencies')->insert(['novel_id' => $novelId, 'run_id' => $runId, 'step_id' => $stepId,
                    'source_step_id' => $dependency['step_id'], 'source_artifact_id' => $dependency['artifact_id']]);
            }
            DB::table('generation_runs')->where('id', $runId)->update(['status' => 'running', 'error_code' => null, 'updated_at' => now()]);

            return $this->lease((string) $stepId, $input + ['checkpoint' => $checkpoint ? ['artifact_id' => (string) $checkpoint->id, 'content' => $this->json($checkpoint->content)] : null]);
        });
    }

    /** 领取与提交分开，任何执行器都不能跨租约提交结果。 */
    public function advance(User $actor, string $novelId, string $runId, string $scopeKey, StageExecutor $executor, ?int $retryOf = null, ?string $eventId = null): array
    {
        $claim = $this->claim($actor, $novelId, $runId, $scopeKey, $retryOf);
        if (! $claim) {
            return $this->read($actor, $novelId, $runId);
        }
        try {
            $result = $executor->execute($claim['input']);
        } catch (Throwable) {
            // 不持久化未脱敏的异常正文，避免意外泄漏调用方上下文。
            $result = new StageResult('failed', ['text' => '测试阶段执行或输出校验失败'], 'STAGE_EXECUTION_FAILED');
        }
        $this->complete($actor, $novelId, $runId, $claim, $result, $eventId);

        return $this->read($actor, $novelId, $runId);
    }

    /** 完成必须匹配当前租约；拒绝迟到 Worker，不覆盖新产物。 */
    public function complete(User $actor, string $novelId, string $runId, array $claim, StageResult $result, ?string $eventId = null): bool
    {
        $this->harness();

        return DB::transaction(function () use ($actor, $novelId, $runId, $claim, $result, $eventId) {
            $run = $this->run($actor, $novelId, $runId, true);
            $step = DB::table('generation_steps')->where('run_id', $runId)->where('id', $claim['id'])->lockForUpdate()->first();
            // 过期或被新 Worker 替代的令牌不可写入产物、状态或消费回执。
            if (! $step || $step->status !== 'running' || (string) $step->fencing_token !== (string) $claim['token'] || $step->lease_owner !== $claim['owner']
                || ! DB::table('generation_steps')->where('id', $step->id)->whereRaw('lease_until > clock_timestamp()')->exists()) {
                return false;
            }
            $result = $this->validatedResult($result);
            $status = $result->status;
            $error = $result->errorCode;
            if (in_array($run->status, ['paused', 'canceled'], true)) {
                $status = 'quarantined';
                $error = 'RUN_NOT_EXECUTABLE';
            }
            try {
                $this->verifyRun($run);
            } catch (NovelProblem) {
                $status = 'quarantined';
                $error = 'DEPENDENCY_MISMATCH';
            }
            // 产物与检查点及阶段状态在同一事务落盘，不留下半个成功阶段。
            $artifactId = DB::table('generation_artifacts')->insertGetId([
                'novel_id' => $novelId, 'run_id' => $runId, 'step_id' => $step->id,
                'type' => $status === 'succeeded' ? 'test_result' : 'test_evidence', 'schema_version' => 'test.echo-v1',
                'content' => NovelIntentService::canonical($result->content), 'content_hash' => $this->hash($result->content),
                'source_hash' => $step->dependency_hash, 'storage_ref' => 'db:'.Str::uuid(), 'created_at' => now(),
            ]);
            $updated = DB::table('generation_steps')->where('id', $step->id)->where('fencing_token', $claim['token'])->where('lease_owner', $claim['owner'])
                ->whereRaw('lease_until > clock_timestamp()')->update(['status' => $status, 'error_code' => $error,
                    'checkpoint_artifact_id' => $status === 'deferred' ? $artifactId : null, 'updated_at' => now()]);
            if ($updated !== 1) {
                // 校验期间到期也必须回滚刚写入的产物，不能留下半份提交。
                throw new NovelProblem('LEASE_LOST', '提交期间租约已失效。');
            }
            if (! in_array($run->status, ['paused', 'canceled'], true)) {
                $this->aggregate($run);
            } elseif ($run->status === 'canceled' && ! DB::table('generation_steps')->where('run_id', $runId)->where('status', 'running')->exists()) {
                DB::table('generation_book_slots')->where('run_id', $run->id)->delete();
            }
            if ($eventId !== null) {
                app(OutboxService::class)->acknowledge($eventId);
            }

            return true;
        });
    }

    private function validatedResult(StageResult $result): StageResult
    {
        try {
            Validator::make(['status' => $result->status, 'content' => $result->content, 'error_code' => $result->errorCode], [
                'status' => 'required|in:succeeded,deferred,blocked,failed,canceled',
                'content' => 'required|array:text', 'content.text' => 'required|string|max:20000',
                'error_code' => ['nullable', 'string', 'max:80', 'regex:/^[A-Z][A-Z0-9_]*$/D'],
            ])->validate();
            if ($result->status !== 'succeeded' && ! $result->errorCode) {
                throw new \InvalidArgumentException('非成功结果必须说明原因。');
            }

            return $result;
        } catch (Throwable) {
            return new StageResult('failed', ['text' => '测试阶段输出校验失败'], 'STAGE_EXECUTION_FAILED');
        }
    }

    /** 只在真正领取步骤时占用整书槽，等待依赖或重放消息不能抢回槽位。 */
    private function reserveQueuedRun(string $novelId, string $runId): bool
    {
        if (! DB::table('outbox_events')->where('run_id', $runId)->exists()) {
            return true;
        }
        $slot = DB::table('generation_book_slots')->where('novel_id', $novelId)->first();
        if ($slot && (string) $slot->run_id !== $runId) {
            return false;
        }
        DB::table('generation_book_slots')->insertOrIgnore(['novel_id' => $novelId, 'run_id' => $runId]);

        return true;
    }

    private function lease(string $stepId, array $input): array
    {
        $owner = (string) Str::uuid();
        DB::table('generation_steps')->where('id', $stepId)->update([
            'fencing_token' => DB::raw('fencing_token + 1'), 'lease_owner' => $owner,
            'lease_until' => DB::raw("clock_timestamp() + interval '90 seconds'"),
            'heartbeat_at' => DB::raw('clock_timestamp()'), 'updated_at' => now(),
        ]);
        $token = (string) DB::table('generation_steps')->where('id', $stepId)->value('fencing_token');
        $input['_lease'] = ['id' => $stepId, 'token' => $token, 'owner' => $owner];

        return ['id' => $stepId, 'token' => $token, 'owner' => $owner, 'input' => $input];
    }

    /** 心跳不能复活已过期租约，也不能续期其他 Worker 的令牌。 */
    public function heartbeat(string $stepId, string $token, string $owner): bool
    {
        return DB::table('generation_steps')->where('id', $stepId)->where('fencing_token', $token)->where('lease_owner', $owner)
            ->where('status', 'running')->whereRaw('lease_until > clock_timestamp()')->update([
                'lease_until' => DB::raw("clock_timestamp() + interval '90 seconds'"), 'heartbeat_at' => DB::raw('clock_timestamp()'),
            ]) === 1;
    }

    private function aggregate(object $run): void
    {
        $latest = DB::table('generation_steps')->where('run_id', $run->id)->orderByDesc('attempt_no')->get()->unique('scope_key');
        $states = $latest->pluck('status')->all();
        // 成功目标是整张声明图，不能用“最后一个任务成功”代替。
        $status = count($latest) === count($this->json($run->frozen_config)['nodes']) && count(array_diff($states, ['succeeded'])) === 0 ? 'succeeded' : 'waiting_dependency';
        foreach (['running', 'deferred', 'blocked', 'failed', 'quarantined', 'canceled'] as $candidate) {
            if (in_array($candidate, $states, true)) {
                $status = $candidate === 'quarantined' ? 'needs_attention' : $candidate;
            }
        }
        $error = $latest->first(fn ($step) => $step->error_code !== null)?->error_code;
        DB::table('generation_runs')->where('id', $run->id)->update(['status' => $status, 'error_code' => $error, 'updated_at' => now()]);
        if (in_array($status, ['succeeded', 'failed', 'canceled'], true) && ! in_array('running', $states, true)) {
            DB::table('generation_book_slots')->where('run_id', $run->id)->delete();
        }
    }
}
