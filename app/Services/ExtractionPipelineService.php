<?php

namespace App\Services;

use App\Context\ContextComposer;
use App\Exceptions\NovelProblem;
use App\Extraction\ExtractionContract;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;

/** E03 注册离线提取阶段；模型请求与数据库完成事务分离，不开放真实模型。 */
final class ExtractionPipelineService
{
    private function hash(mixed $v): string
    {
        return hash('sha256', NovelIntentService::canonical($v));
    }

    public function start(User $actor, string $novel, array $data, string $key): array
    {
        Validator::make(['data' => $data, 'key' => $key], ['data' => 'required|array:chapter_version_id,body_hash,expected_version,expected_state_version,parent_id,baseline_id,rule_context_id,offline_output,reason,route_version_id,billing', 'key' => ['required', 'string', 'max:128', 'regex:/^[\x20-\x7E]+$/D']])->validate();
        Validator::make($data, ['route_version_id' => 'required|integer|min:1', 'billing' => 'required|array'])->validate();
        $d = array_diff_key($data, array_flip(['route_version_id', 'billing']));

        return DB::transaction(function () use ($actor, $novel, $data, $d, $key) {
            $book = app(NovelIntentService::class)->novel($actor, $novel, true);
            $where = ['actor_id' => $actor->id, 'novel_scope' => $novel, 'command' => 'extraction.start', 'key' => $key];
            if ($old = DB::table('novel_commands')->where($where)->first()) {
                if ($old->request_hash !== $this->hash($data)) {
                    throw new NovelProblem('IDEMPOTENCY_CONFLICT', '此键已对应不同提取运行。');
                }

                return json_decode($old->response, true, flags: JSON_THROW_ON_ERROR);
            }
            // 复用确定性入口做来源预演；所有临时事件、记录和审计一并回滚。
            DB::beginTransaction();
            try {
                $preview = app(ExtractionService::class)->extract($actor, $novel, $d, 'preview:'.Str::uuid());
            } finally {
                DB::rollBack();
            }
            $body = app(DraftingService::class)->read($actor, $novel, 'chapter', (string) $d['chapter_version_id']);
            $chapter = DB::table('chapters')->where('novel_id', $novel)->find($body['node_id']);
            if ($body['stale'] || ! $body['report']['complete'] || (string) DB::table('chapter_versions')->where('novel_id', $novel)->where('node_id', $body['node_id'])->orderByDesc('version')->value('id') !== $body['id'] || in_array('INVALID_CANDIDATE_BASELINE', array_column($preview['report']['issues'], 'code'), true)) {
                throw new NovelProblem('DEPENDENCY_MISMATCH', '正文或前章基线不能用于新的提取运行。');
            }
            $baseline = empty($d['baseline_id']) ? null : app(ExtractionService::class)->read($actor, $novel, (string) $d['baseline_id']);
            $rules = empty($d['rule_context_id']) ? null : app(StoryConsistencyService::class)->read($actor, $novel, (string) $d['rule_context_id']);
            $source = app(StoryStateService::class)->registerChapter($actor, $novel, $body['id']);
            $admission = $preview['frozen_input'];
            $admission['source_id'] = $source;
            $definitions = [];
            foreach ($admission['approved_inventory'] as $entity) {
                if ($entity['approved_version_id'] && ! $entity['retired']) {
                    app(WorldService::class)->assertReference($actor, $novel, $entity['approved_version_id']);
                    $definitions[] = app(WorldService::class)->readVersion($actor, $novel, $entity['approved_version_id']);
                }
            }
            $context = ['body' => $body['body'], 'source' => ['chapter_version_id' => $body['id'], 'body_hash' => $body['body_hash']], 'author_constraints' => $admission, 'approved_definitions' => $definitions, 'baseline_events' => $baseline['events'] ?? [], 'author_rules' => $rules['payload']['rules'] ?? [], 'requirements' => app(NovelIntentService::class)->requirements($book), 'instructions' => '仅提议事件及引文；按实际码点偏移定位。隐藏真相不等于角色知情；不得输出规则或批准字段。'];
            $cfg = app(AuthoringConfigurationService::class)->freeze($actor, ['route_version_id' => (string) $data['route_version_id'], 'variables' => ['context' => NovelIntentService::canonical($context)]], 'extraction.events');
            if ($this->hash($cfg['prompt']['content']['output_schema']) !== $this->hash(ExtractionContract::schema()) || $cfg['route']['content']['mode'] !== 'offline_fake') {
                throw new NovelProblem('EXTRACTION_SCHEMA_MISMATCH', '提取路由须为offline_fake并采用注册的事件提取契约。');
            }
            if (($data['billing']['category'] ?? '') !== 'generation' || (string) ($data['billing']['chapter_id'] ?? '') !== (string) $chapter->id) {
                throw new NovelProblem('INVALID_BILLING_SCOPE', '提取预算必须归属当前章节及generation用途。', 422);
            }
            $billing = app(BudgetService::class)->freeze($actor, $novel, $data['billing'], $cfg);
            $model = $cfg['models'][0];
            $mode = $billing['prices'][$model['model_version_id']]['reasoning_mode'];
            $manifest = app(ContextComposer::class)->compose([['category' => 'extraction_contract', 'zone' => '系统及作者候选资料（不证明角色知情）', 'text' => $cfg['rendered_prompt']."\n输出契约：".NovelIntentService::canonical(ExtractionContract::schema()), 'required' => true]], $model['declared_capabilities']['context_tokens'], $billing['max_output_tokens'], $billing['max_reasoning_tokens'], $mode, 256);
            if ($manifest['status'] !== 'assembled') {
                throw new NovelProblem('CONTEXT_CAPACITY_EXCEEDED', '完整提取正文与硬约束无法放入冻结容量。');
            }
            $cfg['rendered_prompt'] = $manifest['final_prompt'];
            $source = app(StoryStateService::class)->registerChapter($actor, $novel, $body['id']);
            $frozen = ['schema_version' => 'extraction-v1', 'row_version' => $book->row_version, 'chapter_version_id' => $body['id'], 'body_hash' => $body['body_hash'], 'source_id' => $source, 'baseline_id' => $baseline['id'] ?? null, 'baseline_hash' => $baseline['input_hash'] ?? null, 'configuration' => $cfg, 'billing' => $billing, 'context' => $manifest, 'request' => array_diff_key($d, ['offline_output' => true]), 'nodes' => [['key' => 'extract', 'stage' => 'extraction.events', 'depends_on' => [], 'input' => ['offline_output' => $d['offline_output']]]]];
            $outline = DB::table('rolling_plans')->where('novel_id', $novel)->where('id', $body['plan_id'])->value('outline_version_id');
            $id = (string) DB::table('generation_runs')->insertGetId(['novel_id' => $novel, 'actor_id' => $actor->id, 'outline_version_id' => $outline, 'configuration_route_version_id' => $cfg['route']['id'], 'expected_state_version' => $book->state_version, 'command_key' => 'e03:'.hash('sha256', $key), 'request_hash' => $this->hash($data), 'frozen_config' => NovelIntentService::canonical($frozen), 'input_hash' => $this->hash($frozen), 'created_at' => now(), 'updated_at' => now()]);
            app(OutboxService::class)->enqueue($actor, $novel, $id, 'extract');
            $response = ['run_id' => $id, 'status_url' => '/api/v1/novels/'.$novel.'/runs/'.$id, 'mode' => 'offline_response_replay'];
            DB::table('novel_commands')->insert($where + ['request_hash' => $this->hash($data), 'response' => NovelIntentService::canonical($response), 'created_at' => now()]);

            return $response;
        });
    }

    /** 每次认领/提交重验来源，响应保存后进程中断也不允许套用新正文。 */
    public function verifyFrozen(object $run, array $frozen): void
    {
        $actor = User::findOrFail($run->actor_id);
        $book = app(NovelIntentService::class)->novel($actor, (string) $run->novel_id);
        $body = app(DraftingService::class)->read($actor, (string) $run->novel_id, 'chapter', $frozen['chapter_version_id']);
        $latest = (string) DB::table('chapter_versions')->where('novel_id', $run->novel_id)->where('node_id', $body['node_id'])->orderByDesc('version')->value('id');
        if ($this->hash($frozen) !== $run->input_hash || $book->status === 'archived' || $book->row_version !== $frozen['row_version'] || (string) $book->state_version !== (string) $run->expected_state_version || $body['stale'] || $latest !== $body['id'] || $body['body_hash'] !== $frozen['body_hash']) {
            throw new NovelProblem('DEPENDENCY_MISMATCH', '提取的冻结正文、作者输入或正式状态已改变。');
        }
        $own = DB::table('extraction_attempts')->where('run_id', $run->id)->orderByDesc('id')->first();
        if ($own && json_decode($own->report, true)['eligible'] && ! app(ExtractionService::class)->read($actor, (string) $run->novel_id, (string) $own->id)['current_eligible']) {
            throw new NovelProblem('DEPENDENCY_MISMATCH', '此运行的旧提取候选已被新提取替代，不能复用旧事件。');
        }
        if ($frozen['baseline_id']) {
            $b = app(ExtractionService::class)->read($actor, (string) $run->novel_id, $frozen['baseline_id']);
            if (! $b['current_eligible'] || $b['input_hash'] !== $frozen['baseline_hash']) {
                throw new NovelProblem('DEPENDENCY_MISMATCH', '前章候选提取基线已失效。');
            }
        }
    }

    public function generated(object $run, string $artifact, array $output): array
    {
        $frozen = json_decode($run->frozen_config, true, flags: JSON_THROW_ON_ERROR);
        $data = $frozen['request'] + ['offline_output' => $output];

        return app(ExtractionService::class)->extract(User::findOrFail($run->actor_id), (string) $run->novel_id, $data, 'artifact:'.$artifact, ['run_id' => (string) $run->id, 'artifact_id' => $artifact]);
    }
}
