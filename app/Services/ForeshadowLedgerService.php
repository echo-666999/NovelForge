<?php

namespace App\Services;

use App\Exceptions\NovelProblem;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;

/** 作者规划账本；候选、冻结承诺和正式正文证据保持独立。 */
final class ForeshadowLedgerService
{
    public const TEXT = ['name', 'surface_clue', 'hidden_meaning', 'misdirection', 'no_early_reveal', 'causal_role', 'prerequisite_description'];

    // 书行锁串行化所有命令，幂等重试优先于小说乐观版本检查。
    public function execute(User $actor, string $novelId, string $command, array $data, string $key): array
    {
        app(NovelIntentService::class)->authorize($actor);
        Validator::make(['key' => $key], ['key' => ['required', 'string', 'max:128', 'regex:/^[\x20-\x7E]+$/D']])->validate();

        return DB::transaction(function () use ($actor, $novelId, $command, $data, $key) {
            $novel = app(NovelIntentService::class)->novel($actor, $novelId, true);
            $scope = ['actor_id' => $actor->id, 'novel_scope' => $novelId, 'command' => 'foreshadow.'.$command, 'key' => $key];
            $hash = hash('sha256', NovelIntentService::canonical($data));
            if ($old = DB::table('novel_commands')->where($scope)->first()) {
                if ($old->request_hash !== $hash) {
                    throw new NovelProblem('IDEMPOTENCY_CONFLICT', '操作键已用于不同内容。');
                }

                return json_decode($old->response, true, flags: JSON_THROW_ON_ERROR);
            }
            // 顶层同样拒绝未知字段，不能夹带正式状态或假证据。
            $allowed = match ($command) {
                'create' => ['plan'], 'freeze' => ['plan_id'], 'propose' => ['plan_id', 'kind', 'plan', 'alternative'], 'decide' => ['proposal_id', 'decision'], default => [],
            };
            Validator::make(['data' => $data], ['data' => 'array:'.implode(',', array_merge($allowed, ['expected_version', 'reason']))])->validate();
            Validator::make($data, ['expected_version' => 'required|integer|min:1', 'reason' => 'required|string|max:2000', 'status' => 'prohibited', 'lifecycle' => 'prohibited', 'overdue' => 'prohibited', 'commit_id' => 'prohibited'])->validate();
            if ((int) $data['expected_version'] !== $novel->row_version) {
                throw new NovelProblem('STALE_STATE', '规划已改变，请刷新小说版本。');
            }
            if (! in_array($novel->status, ['draft', 'planning'])) {
                throw new NovelProblem('INVALID_TRANSITION', '当前小说状态不可变更规划账本。');
            }
            $result = match ($command) {
                'create' => $this->create($actor, $novelId, $data),
                'freeze' => $this->freeze($actor, $novelId, $data),
                'propose' => $this->propose($actor, $novelId, $data),
                'decide' => $this->decide($actor, $novelId, $data),
                default => throw new NovelProblem('INVALID_ACTION', '不支持的伏笔操作。', 422),
            };
            $novel->row_version++;
            $novel->save();
            $result['row_version'] = $novel->row_version;
            DB::table('novel_commands')->insert($scope + ['request_hash' => $hash, 'response' => NovelIntentService::canonical($result), 'created_at' => now()]);

            return $result;
        });
    }

    private function create(User $actor, string $novel, array $data): array
    {
        Validator::make($data, ['plan' => 'required|array'])->validate();
        $p = $this->payload($actor, $novel, $data['plan']);
        $lineage = (string) DB::table('foreshadow_lineages')->insertGetId(['novel_id' => $novel, 'actor_id' => $actor->id]);

        return ['plan' => $this->writePlan($actor, $novel, $lineage, 1, $p, 'planned', $data['reason'])];
    }

    // 只接受白名单规划字段；知情范围是未来意图，不写入角色知识。
    private function payload(User $actor, string $novel, array $p): array
    {
        $fields = array_merge(self::TEXT, ['blueprint_version_id', 'thread_id', 'plant_start', 'plant_end', 'payoff_target', 'payoff_start', 'payoff_end', 'required', 'importance', 'secrecy', 'reinforcements', 'entity_version_ids', 'knower_version_ids', 'prerequisite_event_ids']);
        $rules = ['plan' => 'required|array:'.implode(',', $fields)];
        foreach (self::TEXT as $field) {
            $rules['plan.'.$field] = 'required|string|max:'.($field === 'name' ? 200 : 10000);
        }
        foreach (['blueprint_version_id', 'thread_id'] as $field) {
            $rules['plan.'.$field] = ($field === 'thread_id' ? 'nullable' : 'required').'|integer|min:1|max:9223372036854775807';
        }
        foreach (['plant_start', 'plant_end', 'payoff_target', 'payoff_start', 'payoff_end'] as $field) {
            $rules['plan.'.$field] = 'required|integer|min:1|max:100000';
        }
        $rules += ['plan.required' => 'required|boolean', 'plan.importance' => ['required', Rule::in(['major', 'minor'])], 'plan.secrecy' => ['required', Rule::in(['author_only', 'reader_hint', 'character_limited'])], 'plan.reinforcements' => 'present|array|max:100', 'plan.reinforcements.*' => 'array:start,end,new_information,choice_impact', 'plan.reinforcements.*.start' => 'required|integer|min:1|max:100000', 'plan.reinforcements.*.end' => 'required|integer|min:1|max:100000', 'plan.reinforcements.*.new_information' => 'required|string|max:5000', 'plan.reinforcements.*.choice_impact' => 'required|string|max:5000'];
        foreach (['entity_version_ids', 'knower_version_ids', 'prerequisite_event_ids'] as $field) {
            $rules['plan.'.$field] = 'present|array|max:200';
            $rules['plan.'.$field.'.*'] = 'required|integer|min:1|max:9223372036854775807|distinct';
        }
        $p = Validator::make(['plan' => $p], $rules)->validate()['plan'];
        foreach (['plant_start', 'plant_end', 'payoff_target', 'payoff_start', 'payoff_end'] as $field) {
            $p[$field] = (int) $p[$field];
        }
        $p['required'] = (bool) $p['required'];
        $p['blueprint_version_id'] = (string) $p['blueprint_version_id'];
        $p['thread_id'] = ! empty($p['thread_id']) ? (string) $p['thread_id'] : null;
        if ($p['plant_end'] < $p['plant_start'] || $p['payoff_start'] <= $p['plant_end'] || $p['payoff_target'] < $p['payoff_start'] || $p['payoff_target'] > $p['payoff_end']) {
            throw new NovelProblem('INVALID_WINDOW', '埋设须早于回收，目标章须位于允许窗口。', 422);
        }
        $blueprint = DB::table('blueprint_versions')->where('novel_id', $novel)->where('id', $p['blueprint_version_id'])->where('status', 'sealed')->first();
        if (! $blueprint) {
            throw new NovelProblem('INVALID_REFERENCE', '蓝图必须是同书封存版本。', 422);
        }
        if ($p['thread_id'] && ! DB::table('blueprint_thread_versions')->where('blueprint_version_id', $blueprint->id)->where('thread_id', $p['thread_id'])->exists()) {
            throw new NovelProblem('INVALID_REFERENCE', '主支线不在绑定的蓝图版本内。', 422);
        }
        foreach ($p['reinforcements'] as &$window) {
            $window['start'] = (int) $window['start'];
            $window['end'] = (int) $window['end'];
            if ($window['start'] <= $p['plant_end'] || $window['end'] < $window['start'] || $window['end'] >= $p['payoff_start']) {
                throw new NovelProblem('INVALID_WINDOW', '强化窗口须在首次埋设后、回收窗口前。', 422);
            }
        }
        unset($window);
        foreach (['entity_version_ids', 'knower_version_ids', 'prerequisite_event_ids'] as $field) {
            $p[$field] = array_map('strval', $p[$field]);
        }
        foreach (['entity_version_ids', 'knower_version_ids'] as $field) {
            foreach ($p[$field] as $id) {
                $ref = app(WorldService::class)->assertReference($actor, $novel, $id);
                if ($field === 'knower_version_ids' && DB::table('entities')->where('id', $ref['entity_id'])->value('type') !== 'character') {
                    throw new NovelProblem('INVALID_REFERENCE', '计划知情者必须是人物。', 422);
                }
            }
        }
        if (DB::table('proposed_events')->where('novel_id', $novel)->whereIn('id', $p['prerequisite_event_ids'])->count() !== count($p['prerequisite_event_ids'])) {
            throw new NovelProblem('INVALID_REFERENCE', '前置候选事件必须来自同书。', 422);
        }

        return $p;
    }

    private function writePlan(User $actor, string $novel, string $lineage, int $version, array $p, string $lifecycle, string $reason): array
    {
        $row = array_intersect_key($p, array_flip(['blueprint_version_id', 'thread_id', 'name', 'plant_start', 'plant_end', 'payoff_target', 'payoff_start', 'payoff_end', 'required']));
        $id = (string) DB::table('foreshadow_plans')->insertGetId($row + ['novel_id' => $novel, 'lineage_id' => $lineage, 'version' => $version, 'status' => 'building', 'lifecycle' => $lifecycle, 'payload' => NovelIntentService::canonical($p), 'content_hash' => hash('sha256', NovelIntentService::canonical(['plan' => $p, 'lifecycle' => $lifecycle])), 'reason' => $reason, 'actor_id' => $actor->id]);
        foreach (['entity_version_ids' => 'related', 'knower_version_ids' => 'knower'] as $field => $role) {
            foreach ($p[$field] as $v) {
                $ref = app(WorldService::class)->assertReference($actor, $novel, $v);
                DB::table('foreshadow_entity_refs')->insert(['novel_id' => $novel, 'plan_id' => $id, 'entity_id' => $ref['entity_id'], 'definition_version_id' => $v, 'role' => $role]);
            }
        }
        foreach ($p['prerequisite_event_ids'] as $v) {
            DB::table('foreshadow_prerequisites')->insert(['novel_id' => $novel, 'plan_id' => $id, 'event_id' => $v]);
        }
        DB::table('foreshadow_plans')->where('id', $id)->update(['status' => 'sealed']);

        return $this->plan($novel, $id);
    }

    private function plan(string $novel, mixed $id): array
    {
        Validator::make(['id' => $id], ['id' => 'required|integer|min:1|max:9223372036854775807'])->validate();
        $p = DB::table('foreshadow_plans')->where('novel_id', $novel)->where('id', $id)->where('status', 'sealed')->first();
        if (! $p) {
            throw new NovelProblem('NOT_FOUND', '伏笔计划不存在。', 404);
        }

        return $this->decode($p);
    }

    private function current(string $novel, mixed $id): array
    {
        $p = $this->plan($novel, $id);
        if (DB::table('foreshadow_plans')->where('lineage_id', $p['lineage_id'])->where('version', '>', $p['version'])->exists()) {
            throw new NovelProblem('STALE_PLAN', '此计划已有新版本，请刷新。');
        }
        if ($p['lifecycle'] === 'abandoned') {
            throw new NovelProblem('INVALID_TRANSITION', '已批准放弃的伏笔不可继续变更。');
        }

        return $p;
    }

    private function freeze(User $actor, string $novel, array $data): array
    {
        $p = $this->current($novel, $data['plan_id'] ?? null);
        $this->payload($actor, $novel, $p['payload']);
        if (! DB::table('blueprint_approvals')->where('novel_id', $novel)->where('blueprint_version_id', $p['blueprint_version_id'])->exists()) {
            throw new NovelProblem('BLUEPRINT_NOT_APPROVED', '须先批准绑定蓝图，才能冻结伏笔承诺。');
        }
        if (DB::table('foreshadow_freezes')->where('plan_id', $p['id'])->exists()) {
            throw new NovelProblem('ALREADY_FROZEN', '此版本已经冻结。');
        }
        $id = DB::table('foreshadow_freezes')->insertGetId(['novel_id' => $novel, 'plan_id' => $p['id'], 'content_hash' => $p['content_hash'], 'reason' => $data['reason'], 'actor_id' => $actor->id]);

        return ['freeze_id' => (string) $id, 'plan' => $p];
    }

    private function propose(User $actor, string $novel, array $data): array
    {
        $p = $this->current($novel, $data['plan_id'] ?? null);
        Validator::make($data, ['kind' => ['required', Rule::in(['reschedule', 'abandon'])], 'alternative' => 'required_if:kind,abandon|nullable|string|max:10000', 'plan' => 'required_if:kind,reschedule|array'])->validate();
        $next = $data['kind'] === 'reschedule' ? $this->payload($actor, $novel, $data['plan'] ?? []) : $p['payload'];
        // 改期只调整窗口与强化安排，不能借改期降低必回收要求或偷换隐藏含义。
        $mutable = array_flip(['plant_start', 'plant_end', 'payoff_target', 'payoff_start', 'payoff_end', 'reinforcements']);
        if ($data['kind'] === 'reschedule' && NovelIntentService::canonical(array_diff_key($next, $mutable)) !== NovelIntentService::canonical(array_diff_key($p['payload'], $mutable))) {
            throw new NovelProblem('INVALID_REVISION', '改期只允许调整窗口和强化安排；不得改写身份、含义、来源或必回收要求。', 422);
        }
        $id = (string) DB::table('foreshadow_proposals')->insertGetId(['novel_id' => $novel, 'plan_id' => $p['id'], 'kind' => $data['kind'], 'payload' => NovelIntentService::canonical($next), 'reason' => $data['reason'], 'alternative' => $data['alternative'] ?? '', 'actor_id' => $actor->id]);

        return ['proposal_id' => $id, 'effective_plan_id' => $p['id']];
    }

    private function decide(User $actor, string $novel, array $data): array
    {
        Validator::make($data, ['proposal_id' => 'required|integer|min:1|max:9223372036854775807', 'decision' => ['required', Rule::in(['approve', 'reject'])]])->validate();
        $q = DB::table('foreshadow_proposals')->where('novel_id', $novel)->where('id', $data['proposal_id'])->first();
        if (! $q) {
            throw new NovelProblem('NOT_FOUND', '变更候选不存在。', 404);
        }
        if (DB::table('foreshadow_decisions')->where('proposal_id', $q->id)->exists()) {
            throw new NovelProblem('ALREADY_DECIDED', '该候选已有作者决定。');
        }
        $new = null;
        if ($data['decision'] === 'approve') {
            $old = $this->current($novel, $q->plan_id);
            $next = $this->payload($actor, $novel, json_decode($q->payload, true, flags: JSON_THROW_ON_ERROR));
            $new = $this->writePlan($actor, $novel, $old['lineage_id'], $old['version'] + 1, $next, $q->kind === 'abandon' ? 'abandoned' : 'planned', $data['reason']);
        }
        $id = (string) DB::table('foreshadow_decisions')->insertGetId(['novel_id' => $novel, 'proposal_id' => $q->id, 'plan_id' => $new['id'] ?? null, 'decision' => $data['decision'], 'reason' => $data['reason'], 'actor_id' => $actor->id]);

        return ['decision_id' => $id, 'plan' => $new];
    }

    // 统一 API 标识为十进制字符串，避免前端整数精度损失。
    private function decode(object $row): array
    {
        $a = (array) $row;
        foreach ($a as $k => &$v) {
            if ($v !== null && ($k === 'id' || str_ends_with($k, '_id'))) {
                $v = (string) $v;
            }
            if ($k === 'payload' || $k === 'content') {
                $v = json_decode($v, true, flags: JSON_THROW_ON_ERROR);
            }
        }

        return $a;
    }

    public function read(User $actor, string $novel, string $id): array
    {
        app(NovelIntentService::class)->novel($actor, $novel);
        $p = $this->plan($novel, $id);
        $p['history'] = DB::table('foreshadow_plans')->where('lineage_id', $p['lineage_id'])->orderBy('version')->get()->map(fn ($r) => $this->decode($r))->all();
        $ids = array_column($p['history'], 'id');
        $p['freezes'] = DB::table('foreshadow_freezes')->whereIn('plan_id', $ids)->get()->map(fn ($r) => $this->decode($r))->all();
        $p['proposals'] = DB::table('foreshadow_proposals')->whereIn('plan_id', $ids)->orderBy('id')->get()->map(function ($r) {
            $q = $this->decode($r);
            $decision = DB::table('foreshadow_decisions')->where('proposal_id', $r->id)->first();
            $q['decision'] = $decision ? $this->decode($decision) : null;

            return $q;
        })->all();
        $p['occurrences'] = $this->formalOccurrences($novel, $p['lineage_id']);
        $p['formal_progress'] = $this->hasFormalBaseline($novel) ? 'connected' : 'not_connected';

        return $p;
    }

    /** 正式生命周期只从当前基线的真实提交证据派生，规划版本不直接推进状态。 */
    private function hasFormalBaseline(string $novel): bool
    {
        return (bool) DB::table('novels')->where('id', $novel)->value('active_canonical_revision_id');
    }

    private function formalOccurrences(string $novel, string $lineage, ?int $chapter = null): array
    {
        $revision = DB::table('novels')->where('id', $novel)->value('active_canonical_revision_id');
        if (! $revision) {
            return [];
        }

        return DB::table('foreshadow_occurrences as o')->join('foreshadow_plans as p', 'p.id', '=', 'o.plan_id')->join('canonical_commits as c', 'c.id', '=', 'o.commit_id')->where('o.novel_id', $novel)->where('o.revision_id', $revision)->where('p.lineage_id', $lineage)->when($chapter !== null, fn ($q) => $q->where('c.chapter_sequence', '<=', $chapter))->orderBy('c.chapter_sequence')->orderBy('o.id')->get(['o.*', 'c.chapter_sequence'])->map(fn ($o) => $this->decode($o))->all();
    }

    public function ledger(User $actor, string $novel, array $filters = []): array
    {
        $book = app(NovelIntentService::class)->novel($actor, $novel);
        $v = Validator::make($filters, ['chapter' => 'sometimes|integer|min:1|max:100000', 'required' => 'sometimes|boolean', 'due' => 'sometimes|boolean', 'overdue' => 'sometimes|boolean', 'no_evidence' => 'sometimes|boolean', 'entity_id' => 'sometimes|integer|min:1|max:9223372036854775807', 'from' => 'sometimes|integer|min:1|max:100000', 'to' => 'sometimes|integer|min:1|max:100000', 'lifecycle' => ['sometimes', Rule::in(['planned', 'abandoned'])], 'blueprint_version_id' => 'sometimes|integer|min:1|max:9223372036854775807', 'thread_kind' => ['sometimes', Rule::in(['main', 'sub'])]])->validate();
        $chapter = isset($v['chapter']) ? (int) $v['chapter'] : null;
        $rows = DB::table('foreshadow_plans as p')->where('p.novel_id', $novel)->where('p.status', 'sealed')->whereNotExists(fn ($q) => $q->selectRaw('1')->from('foreshadow_plans as n')->whereColumn('n.lineage_id', 'p.lineage_id')->whereColumn('n.version', '>', 'p.version'))->orderBy('p.payoff_target')->orderBy('p.id')->get('p.*');
        $items = [];
        foreach ($rows as $row) {
            $p = $this->decode($row);
            $active = $p['lifecycle'] === 'planned';
            $p += ['first_actual_plant' => null, 'last_actual_reinforcement' => null, 'formal_status' => null, 'evidence_count' => 0, 'due' => $chapter === null ? null : ($active && $chapter >= $p['payoff_start'] && $chapter <= $p['payoff_end']), 'overdue' => $chapter === null ? null : ($active && $chapter > $p['payoff_end']), 'priority' => $chapter === null || ! $active ? 'none' : ($chapter >= $p['payoff_target'] - 5 ? 'high' : ($chapter >= $p['payoff_target'] - 20 ? 'groundwork_check' : 'normal'))];
            $actual = $this->formalOccurrences($novel, $p['lineage_id'], $chapter);
            $p['evidence_count'] = count($actual);
            $plants = array_values(array_filter($actual, fn ($o) => $o['kind'] === 'plant'));
            $reinforcements = array_values(array_filter($actual, fn ($o) => $o['kind'] === 'reinforce'));
            $p['first_actual_plant'] = $plants[0]['chapter_sequence'] ?? null;
            $p['last_actual_reinforcement'] = $reinforcements ? end($reinforcements)['chapter_sequence'] : null;
            $p['formal_status'] = ! $this->hasFormalBaseline($novel) ? null : (in_array('payoff', array_column($actual, 'kind'), true) ? 'paid_off' : ($reinforcements ? 'reinforced' : ($plants ? 'planted' : 'planned')));
            if ($p['formal_status'] === 'paid_off') {
                $p['overdue'] = false;
                $p['due'] = false;
            }
            $p['frozen'] = DB::table('foreshadow_freezes')->where('plan_id', $p['id'])->exists();
            $p['blockers'] = $active && ! $this->hasFormalBaseline($novel) ? ['FORMAL_EVIDENCE_UNAVAILABLE'] : [];
            if ($p['overdue']) {
                $p['blockers'][] = 'OVERDUE_WITHOUT_PAYOFF';
            }
            if ($p['payload']['prerequisite_event_ids']) {
                $p['blockers'][] = 'PREREQUISITES_NOT_FORMALLY_COMMITTED';
            }
            $p['suggestions'] = $p['priority'] === 'normal' || $p['priority'] === 'none' ? [] : ['核对首次埋设及强化的正式正文证据', '核对知情路径和因果作用；不足时提出补铺垫、改期或放弃候选'];
            $skip = false;
            foreach (['required', 'due', 'overdue'] as $f) {
                if (isset($v[$f]) && (bool) $v[$f] !== $p[$f]) {
                    $skip = true;
                }
            }
            if (isset($v['lifecycle']) && $p['lifecycle'] !== $v['lifecycle']) {
                $skip = true;
            }
            if (isset($v['blueprint_version_id']) && $p['blueprint_version_id'] !== (string) $v['blueprint_version_id']) {
                $skip = true;
            }
            if (isset($v['from']) && $p['payoff_end'] < $v['from'] || isset($v['to']) && $p['payoff_start'] > $v['to']) {
                $skip = true;
            }
            if (isset($v['no_evidence']) && (bool) $v['no_evidence'] !== ($p['evidence_count'] === 0)) {
                $skip = true;
            }
            if (isset($v['entity_id']) && ! DB::table('foreshadow_entity_refs')->where('plan_id', $p['id'])->where('entity_id', $v['entity_id'])->exists()) {
                $skip = true;
            }
            if (! $skip) {
                $items[] = $p;
            }
        }
        // 主支线取蓝图具体版本；没有正式事件时不推断闭合或准备度百分比。
        $blueprint = isset($v['blueprint_version_id']) ? (string) $v['blueprint_version_id'] : DB::table('blueprint_approvals')->where('novel_id', $novel)->orderByDesc('id')->value('blueprint_version_id');
        $threads = $blueprint ? DB::table('blueprint_thread_versions')->where('novel_id', $novel)->where('blueprint_version_id', $blueprint)->when($v['thread_kind'] ?? null, fn ($q, $kind) => $q->where('kind', $kind))->get()->map(function ($r) use ($rows) {
            $t = $this->decode($r);
            $t['formal_status'] = 'not_connected';
            $t['evidence_count'] = 0;
            $t['linked_plan_ids'] = $rows->filter(fn ($p) => $p->blueprint_version_id == $r->blueprint_version_id && $p->thread_id == $r->thread_id)->map(fn ($p) => (string) $p->id)->values()->all();
            $t['blockers'] = ['FORMAL_EVIDENCE_UNAVAILABLE'];

            return $t;
        })->all() : [];
        $ending = $blueprint ? DB::table('ending_contracts')->where('novel_id', $novel)->where('blueprint_version_id', $blueprint)->first() : null;

        return ['novel_id' => $novel, 'row_version' => $book->row_version, 'chapter' => $chapter, 'schedule_basis' => $chapter === null ? 'unspecified' : 'author_preview', 'formal_progress' => $this->hasFormalBaseline($novel) ? 'connected' : 'not_connected', 'items' => $items, 'threads' => $threads, 'ending' => $ending ? $this->decode($ending) : null];
    }
}
