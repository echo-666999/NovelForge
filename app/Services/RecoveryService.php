<?php

namespace App\Services;

use App\Exceptions\NovelProblem;
use App\Generation\RecoveryPolicy;
use App\Generation\StageExecutor;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;

/** C06 恢复控制：所有写操作先锁小说，再核对预览，最后追加不可变决策。 */
final class RecoveryService
{
    public function preview(User $actor, string $novelId, string $runId, array $changedScopes = []): array
    {
        $data = app(GenerationService::class)->read($actor, $novelId, $runId);
        $run = $data['run'];
        $nodes = $run['frozen_config']['nodes'];
        if (array_diff($changedScopes, array_column($nodes, 'key'))) {
            throw new NovelProblem('INVALID_STAGE', '变更范围不在运行图内。', 422);
        }
        $novel = app(NovelIntentService::class)->novel($actor, $novelId);
        $stale = (string) $novel->state_version !== $run['expected_state_version'] || $novel->status === 'archived';
        if (($run['frozen_config']['schema_version'] ?? null) === 'planning-v1') {
            try {
                app(PlanningPipelineService::class)->verifyFrozen((object) $run, $run['frozen_config']);
            } catch (NovelProblem) {
                $stale = true;
            }
        }
        // E02 也重验计划、当前候选及前场景来源，不能把过期正文标成可复用。
        if (($run['frozen_config']['schema_version'] ?? null) === 'drafting-v1') {
            try {
                app(DraftingService::class)->verifyFrozen((object) $run, $run['frozen_config']);
            } catch (NovelProblem) {
                $stale = true;
            }
        }
        // E03 恢复必须重验同一正文和前章候选提取，不重绑定旧证据。
        if (($run['frozen_config']['schema_version'] ?? null) === 'extraction-v1') {
            try {
                app(ExtractionPipelineService::class)->verifyFrozen((object) $run, $run['frozen_config']);
            } catch (NovelProblem) {
                $stale = true;
            }
        }
        // E04恢复继续绑定原评审上下文，不重用旧正文评审。
        if (($run['frozen_config']['schema_version'] ?? null) === 'review-v1') {
            try {
                app(ReviewPipelineService::class)->verifyFrozen((object) $run, $run['frozen_config']);
            } catch (NovelProblem) {
                $stale = true;
            }
        }
        // E05恢复继续绑定原问题、范围、文风和评审；不重置文学轮次。
        if (($run['frozen_config']['schema_version'] ?? null) === 'revision-v1') {
            try {
                app(RevisionPipelineService::class)->verifyFrozen((object) $run, $run['frozen_config']);
            } catch (NovelProblem) {
                $stale = true;
            }
        }
        $latest = collect($data['steps'])->sortByDesc('attempt_no')->unique('scope_key')->keyBy('scope_key');
        $artifacts = collect($data['artifacts'])->keyBy('step_id');
        $invalid = $stale ? array_column($nodes, 'key') : $changedScopes;
        $rows = [];
        foreach ($nodes as $node) {
            if (array_intersect($node['depends_on'], $invalid)) {
                $invalid[] = $node['key'];
            }
            $step = $latest->get($node['key']);
            $artifact = $step ? $artifacts->get($step['id']) : null;
            $verified = $artifact && hash_equals($artifact['content_hash'], $this->hash($artifact['content'])) && hash_equals($artifact['source_hash'], $step['dependency_hash']) && hash_equals($step['dependency_hash'], $this->hash($step['frozen_input']));
            if ($artifact && ! $verified) {
                $invalid[] = $node['key'];
            }
            $unknown = $step && app(GenerationService::class)->hasUncertainRequest($step['id']);
            $classification = RecoveryPolicy::classify($unknown ? 'UNKNOWN_REQUEST_OUTCOME' : ($step['error_code'] ?? null));
            $disposition = in_array($node['key'], $invalid, true) ? 'stale' : ($verified && $step['status'] === 'succeeded' ? 'reuse' : ($verified && $step['checkpoint_artifact_id'] ? 'checkpoint' : 'redo'));
            $rows[] = ['scope' => $node['key'], 'step_id' => $step['id'] ?? null, 'status' => $step['status'] ?? 'not_started', 'technical_attempts' => $step['attempt_no'] ?? 0,
                'automatic_revision_rounds' => ($run['frozen_config']['schema_version'] ?? null) === 'revision-v1' ? (int) DB::table('revision_tasks')->where('id', $run['frozen_config']['task_id'])->value('automatic_round') : 0, 'revision_executor_available' => ($run['frozen_config']['schema_version'] ?? null) === 'revision-v1', 'disposition' => $disposition, 'artifact_id' => $artifact['id'] ?? null,
                'next_retry_at' => $step ? DB::table('outbox_events')->where('run_id', $runId)->where('scope_key', $node['key'])->where('retry_of', $step['attempt_no'])->value('next_delivery_at') : null, 'artifact_content' => $artifact['content'] ?? null, 'artifact_hash' => $artifact['content_hash'] ?? null, 'unknown_request' => $unknown, 'error_code' => $step['error_code'] ?? null, 'classification' => $classification,
                'retry_allowed' => ! in_array($classification['kind'], ['hard_constraint', 'literary'], true) && ! $unknown && $disposition !== 'stale' && $step && in_array($step['status'], ['failed', 'deferred', 'blocked', 'quarantined'], true) && $step['attempt_no'] < 3];
        }
        $requests = DB::table('ai_requests')->where('run_id', $runId)->orderBy('id')->get(['id', 'status', 'error_code'])->map(function ($r) {
            // 未知费用原样展示，不与已知费用相加，也不把核销当成请求完成。
            return ['id' => (string) $r->id, 'status' => $r->status, 'error_code' => $r->error_code, 'billing' => app(BudgetService::class)->read((string) $r->id)];
        })->all();
        $result = ['novel_id' => $novelId, 'run_id' => $runId, 'status' => $run['status'], 'input_hash' => $run['input_hash'], 'changed_scopes' => $changedScopes,
            'state_version' => (string) $novel->state_version, 'rows' => $rows, 'requests' => $requests,
            'decision_version' => DB::table('recovery_decisions')->where('run_id', $runId)->count(),
            'late_evidence_count' => DB::table('generation_late_evidence')->where('run_id', $runId)->count(),
            'late_evidence' => DB::table('generation_late_evidence')->where('run_id', $runId)->orderBy('id')->get(['id', 'step_id', 'reason', 'content', 'content_hash'])->map(fn ($e) => ['id' => (string) $e->id, 'step_id' => (string) $e->step_id, 'reason' => $e->reason, 'content' => json_decode($e->content, true), 'content_hash' => $e->content_hash])->all(),
            'decisions' => DB::table('recovery_decisions')->where('run_id', $runId)->orderBy('id')->get(['id', 'actor_id', 'action', 'reason', 'created_at'])->map(fn ($d) => ['id' => (string) $d->id, 'actor_id' => (string) $d->actor_id, 'action' => $d->action, 'reason' => $d->reason, 'created_at' => $d->created_at])->all(),
            'configuration_rule' => '默认配置变化不改变旧运行；显式变更须新运行。变更节点及下游重验，原产物永久保留。',
            'execution_boundary' => '支持离线 test.echo、E01 规划及 E02 正文与 E03 事件提取与 E04 评审和 E05 受限修订回放；不自动采纳或提交。'];
        $result['preview_hash'] = $this->hash($result);

        return $result;
    }

    /** 离线重提取适配点；实际事件提取模块接入前不开放生产执行。 */
    public function replayEvidence(User $actor, string $novelId, string $runId, string $decisionId, StageExecutor $extractor): array
    {
        if (! app()->environment('testing')) {
            throw new NovelProblem('STAGE_NOT_AVAILABLE', '事件提取执行器尚未注册。', 403);
        }
        app(GenerationService::class)->read($actor, $novelId, $runId);
        $decision = DB::table('recovery_decisions')->where('novel_id', $novelId)->where('run_id', $runId)->where('id', $decisionId)->where('action', 'reextract')->first();
        if (! $decision) {
            throw new NovelProblem('NOT_FOUND', '重新提取决定不存在。', 404);
        }

        // 小说锁序列化离线重放；生产适配器不得在本事务中调用网络。
        return DB::transaction(function () use ($actor, $novelId, $runId, $decision, $extractor) {
            app(NovelIntentService::class)->novel($actor, $novelId, true);
            if ($old = DB::table('recovery_outputs')->where('decision_id', $decision->id)->first()) {
                return (array) $old;
            }
            if (in_array(DB::table('generation_runs')->where('id', $runId)->value('status'), ['paused', 'canceled', 'needs_attention'], true)) {
                throw new NovelProblem('RUN_NOT_EXECUTABLE', '当前运行不能重提取。');
            }
            $source = json_decode($decision->payload, true)['source'];
            if (! isset($source['artifact_id'])) {
                throw new NovelProblem('REGISTERED_EXTRACTION_REQUIRED', '章节正文须使用E03绑定原文的提取入口，不能回退到测试提取器。');
            }
            $artifact = DB::table('generation_artifacts')->where('novel_id', $novelId)->where('run_id', $runId)->where('id', $source['artifact_id'])->first();
            if (! $artifact || ! hash_equals($source['content_hash'], $this->hash(json_decode($artifact->content, true)))) {
                throw new NovelProblem('DEPENDENCY_MISMATCH', '原证据不完整，不能重提取。');
            }
            $result = $extractor->execute(['source_artifact_id' => (string) $artifact->id, 'source_hash' => $source['content_hash'], 'source' => json_decode($artifact->content, true), 'rewrite_source' => false]);
            Validator::make($result->content, ['text' => 'required|string|max:20000'])->validate();
            if ($result->status !== 'succeeded' || array_keys($result->content) !== ['text']) {
                throw new NovelProblem('INVALID_EXTRACTION', '离线提取结果不符合测试契约。');
            }
            $id = DB::table('recovery_outputs')->insertGetId(['novel_id' => $novelId, 'run_id' => $runId, 'decision_id' => $decision->id, 'source_artifact_id' => $artifact->id,
                'source_hash' => $source['content_hash'], 'content' => NovelIntentService::canonical($result->content), 'content_hash' => $this->hash($result->content), 'schema_version' => 'test.extract-v1']);

            return (array) DB::table('recovery_outputs')->find($id);
        });
    }

    private function hash(array $value): string
    {
        return hash('sha256', NovelIntentService::canonical($value));
    }

    public function execute(User $actor, string $novelId, string $runId, string $action, array $payload, string $key): array
    {
        Validator::make($payload + ['action' => $action, 'key' => $key], [
            'action' => 'required|in:pause,resume,cancel,manual,retry,reextract,change_plan', 'key' => ['required', 'string', 'max:128', 'regex:/^[\x20-\x7E]+$/D'],
            'reason' => 'required|string|max:2000', 'preview_hash' => 'required|string|size:64', 'scope' => 'sometimes|required|string|max:80',
            'changed_scopes' => 'sometimes|array|max:100', 'changed_scopes.*' => 'required|string|distinct', 'model_version_id' => 'sometimes|required|string|regex:/^[1-9][0-9]{0,18}$/D',
        ])->validate();
        if (array_diff(array_keys($payload), ['reason', 'preview_hash', 'scope', 'changed_scopes', 'model_version_id'])) {
            throw new NovelProblem('INVALID_RECOVERY', '不接受未知恢复参数。', 422);
        }

        return DB::transaction(function () use ($actor, $novelId, $runId, $action, $payload, $key) {
            app(NovelIntentService::class)->novel($actor, $novelId, true);
            $data = app(GenerationService::class)->read($actor, $novelId, $runId);
            DB::table('generation_runs')->where('id', $runId)->lockForUpdate()->first();
            $identity = ['actor_id' => $actor->id, 'run_id' => $runId, 'command_key' => $key];
            $hash = $this->hash(['action' => $action, 'payload' => $payload]);
            if ($old = DB::table('recovery_decisions')->where($identity)->first()) {
                if (! hash_equals($old->request_hash, $hash)) {
                    throw new NovelProblem('IDEMPOTENCY_CONFLICT', '操作键已用于其他决定。');
                }

                return json_decode($old->response, true, flags: JSON_THROW_ON_ERROR);
            }
            $preview = $this->preview($actor, $novelId, $runId, $payload['changed_scopes'] ?? []);
            if (! hash_equals($preview['preview_hash'], $payload['preview_hash'])) {
                throw new NovelProblem('STALE_RECOVERY_PREVIEW', '状态或证据已改变，请重新预览。');
            }
            $status = $data['run']['status'];
            if ($status === 'canceled' || ($status === 'succeeded' && ! in_array($action, ['reextract', 'change_plan'], true))) {
                throw new NovelProblem('RUN_NOT_EXECUTABLE', '该运行已终止，不能恢复或重新标记成功。');
            }
            $row = collect($preview['rows'])->firstWhere('scope', $payload['scope'] ?? '');
            $outcome = 'applied';
            $eventId = null;
            $source = null;
            if (in_array($action, ['pause', 'cancel', 'manual'], true)) {
                $status = ['pause' => 'paused', 'cancel' => 'canceled', 'manual' => 'needs_attention'][$action];
                // 控制点立即终止旧租约；即使马上恢复，旧响应也只能进入隔离证据表。
                DB::table('generation_steps')->where('run_id', $runId)->whereIn('status', ['queued', 'running'])->update(['status' => 'quarantined', 'error_code' => 'RUN_CONTROL_INTERRUPTED', 'updated_at' => now()]);
                DB::table('generation_book_slots')->where('run_id', $runId)->delete();
                DB::table('generation_runs')->where('id', $runId)->update(['status' => $status, 'error_code' => 'RUN_CONTROL_INTERRUPTED', 'updated_at' => now()]);
            } elseif ($action === 'resume') {
                if (! in_array($status, ['paused', 'needs_attention'], true) || collect($preview['rows'])->contains(fn ($r) => $r['unknown_request'] || $r['disposition'] === 'stale')) {
                    throw new NovelProblem('RECOVERY_BLOCKED', '仅可恢复无过期来源及未知请求的暂停运行。');
                }
                $status = 'waiting_dependency';
                DB::table('generation_runs')->where('id', $runId)->update(['status' => $status, 'error_code' => null, 'updated_at' => now()]);
                // 成功产物不重做；被暂停中断的步骤由明确的重试决定续跑。
            } elseif ($action === 'retry') {
                if (! $row || ! $row['retry_allowed'] || in_array($status, ['paused', 'needs_attention'], true)) {
                    throw new NovelProblem('RECOVERY_BLOCKED', '当前阶段不可重试；请检查次数、来源、暂停和未知费用。');
                }
                if (isset($payload['model_version_id'])) {
                    $models = $data['run']['frozen_config']['configuration']['models'] ?? [];
                    if (! in_array($payload['model_version_id'], array_column($models, 'model_version_id'), true)) {
                        throw new NovelProblem('UNAPPROVED_FALLBACK', '模型不在冻结批准清单中。');
                    }
                }
                if (DB::table('outbox_events')->where('run_id', $runId)->where('scope_key', $row['scope'])->where('retry_of', $row['technical_attempts'])->exists()) {
                    throw new NovelProblem('RETRY_ALREADY_SCHEDULED', '该下一尝试已有持久意图，不能再修改模型或重排恢复。');
                }
                $eventId = app(OutboxService::class)->enqueue($actor, $novelId, $runId, $row['scope'], $row['technical_attempts']);
            } else {
                // 下游业务执行器未注册时，只保存可审查的决定，不伪造提取或迁移成功。
                if ($action === 'reextract') {
                    if (($data['run']['frozen_config']['schema_version'] ?? null) === 'extraction-v1') {
                        if (! $row || ! $row['artifact_id'] || $row['disposition'] === 'stale' || $row['unknown_request']) {
                            throw new NovelProblem('INVALID_SOURCE', '来源过期或请求未决时不能准备重新提取。');
                        }
                        app(ExtractionPipelineService::class)->verifyFrozen((object) $data['run'], $data['run']['frozen_config']);
                        $cfg = $data['run']['frozen_config'];
                        $attempt = DB::table('extraction_attempts')->where('run_id', $runId)->orderByDesc('id')->value('id');
                        $source = ['chapter_version_id' => $cfg['chapter_version_id'], 'body_hash' => $cfg['body_hash'], 'parent_extraction_id' => $attempt ? (string) $attempt : null, 'rewrite_source' => false, 'editor_url' => '/admin/extraction-ledger?'.http_build_query(['novel' => $novelId, 'version' => $cfg['chapter_version_id'], 'parent' => $attempt, 'baseline' => $cfg['baseline_id']])];
                    } else {
                        if (! $row || ! $row['artifact_id'] || $row['disposition'] !== 'reuse') {
                            throw new NovelProblem('INVALID_SOURCE', '重新提取必须引用完整且校验通过的成功原产物。');
                        }
                        $source = ['artifact_id' => $row['artifact_id'], 'content_hash' => $row['artifact_hash'], 'rewrite_source' => false];
                    }
                } elseif (empty($payload['changed_scopes'])) {
                    throw new NovelProblem('INVALID_RECOVERY', '配置变更必须明确受影响的节点范围。', 422);
                }
                $outcome = $action === 'reextract' && ($data['run']['frozen_config']['schema_version'] ?? null) === 'extraction-v1' ? 'awaiting_offline_response' : 'awaiting_stage_implementation';
            }
            $response = ['run_id' => $runId, 'action' => $action, 'status' => $status, 'outcome' => $outcome, 'event_id' => $eventId, 'source' => $source, 'decision_version' => $preview['decision_version'] + 1];
            DB::table('recovery_decisions')->insert($identity + ['novel_id' => $novelId, 'action' => $action, 'reason' => $payload['reason'], 'request_hash' => $hash,
                'payload' => NovelIntentService::canonical(['request' => $payload, 'preview' => $preview, 'source' => $source]), 'response' => NovelIntentService::canonical($response)]);

            return $response;
        });
    }
}
