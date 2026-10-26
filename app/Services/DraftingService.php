<?php

namespace App\Services;

use App\Drafting\BodyContract;
use App\Exceptions\NovelProblem;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;

/** E02 仅候选正文：顺序场景、不可变编辑和精确组装，不创建正式剧情或评审。 */
final class DraftingService
{
    private function hash(mixed $v): string
    {
        return hash('sha256', NovelIntentService::canonical($v));
    }

    private function decode(object $row): array
    {
        $r = (array) $row;
        foreach ($r as $k => $v) {
            if ($v !== null && ($k === 'id' || str_ends_with($k, '_id'))) {
                $r[$k] = (string) $v;
            }
        }
        $r['created_at'] = CarbonImmutable::parse($row->created_at)->toISOString();
        $r['report'] = json_decode($row->report, true, flags: JSON_THROW_ON_ERROR);
        $r['sources'] = json_decode($row->sources, true, flags: JSON_THROW_ON_ERROR);
        if ($row->body_hash !== hash('sha256', $row->body) || $row->character_count !== BodyContract::count($row->body)) {
            throw new NovelProblem('BODY_HASH_MISMATCH', '正文哈希或字数与保存版本不符。');
        }

        return $r;
    }

    /** 所有写入先锁书，再重放原始响应；编辑竞争以节点版本判断。 */
    private function command(User $actor, string $novel, string $command, array $d, string $key, callable $fn): array
    {
        Validator::make(['key' => $key], ['key' => ['required', 'string', 'max:128', 'regex:/^[\x20-\x7E]+$/D']])->validate();

        return DB::transaction(function () use ($actor, $novel, $command, $d, $key, $fn) {
            $book = app(NovelIntentService::class)->novel($actor, $novel, true);
            $where = ['actor_id' => $actor->id, 'novel_scope' => $novel, 'command' => 'draft.'.$command, 'key' => $key];
            if ($old = DB::table('novel_commands')->where($where)->first()) {
                if ($old->request_hash !== $this->hash($d)) {
                    throw new NovelProblem('IDEMPOTENCY_CONFLICT', '操作键已对应不同正文操作。');
                }

                return json_decode(NovelIntentService::canonical(json_decode($old->response, true, flags: JSON_THROW_ON_ERROR)), true, flags: JSON_THROW_ON_ERROR);
            }
            if ($book->status === 'archived') {
                throw new NovelProblem('STALE_STATE', '归档小说只读。');
            }
            $result = json_decode(NovelIntentService::canonical($fn($book)), true, flags: JSON_THROW_ON_ERROR);
            DB::table('novel_commands')->insert($where + ['request_hash' => $this->hash($d), 'response' => NovelIntentService::canonical($result), 'created_at' => now()]);

            return $result;
        });
    }

    private function plan(User $actor, string $novel, string $plan): array
    {
        $p = app(RollingPlanService::class)->read($actor, $novel, $plan);
        if ($p['display_status'] !== 'frozen') {
            throw new NovelProblem('PLAN_NOT_FROZEN', '只能使用当前有效的作者冻结计划。');
        }

        return $p;
    }

    private function node(array $plan, string $kind, string $id): array
    {
        $node = collect($plan['nodes'])->first(fn ($n) => $n['node_type'] === $kind && (string) $n[$kind.'_id'] === $id);
        $chapter = $kind === 'chapter' ? $node : collect($plan['nodes'])->firstWhere('id', $node['parent_plan_node_id'] ?? null);
        if (! $node || ! $chapter || $chapter['sequence'] < $plan['start_chapter'] || $chapter['sequence'] > $plan['end_chapter']) {
            throw new NovelProblem('NOT_FOUND', '节点不在本书计划窗口。', 404);
        }

        return $node;
    }

    private function latest(string $novel, string $kind, string $node): ?array
    {
        $r = DB::table($kind.'_versions')->where('novel_id', $novel)->where('node_id', $node)->orderByDesc('version')->first();

        return $r ? $this->decode($r) : null;
    }

    private function scenes(array $plan, array $chapter): array
    {
        return collect($plan['nodes'])->where('node_type', 'scene')->where('parent_plan_node_id', $chapter['id'])->sortBy('sequence')->values()->all();
    }

    private function budget(array $node): array
    {
        return array_intersect_key($node['details']['details'], array_flip(['min_words', 'target_words', 'max_words']));
    }

    /** 一个场景一个持久运行；前场景不完整、过期或尚未生成时禁止跳序。 */
    public function start(User $actor, string $novel, array $d, string $key): array
    {
        return $this->command($actor, $novel, 'start', $d, $key, function ($book) use ($actor, $novel, $d, $key) {
            Validator::make($d, ['plan_id' => 'required|integer|min:1', 'scene_id' => 'required|integer|min:1', 'pov_id' => 'required|integer|min:1', 'route_version_id' => 'required|integer|min:1', 'billing' => 'required|array', 'offline_output' => 'required|array:body', 'offline_output.body' => 'required|string|max:100000', 'expected_version' => 'required|integer|min:1', 'expected_candidate_version' => 'required|integer|min:0', 'reason' => 'required|string|max:2000'])->validate();
            // 提前拒绝数据库无法保存的正文，避免完成事务才发现零字节。
            BodyContract::normalize($d['offline_output']['body']);
            $this->fields($d, ['plan_id', 'scene_id', 'pov_id', 'route_version_id', 'billing', 'offline_output', 'expected_version', 'expected_candidate_version', 'reason']);
            if ((int) $d['expected_version'] !== $book->row_version) {
                throw new NovelProblem('STALE_STATE', '小说输入已改变。');
            }
            $plan = $this->plan($actor, $novel, (string) $d['plan_id']);
            $scene = $this->node($plan, 'scene', (string) $d['scene_id']);
            $latest = $this->latest($novel, 'scene', (string) $d['scene_id']);
            if (($latest['version'] ?? 0) !== (int) $d['expected_candidate_version']) {
                throw new NovelProblem('STALE_CANDIDATE', '场景候选已被另一次保存改变。');
            }
            $chapter = collect($plan['nodes'])->firstWhere('id', $scene['parent_plan_node_id']);
            // 正式历史的重新生成须走重基线流程；当前只接入下一章连续衔接。
            if ($book->state_version > 0 && (int) DB::table('canonical_revisions')->where('novel_id', $novel)->where('id', $book->active_canonical_revision_id)->value('cursor') + 1 !== $chapter['sequence']) {
                throw new NovelProblem('HISTORY_CHANGE_REQUIRED', '只能从最新正式前缀生成下一章，历史重生成等待专门流程。');
            }
            $previous = null;
            foreach ($this->scenes($plan, $chapter) as $s) {
                if ((string) $s['scene_id'] === (string) $d['scene_id']) {
                    break;
                }
                $previous = $this->latest($novel, 'scene', (string) $s['scene_id']);
                if (! $previous || ! $previous['report']['complete'] || $this->stale($actor, $novel, 'scene', $previous)) {
                    throw new NovelProblem('PREVIOUS_SCENE_REQUIRED', '须先完成本章前面的场景，且衔接来源必须仍有效。');
                }
            }
            $config = app(AuthoringConfigurationService::class)->freeze($actor, ['route_version_id' => (string) $d['route_version_id'], 'variables' => ['context' => '按冻结计划生成完整场景正文，保留作者原始约束；仅离线回放。']], 'drafting.scene');
            if ($this->hash($config['prompt']['content']['output_schema']) !== $this->hash(BodyContract::schema()) || $config['route']['content']['mode'] !== 'offline_fake') {
                throw new NovelProblem('DRAFT_SCHEMA_MISMATCH', '正文路由须为离线模式并采用正文协议。');
            }
            $billingInput = $d['billing'];
            if (($billingInput['category'] ?? '') !== 'generation' || (string) ($billingInput['chapter_id'] ?? '') !== (string) $chapter['chapter_id']) {
                throw new NovelProblem('INVALID_BILLING_SCOPE', '正文预算必须归属当前章节及生成用途。', 422);
            }
            $billing = app(BudgetService::class)->freeze($actor, $novel, $billingInput, $config);
            // 预分配身份仅用于组装分支；所有持久行在同一事务内一次性写入冻结输入。
            $id = (string) DB::selectOne("SELECT nextval(pg_get_serial_sequence('generation_runs','id')) AS id")->id;
            $context = app(ContextAssemblyService::class)->prepare($actor, $book, (object) ['stage' => 'drafting.scene', 'dependency_hash' => null], (object) ['id' => $id, 'outline_version_id' => $plan['outline_version_id'], 'expected_state_version' => $book->state_version, 'status' => 'queued', 'frozen_config' => NovelIntentService::canonical(['configuration' => $config, 'billing' => $billing])], ['scope' => $book->state_version > 0 ? 'formal' : 'candidate', 'revision_id' => $book->active_canonical_revision_id, 'plan_id' => $plan['id'], 'scene_id' => $d['scene_id'], 'pov_id' => $d['pov_id'], 'configuration' => ['route_version_id' => $config['route']['id'], 'variables' => $config['variables']], 'billing' => $billingInput, 'safety_margin' => 256], '', $previous);
            if ($context['status'] !== 'assembled') {
                throw new NovelProblem('CONTEXT_NOT_READY', '上下文缺少必要来源或超过容量。');
            }
            $config['rendered_prompt'] = $context['final_prompt'];
            $config['token_measurement'] = 'utf8_bytes_upper_estimate_v1';
            $frozen = ['schema_version' => 'drafting-v1', 'outline_hash' => DB::table('outline_versions')->where('id', $plan['outline_version_id'])->value('content_hash'), 'plan_id' => (string) $plan['id'], 'scene_id' => (string) $d['scene_id'], 'chapter_id' => (string) $chapter['chapter_id'], 'expected_row_version' => $book->row_version, 'expected_candidate_version' => (int) $d['expected_candidate_version'], 'previous' => $previous ? ['id' => $previous['id'], 'body_hash' => $previous['body_hash']] : null, 'configuration' => $config, 'billing' => $billing, 'context' => $context, 'nodes' => [['key' => 'scene', 'stage' => 'drafting.scene', 'depends_on' => [], 'input' => ['offline_output' => $d['offline_output']]]]];
            DB::table('generation_runs')->insert(['id' => $id, 'novel_id' => $novel, 'actor_id' => $actor->id, 'outline_version_id' => $plan['outline_version_id'], 'configuration_route_version_id' => $config['route']['id'], 'expected_state_version' => $book->state_version, 'command_key' => 'draft:'.hash('sha256', $key), 'request_hash' => $this->hash($d), 'frozen_config' => NovelIntentService::canonical($frozen), 'input_hash' => $this->hash($frozen), 'created_at' => now(), 'updated_at' => now()]);
            app(OutboxService::class)->enqueue($actor, $novel, $id, 'scene');

            return ['run_id' => $id, 'status_url' => '/api/v1/novels/'.$novel.'/runs/'.$id, 'status' => 'queued'];
        });
    }

    /** 源身份变化只阻断后续写入，不改写已保存的历史候选和请求证据。 */
    public function verifyFrozen(object $run, array $frozen): void
    {
        $book = DB::table('novels')->find($run->novel_id);
        $actor = User::findOrFail($run->actor_id);
        $plan = $this->plan($actor, (string) $run->novel_id, $frozen['plan_id']);
        $latest = $this->latest((string) $run->novel_id, 'scene', $frozen['scene_id']);
        $own = $latest && (string) ($latest['run_id'] ?? '') === (string) $run->id;
        if ($this->hash($frozen) !== $run->input_hash || $book->row_version !== $frozen['expected_row_version'] || (string) $book->state_version !== (string) $run->expected_state_version || $book->status === 'archived' || (string) $plan['outline_version_id'] !== (string) $run->outline_version_id || (! $own && ($latest['version'] ?? 0) !== $frozen['expected_candidate_version'])) {
            throw new NovelProblem('DEPENDENCY_MISMATCH', '冻结正文输入、候选版本或正式状态已改变。');
        }
        if ($frozen['previous']) {
            $p = $this->read($actor, (string) $run->novel_id, 'scene', $frozen['previous']['id']);
            if ($p['stale'] || $p['body_hash'] !== $frozen['previous']['body_hash'] || $this->latest((string) $run->novel_id, 'scene', $p['node_id'])['id'] !== $p['id']) {
                throw new NovelProblem('DEPENDENCY_MISMATCH', '前场景衔接来源已改变。');
            }
        }
    }

    /** 编排完成事务的内部钩子；迟到、隔离或失败产物不会成为正文。 */
    public function generated(object $run, string $artifact, array $content): array
    {
        $frozen = json_decode($run->frozen_config, true, flags: JSON_THROW_ON_ERROR);
        $actor = User::findOrFail($run->actor_id);
        $plan = $this->plan($actor, (string) $run->novel_id, $frozen['plan_id']);
        $scene = $this->node($plan, 'scene', $frozen['scene_id']);

        return $this->persist($actor, (string) $run->novel_id, 'scene', $plan, $scene, $scene['details']['title'], $content['body'], '离线正文响应回放', $frozen['previous'] ? [$frozen['previous']] : [], $artifact, (string) $run->id);
    }

    private function persist(User $actor, string $novel, string $kind, array $plan, array $node, string $title, string $raw, string $reason, array $sources, ?string $artifact = null, ?string $run = null): array
    {
        $body = BodyContract::normalize($raw);
        $report = BodyContract::inspect($body, $title, $this->budget($node));
        $last = $this->latest($novel, $kind, (string) $node[$kind.'_id']);
        $id = DB::table($kind.'_versions')->insertGetId(['novel_id' => $novel, 'node_id' => $node[$kind.'_id'], 'plan_id' => $plan['id'], 'version' => ($last['version'] ?? 0) + 1, 'parent_version_id' => $last['id'] ?? null, 'title' => $title, 'body' => $body, 'raw_body' => $raw, 'body_hash' => hash('sha256', $body), 'character_count' => $report['character_count'], 'source_artifact_id' => $artifact, 'run_id' => $run, 'status' => $report['complete'] ? 'drafting' : 'revision_required', 'report' => NovelIntentService::canonical($report), 'sources' => NovelIntentService::canonical($sources), 'actor_id' => $actor->id, 'reason' => $reason]);

        return $this->read($actor, $novel, $kind, (string) $id);
    }

    public function save(User $actor, string $novel, string $kind, string $nodeId, array $d, string $key): array
    {
        $this->kind($kind);
        Validator::make(['id' => $nodeId], ['id' => 'required|integer|min:1|max:9223372036854775807'])->validate();

        return $this->command($actor, $novel, 'save.'.$kind.'.'.$nodeId, $d, $key, function () use ($actor, $novel, $kind, $nodeId, $d) {
            Validator::make($d, ['plan_id' => 'required|integer|min:1', 'source_version_id' => 'required|integer|min:1', 'expected_version' => 'required|integer|min:1', 'body' => 'required|string|max:100000', 'title' => 'required|string|max:255', 'reason' => 'required|string|max:2000'])->validate();
            $this->fields($d, ['plan_id', 'source_version_id', 'expected_version', 'body', 'title', 'reason']);
            $source = $this->read($actor, $novel, $kind, (string) $d['source_version_id']);
            $latest = $this->latest($novel, $kind, $nodeId);
            if ($source['node_id'] !== $nodeId || ! $latest || (int) $d['expected_version'] !== $latest['version'] || $source['id'] !== $latest['id']) {
                throw new NovelProblem('STALE_CANDIDATE', '候选已更新；请比较当前版后另存。');
            }
            $plan = $this->plan($actor, $novel, (string) $d['plan_id']);
            if ($source['plan_id'] !== (string) $plan['id']) {
                throw new NovelProblem('DEPENDENCY_MISMATCH', '编辑不能悄悄更换来源计划。');
            }
            if ($source['title'] === $d['title'] && $source['body'] === BodyContract::normalize($d['body'])) {
                return $source;
            }
            $result = $this->persist($actor, $novel, $kind, $plan, $this->node($plan, $kind, $nodeId), $d['title'], $d['body'], $d['reason'], $source['sources']);
            if ($kind === 'chapter') {
                foreach (DB::table('chapter_scene_versions')->where('chapter_version_id', $source['id'])->orderBy('position')->get() as $link) {
                    DB::table('chapter_scene_versions')->insert(['novel_id' => $novel, 'chapter_version_id' => $result['id'], 'scene_version_id' => $link->scene_version_id, 'position' => $link->position]);
                }
            }

            return $result;
        });
    }

    /** 只从完整有序列表重建正文，绝不向旧章字符串追加，因此重试不会重复拼接。 */
    public function assemble(User $actor, string $novel, string $chapterId, array $d, string $key): array
    {
        Validator::make(['id' => $chapterId], ['id' => 'required|integer|min:1|max:9223372036854775807'])->validate();

        return $this->command($actor, $novel, 'assemble.'.$chapterId, $d, $key, function () use ($actor, $novel, $chapterId, $d) {
            Validator::make($d, ['plan_id' => 'required|integer|min:1', 'scene_version_ids' => 'required|array|min:1|max:100', 'scene_version_ids.*' => 'required|integer|min:1|distinct', 'expected_version' => 'required|integer|min:0', 'title' => 'required|string|max:255', 'reason' => 'required|string|max:2000'])->validate();
            $plan = $this->plan($actor, $novel, (string) $d['plan_id']);
            $this->fields($d, ['plan_id', 'scene_version_ids', 'expected_version', 'title', 'reason']);
            $chapter = $this->node($plan, 'chapter', $chapterId);
            $scenes = $this->scenes($plan, $chapter);
            if (count($scenes) !== count($d['scene_version_ids'])) {
                throw new NovelProblem('INCOMPLETE_CHAPTER', '必须提供本章全部场景，不能遗漏或重复。');
            }
            $body = [];
            $sources = [];
            foreach ($scenes as $i => $scene) {
                $version = $this->read($actor, $novel, 'scene', (string) $d['scene_version_ids'][$i]);
                if ($version['node_id'] !== (string) $scene['scene_id'] || $version['plan_id'] !== (string) $plan['id'] || $version['stale'] || ! $version['report']['complete'] || $this->latest($novel, 'scene', $version['node_id'])['id'] !== $version['id']) {
                    throw new NovelProblem('INVALID_SCENE_VERSION', '场景版本须同计划、有效、完整且顺序正确。');
                }
                $body[] = $version['body'];
                $sources[] = ['id' => $version['id'], 'body_hash' => $version['body_hash']];
            }
            $latest = $this->latest($novel, 'chapter', $chapterId);
            if (($latest['version'] ?? 0) !== (int) $d['expected_version']) {
                throw new NovelProblem('STALE_CANDIDATE', '章节候选已更新。');
            }
            $joined = implode("\n\n", $body);
            if ($latest && $latest['body'] === $joined && $latest['title'] === $d['title'] && $latest['sources'] === $sources) {
                return $this->read($actor, $novel, 'chapter', $latest['id']);
            }
            $result = $this->persist($actor, $novel, 'chapter', $plan, $chapter, $d['title'], $joined, $d['reason'], $sources);
            foreach ($sources as $i => $source) {
                DB::table('chapter_scene_versions')->insert(['novel_id' => $novel, 'chapter_version_id' => $result['id'], 'scene_version_id' => $source['id'], 'position' => $i + 1]);
            }

            return $result;
        });
    }

    /** 未声明字段不能悄悄成为正式状态写入或配置覆盖入口。 */
    private function fields(array $d, array $allowed): void
    {
        if (array_diff(array_keys($d), $allowed)) {
            throw new NovelProblem('INVALID_DRAFT_INPUT', '正文操作含未声明字段。', 422);
        }
    }

    private function kind(string $kind): void
    {
        if (! in_array($kind, ['scene', 'chapter'], true)) {
            throw new NovelProblem('NOT_FOUND', '正文类型不存在。', 404);
        }
    }

    /** 当前资格派生展示；历史报告及正文始终保持原值。 */
    private function stale(User $actor, string $novel, string $kind, array $v): bool
    {
        $p = app(RollingPlanService::class)->read($actor, $novel, $v['plan_id']);
        if ($p['display_status'] !== 'frozen') {
            return true;
        }
        foreach ($v['sources'] as $source) {
            $row = DB::table('scene_versions')->where('novel_id', $novel)->where('id', $source['id'])->first();
            if (! $row || $source['body_hash'] !== $row->body_hash || $this->latest($novel, 'scene', (string) $row->node_id)['id'] !== (string) $row->id || $this->stale($actor, $novel, 'scene', $this->decode($row))) {
                return true;
            }
        }

        return false;
    }

    public function read(User $actor, string $novel, string $kind, string $id): array
    {
        $this->kind($kind);
        app(NovelIntentService::class)->novel($actor, $novel);
        Validator::make(['id' => $id], ['id' => 'required|integer|min:1|max:9223372036854775807'])->validate();
        $row = DB::table($kind.'_versions')->where('novel_id', $novel)->find($id) ?? throw new NovelProblem('NOT_FOUND', '正文版本不存在。', 404);
        $v = $this->decode($row);
        $v['stale'] = $this->stale($actor, $novel, $kind, $v);

        $v['history'] = DB::table($kind.'_versions')->where('novel_id', $novel)->where('node_id', $v['node_id'])->orderByDesc('version')->limit(50)->get(['id', 'version', 'title', 'body_hash'])->map(fn ($r) => ['id' => (string) $r->id, 'version' => $r->version, 'title' => $r->title, 'body_hash' => $r->body_hash])->all();

        // E06正式指针由映射判定，候选版本和旧评审不会自动升级成正式稿。
        $formal = $kind === 'chapter' ? DB::table('canonical_chapters as m')->join('novels as n', 'n.active_canonical_revision_id', '=', 'm.revision_id')->where('m.novel_id', $novel)->where('m.chapter_version_id', $id)->first(['m.commit_id', 'm.revision_id']) : null;

        return $v + ['display_status' => $formal ? 'committed' : ($v['stale'] ? 'stale' : $v['status']), 'formal' => $formal ? ['commit_id' => (string) $formal->commit_id, 'revision_id' => (string) $formal->revision_id] : null, 'review_status' => 'not_implemented', 'accepted' => false];
    }

    /** 正式版本仅按活动基线映射读取；展示不回退候选，也不重算当前规划资格。 */
    public function formal(User $actor, string $novel, string $chapter): ?array
    {
        $book = app(NovelIntentService::class)->novel($actor, $novel);
        Validator::make(['chapter' => $chapter], ['chapter' => 'required|integer|min:1|max:9223372036854775807'])->validate();
        if (! DB::table('chapters')->where('novel_id', $novel)->where('id', $chapter)->exists()) {
            throw new NovelProblem('NOT_FOUND', '章节不属于当前小说。', 404);
        }
        $formal = $this->formalVersions($novel, $book->active_canonical_revision_id, $chapter)[$chapter] ?? null;
        if ($formal === null) {
            return null;
        }
        $body = DB::table('chapter_versions')->where('novel_id', $novel)->where('node_id', $chapter)->find($formal['id']);
        if (! $body) {
            throw new NovelProblem('NOT_FOUND', '正式映射的正文不存在。', 404);
        }

        return $this->decode($body) + $formal + ['canonical' => true, 'display_status' => 'committed'];
    }

    /** 一次读取正式列表元数据，不把整本正式正文塞入列表响应。 */
    private function formalVersions(string $novel, ?int $revision, ?string $chapter = null): array
    {
        if ($revision === null) {
            return [];
        }

        return DB::table('canonical_chapters as m')->join('canonical_commits as c', 'c.id', '=', 'm.commit_id')
            ->join('chapter_versions as v', 'v.id', '=', 'm.chapter_version_id')
            ->where('m.novel_id', $novel)->where('m.revision_id', $revision)->where('c.status', 'committed')
            ->when($chapter !== null, fn ($q) => $q->where('m.chapter_id', $chapter))
            ->get(['m.chapter_id', 'm.chapter_version_id as id', 'm.commit_id', 'm.revision_id', 'v.version', 'v.title', 'v.body_hash', 'v.character_count', 'c.created_at as committed_at'])
            ->mapWithKeys(fn ($v) => [(string) $v->chapter_id => ['id' => (string) $v->id, 'commit_id' => (string) $v->commit_id, 'revision_id' => (string) $v->revision_id, 'version' => $v->version, 'title' => $v->title, 'body_hash' => $v->body_hash, 'character_count' => $v->character_count, 'committed_at' => CarbonImmutable::parse($v->committed_at)->toISOString()]])->all();
    }

    /** 已存评审只是历史记录；本列表不以旧PASS推断当前可提交资格。 */
    private function recordedReview(string $novel, string $version): ?array
    {
        $review = DB::table('reviews')->where('novel_id', $novel)->where('chapter_version_id', $version)->orderByDesc('id')->first();
        if (! $review) {
            return null;
        }

        return ['id' => (string) $review->id, 'verdict' => json_decode($review->report, true, flags: JSON_THROW_ON_ERROR)['verdict'], 'created_at' => CarbonImmutable::parse($review->created_at)->toISOString(), 'eligibility' => 'not_checked'];
    }

    public function listing(User $actor, string $novel, array $filters = []): array
    {
        $book = app(NovelIntentService::class)->novel($actor, $novel);
        Validator::make($filters, ['chapter_id' => 'nullable|integer|min:1', 'status' => 'nullable|in:planned,drafting,revision_required,stale,committed', 'missing' => 'nullable|boolean', 'word_issue' => 'nullable|boolean'])->validate();
        $formalVersions = $this->formalVersions($novel, $book->active_canonical_revision_id);
        $items = [];
        foreach (DB::table('chapters')->where('novel_id', $novel)->when($filters['chapter_id'] ?? null, fn ($q, $v) => $q->where('id', $v))->orderBy('sequence')->get() as $ch) {
            $latest = $this->latest($novel, 'chapter', (string) $ch->id);
            $candidate = $latest ? $this->read($actor, $novel, 'chapter', $latest['id']) : null;
            $formal = $formalVersions[$ch->id] ?? null;
            $candidateStatus = $candidate['display_status'] ?? 'planned';
            $status = $formal ? 'committed' : $candidateStatus;
            $filterStatus = $filters['status'] ?? null;
            if (($filterStatus !== null && ($filterStatus === 'committed' ? $formal === null : $filterStatus !== $candidateStatus)) || (! empty($filters['missing']) && $candidate) || (! empty($filters['word_issue']) && ! array_intersect($candidate['report']['issues'] ?? [], ['BELOW_MINIMUM', 'ABOVE_MAXIMUM']))) {
                continue;
            }
            if ($candidate !== null) {
                // 仅丰富列表副本，不改变E03–E05冻结的正文读取对象或资格哈希。
                $candidate['recorded_review'] = $this->recordedReview($novel, $candidate['id']);
                $candidate['review_status'] = $candidate['recorded_review'] ? 'recorded_'.$candidate['recorded_review']['verdict'] : 'not_reviewed';
            }
            $scenes = [];
            foreach (DB::table('scenes')->where('novel_id', $novel)->where('parent_id', $ch->id)->where('retired', false)->orderBy('sequence')->get() as $sc) {
                $latestScene = $this->latest($novel, 'scene', (string) $sc->id);
                $scenes[] = ['id' => (string) $sc->id, 'title' => $sc->title, 'candidate' => $latestScene ? $this->read($actor, $novel, 'scene', $latestScene['id']) : null];
            }
            $items[] = ['id' => (string) $ch->id, 'parent_id' => (string) $ch->parent_id, 'sequence' => $ch->sequence, 'title' => $ch->title, 'candidate' => $candidate, 'formal' => $formal, 'status' => $status, 'candidate_status' => $candidateStatus, 'scenes' => $scenes];
        }

        return ['items' => $items, 'formal_status' => $formalVersions ? 'available' : 'empty', 'revision_id' => $book->active_canonical_revision_id === null ? null : (string) $book->active_canonical_revision_id];
    }
}
