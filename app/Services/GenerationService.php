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
            'nodes' => 'required|array|min:1|max:100',
            'nodes.*' => 'required|array:key,stage,depends_on,input',
            'nodes.*.key' => 'required|string|regex:/^[a-z][a-z0-9_]{0,79}$/D|distinct',
            'nodes.*.stage' => 'required|in:test.echo',
            'nodes.*.depends_on' => 'present|array|max:100',
            'nodes.*.depends_on.*' => 'required|string',
            'nodes.*.input' => 'required|array:text',
            'nodes.*.input.text' => 'required|string|max:10000',
        ])->validate();
        if (array_diff(array_keys($payload), ['outline_version_id', 'expected_version', 'nodes'])) {
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
            $id = DB::table('generation_runs')->insertGetId($identity + [
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
            DB::table('novels')->where('id', $novelId)->sharedLock()->first();
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
                if ($value !== null && ($key === 'id' || str_ends_with($key, '_id') || $key === 'expected_state_version')) {
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

    /** 一次只领取一个阶段。数据库锁仅保护领取/落盘，执行阶段位于事务之外。 */
    public function advance(User $actor, string $novelId, string $runId, string $scopeKey, StageExecutor $executor, ?int $retryOf = null): array
    {
        $this->harness();
        $claim = DB::transaction(function () use ($actor, $novelId, $runId, $scopeKey, $retryOf) {
            $run = $this->run($actor, $novelId, $runId, true);
            $frozen = $this->verifyRun($run);
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
            $input = ['run_input_hash' => $run->input_hash, 'node' => $node, 'dependencies' => $dependencies];
            if ($previous) {
                $previousInput = $this->json($previous->frozen_input);
                unset($previousInput['resume_checkpoint']);
                if ($this->hash($previousInput) !== $this->hash($input)) {
                    throw new NovelProblem('DEPENDENCY_MISMATCH', '依赖身份改变，禁止复用既有尝试。');
                }
                if ($previous->status === 'succeeded') {
                    $this->artifact($previous);

                    return null;
                }
                if (in_array($previous->status, ['queued', 'running'], true)) {
                    return null;
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
            $checkpoint = $previous?->checkpoint_artifact_id ? $this->artifact($previous) : null;
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

            return ['id' => $stepId, 'input' => $input + ['checkpoint' => $checkpoint ? ['artifact_id' => (string) $checkpoint->id, 'content' => $this->json($checkpoint->content)] : null]];
        });
        if (! $claim) {
            return $this->read($actor, $novelId, $runId);
        }
        try {
            $result = $executor->execute($claim['input']);
            // 测试输出是明确的版本化结构；非法输出保留失败记录而非成功产物。
            Validator::make(['status' => $result->status, 'content' => $result->content, 'error_code' => $result->errorCode], [
                'status' => 'required|in:succeeded,deferred,blocked,failed,canceled',
                'content' => 'required|array:text', 'content.text' => 'required|string|max:20000',
                'error_code' => ['nullable', 'string', 'max:80', 'regex:/^[A-Z][A-Z0-9_]*$/D'],
            ])->validate();
            if ($result->status !== 'succeeded' && ! $result->errorCode) {
                throw new \InvalidArgumentException('非成功结果必须说明原因。');
            }
        } catch (Throwable) {
            // 不持久化未脱敏的异常正文，避免意外泄漏调用方上下文。
            $result = new StageResult('failed', ['text' => '测试阶段执行或输出校验失败'], 'STAGE_EXECUTION_FAILED');
        }
        DB::transaction(function () use ($actor, $novelId, $runId, $claim, $result) {
            $run = $this->run($actor, $novelId, $runId, true);
            $step = DB::table('generation_steps')->find($claim['id']);
            $status = $result->status;
            $error = $result->errorCode;
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
            DB::table('generation_steps')->where('id', $step->id)->update(['status' => $status, 'error_code' => $error,
                'checkpoint_artifact_id' => $status === 'deferred' ? $artifactId : null, 'updated_at' => now()]);
            $this->aggregate($run);
        });

        return $this->read($actor, $novelId, $runId);
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
    }
}
