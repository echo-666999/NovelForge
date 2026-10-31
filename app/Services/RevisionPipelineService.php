<?php

namespace App\Services;

use App\Context\ContextComposer;
use App\Exceptions\NovelProblem;
use App\Extraction\ExtractionContract;
use App\Generation\StageExecutionMode;
use App\Models\User;
use App\Revision\RevisionContract;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;

/** E05补丁阶段复用租约、网关和预算，执行结果不自动采纳。 */
final class RevisionPipelineService
{
    public function start(User $actor, string $novel, string $task, array $d, string $key): array
    {
        StageExecutionMode::validate($d);
        ExtractionContract::transport($d);
        Validator::make(['d' => $d, 'key' => $key], ['d' => 'required|array:route_version_id,billing,execution_mode,offline_output,reason', 'key' => ['required', 'string', 'max:128', 'regex:/^[\x20-\x7E]+$/D']])->validate();
        Validator::make($d, ['route_version_id' => 'required|integer|min:1', 'billing' => 'required|array', 'offline_output' => 'sometimes|array', 'reason' => 'required|string|max:2000'])->validate();
        $s = app(RevisionService::class);
        if (strlen(NovelIntentService::canonical($d)) > 1000000) {
            throw new NovelProblem('REQUEST_TOO_LARGE', '修订运行输入过大。', 422);
        }

        return DB::transaction(function () use ($actor, $novel, $task, $d, $key, $s) {
            $book = app(NovelIntentService::class)->novel($actor, $novel, true);
            $where = ['actor_id' => $actor->id, 'novel_scope' => $novel, 'command' => 'revision.start.'.$task, 'key' => $key];
            if ($old = DB::table('novel_commands')->where($where)->first()) {
                if ($old->request_hash !== $s->hash($d)) {
                    throw new NovelProblem('IDEMPOTENCY_CONFLICT', '运行键已对应不同修订。');
                }

                return json_decode(NovelIntentService::canonical(json_decode($old->response, true)), true);
            }
            $t = $s->verifySource($actor, $novel, $task);
            $r = $s->read($actor, $novel, $task);
            if ($t['mode'] !== (StageExecutionMode::mode($d) === 'production' ? 'production' : 'offline') || $r['status'] !== 'draft' || $r['run_id']) {
                throw new NovelProblem('REVISION_MANUAL_REQUIRED', '本轮不允许再启动自动文学修订。');
            }
            $cfg = app(AuthoringConfigurationService::class)->freeze($actor, ['route_version_id' => (string) $d['route_version_id'], 'variables' => ['context' => NovelIntentService::canonical($t['input'])]], 'revision.propose');
            StageExecutionMode::assertRoute($d, $cfg);
            if ($s->hash($cfg['prompt']['content']['output_schema']) !== $s->hash(RevisionContract::schema()) || $cfg['style']['id'] !== $t['input']['style_version_id']) {
                throw new NovelProblem('REVISION_CONFIGURATION_MISMATCH', '补丁须使用注册协议和正文同一文风版本。');
            }
            if (($d['billing']['category'] ?? '') !== 'generation' || (string) ($d['billing']['chapter_id'] ?? '') !== $t['chapter_node_id']) {
                throw new NovelProblem('INVALID_BILLING_SCOPE', '修订预算须属于本章generation用途。', 422);
            }
            $billing = app(BudgetService::class)->freeze($actor, $novel, $d['billing'], $cfg);
            $m = $cfg['models'][0];
            $mode = $billing['prices'][$m['model_version_id']]['reasoning_mode'];
            $manifest = app(ContextComposer::class)->compose([['category' => 'scoped_revision', 'zone' => '作者冻结的受限修订资料，不代表角色知情', 'text' => $cfg['rendered_prompt']."\n只输出限定补丁，禁止改写事实/策略：".NovelIntentService::canonical(RevisionContract::schema()), 'required' => true]], $m['declared_capabilities']['context_tokens'], $billing['max_output_tokens'], $billing['max_reasoning_tokens'], $mode, 256);
            if ($manifest['status'] !== 'assembled') {
                throw new NovelProblem('CONTEXT_CAPACITY_EXCEEDED', '原文与必须保留事实不能裁剪。');
            }$cfg['rendered_prompt'] = $manifest['final_prompt'];
            $f = ['execution_mode' => StageExecutionMode::mode($d), 'schema_version' => 'revision-v1', 'row_version' => $book->row_version, 'task_id' => $task, 'task_hash' => $t['input_hash'], 'chapter_version_id' => $t['source_version_id'], 'body_hash' => $t['input']['source']['body_hash'], 'configuration' => $cfg, 'billing' => $billing, 'context' => $manifest, 'nodes' => [['key' => 'revise', 'stage' => 'revision.propose', 'depends_on' => [], 'input' => StageExecutionMode::node($d)]]];
            $outline = DB::table('rolling_plans')->where('id', $t['input']['source']['plan_id'])->value('outline_version_id');
            $id = (string) DB::table('generation_runs')->insertGetId(['novel_id' => $novel, 'actor_id' => $actor->id, 'outline_version_id' => $outline, 'configuration_route_version_id' => $cfg['route']['id'], 'expected_state_version' => $book->state_version, 'command_key' => 'e05:'.hash('sha256', $task.':'.$key), 'request_hash' => $s->hash($d), 'frozen_config' => NovelIntentService::canonical($f), 'input_hash' => $s->hash($f), 'created_at' => now(), 'updated_at' => now()]);
            app(OutboxService::class)->enqueue($actor, $novel, $id, 'revise');
            $result = ['run_id' => $id, 'task_id' => $task, 'status_url' => '/api/v1/novels/'.$novel.'/runs/'.$id];
            $result = json_decode(NovelIntentService::canonical($result), true);
            DB::table('novel_commands')->insert($where + ['request_hash' => $s->hash($d), 'response' => NovelIntentService::canonical($result), 'created_at' => now()]);

            return $result;
        });
    }

    public function verifyFrozen(object $run, array $f): void
    {
        $s = app(RevisionService::class);
        $t = $s->verifySource(User::findOrFail($run->actor_id), (string) $run->novel_id, $f['task_id']);
        $book = DB::table('novels')->find($run->novel_id);
        if ($s->hash($f) !== $run->input_hash || $t['input_hash'] !== $f['task_hash'] || $book->row_version !== $f['row_version'] || (string) $book->state_version !== (string) $run->expected_state_version || $book->status === 'archived' || DB::table('revision_decisions')->where('task_id', $t['id'])->exists()) {
            throw new NovelProblem('DEPENDENCY_MISMATCH', '修订的冻结来源或选择状态已改变。');
        }
    }

    public function generated(object $run, string $artifact, array $output): array
    {
        $f = json_decode($run->frozen_config, true);

        return app(RevisionService::class)->propose(User::findOrFail($run->actor_id), (string) $run->novel_id, $f['task_id'], ['offline_output' => $output, 'reason' => StageExecutionMode::mode($f) === 'production' ? '真实模型受限修订产物' : 'E05注册离线补丁回放'], 'artifact:'.$artifact, app(RealResultOrigin::class)->forArtifact($run, $artifact));
    }
}
