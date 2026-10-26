<?php

namespace App\Services;

use App\Context\ContextComposer;
use App\Exceptions\NovelProblem;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;

/** D06 只组装和冻结离线上下文，不发请求、不写正式剧情、不修改阶段输入。 */
final class ContextAssemblyService
{
    /** 检索分数是浮点排名值，封存时转为十进制文本，遵守项目规范化协议。 */
    private function scoreText(mixed $value): mixed
    {
        if (is_float($value)) {
            return (string) $value;
        }
        if (is_array($value)) {
            return array_map(fn ($v) => $this->scoreText($v), $value);
        }

        return $value;
    }

    /** 引文范围可能包含尚未知晓的其他事实；角色区只保存可定位的证据元数据。 */
    private function evidenceReference(array $evidence): array
    {
        $reference = array_intersect_key($evidence, array_flip(['id', 'novel_id', 'source_version_id', 'source_hash', 'start_offset', 'end_offset', 'offset_unit']));
        $reference['quote_hash'] = hash('sha256', $evidence['quote']);

        return $reference;
    }

    /** 保留D02已证明的知识、信念类型及关系，原始引文留在作者证据区。 */
    private function povSnapshot(array $known): array
    {
        foreach (['knowledge', 'beliefs'] as $field) {
            foreach ($known[$field] as &$item) {
                $item['evidence'] = $this->evidenceReference($item['evidence']);
            }
            unset($item);
        }

        return $known;
    }

    private function hash(mixed $value): string
    {
        return hash('sha256', NovelIntentService::canonical($value));
    }

    private function step(User $actor, string $id): array
    {
        Validator::make(['id' => $id], ['id' => 'required|integer|min:1|max:9223372036854775807'])->validate();
        $step = DB::table('generation_steps')->find($id);
        if (! $step) {
            throw new NovelProblem('NOT_FOUND', '阶段不存在。', 404);
        }
        $book = app(NovelIntentService::class)->novel($actor, (string) $step->novel_id, true);
        $run = DB::table('generation_runs')->find($step->run_id);

        return [$book, $step, $run];
    }

    public function assemble(User $actor, string $stepId, array $d, string $key): array
    {
        return DB::transaction(function () use ($actor, $stepId, $d, $key) {
            [$book, $step, $run] = $this->step($actor, $stepId);
            Validator::make(['key' => $key, 'data' => $d], ['key' => ['required', 'string', 'max:128', 'regex:/^[\x20-\x7E]+$/D'], 'data' => 'array:scope,plan_id,scene_id,context_id,pov_id,configuration,billing,safety_margin,search,expected_version,reason'])->validate();
            $where = ['actor_id' => $actor->id, 'step_id' => $stepId, 'command_key' => $key];
            if ($old = DB::table('context_manifests')->where($where)->first()) {
                if ($old->request_hash !== $this->hash($d)) {
                    throw new NovelProblem('IDEMPOTENCY_CONFLICT', '此操作键已绑定其他组装输入。');
                }

                return $this->decode($old);
            }
            Validator::make($d, ['scope' => 'required|in:candidate,formal', 'plan_id' => 'required|integer|min:1', 'scene_id' => 'required|integer|min:1', 'context_id' => 'nullable|integer|min:1', 'pov_id' => 'required|integer|min:1', 'configuration' => 'required|array', 'billing' => 'required|array', 'safety_margin' => 'required|integer|min:1|max:1000000', 'search' => 'sometimes|array:build_id,profile_id,query,limit,vector', 'expected_version' => 'required|integer|min:1', 'reason' => 'required|string|max:2000'])->validate();
            if ($d['scope'] === 'formal') {
                throw new NovelProblem('FORMAL_CONTEXT_UNAVAILABLE', '正式状态与正文来源尚未接入，不会回退到候选分支。');
            }
            if ($book->row_version !== (int) $d['expected_version']) {
                throw new NovelProblem('STALE_STATE', '小说已变化，请刷新后重新组装。');
            }
            $result = $this->prepare($actor, $book, $step, $run, $d, $stepId);
            $novel = (string) $book->id;
            $branch = (string) $run->id;
            $id = DB::table('context_manifests')->insertGetId($where + ['novel_id' => $novel, 'run_id' => $branch, 'request_hash' => $this->hash($d), 'input' => NovelIntentService::canonical($d), 'payload' => NovelIntentService::canonical($result), 'content_hash' => $this->hash($result)]);

            return $this->read($actor, $stepId, (string) $id);
        });
    }

    /** 共用纯组装路径；E02 在持久运行创建前冻结最终请求，不修改已有运行输入。 */
    public function prepare(User $actor, object $book, object $step, object $run, array $d, string $stepId, ?array $previous = null): array
    {
        $novel = (string) $book->id;
        $branch = (string) $run->id;
        $plan = app(RollingPlanService::class)->read($actor, $novel, (string) $d['plan_id']);
        $scene = collect($plan['nodes'])->first(fn ($n) => $n['node_type'] === 'scene' && (string) $n['scene_id'] === (string) $d['scene_id']);
        $chapter = $scene ? collect($plan['nodes'])->firstWhere('id', $scene['parent_plan_node_id']) : null;
        if (! $chapter || $chapter['sequence'] < $plan['start_chapter'] || $chapter['sequence'] > $plan['end_chapter']) {
            throw new NovelProblem('INVALID_SCENE', '场景不在选定规划窗口内。', 422);
        }
        $pov = DB::table('entities')->where('novel_id', $novel)->where('id', $d['pov_id'])->where('type', 'character')->first();
        if (! $pov || ! $pov->approved_version_id) {
            throw new NovelProblem('INVALID_KNOWER', '请选择同书已批准人物作为 POV。', 422);
        }
        app(WorldService::class)->assertReference($actor, $novel, (string) $pov->approved_version_id);
        if ((string) ($scene['details']['details']['pov_version_id'] ?? '') !== (string) $pov->approved_version_id) {
            throw new NovelProblem('POV_MISMATCH', 'POV 与冻结场景计划不一致。');
        }
        $frozen = json_decode($run->frozen_config, true, flags: JSON_THROW_ON_ERROR);
        // 已有运行只用启动时冻结的配置；默认版本发布或退役不能改写历史身份。
        if (isset($frozen['configuration'])) {
            $config = $frozen['configuration'];
            $expected = ['route_version_id' => $config['route']['id'], 'variables' => $config['variables']];
            if ($this->hash($d['configuration']) !== $this->hash($expected)) {
                throw new NovelProblem('CONTEXT_IDENTITY_MISMATCH', '组装配置必须与运行冻结版本和变量一致；变更路由需另建运行尝试。');
            }
        } else {
            $config = app(AuthoringConfigurationService::class)->freeze($actor, $d['configuration'], $step->stage);
        }
        $billing = app(BudgetService::class)->freeze($actor, $novel, $d['billing'], $config);
        if (isset($frozen['billing']) && $this->hash($billing) !== $this->hash($frozen['billing'])) {
            throw new NovelProblem('CONTEXT_IDENTITY_MISMATCH', '预算及价格必须与运行冻结版本一致。');
        }
        if ((string) $run->outline_version_id !== (string) $plan['outline_version_id']) {
            throw new NovelProblem('CONTEXT_IDENTITY_MISMATCH', '计划不属于运行冻结大纲。');
        }
        $items = [];
        $add = function (string $category, string $zone, string $table, mixed $id, mixed $version, mixed $value, bool $required = true, ?float $score = null, array $channels = []) use (&$items) {
            $text = is_string($value) ? $value : NovelIntentService::canonical($value);
            $items[] = ['item_id' => count($items) + 1, 'category' => $category, 'zone' => $zone, 'source_type' => $table, 'source_id' => (string) $id, 'source_version' => (string) $version, 'source_hash' => hash('sha256', $text), 'text' => $text, 'required' => $required, 'score' => $score === null ? null : (string) $score, 'channels' => $this->scoreText($channels), 'inclusion_reason' => $required ? 'deterministic_required' : 'retrieved_or_optional'];
        };
        $add('system_contract', '系统任务与输出契约', 'prompt_versions', $config['prompt']['id'], $config['prompt']['version'], ['task' => $config['rendered_prompt'], 'output_schema' => $config['prompt']['content']['output_schema'], 'boundary' => '候选资料仅供离线检查；作者区不是角色知识，不得在正文提前揭示。']);
        $bp = app(BlueprintService::class)->read($actor, $novel, (string) $plan['blueprint_version_id']);
        $add('hard_constraint', '作者约束（不证明角色知情）', 'blueprint_versions', $bp['id'], $bp['version'], $bp['content']['requirement_snapshot']);
        // 全局核心设定、世界规则与当前 POV 必须确定性注入，不能因蓝图未列引用而漏掉。
        $globalConstraints = DB::table('entities as e')->join('entity_definition_versions as v', 'v.id', '=', 'e.approved_version_id')->where('e.novel_id', $novel)->where('e.retired', false)->where(function ($q) use ($d) {
            $q->where('e.type', 'rule')->orWhereRaw("v.definition->>'criticality' = 'core'")->orWhere('e.id', $d['pov_id']);
        })->orderBy('e.id')->pluck('v.id')->map(fn ($id) => (string) $id)->all();
        foreach (array_values(array_unique([...$bp['content']['entity_version_ids'], ...$globalConstraints])) as $id) {
            app(WorldService::class)->assertReference($actor, $novel, (string) $id);
            $e = app(WorldService::class)->readVersion($actor, $novel, (string) $id);
            $add('hard_constraint', '作者约束（不证明角色知情）', 'entity_definition_versions', $id, $e['version'], $e['definition']);
        }
        $add('scene_plan', '作者计划且角色未知', 'rolling_plans', $plan['id'], $plan['version'], ['chapter' => $chapter, 'scene' => $scene]);
        $issues = [];
        if ((int) $run->expected_state_version !== $book->state_version) {
            $issues[] = 'STALE_RUN_STATE';
        }
        if ($plan['display_status'] !== 'frozen') {
            $issues[] = 'PLAN_NOT_CURRENTLY_FROZEN';
        }
        if ($run->status === 'canceled') {
            $issues[] = 'BRANCH_CANCELED';
        }
        $state = ['formal_state_version' => $book->state_version, 'formal_state' => 'not_connected', 'candidate_branch' => $branch];
        $add('state', '作者状态基线（候选）', 'novels', $novel, $book->state_version, $state);
        if (isset($d['context_id'])) {
            $context = app(StoryConsistencyService::class)->read($actor, $novel, (string) $d['context_id']);
            $events = array_column($context['events'], null, 'id');
            $ids = array_map(fn ($e) => $e['evidence']['source_version_id'], $events);
            $valid = app(LayeredMemoryService::class)->verifiedSources($actor, $novel, $branch, $ids);
            if (count($valid) !== count(array_unique($ids))) {
                $issues[] = 'INVALID_KNOWLEDGE_SOURCE';
            } else {
                $add('candidate_state', '作者候选事实与隐藏真相（不证明角色知情）', 'story_context_versions', $context['id'], $context['version'], array_values(array_filter($events, fn ($e) => $e['chapter_sequence'] <= $chapter['sequence'])));
                $known = app(StoryConsistencyService::class)->pov($actor, $novel, (string) $d['context_id'], (string) $d['pov_id'], $chapter['sequence']);
                if (! $known['usable']) {
                    $issues[] = 'POV_CONTEXT_UNUSABLE';
                } else {
                    $add('knowledge_relationships', 'POV 可知信息（候选证据）', 'story_context_versions', $context['id'], $context['version'], $this->povSnapshot($known));
                    foreach ($known['knowledge'] as $knowledge) {
                        // 推断和被告知的非事实保留原类型，不提升为确定性的关键事实。
                        if (! in_array($knowledge['knowledge_kind'], ['fact', 'hidden_truth'], true)) {
                            continue;
                        }
                        $e = $events[$knowledge['fact_event_id']];
                        $add('key_fact', 'POV 可知信息（候选证据）', 'proposed_events', $e['id'], $e['content_hash'], ['attribute' => $e['attribute'], 'value' => $e['value'], 'evidence' => $this->evidenceReference($e['evidence'])]);
                    }
                }
            }
        }
        // 伏笔、终局和关键事实直接读取，与检索是否启用及 Top K 无关。
        $ledger = app(ForeshadowLedgerService::class)->ledger($actor, $novel, ['chapter' => $chapter['sequence'], 'blueprint_version_id' => $plan['blueprint_version_id']]);
        foreach ($ledger['items'] as $f) {
            if ($f['lifecycle'] === 'planned' && ($f['due'] || $f['overdue'] || $f['priority'] === 'high')) {
                $add('foreshadow', '作者计划且角色未知', 'foreshadow_plans', $f['id'], $f['version'], $f['payload']);
            }
        }
        $add('ending', '作者计划且角色未知', 'blueprint_versions', $bp['id'], $bp['version'], $bp['content']['ending']);
        // 前场景全文不可裁剪，仅属于作者候选资料，不证明角色知情。
        if ($previous !== null) {
            $add('previous_scene', '前场景衔接（作者候选正文）', 'scene_versions', $previous['id'], $previous['body_hash'], $previous['body']);
        }
        // 尚未创建的新分支不可能已有候选记忆；不借用其他运行的资料。
        $retrieval = null;
        if (DB::table('generation_runs')->where('novel_id', $novel)->where('id', $branch)->exists()) {
            $facts = DB::table('memory_facts')->where('novel_id', $novel)->where('branch_id', $branch)->where('introduced_chapter', '<=', $chapter['sequence'])->orderBy('id')->get();
            $sources = app(LayeredMemoryService::class)->verifiedSources($actor, $novel, $branch, $facts->pluck('source_version_id')->all());
            foreach ($facts as $fact) {
                if (! isset($sources[$fact->source_version_id])) {
                    $issues[] = 'MISSING_REQUIRED_FACT_SOURCE';

                    continue;
                }
                $add('key_fact', '作者事实（不证明角色知情）', 'memory_facts', $fact->id, $fact->statement_hash, $fact->statement);
            }
            // 最新候选正文不依赖索引；无章位证明，始终放在作者区，不进入 POV 区。
            $recent = DB::table('source_documents')->where('novel_id', $novel)->where('run_id', $branch)->orderByDesc('id')->limit(2)->get();
            $valid = app(LayeredMemoryService::class)->verifiedSources($actor, $novel, $branch, $recent->pluck('id')->all());
            foreach ($recent as $source) {
                if (isset($valid[$source->id])) {
                    $add('recent_body', '作者候选正文（章位未认证，角色未知）', 'source_documents', $source->id, $source->source_hash, $source->body, false);
                }
            }
            // 最近摘要直接读取活动版本，不依赖索引追赶；角色声明仍不等于获知证据。
            $page = 1;
            do {
                $memories = app(LayeredMemoryService::class)->listing($actor, $novel, ['scope' => 'candidate', 'branch_id' => $branch, 'chapter' => $chapter['sequence'], 'status' => 'active', 'page' => $page]);
                foreach ($memories['items'] as $memory) {
                    $add('memory_'.$memory['kind'], '作者记忆（不证明角色知情）', 'memory_items', $memory['id'], $memory['version'], $memory['summary'], false, (float) $memory['importance']);
                }
                $page++;
            } while ($memories['next_page'] !== null);
            if (isset($d['search'])) {
                $retrieval = app(HybridSearchService::class)->search($actor, $novel, $d['search'] + ['scope' => 'candidate', 'branch_id' => $branch, 'chapter' => $chapter['sequence'], 'audience' => 'author']);
                foreach ($retrieval['results'] as $hit) {
                    $add('long_term_memory', '作者检索资料（不证明角色知情）', 'source_documents', $hit['source_version_id'], $hit['source_hash'], $hit['body'], false, $hit['score'], $hit['contributions']);
                }
            }
        }
        $add('style', '文风样例与负面清单', 'style_versions', $config['style']['id'], $config['style']['version'], $config['style']['content']['voice'], false);
        $model = $config['models'][0];
        $price = $billing['prices'][$model['model_version_id']];
        $mode = $price['reasoning_mode'];
        $capabilityMode = $model['declared_capabilities']['reasoning_metering'] ?? 'unknown';
        if ($capabilityMode !== 'unknown' && $capabilityMode !== $mode) {
            $issues[] = 'REASONING_MODE_MISMATCH';
        }
        if ($mode === 'separate' && $billing['max_reasoning_tokens'] === 0) {
            $issues[] = 'MISSING_REASONING_RESERVE';
        }
        $result = app(ContextComposer::class)->compose($items, $model['declared_capabilities']['context_tokens'], $billing['max_output_tokens'], $billing['max_reasoning_tokens'], $mode, $d['safety_margin']);
        $result += ['schema' => 'context-manifest-v1', 'scope' => $d['scope'], 'formal_usable' => false, 'real_model_eligible' => false, 'novel_id' => $novel, 'branch_id' => $branch, 'step_id' => $stepId, 'stage' => $step->stage, 'chapter' => $chapter['sequence'], 'scene_id' => (string) $d['scene_id'], 'pov_id' => (string) $d['pov_id'], 'row_version' => $book->row_version, 'state_version' => $book->state_version, 'configuration' => $config, 'billing' => $billing, 'retrieval' => $retrieval, 'issues' => array_values(array_unique($issues)), 'target_model_version_id' => $model['model_version_id'], 'step_dependency_hash' => $step->dependency_hash];
        if ($issues) {
            $result['status'] = 'missing_required';
        }
        $result = $this->scoreText($result);

        return $result;
    }

    private function decode(object $row): array
    {
        $payload = json_decode($row->payload, true, flags: JSON_THROW_ON_ERROR);
        if ($this->hash($payload) !== $row->content_hash || hash('sha256', $payload['final_prompt']) !== $payload['final_prompt_hash']) {
            throw new NovelProblem('CONTEXT_HASH_MISMATCH', '冻结清单哈希不匹配。');
        }

        return ['id' => (string) $row->id, 'content_hash' => $row->content_hash, 'created_at' => $row->created_at] + $payload;
    }

    public function read(User $actor, string $stepId, ?string $id = null): array
    {
        return DB::transaction(function () use ($actor, $stepId, $id) {
            [$book, $step, $run] = $this->step($actor, $stepId);
            Validator::make(['id' => $id], ['id' => 'nullable|integer|min:1|max:9223372036854775807'])->validate();
            $query = DB::table('context_manifests')->where('step_id', $stepId);
            $row = $id ? $query->where('id', $id)->first() : $query->orderByDesc('id')->first();
            if (! $row) {
                throw new NovelProblem('NOT_FOUND', '阶段尚无上下文清单。', 404);
            }
            $r = $this->decode($row);
            // 历史快照保持原样；当前资格单列，保守地将任何书级输入变化标为过期。
            $r['current_status'] = $book->row_version !== $r['row_version'] || $book->state_version !== $r['state_version'] || $run->status === 'canceled' ? 'stale' : $r['status'];
            $r['history'] = DB::table('context_manifests')->where('step_id', $stepId)->orderByDesc('id')->limit(50)->pluck('id')->map(fn ($id) => (string) $id)->all();

            return $r;
        });
    }

    /** 比较只输出同一已授权阶段的两份冻结文本及条目差异。 */
    public function compare(User $actor, string $step, string $left, string $right): array
    {
        $a = $this->read($actor, $step, $left);
        $b = $this->read($actor, $step, $right);

        return ['left' => $a, 'right' => $b, 'same_prompt' => $a['final_prompt_hash'] === $b['final_prompt_hash']];
    }
}
