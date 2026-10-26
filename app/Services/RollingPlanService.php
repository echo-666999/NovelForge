<?php

namespace App\Services;

use App\Exceptions\NovelProblem;
use App\Models\Chapter;
use App\Models\Novel;
use App\Models\PlanFreeze;
use App\Models\PlanNode;
use App\Models\PlanValidation;
use App\Models\PremiseVersion;
use App\Models\RequirementCoverage;
use App\Models\RollingPlan;
use App\Models\Scene;
use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

final class RollingPlanService
{
    public const SCENE_FIELDS = ['pov', 'narration_mode', 'story_time', 'time_end', 'location', 'participants', 'conflict', 'emotion_start', 'emotion_end', 'required_events', 'forbidden_changes', 'transition_in', 'transition_out'];

    public const BUDGET_FIELDS = ['min_words', 'target_words', 'max_words'];

    public const EVIDENCE_FIELDS = ['objective', 'summary', 'details.required_events', 'details.forbidden_changes', 'details.foreshadow_tasks'];

    private function hash(mixed $data): string
    {
        return hash('sha256', NovelIntentService::canonical($data));
    }

    public function execute(User $actor, string $id, string $command, array $data, string $key): array
    {
        app(NovelIntentService::class)->authorize($actor);
        Validator::make(['key' => $key], ['key' => ['required', 'string', 'max:128', 'regex:/^[\x20-\x7E]+$/D']])->validate();
        $hash = $this->hash($data);
        try {
            return DB::transaction(function () use ($actor, $id, $command, $data, $key, $hash) {
                // 与其他规划服务共用小说行锁，覆盖证据、快照和命令响应一起提交。
                DB::select('SELECT pg_advisory_xact_lock(hashtextextended(?,0))', [NovelIntentService::canonical([$actor->id, $id, 'plan.'.$command, $key])]);
                $novel = app(NovelIntentService::class)->novel($actor, $id, true);
                $where = ['actor_id' => $actor->id, 'novel_scope' => $id, 'command' => 'plan.'.$command, 'key' => $key];
                if ($old = DB::table('novel_commands')->where($where)->first()) {
                    if ($old->request_hash !== $hash) {
                        throw new NovelProblem('IDEMPOTENCY_CONFLICT', '操作键已用于其他内容。');
                    }

                    return json_decode($old->response, true, flags: JSON_THROW_ON_ERROR);
                }
                Validator::make($data, ['expected_version' => 'required|integer|min:1', 'reason' => 'required|string|max:2000'])->validate();
                if ($novel->row_version != (int) $data['expected_version']) {
                    throw new NovelProblem('STALE_STATE', '规划已变化，请刷新。');
                }
                if ($novel->state_version > 0) {
                    throw new NovelProblem('HISTORY_CHANGE_REQUIRED', '已有正式状态，请通过历史变更流程处理；该流程尚未接入。');
                }
                if (! in_array($novel->status, ['draft', 'planning'])) {
                    throw new NovelProblem('INVALID_TRANSITION', '当前小说不可修改规划。');
                }
                $result = match ($command) {
                    'save' => $this->save($actor, $novel, $data),
                    'validate' => $this->validatePlan($actor, $novel, $data),
                    'freeze' => $this->freeze($actor, $novel, $data),
                    'split','merge','reorder' => $this->transform($actor, $novel, $command, $data),
                    default => throw new NovelProblem('INVALID_TRANSITION', '不支持的规划操作。')
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
                throw new NovelProblem('PLAN_CONFLICT', '计划归属、冻结或不可变约束冲突。');
            }
            throw $e;
        }
    }

    // B02 的所有写入口也必须遵守冻结规则，不能只隐藏后台按钮。
    public function assertEditable(Novel $novel, string $type, ?array $old, array $new): void
    {
        if ($novel->state_version > 0) {
            throw new NovelProblem('HISTORY_CHANGE_REQUIRED', '正式历史结构不可直接修改，请进入历史变更流程。');
        }
        if ($old && ($old['retired'] ?? false)) {
            throw new NovelProblem('INVALID_TRANSITION', '已合并的场景标识只供历史追溯。');
        }
        $ranges = RollingPlan::where('novel_id', $novel->id)->whereIn('id', PlanFreeze::select('rolling_plan_id'))->get();
        foreach ($ranges as $p) {
            foreach (array_filter([$old, $new]) as $row) {
                if ($type === 'scene') {
                    $n = Chapter::where('novel_id', $novel->id)->find($row['parent_id'] ?? null)?->sequence;
                    $hit = $n && $n >= $p->start_chapter && $n <= $p->end_chapter;
                } elseif ($type === 'chapter') {
                    $hit = ($row['sequence'] ?? 0) >= $p->start_chapter && ($row['sequence'] ?? 0) <= $p->end_chapter;
                } else {
                    $hit = ($row['start_chapter'] ?? PHP_INT_MAX) <= $p->end_chapter && ($row['end_chapter'] ?? 0) >= $p->start_chapter;
                }
                if ($hit) {
                    throw new NovelProblem('PLAN_FROZEN', '该节点属于冻结窗口；不能原地修改，须走后续历史变更流程。');
                }
            }
        }
    }

    private function get(string $novel, string $id): RollingPlan
    {
        if (! preg_match('/^[1-9][0-9]{0,18}$/D', $id) || (strlen($id) === 19 && strcmp($id, '9223372036854775807') > 0)) {
            throw new NovelProblem('NOT_FOUND', '计划不存在。', 404);
        }

        return RollingPlan::where('novel_id', $novel)->where('status', 'sealed')->find($id) ?? throw new NovelProblem('NOT_FOUND', '计划不存在。', 404);
    }

    private function save(User $actor, Novel $novel, array $data): array
    {
        $d = Validator::make($data, [
            'blueprint_version_id' => 'required|integer|min:1', 'start_chapter' => 'required|integer|min:1', 'end_chapter' => 'required|integer|gte:start_chapter',
            'coverages' => 'present|array|max:10000', 'coverages.*.requirement_version_id' => 'required|integer|min:1',
            'coverages.*.type' => ['required', Rule::in(['story_arc', 'chapter', 'scene'])], 'coverages.*.node_id' => 'required|integer|min:1',
            'coverages.*.field' => ['required', Rule::in(self::EVIDENCE_FIELDS)], 'coverages.*.quote' => 'required|string|max:20000',
            'coverages.*.start_offset' => 'required|integer|min:0', 'coverages.*.end_offset' => 'required|integer|min:1',
        ])->validate();
        $start = (int) $d['start_chapter'];
        $end = (int) $d['end_chapter'];
        // 短篇或末尾不足五章允许尾窗；首窗固定覆盖前十章，避免以局部通过冒充十章计划完成。
        if ($end > $novel->target_chapters || $end - $start + 1 > 10 || ($end - $start + 1 < 5 && $end !== $novel->target_chapters) || ($start === 1 && $end !== min(10, $novel->target_chapters))) {
            throw new NovelProblem('WINDOW_INVALID', '首窗需为前十章；后续每窗五至十章，尾窗可不足五章。', 422);
        }
        $bp = app(BlueprintService::class)->read($actor, $novel->id, (string) $d['blueprint_version_id']);
        if (! $bp['approval'] || $bp['display_status'] !== 'approved') {
            throw new NovelProblem('BLUEPRINT_NOT_APPROVED', '请选择当前已批准蓝图。', 422);
        }
        $outline = app(StructureService::class)->snapshot($actor, $novel, ['reason' => $data['reason']])['version'];
        $index = collect($outline['nodes'])->keyBy(fn ($n) => $n['node_type'].':'.$n[$n['node_type'].'_id']);
        $requirements = app(NovelIntentService::class)->requirements($novel);
        $reqIds = array_column($requirements, 'id');
        $cover = [];
        foreach ($d['coverages'] as $c) {
            $node = $index->get($c['type'].':'.$c['node_id']);
            if (! $node || ! in_array((string) $c['requirement_version_id'], $reqIds, true)) {
                throw new NovelProblem('NOT_FOUND', '覆盖节点或当前要求不属于此书。', 404);
            }
            $text = data_get($node['details'], $c['field']);
            // 偏移按 Unicode 码点计数，emoji 不能按字节或 UTF-16 长度截取。
            $a = (int) $c['start_offset'];
            $b = (int) $c['end_offset'];
            if (! is_string($text) || $b <= $a || $b > mb_strlen($text) || mb_substr($text, $a, $b - $a) !== $c['quote']) {
                throw new NovelProblem('EVIDENCE_INVALID', '覆盖引文与指定字段、码点偏移不匹配。', 422);
            }
            $cover[] = ['requirement_version_id' => (string) $c['requirement_version_id'], 'plan_node_id' => $node['id'], 'field' => $c['field'], 'quote' => $c['quote'], 'start_offset' => $a, 'end_offset' => $b, 'source_hash' => hash('sha256', $text)];
        }
        $content = ['schema' => 'rolling-plan-v1', 'blueprint_hash' => $bp['content_hash'], 'outline_hash' => $outline['content_hash'], 'requirements' => $requirements, 'coverages' => $cover];
        $plan = RollingPlan::create(['novel_id' => $novel->id, 'version' => (RollingPlan::where('novel_id', $novel->id)->max('version') ?? 0) + 1, 'outline_version_id' => $outline['id'], 'blueprint_version_id' => $bp['id'], 'start_chapter' => $start, 'end_chapter' => $end, 'content' => $content, 'content_hash' => $this->hash($content), 'status' => 'building', 'reason' => $data['reason'], 'actor_id' => $actor->id, 'created_at' => now()]);
        foreach ($cover as $c) {
            RequirementCoverage::create($c + ['novel_id' => $novel->id, 'rolling_plan_id' => $plan->id]);
        }
        $plan->status = 'sealed';
        $plan->save();

        return ['plan' => $this->read($actor, $novel->id, $plan->id)];
    }

    public function report(User $actor, Novel $novel, RollingPlan $p): array
    {
        $issues = [];
        $add = function ($code, $path, $message) use (&$issues) {
            $issues[] = compact('code', 'path', 'message');
        };
        $bp = app(BlueprintService::class)->read($actor, $novel->id, $p->blueprint_version_id);
        $outline = app(StructureService::class)->version($actor, $novel->id, $p->outline_version_id);
        $working = app(StructureService::class)->working($actor, $novel->id);
        $frozen = PlanFreeze::where('rolling_plan_id', $p->id)->exists();
        $effectiveNodes = fn ($rows) => $frozen ? $this->frozenDependencies($p, $rows, $outline['nodes']) : $rows;
        $nodes = collect($outline['content']['nodes']);
        $reqs = app(NovelIntentService::class)->requirements($novel);
        if (! $frozen) {
            // 滚动窗口只能接在已冻结窗口之后，不能跳过前章或重复冻结相交区间。
            $next = 1;
            foreach (RollingPlan::where('novel_id', $novel->id)->whereIn('id', PlanFreeze::select('rolling_plan_id'))->orderBy('start_chapter')->get() as $prior) {
                if ($prior->start_chapter <= $next) {
                    $next = max($next, $prior->end_chapter + 1);
                }
            }
            if ($p->start_chapter !== $next) {
                $add('WINDOW_OUT_OF_ORDER', 'window', '窗口必须从首个尚未冻结的章节开始。');
            }
        }

        if ($p->content_hash !== $this->hash($p->content) || $p->content['outline_hash'] !== $outline['content_hash'] || $p->content['blueprint_hash'] !== $bp['content_hash']) {
            $add('SNAPSHOT_INVALID', 'plan', '版本哈希不匹配。');
        }
        if (! $bp['approval'] || $bp['display_status'] !== 'approved') {
            $add('BLUEPRINT_STALE', 'blueprint', '蓝图不是当前批准版本。');
        }
        if ($bp['source_version_id'] !== (string) PremiseVersion::where('novel_id', $novel->id)->latest('version')->value('id') || $this->hash($bp['content']['requirement_snapshot']) !== $this->hash($reqs)) {
            $add('BLUEPRINT_STALE', 'requirements', '输入或要求已变更，须重新批准蓝图。');
        }
        $base = app(StructureService::class)->version($actor, $novel->id, $bp['outline_version_id'])['content'];
        // 章和场景可滚动细化，卷弧与主支线变更必须重新批准蓝图。
        $upper = fn ($rows) => array_values(array_filter($rows, fn ($n) => in_array($n['type'], ['volume', 'story_arc'])));
        if ($this->hash($upper($base['nodes'])) !== $this->hash($upper($working['nodes'])) || $this->hash($base['threads']) !== $this->hash($working['threads']) || $bp['content']['ending']['target_chapters'] !== $novel->target_chapters) {
            $add('BLUEPRINT_STALE', 'structure', '卷弧、主支线或终局目标已变化。');
        }
        // 检查计划中涉及的完整草稿，避免检查后编辑未冻结节点却沿用旧报告。
        if ($this->hash($effectiveNodes($outline['content']['nodes'])) !== $this->hash($effectiveNodes($working['nodes'])) || $this->hash($outline['content']['threads']) !== $this->hash($working['threads']) || $this->hash($p->content['requirements']) !== $this->hash($reqs) || $this->hash($outline['content']['novel']['chapter_length_policy']) !== $this->hash($novel->chapter_length_policy)) {
            $add('PLAN_STALE', 'plan', '工作草稿或要求已变更，请保存新计划。');
        }
        // E06只允许继续消费未变化的已冻结窗口；结构写入和历史重基线门禁仍保留。
        $committedPrefix = $frozen && $novel->active_canonical_revision_id && DB::table('canonical_revisions')->where('novel_id', $novel->id)->where('id', $novel->active_canonical_revision_id)->where('local_state_version', $novel->state_version)->exists();
        if ($novel->state_version > 0 && ! $committedPrefix) {
            $add('HISTORY_CHANGE_REQUIRED', 'novel', '必须绑定一致的正式前缀才能消费冻结计划；历史结构修改仍需专门流程。');
        }
        if (! in_array($novel->status, ['draft', 'planning'])) {
            $add('INVALID_TRANSITION', 'novel', '当前小说状态不允许冻结规划。');
        }
        foreach ($bp['content']['entity_version_ids'] as $ref) {
            try {
                app(WorldService::class)->assertReference($actor, $novel->id, $ref);
            } catch (NovelProblem $e) {
                $add($e->errorCode, 'world.'.$ref, $e->getMessage());
            }
        }
        $chapters = $nodes->where('type', 'chapter')->keyBy('sequence');
        for ($i = 1; $i <= $novel->target_chapters; $i++) {
            if (! $chapters->has($i)) {
                $add('SKELETON_MISSING', 'chapter.'.$i, '全书骨架缺少此章。');
            }
        }
        $sceneNodes = $nodes->where('type', 'scene');
        $ready = [];
        for ($i = $p->start_chapter; $i <= $p->end_chapter; $i++) {
            $ch = $chapters->get($i);
            if (! $ch) {
                continue;
            }
            $before = count($issues);
            $path = 'chapter.'.$i;
            if ($ch['status'] !== 'detailed' || ! trim($ch['objective']) || preg_match('/^第[\d一二三四五六七八九十百千零〇]+章\s*$/u', $ch['title'])) {
                $add('CHAPTER_INCOMPLETE', $path, '章节需为详细计划且具有具体章名与目标。');
            }
            $scenes = $sceneNodes->where('parent_id', $ch['id'])->values();
            if (! $scenes->count()) {
                $add('SCENE_MISSING', $path, '章节缺少场景。');
            }
            $sum = array_fill_keys(self::BUDGET_FIELDS, 0);
            $seen = [];
            foreach ($scenes as $s) {
                $sp = $path.'.scene.'.$s['sequence'];
                $d = $s['details'];
                if ($s['status'] !== 'detailed' || ! trim($s['objective'])) {
                    $add('SCENE_INCOMPLETE', $sp, '场景尚未细化。');
                }
                foreach (self::SCENE_FIELDS as $field) {
                    if (! trim((string) ($d[$field] ?? ''))) {
                        $add('SCENE_FIELD_MISSING', $sp.'.'.$field, '场景必填信息缺失；无事项也应明确说明。');
                    }
                }
                try {
                    $ref = app(WorldService::class)->assertReference($actor, $novel->id, (string) ($d['pov_version_id'] ?? '0'));
                    $definition = app(WorldService::class)->readVersion($actor, $novel->id, (string) $d['pov_version_id']);
                    if ($definition['definition']['type'] !== 'character' || $definition['definition']['name'] !== ($d['pov'] ?? '')) {
                        $add('POV_INVALID', $sp, '视角名须与已批准人物版本一致。');
                    }
                } catch (NovelProblem $e) {
                    $add('POV_INVALID', $sp, $e->getMessage());
                }
                $this->budget($d, $sp, $add);
                foreach (self::BUDGET_FIELDS as $k) {
                    $sum[$k] += (int) ($d[$k] ?? 0);
                }
                // 完全相同的目标、必要事件与冲突不能作为重复场景填补字数。
                $signature = $this->hash([trim($s['objective']), trim($d['required_events'] ?? ''), trim($d['conflict'] ?? '')]);
                if (isset($seen[$signature])) {
                    $add('DUPLICATE_SCENE', $sp, '存在重复场景目标、事件与冲突。');
                }
                $seen[$signature] = true;
            }
            $this->budget($ch['details'], $path, $add);
            foreach (self::BUDGET_FIELDS as $k) {
                if ($sum[$k] !== ($ch['details'][$k] ?? null)) {
                    $add('BUDGET_MISMATCH', $path.'.'.$k, '各场景字数下限、目标、上限之和须分别等于章节预算。');
                }
            }
            if (($ch['details']['min_words'] ?? 0) < $novel->chapter_length_policy['min'] || ($ch['details']['max_words'] ?? PHP_INT_MAX) > $novel->chapter_length_policy['max']) {
                $add('LENGTH_POLICY_MISMATCH', $path, '章节预算超出小说字数政策。');
            }
            if (count($issues) === $before) {
                $ready[] = $i;
            }
        }
        // 每项未豁免要求必须有蓝图→故事弧→所属章→所属场景的连贯证据链。
        $plans = collect($outline['nodes'])->keyBy('id');
        foreach ($reqs as $r) {
            if ($r['strength'] === 'preference' && $r['decision_status'] === 'waived') {
                continue;
            }
            if ($r['decision_status'] !== 'accepted' || $r['strength'] === 'undecided') {
                $add('AUTHOR_DECISION_REQUIRED', 'requirement.'.$r['id'], '要求尚未明确接受或豁免。');
            }
            $covered = $this->chain($p, $r['id'], $plans);
            if (! $covered) {
                $add('REQUIREMENT_UNCOVERED', 'requirement.'.$r['id'], '要求缺少同一父子路径上的弧、章、场景覆盖。');
            }
        }
        $context = ['source' => PremiseVersion::where('novel_id', $novel->id)->latest('version')->value('id'), 'requirements' => $reqs, 'nodes' => $effectiveNodes($working['nodes']), 'threads' => $working['threads'], 'blueprint' => $bp['id'], 'approval' => $bp['approval'], 'length_policy' => $novel->chapter_length_policy, 'target' => $novel->target_chapters];

        return ['schema' => 'plan-check-v1', 'rolling_plan_id' => $p->id, 'content_hash' => $p->content_hash, 'dependency_hash' => $this->hash($context), 'passed' => ! $issues, 'blockers' => $issues, 'detailed_chapters' => $ready, 'window' => [$p->start_chapter, $p->end_chapter], 'generation_eligible' => false, 'canonical_state_changed' => false];
    }

    // 已冻结窗口只跟踪自身节点、覆盖引用和卷弧；后续窗口正常细化不会使前窗失效。
    private function frozenDependencies(RollingPlan $p, array $rows, array $planNodes): array
    {
        $coverageIds = array_column($p->content['coverages'], 'plan_node_id');
        $keys = [];
        $chapters = [];
        foreach ($planNodes as $n) {
            if ($n['node_type'] === 'chapter' && $n['sequence'] >= $p->start_chapter && $n['sequence'] <= $p->end_chapter) {
                $chapters[] = $n['chapter_id'];
            }
            if (in_array($n['id'], $coverageIds, true)) {
                $keys[] = $n['node_type'].':'.$n[$n['node_type'].'_id'];
            }
        }

        return array_values(array_filter($rows, fn ($n) => in_array($n['type'], ['volume', 'story_arc']) || in_array($n['type'].':'.$n['id'], $keys, true) || ($n['type'] === 'chapter' && in_array($n['id'], $chapters, true)) || ($n['type'] === 'scene' && in_array($n['parent_id'], $chapters, true))));
    }

    private function budget(array $d, string $path, callable $add): void
    {
        foreach (self::BUDGET_FIELDS as $k) {
            if (! is_int($d[$k] ?? null) || $d[$k] < 1) {
                $add('BUDGET_REQUIRED', $path, '字数下限、目标和上限必须为正整数。');

                return;
            }
        }
        if ($d['min_words'] > $d['target_words'] || $d['target_words'] > $d['max_words']) {
            $add('BUDGET_RANGE_INVALID', $path, '字数必须满足下限 ≤ 目标 ≤ 上限。');
        }
    }

    private function chain(RollingPlan $p, string $req, $nodes): bool
    {
        $ids = collect($p->content['coverages'])->where('requirement_version_id', $req)->pluck('plan_node_id')->all();
        foreach ($ids as $id) {
            $scene = $nodes->get($id);
            if (($scene['node_type'] ?? null) !== 'scene') {
                continue;
            }
            $ch = $nodes->get($scene['parent_plan_node_id']);
            $arc = $nodes->get($ch['parent_plan_node_id'] ?? '');
            if ($ch && $arc && in_array($ch['id'], $ids, true) && in_array($arc['id'], $ids, true)) {
                return true;
            }
        }

        return false;
    }

    private function validatePlan(User $actor, Novel $novel, array $data): array
    {
        Validator::make($data, ['rolling_plan_id' => 'required|integer|min:1'])->validate();
        $p = $this->get($novel->id, (string) $data['rolling_plan_id']);
        $report = $this->report($actor, $novel, $p);
        $check = PlanValidation::create(['novel_id' => $novel->id, 'rolling_plan_id' => $p->id, 'report' => $report, 'report_hash' => $this->hash($report), 'reason' => $data['reason'], 'actor_id' => $actor->id, 'created_at' => now()]);

        return ['validation' => $check->toArray()];
    }

    private function freeze(User $actor, Novel $novel, array $data): array
    {
        Validator::make($data, ['rolling_plan_id' => 'required|integer|min:1', 'validation_id' => 'required|integer|min:1', 'report_hash' => 'required|string|size:64'])->validate();
        $p = $this->get($novel->id, (string) $data['rolling_plan_id']);
        $v = PlanValidation::where('rolling_plan_id', $p->id)->where('novel_id', $novel->id)->find($data['validation_id']);
        $report = $this->report($actor, $novel, $p);
        if (! $v || ! hash_equals($v->report_hash, $data['report_hash']) || ! hash_equals($v->report_hash, $this->hash($report))) {
            throw new NovelProblem('VALIDATION_STALE', '检查报告已过期，请重新检查。');
        }
        if (! $report['passed']) {
            throw new NovelProblem('PLAN_BLOCKED', '场景计划仍有阻塞项。', 422);
        }
        if (PlanFreeze::where('rolling_plan_id', $p->id)->exists()) {
            throw new NovelProblem('PLAN_FROZEN', '此版本已经冻结。');
        }
        $f = PlanFreeze::create(['novel_id' => $novel->id, 'rolling_plan_id' => $p->id, 'validation_id' => $v->id, 'report_hash' => $v->report_hash, 'reason' => $data['reason'], 'actor_id' => $actor->id, 'created_at' => now()]);

        return ['freeze' => $f->toArray(), 'generation_eligible' => false];
    }

    public function read(User $actor, string $novelId, string $id): array
    {
        $novel = app(NovelIntentService::class)->novel($actor, $novelId);
        $p = $this->get($novelId, $id);
        $report = $this->report($actor, $novel, $p);
        $f = PlanFreeze::where('rolling_plan_id', $p->id)->first();
        $codes = array_column($report['blockers'], 'code');
        $status = array_intersect($codes, ['PLAN_STALE', 'BLUEPRINT_STALE']) ? 'stale' : ($report['passed'] ? ($f ? 'frozen' : 'ready') : 'blocked');

        return $p->toArray() + ['display_status' => $status, 'report' => $report, 'freeze' => $f?->toArray(), 'validation' => PlanValidation::where('rolling_plan_id', $p->id)->latest('id')->first()?->toArray(), 'nodes' => PlanNode::where('outline_version_id', $p->outline_version_id)->orderBy('id')->get()->toArray(), 'generation_eligible' => false];
    }

    public function listing(User $actor, string $id, array $filters = []): array
    {
        app(NovelIntentService::class)->novel($actor, $id);
        $d = Validator::make($filters, ['before' => 'nullable|integer|min:1', 'limit' => 'sometimes|integer|min:1|max:100', 'status' => ['nullable', Rule::in(['ready', 'blocked', 'frozen', 'stale'])]])->validate();
        // 状态由当前依赖实时计算；先筛选再分页，不把页内筛选伪装成全局筛选。
        $limit = $d['limit'] ?? 20;
        $rows = [];
        foreach (RollingPlan::where('novel_id', $id)->where('status', 'sealed')->when($d['before'] ?? null, fn ($q, $v) => $q->where('id', '<', $v))->orderByDesc('id')->cursor() as $p) {
            $row = $this->read($actor, $id, $p->id);
            if (! empty($d['status']) && $row['display_status'] !== $d['status']) {
                continue;
            }
            $rows[] = $row;
            if (count($rows) > $limit) {
                break;
            }
        }
        $more = count($rows) > $limit;
        $rows = array_slice($rows, 0, $limit);

        return ['items' => $rows, 'next_before' => $more ? $rows[count($rows) - 1]['id'] : null];
    }

    public function coverage(User $actor, Novel $novel, array $requirements): array
    {
        $p = RollingPlan::where('novel_id', $novel->id)->where('status', 'sealed')->latest('version')->first();
        if (! $p) {
            return $requirements;
        }
        $report = $this->report($actor, $novel, $p);
        $stale = (bool) array_intersect(array_column($report['blockers'], 'code'), ['PLAN_STALE', 'BLUEPRINT_STALE', 'SNAPSHOT_INVALID']);
        $nodes = PlanNode::where('outline_version_id', $p->outline_version_id)->get()->keyBy('id')->map->toArray();
        foreach ($requirements as &$r) {
            $r['coverage_evidence'] = array_values(array_filter($p->content['coverages'], fn ($c) => $c['requirement_version_id'] === $r['id']));
            if ($r['coverage_evidence']) {
                $r['plan_url'] = '/admin/novels/'.$novel->id.'/plans?selectedId='.$p->id;
            }
            if ($r['decision_status'] === 'accepted' && ! $stale && $this->chain($p, $r['id'], $nodes)) {
                $r['coverage_status'] = 'covered';
            } elseif ($stale && $r['coverage_evidence']) {
                $r['coverage_status'] = 'stale';
            }
        } unset($r);

        return $requirements;
    }

    private function transform(User $actor, Novel $novel, string $command, array $data): array
    {
        Validator::make($data, ['chapter_id' => 'required|integer|min:1', 'scene_ids' => 'required|array|min:1', 'scene_ids.*' => 'required|integer|min:1|distinct'])->validate();
        $ch = Chapter::where('novel_id', $novel->id)->find($data['chapter_id']) ?? throw new NovelProblem('NOT_FOUND', '章节不存在。', 404);
        $this->assertEditable($novel, 'chapter', $ch->toArray(), []);
        $scenes = Scene::where('novel_id', $novel->id)->where('parent_id', $ch->id)->where('retired', false)->orderBy('sequence')->get();
        $ids = array_map('strval', $data['scene_ids']);
        if (count(array_intersect($ids, $scenes->pluck('id')->all())) !== count($ids)) {
            throw new NovelProblem('NOT_FOUND', '场景不属于当前章节。', 404);
        }
        if ($command === 'reorder') {
            if (count($ids) !== $scenes->count()) {
                throw new NovelProblem('VALIDATION_FAILED', '排序必须包含本章全部有效场景。', 422);
            }
            $order = $ids;
        } else {
            if (($command === 'split' && count($ids) !== 1) || ($command === 'merge' && count($ids) < 2)) {
                throw new NovelProblem('VALIDATION_FAILED', '拆分选择一个场景，合并选择至少两个相邻场景。', 422);
            }
            $positions = $scenes->values()->map(fn ($s, $i) => in_array($s->id, $ids, true) ? $i : null)->filter(fn ($v) => $v !== null)->values()->all();
            if (max($positions) - min($positions) + 1 !== count($positions)) {
                throw new NovelProblem('VALIDATION_FAILED', '仅能合并相邻场景。', 422);
            }
            Validator::make($data, ['replacements' => 'required|array|min:1|max:10', 'replacements.*.title' => 'required|string|max:255', 'replacements.*.objective' => 'required|string|max:20000', 'replacements.*.details' => 'required|array'])->validate();
            if (($command === 'split' && count($data['replacements']) < 2) || ($command === 'merge' && count($data['replacements']) !== 1)) {
                throw new NovelProblem('VALIDATION_FAILED', '拆分需提供至少两个替代场景；合并仅提供一个。', 422);
            }
            $selected = $scenes->whereIn('id', $ids);
            $budget = array_fill_keys(self::BUDGET_FIELDS, 0);
            foreach ($data['replacements'] as $r) {
                Validator::make($r['details'], array_fill_keys(self::BUDGET_FIELDS, 'required|integer|min:1|max:1000000'))->validate();
                foreach (self::BUDGET_FIELDS as $k) {
                    $budget[$k] += (int) $r['details'][$k];
                }
            }
            foreach (self::BUDGET_FIELDS as $k) {
                if ($budget[$k] !== $selected->sum(fn ($s) => (int) ($s->details[$k] ?? 0))) {
                    throw new NovelProblem('BUDGET_MISMATCH', '拆分或合并须保持原场景预算总和。', 422);
                }
            }
            // 使用新的稳定标识，旧场景退休，旧快照与覆盖证据不重绑。
            $offset = (int) Scene::where('parent_id', $ch->id)->max('sequence') + 100;
            foreach ($selected as $s) {
                $s->retired = true;
                $s->sequence = $offset++;
                $s->reason = $data['reason'];
                $s->row_version++;
                $s->save();
            }
            $new = [];
            foreach ($data['replacements'] as $r) {
                $result = app(StructureService::class)->execute($actor, $novel->id, 'node', ['type' => 'scene', 'parent_id' => $ch->id, 'source_version_id' => $ch->source_version_id, 'sequence' => $offset++, 'title' => $r['title'], 'objective' => $r['objective'], 'summary' => $r['summary'] ?? '', 'details' => $r['details'], 'status' => 'detailed', 'reason' => $data['reason'], 'expected_version' => $novel->fresh()->row_version], (string) Str::uuid());
                $new[] = $result['node']['id'];
            }
            // 子服务已递增乐观版本，外层需刷新后再提交，不能回写旧计数。
            $novel->refresh();
            $order = [];
            foreach ($scenes as $s) {
                if ($s->id === $scenes[$positions[0]]->id) {
                    array_push($order, ...$new);
                } if (! in_array($s->id, $ids, true)) {
                    $order[] = $s->id;
                }
            }
        }
        // 先移动至空闲序号区再排到 1..N，避免逐行交换触发唯一键冲突。
        $offset = (int) Scene::where('parent_id', $ch->id)->max('sequence') + 100;
        foreach ($order as $i => $id) {
            Scene::whereKey($id)->update(['sequence' => $offset + $i]);
        }
        foreach ($order as $i => $id) {
            Scene::whereKey($id)->update(['sequence' => $i + 1, 'reason' => $data['reason'], 'row_version' => DB::raw('row_version+1')]);
        }

        return ['scene_ids' => $order, 'generation_eligible' => false];
    }
}
