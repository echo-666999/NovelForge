<?php

namespace App\Services;

use App\Exceptions\NovelProblem;
use App\Extraction\ExtractionContract;
use App\Extraction\ExtractionProblem;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/** E03 显式离线提取回放：正文不改写，候选验证不提交正式剧情。 */
final class ExtractionService
{
    private function hash(mixed $v): string
    {
        return hash('sha256', NovelIntentService::canonical($v));
    }

    /** 只准备来源与约束，不需要模型输出，也不创建候选事件或提取记录。 */
    public function prepareInput(User $actor, string $novel, array $d, ?array $origin = null): array
    {
        Validator::make($d, ['chapter_version_id' => 'required|integer|min:1', 'body_hash' => 'required|string|size:64', 'expected_version' => 'required|integer|min:1', 'expected_state_version' => 'required|integer|min:0', 'parent_id' => 'nullable|integer|min:1', 'baseline_id' => 'nullable|integer|min:1', 'rule_context_id' => 'nullable|integer|min:1', 'reason' => 'required|string|max:2000'])->validate();
        ExtractionContract::transport($d);
        $book = app(NovelIntentService::class)->novel($actor, $novel, true);
        if ($book->status === 'archived' || $book->row_version !== (int) $d['expected_version'] || $book->state_version !== (int) $d['expected_state_version']) {
            throw new NovelProblem('STALE_STATE', '小说输入或正式状态版本已改变。');
        }
        $body = app(DraftingService::class)->read($actor, $novel, 'chapter', (string) $d['chapter_version_id']);
        if (! hash_equals($body['body_hash'], $d['body_hash'])) {
            throw new NovelProblem('STALE_EXTRACTION', '请求的正文哈希不对应此版本。');
        }
        $chapter = DB::table('chapters')->where('novel_id', $novel)->find($body['node_id']);
        $source = app(StoryStateService::class)->registerChapter($actor, $novel, $body['id']);
        $parent = empty($d['parent_id']) ? null : $this->read($actor, $novel, (string) $d['parent_id']);
        if ($parent && ($parent['chapter_version_id'] !== $body['id'] || $parent['body_hash'] !== $body['body_hash'] || ($parent['baseline_id'] ?? null) !== (isset($d['baseline_id']) ? (string) $d['baseline_id'] : null))) {
            throw new NovelProblem('REEXTRACTION_SOURCE_MISMATCH', '重新提取只能修正同一正文及同一前章基线；正文换版需新提取。');
        }
        $baseline = empty($d['baseline_id']) ? null : $this->baseline($actor, $novel, (string) $d['baseline_id']);
        $rulesContext = empty($d['rule_context_id']) ? null : app(StoryConsistencyService::class)->read($actor, $novel, (string) $d['rule_context_id']);
        $inventory = DB::table('entities')->where('novel_id', $novel)->orderBy('id')->get(['id', 'name', 'type', 'approved_version_id', 'retired'])->map(fn ($e) => ['id' => (string) $e->id, 'name' => $e->name, 'type' => $e->type, 'approved_version_id' => $e->approved_version_id === null ? null : (string) $e->approved_version_id, 'definition_hash' => $e->approved_version_id === null ? null : DB::table('entity_definition_versions')->where('id', $e->approved_version_id)->value('content_hash'), 'retired' => $e->retired])->all();
        $blueprint = DB::table('rolling_plans')->where('novel_id', $novel)->where('id', $body['plan_id'])->value('blueprint_version_id');
        $foreshadows = app(ForeshadowLedgerService::class)->ledger($actor, $novel, ['chapter' => $chapter->sequence, 'blueprint_version_id' => $blueprint])['items'];
        // 由已冻结运行派生来源标签，不能把真实提取标为手填离线回放。
        $real = $origin && DB::table('generation_runs')->where('id', $origin['run_id'])->where('novel_id', $novel)->where('frozen_config->execution_mode', 'production')->exists();
        $frozen = ['schema' => 'extraction-offline-v1', 'validator_version' => 'e03-hard-constraints-v1', 'execution_mode' => $real ? 'production' : 'explicit_offline_replay', 'row_version' => $book->row_version, 'state_version' => $book->state_version, 'parent_id' => $parent['id'] ?? null, 'chapter_sequence' => $chapter->sequence, 'chapter_version_id' => $body['id'], 'body_hash' => $body['body_hash'], 'source_id' => $source, 'generation_origin' => $origin, 'plan_id' => $body['plan_id'], 'baseline_id' => $baseline['id'] ?? null, 'baseline_input_hash' => $baseline['input_hash'] ?? null, 'rule_context_id' => $rulesContext['id'] ?? null, 'rule_context_hash' => $rulesContext['content_hash'] ?? null, 'approved_inventory' => $inventory, 'foreshadow_plans' => array_map(fn ($p) => array_intersect_key($p, array_flip(['id', 'content_hash', 'frozen', 'lifecycle'])), $foreshadows), 'real_model_eligible' => false];

        return compact('body', 'chapter', 'source', 'parent', 'baseline', 'rulesContext', 'inventory', 'frozen');
    }

    /** 请求级鉴权/并发失败直接拒绝；提取内容失败保存为可定位的不可变记录。 */
    public function extract(User $actor, string $novel, array $d, string $key, ?array $origin = null): array
    {
        Validator::make(['key' => $key, 'd' => $d], ['key' => ['required', 'string', 'max:128', 'regex:/^[\x20-\x7E]+$/D'], 'd' => 'required|array:chapter_version_id,body_hash,expected_version,expected_state_version,parent_id,baseline_id,rule_context_id,offline_output,reason'])->validate();
        Validator::make($d, ['chapter_version_id' => 'required|integer|min:1', 'body_hash' => 'required|string|size:64', 'expected_version' => 'required|integer|min:1', 'expected_state_version' => 'required|integer|min:0', 'parent_id' => 'nullable|integer|min:1', 'baseline_id' => 'nullable|integer|min:1', 'rule_context_id' => 'nullable|integer|min:1', 'offline_output' => 'required|array', 'reason' => 'required|string|max:2000'])->validate();
        ExtractionContract::transport($d);
        if (strlen(NovelIntentService::canonical($d)) > 1000000) {
            throw new NovelProblem('REQUEST_TOO_LARGE', '离线提取输入超过一百万字节。', 422);
        }

        return DB::transaction(function () use ($actor, $novel, $d, $key, $origin) {
            $book = app(NovelIntentService::class)->novel($actor, $novel, true);
            $where = ['actor_id' => $actor->id, 'novel_scope' => $novel, 'command' => $origin ? 'extraction.generated' : 'extraction.replay', 'key' => $key];
            if ($old = DB::table('novel_commands')->where($where)->first()) {
                if ($old->request_hash !== $this->hash($d)) {
                    throw new NovelProblem('IDEMPOTENCY_CONFLICT', '此操作键已用于不同提取输入。');
                }

                return json_decode($old->response, true, flags: JSON_THROW_ON_ERROR);
            }
            if ($book->status === 'archived' || $book->row_version !== (int) $d['expected_version'] || $book->state_version !== (int) $d['expected_state_version']) {
                throw new NovelProblem('STALE_STATE', '小说输入或正式状态版本已改变。');
            }
            // 离线回放与真实阶段共用来源准备，保留原有候选验证流程。
            ['body' => $body, 'chapter' => $chapter, 'source' => $source, 'parent' => $parent, 'baseline' => $baseline, 'rulesContext' => $rulesContext, 'inventory' => $inventory, 'frozen' => $frozen] = $this->prepareInput($actor, $novel, $d, $origin);
            $report = ['status' => 'blocked', 'eligible' => false, 'canonical' => false, 'issues' => []];
            $context = null;
            $events = [];
            $changes = [];
            // 保存点使结构错误不留下半份事件；原始失败响应仍在外层记录中保留。
            DB::beginTransaction();
            try {
                if ($body['stale'] || ! $body['report']['complete'] || (string) DB::table('chapter_versions')->where('novel_id', $novel)->where('node_id', $body['node_id'])->orderByDesc('version')->value('id') !== $body['id']) {
                    throw new NovelProblem('STALE_EXTRACTION', '旧版、过期或不完整正文不能通过提取。');
                }
                if (($chapter->sequence > 1 && ! $baseline) || ($baseline && (! $baseline['baseline_eligible'] || $baseline['chapter_sequence'] !== $chapter->sequence - 1))) {
                    throw new NovelProblem('INVALID_CANDIDATE_BASELINE', '须绑定紧邻前章当前有效的候选提取；不能用空基线忽略历史事实。');
                }
                [$context, $events, $changes, $report] = $this->materialize($actor, $novel, $body, $source, $chapter->sequence, $d['offline_output'], $baseline, $rulesContext, $inventory);
                DB::commit();
            } catch (NovelProblem|ValidationException $e) {
                DB::rollBack();
                $issues = $e instanceof ValidationException ? $e->errors() : ['code' => $e->errorCode];
                $report['issues'][] = ['code' => $e instanceof NovelProblem ? $e->errorCode : 'INVALID_EXTRACTION', 'status' => 'conflict', 'source_id' => $source, 'body_hash' => $body['body_hash'], 'details' => $issues, 'diagnostic' => $e instanceof ExtractionProblem ? $e->diagnostic : ['claimed_events' => array_map(fn ($row) => is_array($row) ? array_intersect_key($row, array_flip(['key', 'evidence'])) : [], array_slice(is_array($d['offline_output']['events'] ?? null) ? $d['offline_output']['events'] : [], 0, 20))], 'suggested_fix' => '对照同一正文修正提取响应后重新提取；不要修改原文制造证据。'];
            } catch (\Throwable $e) {
                DB::rollBack();
                throw $e;
            }
            $id = (string) DB::table('extraction_attempts')->insertGetId(['novel_id' => $novel, 'run_id' => $origin['run_id'] ?? null, 'artifact_id' => $origin['artifact_id'] ?? null, 'chapter_version_id' => $body['id'], 'source_id' => $source, 'parent_id' => $parent['id'] ?? null, 'baseline_id' => $baseline['id'] ?? null, 'context_id' => $context['id'] ?? null, 'body_hash' => $body['body_hash'], 'row_version' => $book->row_version, 'state_version' => $book->state_version, 'frozen_input' => NovelIntentService::canonical($frozen), 'input_hash' => $this->hash($frozen), 'output' => NovelIntentService::canonical($d['offline_output']), 'output_hash' => $this->hash($d['offline_output']), 'report' => NovelIntentService::canonical($report), 'actor_id' => $actor->id, 'reason' => $d['reason']]);
            foreach ($events as $local => $event) {
                DB::table('extraction_events')->insert(['novel_id' => $novel, 'attempt_id' => $id, 'event_id' => $event, 'event_key' => $local]);
            }
            foreach ($changes as $change) {
                DB::table('extraction_foreshadows')->insert($change + ['novel_id' => $novel, 'attempt_id' => $id]);
            }
            $response = $this->read($actor, $novel, $id);
            DB::table('novel_commands')->insert($where + ['request_hash' => $this->hash($d), 'response' => NovelIntentService::canonical($response), 'created_at' => now()]);

            return $response;
        });
    }

    /** 本地键先映射真实事件，再由D01生成补丁、D02验证候选时间与知情路径。 */
    private function materialize(User $actor, string $novel, array $body, string $source, int $chapter, array $p, ?array $baseline, ?array $rulesContext, array $inventory): array
    {
        ExtractionContract::validate($p);
        $base = $baseline && $baseline['context_id'] ? app(StoryConsistencyService::class)->read($actor, $novel, $baseline['context_id']) : null;
        $context = $base['payload'] ?? array_fill_keys(['entries', 'dependencies', 'knowledge', 'relationships', 'rules'], []);
        // 规则仅取作者显式选择的既有上下文，模型输出协议没有rules字段。
        if ($rulesContext) {
            foreach ($rulesContext['payload']['rules'] as $rule) {
                $same = collect($context['rules'])->first(fn ($r) => [$r['kind'], $r['subject_id'], $r['target_id']] === [$rule['kind'], $rule['subject_id'], $rule['target_id']]);
                if ($same && NovelIntentService::canonical($same) !== NovelIntentService::canonical($rule)) {
                    throw new NovelProblem('RULE_CONTEXT_CONFLICT', '作者规则上下文与已有候选规则冲突，不能默默覆盖。');
                }
                if (! $same) {
                    $context['rules'][] = $rule;
                }
            }
        }
        // 叙述位置由真实引文位置派生；响应列表重排不能把后文获知伪装成前文。
        $positions = [];
        foreach ($p['events'] as $index => $row) {
            $positions[] = ['key' => $row['key'], 'start' => (int) $row['evidence']['start_offset'], 'end' => (int) $row['evidence']['end_offset'], 'index' => $index];
        }
        usort($positions, fn ($a, $b) => [$a['start'], $a['end'], $a['index']] <=> [$b['start'], $b['end'], $b['index']]);
        $order = array_flip(array_column($positions, 'key'));
        $events = [];
        $current = null;
        try {
            foreach ($p['events'] as $i => $e) {
                $current = $e;
                $span = $e['evidence'];
                $evidence = app(StoryStateService::class)->evidence($actor, $novel, $source, $span['source_hash'], $span['start_offset'], $span['end_offset'], $span['quote']);
                $event = array_intersect_key($e, array_flip(['definition_version_id', 'holder_id', 'fact_kind', 'action', 'supersedes_id', 'attribute', 'value', 'confidence', 'reason']));
                $event += ['evidence_id' => $evidence, 'chapter_sequence' => $chapter, 'narrative_order' => $order[$e['key']] + 1, 'story_time' => $e['time']];
                $id = app(StoryStateService::class)->propose($actor, $novel, $event);
                $events[$e['key']] = $id;
                $entry = array_intersect_key($e, array_flip(['activity', 'location_id', 'ability_id', 'ability_units', 'previous_owner_id', 'revival_rule_id']));
                $context['entries'][] = $entry + ['event_id' => $id, 'time' => $e['time']];
            }
        } catch (NovelProblem|ValidationException $e) {
            // 错误原文位置只作诊断，不将未通过核验的引文登记为有效证据。
            throw new ExtractionProblem($e instanceof NovelProblem ? $e->errorCode : 'INVALID_EXTRACTION_EVENT', ['event_key' => $current['key'], 'claimed_evidence' => $current['evidence'], 'definition_version_id' => $current['definition_version_id'] ?? null]);
        }
        $ref = function (mixed $value) use ($events, $base): string {
            if (! is_string($value)) {
                throw new NovelProblem('INVALID_EVENT_REFERENCE', '引用必须为local:键或event:前章事件ID。', 422);
            }
            if (str_starts_with($value, 'local:') && isset($events[substr($value, 6)])) {
                return $events[substr($value, 6)];
            }
            if (str_starts_with($value, 'event:') && in_array(substr($value, 6), array_column($base['events'] ?? [], 'id'), true)) {
                return substr($value, 6);
            }
            throw new NovelProblem('INVALID_EVENT_REFERENCE', '事件引用不在本次响应或已验证前章基线。', 422);
        };
        foreach ($p['dependencies'] as $row) {
            $context['dependencies'][] = ['predecessor_id' => $ref($row['predecessor_ref'] ?? null), 'successor_id' => $ref($row['successor_ref'] ?? null), 'relation_type' => $row['relation_type'] ?? null];
        }
        foreach ($p['knowledge'] as $row) {
            $context['knowledge'][] = ['fact_event_id' => $ref($row['fact_ref'] ?? null), 'acquired_event_id' => $ref($row['acquired_ref'] ?? null)] + array_intersect_key($row, array_flip(['knower_id', 'method', 'source_knower_id']));
        }
        foreach ($p['relationships'] as $row) {
            $context['relationships'][] = ['event_id' => $ref($row['event_ref'] ?? null)] + array_intersect_key($row, array_flip(['from_entity_id', 'to_entity_id', 'relation_type', 'state']));
        }
        $context += ['reason' => 'E03 离线提取硬约束验证', 'expected_version' => (int) DB::table('story_context_versions')->where('novel_id', $novel)->max('version')];
        $saved = app(StoryConsistencyService::class)->save($actor, $novel, $context, 'e03:'.Str::uuid());
        $report = $saved['report'];
        // 直接命中的未批准/退役设定也必须定位正文，不能靠漏报事件绕过实体门禁。
        foreach ($inventory as $entity) {
            $at = mb_strpos($body['body'], $entity['name']);
            if ($at !== false && (! $entity['approved_version_id'] || $entity['retired'])) {
                $report['issues'][] = ['code' => 'UNAPPROVED_ENTITY_MENTION', 'status' => 'conflict', 'entity_id' => $entity['id'], 'evidence' => [['source_version_id' => $source, 'source_hash' => $body['body_hash'], 'start_offset' => $at, 'end_offset' => $at + mb_strlen($entity['name']), 'quote' => $entity['name']]], 'suggested_fix' => '先在设定库核实并批准；不得由提取器自动接纳核心实体。'];
            }
        }
        foreach ($p['events'] as $e) {
            $event = collect($saved['events'])->firstWhere('id', $events[$e['key']]);
            // 对已知隐藏事实的字面披露强制登记使用关系，不能靠漏报uses绕过。
            if (in_array($event['attribute'], ['speech', 'knowledge_use', 'reveal'], true)) {
                foreach ($saved['events'] as $secret) {
                    if ($secret['fact_kind'] === 'hidden_truth' && is_string($secret['value']) && $secret['value'] !== '' && str_contains($e['evidence']['quote'], $secret['value'])) {
                        $declared = array_map(fn ($u) => $ref($u['fact_ref']), $e['uses']);
                        if (! in_array($secret['id'], $declared, true)) {
                            $report['issues'][] = ['code' => 'UNDECLARED_SECRET_USE', 'status' => 'conflict', 'event_ids' => [$event['id'], $secret['id']], 'evidence' => [$event['evidence'], $secret['evidence']], 'suggested_fix' => '登记秘密引用并提供先于此句的角色获知证据。'];
                        }
                    }
                }
            }
            foreach ($e['uses'] as $use) {
                $fact = $ref($use['fact_ref']);
                $prefix = array_filter(array_column($saved['events'], null, 'id'), fn ($v) => [$v['chapter_sequence'], $v['narrative_order']] <= [$event['chapter_sequence'], $event['narrative_order']]);
                $prefixContext = $saved['payload'];
                $prefixContext['entries'] = array_values(array_filter($prefixContext['entries'], fn ($r) => isset($prefix[$r['event_id']])));
                foreach (['dependencies' => ['predecessor_id', 'successor_id'], 'knowledge' => ['fact_event_id', 'acquired_event_id'], 'relationships' => ['event_id']] as $field => $keys) {
                    $prefixContext[$field] = array_values(array_filter($prefixContext[$field], fn ($r) => ! array_filter($keys, fn ($k) => ! isset($prefix[$r[$k]]))));
                }
                $known = app(StoryConsistencyValidator::class)->pov($prefixContext, $prefix, $use['knower_id'], $chapter);
                $paths = array_values(array_filter($known['knowledge'], function ($k) use ($fact, $saved, $event) {
                    if ($k['fact_event_id'] !== $fact) {
                        return false;
                    }
                    $acquired = collect($saved['events'])->firstWhere('id', $k['acquired_event_id']);

                    return $acquired['chapter_sequence'] < $event['chapter_sequence'] || ($acquired['chapter_sequence'] === $event['chapter_sequence'] && $acquired['evidence']['end_offset'] <= $event['evidence']['start_offset']);
                }));
                $held = collect($paths)->first(fn ($k) => $k['knowledge_kind'] !== 'inference') ?? ($paths[0] ?? null);
                if ($held && $held['knowledge_kind'] === 'inference' && $event['fact_kind'] === 'fact') {
                    $report['issues'][] = ['code' => 'SECRET_INFERENCE_AS_FACT', 'status' => 'conflict', 'event_ids' => [$event['id'], $fact], 'evidence' => [$event['evidence'], $held['evidence']], 'suggested_fix' => '推测不能自动升级为确定知情，补充真实获知路径或明确角色信念。'];
                }
                if ($use['knower_id'] !== $event['entity_id'] || ! $known['usable'] || ! $held) {
                    $report['issues'][] = ['code' => 'SECRET_KNOWLEDGE_VIOLATION', 'status' => 'conflict', 'event_ids' => [$event['id'], $fact], 'evidence' => [$event['evidence'], collect($saved['events'])->firstWhere('id', $fact)['evidence']], 'suggested_fix' => '补充角色在此句之前的真实获知路径；同章后文获知不能提前授权。'];
                }
            }
        }
        $changes = $this->foreshadows($actor, $novel, $body, $chapter, $p['foreshadows'], $ref, $baseline, $report);
        $report['status'] = array_filter($report['issues'], fn ($i) => $i['status'] === 'conflict') ? 'conflict' : ($report['issues'] ? 'ambiguous' : 'consistent');
        $report['eligible'] = $report['issues'] === [];
        $report['canonical'] = false;
        $report['semantic_completeness'] = 'not_proven_by_offline_replay';

        return [$saved, $events, $changes, $report];
    }

    /** 候选伏笔变化验证来源、窗口和前置埋设，不写D03正式occurrences。 */
    private function foreshadows(User $actor, string $novel, array $body, int $chapter, array $rows, \Closure $ref, ?array $baseline, array &$report): array
    {
        $history = $baseline['candidate_foreshadows'] ?? [];
        $changes = [];
        $bp = DB::table('rolling_plans')->where('id', $body['plan_id'])->value('blueprint_version_id');
        foreach ($rows as $row) {
            $plan = app(ForeshadowLedgerService::class)->read($actor, $novel, $row['plan_id']);
            $latest = (string) DB::table('foreshadow_plans')->where('lineage_id', $plan['lineage_id'])->orderByDesc('version')->value('id');
            if ($plan['id'] !== $latest || $plan['lifecycle'] !== 'planned' || $plan['blueprint_version_id'] !== (string) $bp || ! DB::table('foreshadow_freezes')->where('plan_id', $plan['id'])->exists()) {
                throw new NovelProblem('INVALID_FORESHADOW_SOURCE', '伏笔变化必须引用同书、同蓝图的当前冻结计划。');
            }
            $event = $ref($row['event_ref']);
            // 变化证据必须来自本章，不允许把前章引文重新标为本章回收。
            $ev = DB::table('proposed_events as e')->join('evidence_spans as s', 's.id', '=', 'e.evidence_id')->where('e.id', $event)->first(['s.source_version_id']);
            if ((string) $ev->source_version_id !== (string) DB::table('source_documents')->where('chapter_version_id', $body['id'])->value('id')) {
                throw new NovelProblem('INVALID_FORESHADOW_EVIDENCE', '伏笔变化必须有当前正文事件证据。');
            }
            $prior = array_filter([...$history, ...$changes], fn ($v) => $v['plan_id'] === $plan['id'] && $v['kind'] === 'plant');
            $start = $row['kind'] === 'plant' ? $plan['plant_start'] : ($row['kind'] === 'payoff' ? $plan['payoff_start'] : $plan['plant_start']);
            $end = $row['kind'] === 'plant' ? $plan['plant_end'] : $plan['payoff_end'];
            if ($chapter < $start || $chapter > $end || ($row['kind'] !== 'plant' && ! $prior)) {
                $report['issues'][] = ['code' => 'FORESHADOW_TRANSITION_INVALID', 'status' => 'conflict', 'plan_id' => $plan['id'], 'event_ids' => [$event], 'suggested_fix' => '核对埋设/强化/回收窗口及已有候选埋设；改期仍须作者规划决定。'];
            }
            foreach ($plan['payload']['prerequisite_event_ids'] as $pre) {
                if (! $baseline || ! in_array($pre, $baseline['candidate_event_ids'], true)) {
                    $report['issues'][] = ['code' => 'FORESHADOW_PREREQUISITE_MISSING', 'status' => 'conflict', 'plan_id' => $plan['id'], 'event_ids' => [$event, $pre], 'suggested_fix' => '补齐真实前置事件，不能把作者计划当成已发生事实。'];
                }
            }
            if ($row['kind'] === 'reinforce' && ! array_filter($plan['payload']['reinforcements'], fn ($w) => $chapter >= $w['start'] && $chapter <= $w['end'])) {
                $report['issues'][] = ['code' => 'FORESHADOW_REINFORCEMENT_WINDOW', 'status' => 'conflict', 'plan_id' => $plan['id'], 'event_ids' => [$event], 'suggested_fix' => '强化必须处于作者冻结的强化窗口；修改安排须另作作者决定。'];
            }
            $changes[] = ['plan_id' => $plan['id'], 'event_id' => $event, 'kind' => $row['kind']];
        }

        foreach (app(ForeshadowLedgerService::class)->ledger($actor, $novel, ['chapter' => $chapter, 'blueprint_version_id' => $bp])['items'] as $plan) {
            $meaning = $plan['payload']['hidden_meaning'];
            $at = mb_strpos($body['body'], $meaning);
            if ($plan['frozen'] && $plan['lifecycle'] === 'planned' && $plan['payload']['secrecy'] === 'author_only' && $chapter < $plan['payoff_start'] && $meaning !== '' && $at !== false) {
                $report['issues'][] = ['code' => 'FORESHADOW_EARLY_DISCLOSURE', 'status' => 'conflict', 'plan_id' => $plan['id'], 'evidence' => [['source_hash' => $body['body_hash'], 'start_offset' => $at, 'end_offset' => $at + mb_strlen($meaning), 'quote' => $meaning]], 'suggested_fix' => '作者隐藏含义在允许揭示窗口前被字面披露，核对正文与获知安排。'];
            }
        }

        return $changes;
    }

    /** 每次读取重验当前资格；旧报告原样保留，不重新绑定新正文。 */
    public function read(User $actor, string $novel, string $id): array
    {
        $book = app(NovelIntentService::class)->novel($actor, $novel);
        Validator::make(['id' => $id], ['id' => 'required|integer|min:1'])->validate();
        $a = DB::table('extraction_attempts')->where('novel_id', $novel)->find($id) ?? throw new NovelProblem('NOT_FOUND', '提取记录不存在。', 404);
        $r = (array) $a;
        foreach ($r as $k => $v) {
            if ($v !== null && ($k === 'id' || str_ends_with($k, '_id'))) {
                $r[$k] = (string) $v;
            }
        }
        foreach (['frozen_input', 'output', 'report'] as $field) {
            $r[$field] = json_decode($r[$field], true, flags: JSON_THROW_ON_ERROR);
        }
        if ($this->hash($r['frozen_input']) !== $a->input_hash || $this->hash($r['output']) !== $a->output_hash) {
            throw new NovelProblem('INVALID_EXTRACTION_HASH', '提取输入或响应哈希失配。');
        }
        $body = app(DraftingService::class)->read($actor, $novel, 'chapter', $r['chapter_version_id']);
        $latest = (string) DB::table('chapter_versions')->where('novel_id', $novel)->where('node_id', $body['node_id'])->orderByDesc('version')->value('id');
        $stale = $body['stale'] || $latest !== $body['id'] || $body['body_hash'] !== $r['body_hash'] || $book->row_version !== $a->row_version || $book->state_version !== $a->state_version;
        // 同正文较新的有效提取替代旧候选资格，旧事件和报告保持不可变。
        if (DB::table('extraction_attempts')->where('novel_id', $novel)->where('chapter_version_id', $r['chapter_version_id'])->where('id', '>', $id)->where('report->eligible', true)->exists()) {
            $stale = true;
        }
        $base = $r['baseline_id'] ? $this->baseline($actor, $novel, $r['baseline_id']) : null;
        if ($base && ! $base['baseline_eligible']) {
            $stale = true;
        }
        $context = $r['context_id'] ? app(StoryConsistencyService::class)->read($actor, $novel, $r['context_id']) : null;
        if ($context) {
            // D01重验补丁及引文；作者重新批准实体后旧提取不自动获得新批准。
            try {
                app(StoryStateService::class)->replay($actor, $novel, array_column($context['events'], 'id'), PHP_INT_MAX);
                foreach ($context['events'] as $e) {
                    app(WorldService::class)->assertReference($actor, $novel, $e['definition_version_id']);
                }
            } catch (NovelProblem) {
                $stale = true;
            }
        }
        $r['chapter_sequence'] = $r['frozen_input']['chapter_sequence'];
        $r['stale'] = $stale;
        $r['current_eligible'] = ! $stale && $r['report']['eligible'];
        $r['display_status'] = $stale ? 'stale' : $r['report']['status'];
        $r['candidate_event_ids'] = array_column($context['events'] ?? [], 'id');
        $r['events'] = $context['events'] ?? [];
        $r['patches'] = DB::table('proposed_state_patches')->where('novel_id', $novel)->whereIn('event_id', $r['candidate_event_ids'])->get()->map(fn ($p) => ['event_id' => (string) $p->event_id, 'patch' => json_decode($p->patch, true), 'patch_hash' => $p->patch_hash])->all();
        $local = DB::table('extraction_foreshadows')->where('attempt_id', $id)->get()->map(fn ($c) => ['plan_id' => (string) $c->plan_id, 'event_id' => (string) $c->event_id, 'kind' => $c->kind])->all();
        $r['candidate_foreshadows'] = [...($base['candidate_foreshadows'] ?? []), ...$local];
        $r['created_at'] = CarbonImmutable::parse($r['created_at'])->toISOString();
        $r['canonical'] = false;

        return $r;
    }

    /** E06正式前缀有独立资格，不能因全局状态前进而把已提交证据变成无效候选。 */
    public function baseline(User $actor, string $novel, string $id): array
    {
        $r = $this->read($actor, $novel, $id);
        $r['baseline_eligible'] = $r['current_eligible'];
        $r['baseline_scope'] = 'candidate';
        if (Schema::hasTable('canonical_commits')) {
            $book = app(NovelIntentService::class)->novel($actor, $novel);
            $commit = DB::table('canonical_commits')->where('novel_id', $novel)->where('revision_id', $book->active_canonical_revision_id)->where('event_artifact_id', $id)->where('status', 'committed')->first();
            if ($commit) {
                $formal = app(CanonicalCommitService::class)->read($actor, $novel, (string) $commit->id);
                if ($formal['chapter_version_id'] !== $r['chapter_version_id'] || $formal['body_hash'] !== $r['body_hash'] || ! $r['report']['eligible']) {
                    throw new NovelProblem('INVALID_CANONICAL_PREDECESSOR', '正式前缀与提取证据不一致。');
                }
                $r['baseline_eligible'] = true;
                $r['baseline_scope'] = 'canonical';
                $r['canonical_commit_id'] = (string) $commit->id;
            }
        }

        return $r;
    }

    public function listing(User $actor, string $novel): array
    {
        app(NovelIntentService::class)->novel($actor, $novel);

        return DB::table('extraction_attempts')->where('novel_id', $novel)->orderByDesc('id')->limit(50)->pluck('id')->map(fn ($id) => $this->read($actor, $novel, (string) $id))->all();
    }
}
