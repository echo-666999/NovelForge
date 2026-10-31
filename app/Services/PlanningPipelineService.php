<?php

namespace App\Services;

use App\Context\ContextComposer;
use App\Exceptions\NovelProblem;
use App\Generation\StageExecutionMode;
use App\Models\Novel;
use App\Models\PremiseVersion;
use App\Models\User;
use App\Planning\PlanningContract;
use App\ProvidersGateway\StructuredOutput;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;

/** 五阶段规划 DAG：作者采纳回执是下一阶段的依赖边，批准与冻结仍使用 B01–B05。 */
final class PlanningPipelineService
{
    private function hash(mixed $value): string
    {
        return hash('sha256', NovelIntentService::canonical($value));
    }

    public function start(User $actor, string $novel, array $d, string $key): array
    {
        StageExecutionMode::validate($d);
        if (isset($d['capability_probe']) && (! is_bool($d['capability_probe']) || ($d['capability_probe'] && (($d['phase'] ?? null) !== 'intake' || StageExecutionMode::mode($d) !== 'production')))) {
            throw new NovelProblem('INVALID_CAPABILITY_PROBE', '首次能力请求仅允许真实输入提炼阶段。', 422);
        }

        return DB::transaction(function () use ($actor, $novel, $d, $key) {
            $book = app(NovelIntentService::class)->novel($actor, $novel, true);
            Validator::make(['data' => $d, 'key' => $key], ['data' => 'array:phase,parent_id,route_version_id,billing,capability_probe,execution_mode,offline_output,start_chapter,end_chapter,expected_version,reason', 'key' => ['required', 'string', 'max:128', 'regex:/^[\x20-\x7E]+$/D']])->validate();
            $command = ['actor_id' => $actor->id, 'novel_scope' => $novel, 'command' => 'planning.start', 'key' => $key];
            $old = DB::table('novel_commands')->where($command)->first();
            if ($old) {
                if ($old->request_hash !== $this->hash($d)) {
                    throw new NovelProblem('IDEMPOTENCY_CONFLICT', '操作键已绑定其他规划输入。');
                }

                return json_decode($old->response, true, flags: JSON_THROW_ON_ERROR);
            }
            Validator::make($d, ['phase' => 'required|in:'.implode(',', PlanningContract::PHASES), 'parent_id' => 'nullable|integer|min:1', 'route_version_id' => 'required|integer|min:1', 'billing' => 'required|array', 'offline_output' => 'sometimes|array', 'expected_version' => 'required|integer|min:1', 'reason' => 'required|string|max:2000'])->validate();
            if ($book->row_version !== (int) $d['expected_version'] || $book->status === 'archived' || strlen(NovelIntentService::canonical($d)) > 180000) {
                throw new NovelProblem('STALE_STATE', '小说已变化、归档或离线资料过大。');
            }
            $phase = $d['phase'];
            $range = null;
            if (in_array($phase, ['chapter_plan', 'scene_plan'], true)) {
                Validator::make($d, ['start_chapter' => 'required|integer|min:1', 'end_chapter' => 'required|integer|gte:start_chapter|max:'.$book->target_chapters])->validate();
                if ($d['end_chapter'] - $d['start_chapter'] >= 10) {
                    throw new NovelProblem('WINDOW_INVALID', '近期规划每次最多十章。');
                }
                $range = [(int) $d['start_chapter'], (int) $d['end_chapter']];
            }
            $index = array_search($phase, PlanningContract::PHASES, true);
            $parent = null;
            $receipt = null;
            if ($index > 0) {
                $parent = $this->stage($novel, (string) ($d['parent_id'] ?? '0'));
                $receipt = DB::table('planning_adoptions')->where('stage_id', $parent->id)->first();
                if ($parent->phase !== PlanningContract::PHASES[$index - 1] || ! $receipt) {
                    throw new NovelProblem('PLANNING_DEPENDENCY_REQUIRED', '前一阶段候选尚未由作者采纳。');
                }

            } elseif (! empty($d['parent_id'])) {
                throw new NovelProblem('INVALID_PLANNING_PARENT', '输入提炼不能绑定前一阶段。');
            }
            $approvedBlueprint = null;
            if ($index >= 2) {
                // 作者可以先修订模型草稿再批准，必须追溯到本条 DAG 的蓝图候选，不能借别的分支放行。
                $ancestor = $parent;
                while ($ancestor->phase !== 'blueprint') {
                    $ancestor = $this->stage($novel, (string) $ancestor->parent_id);
                }
                $blueprintReceipt = DB::table('planning_adoptions')->where('stage_id', $ancestor->id)->first();
                $original = json_decode($blueprintReceipt->result, true)['blueprint_id'];
                $latest = DB::table('blueprint_approvals')->where('novel_id', $novel)->orderByDesc('id')->value('blueprint_version_id');
                if (! $latest) {
                    throw new NovelProblem('BLUEPRINT_NOT_APPROVED', '先由作者完成蓝图修订、检查与批准。');
                }
                $version = app(BlueprintService::class)->read($actor, $novel, (string) $latest);
                $cursor = $version;
                $found = false;
                for ($n = 0; $n < 100; $n++) {
                    if ((string) $cursor['id'] === $original) {
                        $found = true;
                        break;
                    }
                    if (DB::table('planning_adoptions')->where('novel_id', $novel)->where('result->blueprint_id', $cursor['id'])->where('stage_id', '<>', $ancestor->id)->exists()) {
                        break;
                    }
                    if (! $cursor['base_version_id']) {
                        break;
                    }
                    $cursor = app(BlueprintService::class)->read($actor, $novel, $cursor['base_version_id']);
                }
                if (! $found) {
                    throw new NovelProblem('BLUEPRINT_LINEAGE_MISMATCH', '已批准蓝图不是当前规划候选的作者修订后代。');
                }
                $approvedBlueprint = $version;
            }
            $source = PremiseVersion::where('novel_id', $novel)->latest('version')->firstOrFail();
            if ($parent) {
                $parentFrozen = json_decode(DB::table('generation_runs')->where('id', $parent->run_id)->value('frozen_config'), true);
                if ($parentFrozen['source_id'] !== $source->id || $parentFrozen['source_hash'] !== $source->content_hash) {
                    throw new NovelProblem('DEPENDENCY_MISMATCH', '创意来源已换版，须从新的输入提炼分支开始。');
                }
            }
            $requirements = app(NovelIntentService::class)->requirements($book);
            if ($index > 0 && collect($requirements)->contains(fn ($r) => $r['decision_status'] !== 'accepted' && ! ($r['decision_status'] === 'waived' && $r['strength'] === 'preference'))) {
                throw new NovelProblem('REQUIREMENT_UNRESOLVED', '先由作者确认输入提炼建议，不能由模型代替决定。');
            }
            // 输入提炼只需原始创意与要求；不携带整书结构和人物历史，避免无关资料挤满模板与计费边界。
            $context = ['requested_range' => $range, 'approved_blueprint' => $approvedBlueprint, 'source' => $source->toArray(), 'requirements' => $requirements, 'structure' => $phase === 'intake' ? null : app(StructureService::class)->working($actor, $novel), 'approved_entities' => $phase === 'intake' ? [] : DB::table('entities as e')->join('entity_definition_versions as v', 'v.id', '=', 'e.approved_version_id')->where('e.novel_id', $novel)->where('e.retired', false)->orderBy('e.id')->get(['e.id', 'e.type', 'e.name', 'v.id as version_id', 'v.definition', 'v.content_hash'])->toArray(), 'parent_receipt' => $receipt ? ['id' => (string) $receipt->id, 'result' => json_decode($receipt->result, true)] : null];
            // 前置规划没有场景/正式正文；复用 D06 组装器保护原始输入、要求及规划依赖，不伪造场景上下文。
            $variables = ['context' => NovelIntentService::canonical($context)];
            $cfg = app(AuthoringConfigurationService::class)->freeze($actor, ['route_version_id' => $d['route_version_id'], 'variables' => $variables, 'capability_probe' => $d['capability_probe'] ?? false], 'planning.'.$phase);
            StageExecutionMode::assertRoute($d, $cfg);
            if ($this->hash($cfg['prompt']['content']['output_schema']) !== $this->hash(PlanningContract::schema($phase))) {
                throw new NovelProblem('CONTRACT_MISMATCH', '路由输出必须匹配本阶段的固定规划 Schema。');
            }
            if (($d['billing']['category'] ?? null) !== 'planning') {
                throw new NovelProblem('CONTRACT_MISMATCH', '规划请求须使用 planning 预算类别。');
            }
            $billing = app(BudgetService::class)->freeze($actor, $novel, $d['billing'], $cfg);
            foreach ($cfg['models'] as $selected) {
                $metering = $billing['prices'][$selected['model_version_id']]['reasoning_mode'];
                $declared = $selected['declared_capabilities']['reasoning_metering'];
                if (($declared !== 'unknown' && $declared !== $metering) || ($metering === 'separate' && $billing['max_reasoning_tokens'] === 0)) {
                    throw new NovelProblem('CONTRACT_MISMATCH', '推理计量与冻结价格不符或缺少单列预留。');
                }
            }
            $model = $cfg['models'][0];
            $mode = $billing['prices'][$model['model_version_id']]['reasoning_mode'];
            $manifest = app(ContextComposer::class)->compose([['category' => 'planning_contract', 'zone' => '系统与作者规划区（不代表角色知识）', 'text' => $cfg['rendered_prompt']."\n输出契约：".NovelIntentService::canonical(PlanningContract::schema($phase)), 'required' => true]], $model['declared_capabilities']['context_tokens'], $billing['max_output_tokens'], $billing['max_reasoning_tokens'], $mode, 256);
            if ($manifest['status'] !== 'assembled') {
                throw new NovelProblem('CONTEXT_CAPACITY_EXCEEDED', '原始创意和强制规划依赖放不下；请调整已批准的离线路由容量。');
            }
            $cfg['rendered_prompt'] = $manifest['final_prompt'];
            $frozen = ['capability_probe' => $d['capability_probe'] ?? false, 'execution_mode' => StageExecutionMode::mode($d), 'schema_version' => 'planning-v1', 'executor_version' => 'planning.'.$phase.'-v1', 'goal' => 'candidate_only', 'outline_version_id' => null, 'outline_hash' => null, 'expected_state_version' => (string) $book->state_version, 'configuration' => $cfg, 'billing' => $billing, 'planning_context' => $manifest, 'planning_range' => $range, 'source_id' => $source->id, 'source_hash' => $source->content_hash, 'requirements_hash' => $this->hash($requirements), 'book_version' => $book->row_version, 'parent_artifact_hash' => $receipt ? DB::table('generation_artifacts')->where('id', $receipt->artifact_id)->value('content_hash') : null, 'nodes' => [['key' => $phase, 'stage' => 'planning.'.$phase, 'depends_on' => [], 'input' => StageExecutionMode::node($d)]]];
            $run = (string) DB::table('generation_runs')->insertGetId(['novel_id' => $novel, 'actor_id' => $actor->id, 'outline_version_id' => null, 'expected_state_version' => $book->state_version, 'configuration_route_version_id' => $cfg['route']['id'], 'command_key' => 'e01:'.Str::uuid(), 'request_hash' => $this->hash($d), 'frozen_config' => NovelIntentService::canonical($frozen), 'input_hash' => $this->hash($frozen), 'status' => 'queued', 'created_at' => now(), 'updated_at' => now()]);
            $id = (string) DB::table('planning_stages')->insertGetId(['novel_id' => $novel, 'run_id' => $run, 'phase' => $phase, 'parent_id' => $parent?->id, 'parent_receipt_id' => $receipt?->id]);
            app(OutboxService::class)->enqueue($actor, $novel, $run, $phase);

            $response = $this->read($actor, $novel, $id) + ['run_id' => $run, 'status_url' => '/api/v1/novels/'.$novel.'/planning-runs/'.$id];
            DB::table('novel_commands')->insert($command + ['request_hash' => $this->hash($d), 'response' => NovelIntentService::canonical($response), 'created_at' => now()]);

            return $response;
        });
    }

    private function stage(string $novel, string $id): object
    {
        Validator::make(['id' => $id], ['id' => 'required|integer|min:1|max:9223372036854775807'])->validate();

        return DB::table('planning_stages')->where('novel_id', $novel)->where('id', $id)->first() ?? throw new NovelProblem('NOT_FOUND', '规划阶段不属于当前小说。', 404);
    }

    /** 每次认领和结果提交都检查原始来源、要求和书级身份；旧候选仍保留可读。 */
    public function verifyFrozen(object $run, array $frozen): void
    {
        $book = Novel::find($run->novel_id);
        $source = PremiseVersion::where('novel_id', $run->novel_id)->latest('version')->first();
        if (! $book || $book->status === 'archived' || $book->row_version !== $frozen['book_version'] || (string) $book->state_version !== (string) $run->expected_state_version || $source?->id !== $frozen['source_id'] || $source->content_hash !== $frozen['source_hash'] || $this->hash($source->input) !== $source->content_hash || $this->hash(app(NovelIntentService::class)->requirements($book)) !== $frozen['requirements_hash'] || $this->hash($frozen) !== $run->input_hash) {
            throw new NovelProblem('DEPENDENCY_MISMATCH', '原始输入、要求或规划身份已改变，请创建新规划运行。');
        }
    }

    public function listing(User $actor, string $novel): array
    {
        app(NovelIntentService::class)->novel($actor, $novel);

        return DB::table('planning_stages')->where('novel_id', $novel)->orderByDesc('id')->limit(50)->get()->map(fn ($s) => ['id' => (string) $s->id, 'phase' => $s->phase, 'run_id' => (string) $s->run_id, 'parent_id' => $s->parent_id === null ? null : (string) $s->parent_id])->all();
    }

    public function read(User $actor, string $novel, string $id): array
    {
        app(NovelIntentService::class)->novel($actor, $novel);
        $stage = $this->stage($novel, $id);
        $data = app(GenerationService::class)->read($actor, $novel, (string) $stage->run_id);
        $adoption = DB::table('planning_adoptions')->where('stage_id', $id)->first();

        return ['id' => $id, 'phase' => $stage->phase, 'parent_id' => $stage->parent_id === null ? null : (string) $stage->parent_id, 'data' => $data, 'adoption' => $adoption ? ['id' => (string) $adoption->id, 'result' => json_decode($adoption->result, true), 'reason' => $adoption->reason] : null, 'mode' => StageExecutionMode::mode(json_decode(DB::table('generation_runs')->where('id', $stage->run_id)->value('frozen_config'), true)) === 'production' ? 'production' : 'offline_response_replay', 'canonical_state_changed' => false];
    }

    /** 候选以现有服务做事务内预演，所有草稿/审计写入回滚；模型声明不等于作者批准。 */
    public function validateCandidate(User $actor, string $runId, array $candidate): void
    {
        $run = DB::table('generation_runs')->find($runId);
        $frozen = json_decode($run->frozen_config, true);
        $this->verifyFrozen($run, $frozen);
        $phase = substr($frozen['nodes'][0]['stage'], 9);
        $this->coverage($phase, $candidate, app(NovelIntentService::class)->requirements(Novel::find($run->novel_id)), PremiseVersion::find($frozen['source_id']), $frozen['planning_range'] ?? null);
        DB::beginTransaction();
        try {
            $this->apply($actor, (string) $run->novel_id, $phase, $candidate, '离线候选结构预演，不代表批准');
        } finally {
            DB::rollBack();
        }
    }

    private function coverage(string $phase, array $c, array $requirements, PremiseVersion $source, ?array $range = null): void
    {
        if (app(StructuredOutput::class)->issues(json_decode(NovelIntentService::canonical($c)), PlanningContract::schema($phase))) {
            throw new NovelProblem('INVALID_PLANNING_CANDIDATE', '规划候选结构不合法。');
        }
        if ($phase === 'intake') {
            foreach ($c['requirements'] as $r) {
                if (! in_array($r['strength'], ['hard', 'preference', 'undecided'], true) || ! str_contains($r['content'], $r['source_quote']) || trim($r['source_quote']) === '' || ! collect($source->input)->contains(fn ($v) => is_string($v) && str_contains($v, $r['source_quote']))) {
                    throw new NovelProblem('PLANNING_COVERAGE_MISSING', '提炼必须保留可定位的原句和特殊细节。');
                }
            }
            foreach (['premise_raw', 'background', 'forbidden_content', 'ending_preference'] as $field) {
                if (! empty($source->input[$field]) && ! collect($c['requirements'])->contains(fn ($r) => str_contains($r['content'], $source->input[$field]))) {
                    throw new NovelProblem('PLANNING_COVERAGE_MISSING', '提炼遗漏原始输入具体内容，不能泛化替换。');
                }
            }

            return;
        }
        if ($phase === 'blueprint') {
            foreach (array_diff(BlueprintService::FIELDS, ['conflicts']) as $field) {
                if (trim($c['body'][$field]) === '') {
                    throw new NovelProblem('BLUEPRINT_INCOMPLETE', '蓝图候选字段不能空缺。');
                }
            }
        } else {
            if (! $c['nodes']) {
                throw new NovelProblem('INVALID_PLANNING_HIERARCHY', '规划候选不能没有节点。');
            }
            if ($range) {
                $coveredChapters = [];
                foreach ($c['nodes'] as $node) {
                    $chapter = $phase === 'chapter_plan' ? $node['sequence'] : DB::table('chapters')->where('novel_id', $source->novel_id)->where('id', $node['parent_id'] ?: 0)->value('sequence');
                    if (! $chapter || $chapter < $range[0] || $chapter > $range[1]) {
                        throw new NovelProblem('WINDOW_INVALID', '候选节点超出作者指定章范围。');
                    }
                    $coveredChapters[] = (int) $chapter;
                }
                $coveredChapters = array_values(array_unique($coveredChapters));
                sort($coveredChapters);
                if ($coveredChapters !== range($range[0], $range[1])) {
                    throw new NovelProblem('WINDOW_INVALID', '候选必须完整覆盖作者指定章范围。');
                }
            }
        }
        $byId = array_column($requirements, null, 'id');
        foreach ($c['coverages'] as $e) {
            if (! isset($byId[$e['requirement_version_id']]) || $e['quote'] !== $byId[$e['requirement_version_id']]['source_quote']) {
                throw new NovelProblem('EVIDENCE_INVALID', '覆盖必须引用当前同书要求的准确原句。');
            }
        }
        $nodes = collect($c['nodes'] ?? [])->keyBy('key');
        $requiredType = ['outline' => 'story_arc', 'chapter_plan' => 'chapter', 'scene_plan' => 'scene'][$phase] ?? null;
        foreach ($requirements as $r) {
            if ($r['decision_status'] === 'waived' && $r['strength'] === 'preference') {
                continue;
            }
            if ($r['decision_status'] !== 'accepted') {
                throw new NovelProblem('REQUIREMENT_UNRESOLVED', '先由作者确认全部原始要求。');
            }
            $covered = false;
            foreach ($c['coverages'] as $e) {
                if ($e['requirement_version_id'] !== $r['id']) {
                    continue;
                }
                $node = $phase === 'blueprint' ? $c : $nodes->get($e['node_key']);
                $text = $node ? data_get($node, $e['field']) : null;
                if (is_string($text) && $e['quote'] === $r['source_quote'] && str_contains($text, $e['quote']) && ($phase === 'blueprint' || $node['type'] === $requiredType)) {
                    $covered = true;
                }
            }
            if (! $covered) {
                throw new NovelProblem('PLANNING_COVERAGE_MISSING', '缺少蓝图/弧/章/场景的实际原文承接证据。');
            }
        }
    }

    /** 写命令按 actor+novel+command 幂等，重放原响应而不是变化后的当前快照。 */
    public function adopt(User $actor, string $novel, string $id, array $d, string $key): array
    {
        return DB::transaction(function () use ($actor, $novel, $id, $d, $key) {
            app(NovelIntentService::class)->novel($actor, $novel, true);
            Validator::make(['key' => $key, 'data' => $d], ['key' => ['required', 'string', 'max:128', 'regex:/^[\x20-\x7E]+$/D'], 'data' => 'array:artifact_id,expected_version,reason'])->validate();
            $where = ['actor_id' => $actor->id, 'novel_scope' => $novel, 'command' => 'planning.adopt', 'key' => $key];
            $hash = $this->hash([$id, $d]);
            if ($old = DB::table('novel_commands')->where($where)->first()) {
                if ($old->request_hash !== $hash) {
                    throw new NovelProblem('IDEMPOTENCY_CONFLICT', '采纳键已绑定其他输入。');
                }

                return json_decode($old->response, true, flags: JSON_THROW_ON_ERROR);
            }
            $response = $this->adoptLocked($actor, $novel, $id, $d);
            DB::table('novel_commands')->insert($where + ['request_hash' => $hash, 'response' => NovelIntentService::canonical($response), 'created_at' => now()]);

            return $response;
        });
    }

    private function adoptLocked(User $actor, string $novel, string $id, array $d): array
    {
        return DB::transaction(function () use ($actor, $novel, $id, $d) {
            $book = app(NovelIntentService::class)->novel($actor, $novel, true);
            $stage = $this->stage($novel, $id);
            Validator::make($d, ['expected_version' => 'required|integer|min:1', 'artifact_id' => 'required|integer|min:1', 'reason' => 'required|string|max:2000'])->validate();
            $old = DB::table('planning_adoptions')->where('stage_id', $id)->first();
            if ($old) {
                if ((string) $old->artifact_id !== (string) $d['artifact_id'] || $old->reason !== $d['reason']) {
                    throw new NovelProblem('IDEMPOTENCY_CONFLICT', '已采纳的阶段不能改绑其他候选。');
                }

                return $this->read($actor, $novel, $id);
            }
            $run = DB::table('generation_runs')->find($stage->run_id);
            $frozen = json_decode($run->frozen_config, true);
            $this->verifyFrozen($run, $frozen);
            if ($book->row_version !== (int) $d['expected_version'] || $run->status !== 'succeeded') {
                throw new NovelProblem('STALE_STATE', '只有当前输入的成功候选可采纳。');
            }
            $artifact = DB::table('generation_artifacts as a')->join('generation_steps as s', 's.id', '=', 'a.step_id')->where('a.run_id', $stage->run_id)->where('a.id', $d['artifact_id'])->where('s.status', 'succeeded')->where('a.type', 'planning_candidate')->select('a.*')->first();
            if (! $artifact || $this->hash(json_decode($artifact->content, true)) !== $artifact->content_hash) {
                throw new NovelProblem('INVALID_PLANNING_CANDIDATE', '候选产物不存在或哈希不一致。');
            }
            $candidate = json_decode($artifact->content, true);
            $this->coverage($stage->phase, $candidate, app(NovelIntentService::class)->requirements($book), PremiseVersion::find($frozen['source_id']), $frozen['planning_range'] ?? null);
            $result = $this->apply($actor, $novel, $stage->phase, $candidate, $d['reason']);
            DB::table('planning_adoptions')->insert(['novel_id' => $novel, 'stage_id' => $id, 'run_id' => $stage->run_id, 'artifact_id' => $artifact->id, 'actor_id' => $actor->id, 'before_version' => $book->row_version, 'after_version' => Novel::find($novel)->row_version, 'reason' => $d['reason'], 'result' => NovelIntentService::canonical($result)]);

            return $this->read($actor, $novel, $id);
        });
    }

    private function apply(User $actor, string $novel, string $phase, array $c, string $reason): array
    {
        $call = fn ($service, $action, $data) => app($service)->execute($actor, $novel, $action, $data + ['expected_version' => Novel::find($novel)->row_version, 'reason' => $reason], (string) Str::uuid());
        $source = PremiseVersion::where('novel_id', $novel)->latest('version')->firstOrFail();
        if ($phase === 'intake') {
            $ids = [];
            foreach ($c['requirements'] as $r) {
                $r['requirement_id'] = $r['requirement_id'] ?: null;
                // 模型只提出建议，作者需在 UI05 再确认强度和决定，不能代替作者接受硬约束。
                $ids[] = app(NovelIntentService::class)->execute($actor, 'requirement', $novel, $r + ['source_version_id' => $source->id, 'decision_status' => 'unresolved', 'expected_version' => Novel::find($novel)->row_version, 'reason' => $reason], (string) Str::uuid())['requirement']['id'];
            }

            return ['requirement_ids' => $ids];
        }
        $mapping = [];
        $identities = [];
        foreach ($c[$phase === 'blueprint' ? 'foundation_nodes' : 'nodes'] as $node) {
            $allowed = match ($phase) {
                'blueprint', 'outline' => ['volume', 'story_arc'], 'chapter_plan' => ['chapter'], 'scene_plan' => ['scene']
            };
            if (! in_array($node['type'], $allowed, true) || isset($mapping[$node['key']]) || $node['key'] === '') {
                throw new NovelProblem('INVALID_PLANNING_HIERARCHY', '本阶段含非法层级或重复局部标识。');
            }
            $identity = $node['type'].':'.$node['node_id'];
            if ($node['node_id'] !== '' && isset($identities[$identity])) {
                throw new NovelProblem('INVALID_PLANNING_HIERARCHY', '同一稳定节点不能在候选中重复覆盖。');
            }
            $identities[$identity] = true;
            $data = $node;
            unset($data['key'], $data['parent_key']);
            $data['node_id'] = $data['node_id'] ?: null;
            $data['parent_id'] = $node['parent_key'] !== '' ? ($mapping[$node['parent_key']]['id'] ?? throw new NovelProblem('INVALID_PLANNING_HIERARCHY', '局部父级必须已声明且拓扑有序。')) : ($data['parent_id'] ?: null);
            $data['source_version_id'] = $source->id;
            if (! in_array($node['type'], ['volume', 'story_arc'], true)) {
                unset($data['start_chapter'], $data['end_chapter']);
            }
            $mapping[$node['key']] = $call(StructureService::class, 'node', $data)['node'] + ['type' => $node['type']];
        }
        $result = ['nodes' => $mapping, 'suggested_coverages' => []];
        foreach ($c['coverages'] as $e) {
            if ($phase === 'blueprint') {
                continue;
            }
            $node = $mapping[$e['node_key']] ?? null;
            $text = $node ? data_get($node, $e['field']) : null;
            if (! is_string($text) || ! str_contains($text, $e['quote']) || ! in_array($e['field'], RollingPlanService::EVIDENCE_FIELDS, true)) {
                throw new NovelProblem('EVIDENCE_INVALID', '结构覆盖证据不能定位到实际保存的节点。');
            }
            $start = mb_strpos($text, $e['quote']);
            $result['suggested_coverages'][] = ['requirement_version_id' => $e['requirement_version_id'], 'type' => $node['type'], 'node_id' => $node['id'], 'field' => $e['field'], 'quote' => $e['quote'], 'start_offset' => $start, 'end_offset' => $start + mb_strlen($e['quote'])];
        }
        $outline = $call(StructureService::class, 'snapshot', [])['version'];
        $result['outline_id'] = $outline['id'];
        if ($phase === 'blueprint') {
            unset($c['foundation_nodes']);
            $bp = $call(BlueprintService::class, 'save', $c + ['outline_version_id' => $outline['id'], 'author_checks' => array_fill_keys(BlueprintService::CHECKS, false)])['blueprint'];
            $result['blueprint_id'] = $bp['id'];
        }

        return $result;
    }
}
