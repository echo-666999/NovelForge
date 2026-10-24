<?php

namespace App\Services;

use App\Exceptions\NovelProblem;
use App\Models\BlueprintApproval;
use App\Models\BlueprintEntityRef;
use App\Models\BlueprintRequirementCoverage;
use App\Models\BlueprintThreadVersion;
use App\Models\BlueprintValidation;
use App\Models\BlueprintVersion;
use App\Models\EndingContract;
use App\Models\Novel;
use App\Models\OutlineVersion;
use App\Models\PremiseVersion;
use App\Models\Thread;
use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Pagination\Cursor;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;

final class BlueprintService
{
    public const FIELDS = ['logline', 'synopsis', 'world_overview', 'character_initial_states', 'structure_summary', 'pacing', 'information_reveals', 'growth_boundaries', 'sacrifices', 'foreshadows', 'turning_points', 'character_arcs', 'conflicts'];

    public const CHECKS = ['protagonist_goal', 'sustained_conflict', 'independent_motives', 'world_consistency', 'volume_changes', 'first_ten_scenes'];

    public const ENDING_TEXT = ['resolution', 'final_choice', 'core_reversal', 'final_relationships', 'forbidden_solutions', 'evidence_requirements'];

    private function hash(mixed $v): string
    {
        return hash('sha256', NovelIntentService::canonical($v));
    }

    private function id(string $id): void
    {
        if (! preg_match('/^[1-9][0-9]{0,18}$/D', $id) || (strlen($id) === 19 && strcmp($id, '9223372036854775807') > 0)) {
            throw new NovelProblem('NOT_FOUND', '蓝图版本不存在。', 404);
        }
    }

    private function get(string $novel, string $id): BlueprintVersion
    {
        $this->id($id);
        $v = BlueprintVersion::where('novel_id', $novel)->where('status', 'sealed')->find($id);
        if (! $v) {
            throw new NovelProblem('NOT_FOUND', '蓝图版本不存在。', 404);
        }

        return $v;
    }

    public function execute(User $actor, string $id, string $command, array $data, string $key): array
    {
        app(NovelIntentService::class)->authorize($actor);
        Validator::make(['key' => $key], ['key' => ['required', 'string', 'max:128', 'regex:/^[\x20-\x7E]+$/D']])->validate();
        $hash = $this->hash($data);
        try {
            return DB::transaction(function () use ($actor, $id, $command, $data, $key, $hash) {
                DB::select('SELECT pg_advisory_xact_lock(hashtextextended(?,0))', [NovelIntentService::canonical([$actor->id, $id, 'blueprint.'.$command, $key])]);
                $novel = app(NovelIntentService::class)->novel($actor, $id, true);
                $where = ['actor_id' => $actor->id, 'novel_scope' => $id, 'command' => 'blueprint.'.$command, 'key' => $key];
                if ($old = DB::table('novel_commands')->where($where)->first()) {
                    if ($old->request_hash !== $hash) {
                        throw new NovelProblem('IDEMPOTENCY_CONFLICT', '操作键已用于不同内容。');
                    }

                    return json_decode($old->response, true, flags: JSON_THROW_ON_ERROR);
                }
                Validator::make($data, ['expected_version' => 'required|integer|min:1', 'reason' => 'required|string|max:2000'])->validate();
                if ($novel->row_version != (int) $data['expected_version']) {
                    throw new NovelProblem('STALE_STATE', '小说输入或规划已变化，请刷新。');
                }
                if (! in_array($novel->status, ['draft', 'planning'])) {
                    throw new NovelProblem('INVALID_TRANSITION', '当前小说不可修改或批准蓝图。');
                }
                $result = match ($command) {
                    'save' => $this->save($actor, $novel, $data),'validate' => $this->validateVersion($actor, $novel, $data),'approve' => $this->approve($actor, $novel, $data),default => throw new NovelProblem('INVALID_TRANSITION', '无此蓝图操作。')
                };
                $novel->row_version++;
                $novel->status = 'planning';
                $novel->save();
                $result['novel'] = $novel->fresh()->toArray();
                DB::table('novel_commands')->insert($where + ['request_hash' => $hash, 'response' => NovelIntentService::canonical($result), 'created_at' => now()]);

                return $result;
            });
        } catch (QueryException $e) {
            if (in_array($e->errorInfo[0] ?? null, ['23503', '23505', '23514'])) {
                throw new NovelProblem('BLUEPRINT_CONFLICT', '蓝图归属、不可变版本或批准记录约束冲突。');
            }throw $e;
        }
    }

    private function context(User $actor, Novel $novel, array $entityIds): array
    {
        $req = app(NovelIntentService::class)->requirements($novel);
        $world = [];
        foreach ($entityIds as $id) {
            $v = app(WorldService::class)->readVersion($actor, $novel->id, (string) $id);
            $detail = app(WorldService::class)->detail($actor, $novel->id, $v['entity_id']);
            $world[] = ['version' => $v, 'entity' => $detail['entity']];
        }
        $structure = app(StructureService::class)->working($actor, $novel->id);

        return ['source_id' => (string) PremiseVersion::where('novel_id', $novel->id)->latest('version')->value('id'), 'target_chapters' => $novel->target_chapters, 'requirements' => $req, 'nodes' => $structure['nodes'], 'threads' => $structure['threads'], 'world' => $world];
    }

    private function save(User $actor, Novel $novel, array $data): array
    {
        $rules = ['outline_version_id' => 'required|integer|min:1', 'body' => 'required|array:'.implode(',', self::FIELDS), ...array_fill_keys(array_map(fn ($k) => 'body.'.$k, self::FIELDS), 'nullable|string|max:50000'),
            'entity_version_ids' => 'present|array|max:200', 'entity_version_ids.*' => 'required|integer|min:1|distinct', 'threads' => 'present|array|max:100',
            'threads.*' => 'array:thread_id,kind,goal,resolution,turning_points,character_arc', 'threads.*.thread_id' => 'required|integer|min:1|distinct', 'threads.*.kind' => ['required', Rule::in(['main', 'sub'])],
            ...array_fill_keys(array_map(fn ($k) => 'threads.*.'.$k, ['goal', 'resolution', 'turning_points', 'character_arc']), 'nullable|string|max:20000'),
            'ending' => 'required|array:target_chapters,start_chapter,end_chapter,'.implode(',', self::ENDING_TEXT).',outcomes,required_foreshadows,open_items',
            'ending.target_chapters' => 'required|integer|min:1|max:100000', 'ending.start_chapter' => 'required|integer|min:1|max:100000', 'ending.end_chapter' => 'required|integer|gte:ending.start_chapter|max:100000',
            ...array_fill_keys(array_map(fn ($k) => 'ending.'.$k, self::ENDING_TEXT), 'nullable|string|max:20000'),
            'ending.outcomes' => 'present|array|max:200', 'ending.outcomes.*' => 'array:entity_definition_version_id,outcome', 'ending.outcomes.*.entity_definition_version_id' => 'required|integer|min:1|distinct', 'ending.outcomes.*.outcome' => 'required|string|max:10000',
            'ending.required_foreshadows' => 'present|array|max:100', 'ending.required_foreshadows.*' => 'required|string|max:10000', 'ending.open_items' => 'present|array|max:100', 'ending.open_items.*' => 'required|string|max:10000',
            'coverages' => 'present|array|max:1000', 'coverages.*' => 'array:requirement_version_id,field,quote', 'coverages.*.requirement_version_id' => 'required|integer|min:1|distinct', 'coverages.*.field' => ['required', Rule::in(array_merge(array_map(fn ($k) => 'body.'.$k, array_diff(self::FIELDS, ['conflicts'])), array_map(fn ($k) => 'ending.'.$k, self::ENDING_TEXT)))], 'coverages.*.quote' => 'required|string|max:20000',
            'author_checks' => 'present|array:'.implode(',', self::CHECKS), ...array_fill_keys(array_map(fn ($k) => 'author_checks.'.$k, self::CHECKS), 'sometimes|boolean')];
        $v = Validator::make($data, $rules)->validate();
        $outline = app(StructureService::class)->version($actor, $novel->id, (string) $v['outline_version_id']);
        $v['entity_version_ids'] = array_map('strval', $v['entity_version_ids']);
        $context = $this->context($actor, $novel, $v['entity_version_ids']);
        foreach (self::FIELDS as $k) {
            $v['body'][$k] = $v['body'][$k] ?? '';
        }foreach (self::ENDING_TEXT as $k) {
            $v['ending'][$k] = $v['ending'][$k] ?? '';
        }
        foreach (['target_chapters', 'start_chapter', 'end_chapter'] as $k) {
            $v['ending'][$k] = (int) $v['ending'][$k];
        }
        foreach (self::CHECKS as $k) {
            $v['author_checks'][$k] = (bool) ($v['author_checks'][$k] ?? false);
        }
        foreach ($v['threads'] as &$t) {
            $thread = Thread::where('novel_id', $novel->id)->find($t['thread_id']);
            if (! $thread) {
                throw new NovelProblem('NOT_FOUND', '主支线不属于本书。', 404);
            }$t['thread_id'] = $thread->id;
            $t['identity_snapshot'] = $thread->toArray();
            foreach (['goal', 'resolution', 'turning_points', 'character_arc'] as $k) {
                $t[$k] = $t[$k] ?? '';
            }
        }unset($t);
        foreach ($v['ending']['outcomes'] as &$o) {
            $o['entity_definition_version_id'] = (string) $o['entity_definition_version_id'];
            if (! in_array($o['entity_definition_version_id'], $v['entity_version_ids'], true)) {
                throw new NovelProblem('VALIDATION_FAILED', '人物结局必须引用本蓝图的实体设定版本。', 422);
            }
        }unset($o);
        $current = collect($context['requirements'])->keyBy('id');
        foreach ($v['coverages'] as &$c) {
            $c['requirement_version_id'] = (string) $c['requirement_version_id'];
            if (! $current->has($c['requirement_version_id'])) {
                throw new NovelProblem('NOT_FOUND', '要求版本不是本书当前要求。', 404);
            }if (! str_contains((string) data_get($v, $c['field']), $c['quote'])) {
                throw new NovelProblem('EVIDENCE_INVALID', '覆盖引用必须准确出现在所选蓝图字段中。', 422);
            }
        }unset($c);
        foreach ($v['ending']['outcomes'] as $o) {
            if (app(WorldService::class)->readVersion($actor, $novel->id, $o['entity_definition_version_id'])['definition']['type'] !== 'character') {
                throw new NovelProblem('VALIDATION_FAILED', '人物结局只能引用人物设定版本。', 422);
            }
        }
        $v['outline_version_id'] = $outline['id'];
        $v['outline_hash'] = $outline['content_hash'];
        $v['source_version_id'] = $context['source_id'];
        $v['requirement_snapshot'] = $context['requirements'];
        $latest = BlueprintVersion::where('novel_id', $novel->id)->latest('version')->first();
        $impact = $this->impact($latest?->content, $v, $outline);
        $blueprint = BlueprintVersion::create(['novel_id' => $novel->id, 'version' => ($latest?->version ?? 0) + 1, 'source_version_id' => $context['source_id'], 'outline_version_id' => $outline['id'], 'base_version_id' => $latest?->id, 'status' => 'building', 'content' => $v, 'impact' => $impact, 'content_hash' => $this->hash($v), 'dependency_hash' => $this->hash($context), 'reason' => $data['reason'], 'actor_id' => $actor->id, 'created_at' => now()]);
        $base = ['novel_id' => $novel->id, 'blueprint_version_id' => $blueprint->id];
        EndingContract::create($base + ['version' => $blueprint->version, 'content' => $v['ending'], 'content_hash' => $this->hash($v['ending'])]);
        foreach ($v['threads'] as $t) {
            BlueprintThreadVersion::create($base + ['thread_id' => $t['thread_id'], 'kind' => $t['kind'], 'content' => $t]);
        }
        foreach ($v['entity_version_ids'] as $id) {
            BlueprintEntityRef::create($base + ['entity_definition_version_id' => $id]);
        }
        foreach ($v['coverages'] as $c) {
            BlueprintRequirementCoverage::create($base + $c);
        }
        $blueprint->status = 'sealed';
        $blueprint->save();

        return ['blueprint' => $this->read($actor, $novel->id, $blueprint->id)];
    }

    private function impact(?array $old, array $new, array $outline): array
    {
        $changed = [];
        foreach (['body', 'ending', 'threads', 'entity_version_ids', 'outline_version_id'] as $k) {
            if (! $old || $this->hash($old[$k]) !== $this->hash($new[$k])) {
                $changed[] = $k;
            }
        }
        $oldNodes = $old ? OutlineVersion::find($old['outline_version_id'])->content['nodes'] : [];
        $nodes = collect([...$oldNodes, ...$outline['content']['nodes']])->keyBy(fn ($n) => $n['type'].':'.$n['id'])->values()->all();

        return ['changed_sections' => $changed, 'ending_changed' => $old !== null && $this->hash($old['ending']) !== $this->hash($new['ending']), 'scope' => 'conservative_all_bound_structure', 'volumes' => array_values(array_map(fn ($n) => ['id' => $n['id'], 'title' => $n['title']], array_filter($nodes, fn ($n) => $n['type'] === 'volume'))), 'chapters' => array_values(array_map(fn ($n) => ['id' => $n['id'], 'title' => $n['title']], array_filter($nodes, fn ($n) => $n['type'] === 'chapter'))), 'planned_foreshadows' => array_values(array_unique([...($old['ending']['required_foreshadows'] ?? []), ...$new['ending']['required_foreshadows']])), 'canonical_text_impact' => null, 'actual_foreshadow_impact' => null, 'note' => '保守列出绑定规划范围；正式正文与伏笔 occurrence 尚未接入，不能判定无影响；作者伏笔计划请在账本逐项复核。'];
    }

    private function report(User $actor, Novel $novel, BlueprintVersion $v): array
    {
        $d = $v->content;
        $context = $this->context($actor, $novel, $d['entity_version_ids']);
        $problems = [];
        $add = function ($code, $field, $message) use (&$problems) {
            $problems[] = ['code' => $code, 'field' => $field, 'message' => $message];
        };
        if ($this->hash($d) !== $v->content_hash) {
            $add('SNAPSHOT_INVALID', 'content', '内容哈希不匹配。');
        }
        if ($this->hash($context) !== $v->dependency_hash) {
            $add('STALE_DEPENDENCIES', 'dependencies', '来源、要求、当前结构或实体资格已改变，请复制为新候选。');
        }
        $latest = BlueprintVersion::where('novel_id', $novel->id)->max('version');
        if ($v->version !== $latest) {
            $add('STALE_BLUEPRINT', 'version', '只能批准最新候选。');
        }
        $outline = app(StructureService::class)->version($actor, $novel->id, $v->outline_version_id);
        if ($this->hash($outline['content']['nodes']) !== $this->hash($context['nodes']) || $this->hash($outline['content']['threads']) !== $this->hash($context['threads'])) {
            $add('OUTLINE_STALE', 'outline_version_id', '绑定结构与工作草稿不同，请先保存新的结构版本。');
        }
        foreach (array_diff(self::FIELDS, ['conflicts']) as $k) {
            if (trim($d['body'][$k]) === '') {
                $add('BLUEPRINT_INCOMPLETE', 'body.'.$k, '蓝图内容尚未完整填写。');
            }
        }
        if (trim($d['body']['conflicts']) !== '') {
            $add('CONFLICT_UNRESOLVED', 'body.conflicts', '登记冲突尚未解决。');
        }
        foreach (self::CHECKS as $k) {
            if (! $d['author_checks'][$k]) {
                $add('AUTHOR_REVIEW_REQUIRED', 'author_checks.'.$k, '作者需完成此项检查。');
            }
        }
        $cover = collect($d['coverages'])->keyBy('requirement_version_id');
        foreach ($context['requirements'] as $req) {
            if ($req['source_version_id'] !== $context['source_id']) {
                $add('SOURCE_VERSION_CHANGED', 'requirements.'.$req['id'], '要求来源不是当前输入。');
            }
            if ($req['decision_status'] === 'waived' && $req['strength'] === 'preference') {
                continue;
            }
            if ($req['decision_status'] !== 'accepted' || $req['strength'] === 'undecided') {
                $add('AUTHOR_DECISION_REQUIRED', 'requirements.'.$req['id'], '要求尚未明确接受或解决。');
            }
            if (! $cover->has($req['id'])) {
                $add('REQUIREMENT_UNCOVERED', 'requirements.'.$req['id'], '缺少此要求的蓝图承接原文。');
            }
        }
        $characters = 0;
        foreach ($d['entity_version_ids'] as $id) {
            try {
                app(WorldService::class)->assertReference($actor, $novel->id, $id);
            } catch (NovelProblem $e) {
                $add($e->errorCode, 'entity_version_ids.'.$id, $e->getMessage());
            }if (app(WorldService::class)->readVersion($actor, $novel->id, $id)['definition']['type'] === 'character') {
                $characters++;
            }
        }
        if (! $characters) {
            $add('CHARACTER_REQUIRED', 'entity_version_ids', '蓝图至少绑定一个已批准人物设定。');
        }
        $main = 0;
        foreach ($d['threads'] as $i => $t) {
            if ($t['kind'] === 'main') {
                $main++;
                if (trim($t['resolution']) === '') {
                    $add('MAIN_RESOLUTION_REQUIRED', 'threads.'.$i, '主线必须有解决方式。');
                }
            }if (trim($t['goal']) === '') {
                $add('THREAD_GOAL_REQUIRED', 'threads.'.$i, '主支线缺少目标。');
            }
        }
        if (! $main) {
            $add('MAIN_THREAD_REQUIRED', 'threads', '至少需要一条主线。');
        }
        $end = $d['ending'];
        if ($end['target_chapters'] !== $novel->target_chapters || $end['start_chapter'] > $end['target_chapters'] || $end['end_chapter'] < $end['target_chapters']) {
            $add('ENDING_WINDOW_INVALID', 'ending', '目标需与小说一致并处于结束窗口内。');
        }
        foreach (self::ENDING_TEXT as $k) {
            if (trim($end[$k]) === '') {
                $add('ENDING_INCOMPLETE', 'ending.'.$k, '终局约束缺失；无相关事项时需明确写明。');
            }
        }
        $outcomeIds = array_column($end['outcomes'], 'entity_definition_version_id');
        foreach ($context['world'] as $entity) {
            $def = $entity['version'];
            if ($def['definition']['type'] === 'character' && $def['definition']['criticality'] === 'core' && ! in_array($def['id'], $outcomeIds, true)) {
                $add('ENDING_OUTCOME_REQUIRED', 'ending.outcomes', '每个绑定的关键人物都需要结局承诺。');
            }
        }
        if (! $end['outcomes']) {
            $add('ENDING_OUTCOME_REQUIRED', 'ending.outcomes', '需要明确主要人物结局。');
        }
        $chapters = collect($outline['content']['nodes'])->where('type', 'chapter');
        $scenes = collect($outline['content']['nodes'])->where('type', 'scene');
        for ($n = 1; $n <= min(10, $novel->target_chapters); $n++) {
            $chapter = $chapters->firstWhere('sequence', $n);
            if (! $chapter || ! $scenes->contains('parent_id', $chapter['id'])) {
                $add('EARLY_SCENE_MISSING', 'chapter.'.$n, '前十章结构需有章节及场景；字数与详细门禁仍由B05负责。');
            }
        }

        return ['validator_schema' => 'blueprint-check-v1', 'passed' => ! $problems, 'blueprint_version_id' => $v->id, 'content_hash' => $v->content_hash, 'dependency_hash' => $this->hash($context), 'blockers' => $problems, 'author_checks' => $d['author_checks'], 'generation_eligible' => false, 'canonical_state_changed' => false];
    }

    private function validateVersion(User $actor, Novel $novel, array $data): array
    {
        Validator::make($data, ['blueprint_version_id' => 'required|integer|min:1'])->validate();
        $v = $this->get($novel->id, (string) $data['blueprint_version_id']);
        $report = $this->report($actor, $novel, $v);
        $row = BlueprintValidation::create(['novel_id' => $novel->id, 'blueprint_version_id' => $v->id, 'actor_id' => $actor->id, 'reason' => $data['reason'], 'report' => $report, 'report_hash' => $this->hash($report), 'created_at' => now()]);

        return ['validation' => $row->toArray()];
    }

    private function approve(User $actor, Novel $novel, array $data): array
    {
        $x = Validator::make($data, ['blueprint_version_id' => 'required|integer|min:1', 'validation_id' => 'required|integer|min:1', 'report_hash' => 'required|string|size:64', 'acknowledge_impact' => 'accepted'])->validate();
        $v = $this->get($novel->id, (string) $x['blueprint_version_id']);
        $validation = BlueprintValidation::where('novel_id', $novel->id)->where('blueprint_version_id', $v->id)->find($x['validation_id']);
        $report = $this->report($actor, $novel, $v);
        if (! $validation || ! hash_equals($validation->report_hash, $x['report_hash']) || ! hash_equals($validation->report_hash, $this->hash($report))) {
            throw new NovelProblem('VALIDATION_STALE', '检查报告已过期或不匹配，请重新检查。');
        }
        if (! $report['passed']) {
            throw new NovelProblem('BLUEPRINT_BLOCKED', '蓝图检查尚有阻塞项，不能批准。', 422);
        }
        if (BlueprintApproval::where('blueprint_version_id', $v->id)->exists()) {
            throw new NovelProblem('INVALID_TRANSITION', '版本已批准。');
        }
        $a = BlueprintApproval::create(['novel_id' => $novel->id, 'blueprint_version_id' => $v->id, 'validation_id' => $validation->id, 'report_hash' => $validation->report_hash, 'reason' => $data['reason'], 'actor_id' => $actor->id, 'created_at' => now()]);

        return ['approval' => $a->toArray(), 'generation_eligible' => false, 'canonical_state_changed' => false];
    }

    public function read(User $actor, string $novel, string $id): array
    {
        app(NovelIntentService::class)->novel($actor, $novel);
        $v = $this->get($novel, $id);
        if ($this->hash($v->content) !== $v->content_hash) {
            throw new NovelProblem('SNAPSHOT_INVALID', '蓝图哈希不匹配。');
        }
        $approval = BlueprintApproval::where('blueprint_version_id', $id)->first();
        $check = BlueprintValidation::where('blueprint_version_id', $id)->latest('id')->first();
        $latestApproved = BlueprintApproval::where('novel_id', $novel)->latest('id')->value('blueprint_version_id');

        return $v->toArray() + ['ending_contract' => EndingContract::where('blueprint_version_id', $id)->first()->toArray(), 'approval' => $approval?->toArray(), 'validation' => $check?->toArray(), 'display_status' => $approval ? ($latestApproved === $v->id ? 'approved' : 'superseded') : ($check && ! $check->report['passed'] ? 'needs_changes' : 'draft'), 'generation_eligible' => false];
    }

    public function listing(User $actor, string $novel, array $filters = []): array
    {
        app(NovelIntentService::class)->novel($actor, $novel);
        $v = Validator::make($filters, ['status' => ['nullable', Rule::in(['draft', 'needs_changes', 'approved', 'superseded'])], 'limit' => 'sometimes|integer|min:1|max:100', 'cursor' => 'nullable|string|max:2000'])->validate();
        try {
            $cursor = empty($v['cursor']) ? null : Cursor::fromEncoded($v['cursor']);
            if (! empty($v['cursor'])) {
                if (! $cursor) {
                    throw new \InvalidArgumentException;
                }$this->id((string) ($cursor->toArray()['id'] ?? ''));
            }
        } catch (\Throwable) {
            throw new NovelProblem('VALIDATION_FAILED', '无效分页游标。', 422);
        }
        $query = BlueprintVersion::where('novel_id', $novel)->where('status', 'sealed');
        if (! empty($v['status'])) {
            $approved = BlueprintApproval::where('novel_id', $novel)->select('blueprint_version_id');
            $latest = BlueprintApproval::where('novel_id', $novel)->latest('id')->value('blueprint_version_id');
            if ($v['status'] === 'approved') {
                $query->whereKey($latest ?? '0');
            } elseif ($v['status'] === 'superseded') {
                $query->whereIn('id', $approved)->where('id', '<>', $latest ?? '0');
            } else {
                $query->whereNotIn('id', $approved);
                $failed = "EXISTS (SELECT 1 FROM blueprint_validations v WHERE v.blueprint_version_id=blueprint_versions.id AND v.id=(SELECT max(v2.id) FROM blueprint_validations v2 WHERE v2.blueprint_version_id=blueprint_versions.id) AND v.report->>'passed'='false')";
                $query->whereRaw(($v['status'] === 'draft' ? 'NOT ' : '').$failed);
            }
        }
        $page = $query->orderBy('id')->cursorPaginate($v['limit'] ?? 20, ['*'], 'cursor', $cursor);

        return ['items' => $page->getCollection()->map(fn ($b) => $this->read($actor, $novel, $b->id))->all(), 'next_cursor' => $page->nextCursor()?->encode(), 'generation_eligible' => false];
    }
}
