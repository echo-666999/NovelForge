<?php

namespace App\Services;

use App\Drafting\BodyContract;
use App\Exceptions\NovelProblem;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/** E06唯一正式写入口；预检不授予豁免，提交事务内不调用模型或队列。 */
final class CanonicalCommitService
{
    private function hash(mixed $v): string
    {
        return hash('sha256', NovelIntentService::canonical($v));
    }

    private function key(string $key): void
    {
        Validator::make(['key' => $key], ['key' => ['required', 'string', 'max:128', 'regex:/^[\x20-\x7E]+$/D']])->validate();
    }

    private function audit(string $reason): void
    {
        DB::select("SELECT set_config('novelforge.reason', ?, true),set_config('novelforge.trace_id',coalesce(nullif(current_setting('novelforge.trace_id',true),''),?),true)", [$reason, (string) Str::uuid()]);
    }

    /** 空根基线没有事实，因此初始化不推进剧情版本或使既有评审失效。 */
    public function initialize(User $actor, string $novel, array $d, string $key): array
    {
        $this->key($key);
        Validator::make(['d' => $d], ['d' => 'required|array:expected_state_version,reason'])->validate();
        Validator::make($d, ['expected_state_version' => 'required|integer|min:0', 'reason' => 'required|string|max:2000'])->validate();

        return DB::transaction(function () use ($actor, $novel, $d, $key) {
            $b = app(NovelIntentService::class)->novel($actor, $novel, true);
            $where = ['actor_id' => $actor->id, 'novel_scope' => $novel, 'command' => 'canonical.initialize', 'key' => $key];
            if ($old = DB::table('novel_commands')->where($where)->first()) {
                if ($old->request_hash !== $this->hash($d)) {
                    throw new NovelProblem('IDEMPOTENCY_CONFLICT', '初始化键已绑定其他输入。');
                }

                return json_decode($old->response, true);
            }
            if ($b->status === 'archived' || $b->state_version !== (int) $d['expected_state_version']) {
                throw new NovelProblem('STALE_STATE', '小说正式状态已变化。');
            }
            if (! $b->active_canonical_revision_id) {
                if ($b->state_version !== 0) {
                    throw new NovelProblem('CANONICAL_CONFLICT', '非空状态不能初始化空根基线。');
                }
                $this->audit($d['reason']);
                $id = DB::table('canonical_revisions')->insertGetId(['novel_id' => $novel, 'version' => 1, 'status' => 'active', 'actor_id' => $actor->id, 'reason' => $d['reason']]);
                $b->active_canonical_revision_id = $id;
                $b->save();
            }
            $r = ['revision_id' => (string) $b->active_canonical_revision_id, 'state_version' => $b->state_version];
            DB::table('novel_commands')->insert($where + ['request_hash' => $this->hash($d), 'response' => NovelIntentService::canonical($r), 'created_at' => now()]);

            return $r;
        });
    }

    private function input(array $d, bool $commit): void
    {
        $fields = ['novel_id', 'chapter_id', 'chapter_version_id', 'review_id', 'expected_revision_id', 'expected_state_version', 'plan_node_version_id', 'event_artifact_id', 'reason'];
        if ($commit) {
            $fields[] = 'validation_artifact_id';
        }
        Validator::make(['d' => $d], ['d' => 'required|array:'.implode(',', $fields)])->validate();
        $rules = ['expected_state_version' => 'required|integer|min:0', 'reason' => 'required|string|max:2000'];
        foreach (array_diff($fields, ['expected_state_version', 'reason']) as $field) {
            $rules[$field] = 'required|string|regex:/^[1-9][0-9]{0,18}$/D';
        }
        Validator::make($d, $rules)->validate();
        foreach (array_diff($fields, ['expected_state_version', 'reason']) as $field) {
            if (strlen($d[$field]) === 19 && strcmp($d[$field], '9223372036854775807') > 0) {
                throw ValidationException::withMessages([$field => '标识超出数据库整数范围。']);
            }
        }
    }

    /** 预检既保留通过证据，也保留明确阻塞；不能把预检当正式提交。 */
    public function preflight(User $actor, string $novel, array $d, string $key): array
    {
        $this->key($key);
        $this->input($d, false);

        return DB::transaction(function () use ($actor, $novel, $d, $key) {
            $book = app(NovelIntentService::class)->novel($actor, $novel, true);
            if ($d['novel_id'] !== $novel || ! DB::table('chapters')->where('novel_id', $novel)->where('id', $d['chapter_id'])->exists()) {
                throw new NovelProblem('NOT_FOUND', '目标章节不属于当前小说。', 404);
            }
            if ($old = DB::table('canonical_preflights')->where('novel_id', $novel)->where('command_key', $key)->first()) {
                if ($old->request_hash !== $this->hash($d)) {
                    throw new NovelProblem('IDEMPOTENCY_CONFLICT', '预检键已绑定其他请求。');
                }

                return $this->decodePreflight($old);
            }
            $this->audit($d['reason']);
            try {
                $g = $this->gates($actor, $novel, $book, $d);
                $report = ['status' => 'ready', 'dependency_hash' => $g['dependency_hash'], 'chapter_sequence' => $g['chapter']->sequence, 'body_hash' => $g['body']['body_hash'], 'event_count' => count($g['local']), 'candidate_state' => $g['replay']['state'], 'message' => '预检通过；提交时仍会在锁内重验。'];
            } catch (NovelProblem $e) {
                $report = ['status' => 'blocked', 'code' => $e->errorCode, 'message' => $e->getMessage()];
            }
            $id = DB::table('canonical_preflights')->insertGetId(['novel_id' => $novel, 'chapter_id' => $d['chapter_id'], 'command_key' => $key, 'request' => NovelIntentService::canonical($d), 'request_hash' => $this->hash($d), 'report' => NovelIntentService::canonical($report), 'content_hash' => $this->hash($report), 'actor_id' => $actor->id]);

            return $this->decodePreflight(DB::table('canonical_preflights')->find($id));
        });
    }

    private function decodePreflight(object $row): array
    {
        $r = json_decode($row->report, true);
        if ($this->hash($r) !== $row->content_hash || $this->hash(json_decode($row->request, true)) !== $row->request_hash) {
            throw new NovelProblem('INVALID_PREFLIGHT_HASH', '预检哈希失配。');
        }

        return ['id' => (string) $row->id, 'request' => json_decode($row->request, true), 'content_hash' => $row->content_hash, 'created_at' => $row->created_at] + $r;
    }

    /** 只规范化重新验证产生的事件标识，不重排列表或改动实体标识。 */
    private function comparableContext(array $payload, array $remap): array
    {
        foreach (['entries' => ['event_id'], 'dependencies' => ['predecessor_id', 'successor_id'], 'knowledge' => ['fact_event_id', 'acquired_event_id'], 'relationships' => ['event_id']] as $field => $keys) {
            foreach ($payload[$field] as &$row) {
                foreach ($keys as $key) {
                    $row[$key] = $remap[$row[$key]] ?? $row[$key];
                }
            }
            unset($row);
        }

        return $payload;
    }

    /** 原提取与重验必须有相同事实值、引文和上下文，不能仅依赖两个PASS标志。 */
    private function assertRevalidation(User $actor, string $novel, array $original, array $context, array $fresh): void
    {
        $oldKeys = DB::table('extraction_events')->where('attempt_id', $original['id'])->pluck('event_id', 'event_key')->map(fn ($id) => (string) $id)->all();
        $newKeys = DB::table('extraction_events')->where('attempt_id', $fresh['id'])->pluck('event_id', 'event_key')->map(fn ($id) => (string) $id)->all();
        if (count($oldKeys) !== count($newKeys) || array_diff_key($oldKeys, $newKeys) || array_diff_key($newKeys, $oldKeys)) {
            throw new NovelProblem('EXTRACTION_CONTENT_MISMATCH', '重验事件键与原提取不同。');
        }
        $remap = [];
        foreach ($newKeys as $key => $id) {
            $remap[$id] = $oldKeys[$key];
        }
        $newContext = app(StoryConsistencyService::class)->read($actor, $novel, $fresh['context_id']);
        if ($this->hash($this->comparableContext($newContext['payload'], $remap)) !== $this->hash($context['payload'])) {
            throw new NovelProblem('EXTRACTION_CONTENT_MISMATCH', '正式前缀、时间、知识、关系或作者规则与原提取不同。');
        }
        $before = array_column($context['events'], null, 'id');
        $after = array_column($newContext['events'], null, 'id');
        foreach ($remap as $new => $old) {
            $left = $before[$old];
            $right = $after[$new];
            foreach (['id', 'created_at', 'content_hash', 'evidence_id', 'entry'] as $field) {
                unset($left[$field], $right[$field]);
            }
            foreach (['id', 'created_at'] as $field) {
                unset($left['evidence'][$field], $right['evidence'][$field]);
            }
            if ($this->hash($left) !== $this->hash($right)) {
                throw new NovelProblem('EXTRACTION_CONTENT_MISMATCH', '重验的事件值或原文证据与原提取不同。');
            }
        }
    }

    private function gates(User $actor, string $novel, object $book, array $d): array
    {
        if ($d['novel_id'] !== $novel) {
            throw new NovelProblem('NOT_FOUND', '小说归属不匹配。', 404);
        }
        if ($book->status === 'archived' || $book->state_version !== (int) $d['expected_state_version'] || (string) $book->active_canonical_revision_id !== $d['expected_revision_id']) {
            throw new NovelProblem('STALE_STATE', '正式基线或状态版本已变化，请重建上下文并复评。');
        }
        $revision = DB::table('canonical_revisions')->where('novel_id', $novel)->where('id', $d['expected_revision_id'])->lockForUpdate()->first();
        $chapter = DB::table('chapters')->where('novel_id', $novel)->where('id', $d['chapter_id'])->lockForUpdate()->first();
        if (! $revision || ! $chapter) {
            throw new NovelProblem('NOT_FOUND', '章节或正式基线不存在。', 404);
        }
        if ($revision->local_state_version !== $book->state_version) {
            throw new NovelProblem('STALE_STATE', '正式游标与全局状态不一致。');
        }
        if ($chapter->sequence !== $revision->cursor + 1) {
            throw new NovelProblem('CANONICAL_CONFLICT', '只能顺序提交下一章；正式历史修订须走后续重基线流程。');
        }
        $body = app(DraftingService::class)->read($actor, $novel, 'chapter', $d['chapter_version_id']);
        if ($body['node_id'] !== $d['chapter_id']) {
            throw new NovelProblem('NOT_FOUND', '正文不属于目标章。', 404);
        }
        // 对直接命中的未批准实体给出码点位置，人工高分不能隐藏该错误。
        foreach (DB::table('entities')->where('novel_id', $novel)->get() as $entity) {
            $at = mb_strpos($body['body'], $entity->name);
            if ($at !== false && ($entity->retired || ! $entity->approved_version_id)) {
                throw new NovelProblem('UNAPPROVED_ENTITY', '未批准或退役实体「'.$entity->name.'」，正文码点'.$at.'–'.($at + mb_strlen($entity->name)).'。', 422);
            }
        }
        if ((string) DB::table('chapter_versions')->where('novel_id', $novel)->where('node_id', $chapter->id)->orderByDesc('version')->value('id') !== $body['id'] || $body['stale']) {
            throw new NovelProblem('REVIEW_STALE', '正文或场景已经修改，旧评审不得提交。');
        }
        $plan = app(RollingPlanService::class)->read($actor, $novel, $body['plan_id']);
        $node = DB::table('plan_nodes')->where('id', $d['plan_node_version_id'])->where('outline_version_id', $plan['outline_version_id'])->where('chapter_id', $chapter->id)->where('node_type', 'chapter')->first();
        if (! $node || $plan['display_status'] !== 'frozen' || $node->sequence !== $chapter->sequence || $chapter->sequence < $plan['start_chapter'] || $chapter->sequence > $plan['end_chapter']) {
            throw new NovelProblem('PLAN_NOT_FROZEN', '章节不属于当前有效的冻结场景计划。');
        }
        $details = json_decode($node->details, true)['details'];
        if (! BodyContract::inspect($body['body'], $body['title'], $details)['complete'] || ! $body['report']['complete']) {
            throw new NovelProblem('INCOMPLETE_BODY', '章节正文或字数不完整。');
        }
        $expected = DB::table('plan_nodes')->where('parent_plan_node_id', $node->id)->where('node_type', 'scene')->orderBy('sequence')->pluck('scene_id')->map(fn ($id) => (string) $id)->all();
        $actual = DB::table('chapter_scene_versions as l')->join('scene_versions as v', 'v.id', '=', 'l.scene_version_id')->where('l.chapter_version_id', $body['id'])->orderBy('l.position')->pluck('v.node_id')->map(fn ($id) => (string) $id)->all();
        if (! $expected || $actual !== $expected || count($body['sources']) !== count($expected)) {
            throw new NovelProblem('INCOMPLETE_SCENES', '正式章必须包含冻结计划中的全部场景。');
        }
        $reviewSource = DB::table('reviews')->where('novel_id', $novel)->where('id', $d['review_id'])->first();
        if (! $reviewSource) {
            throw new NovelProblem('NOT_FOUND', '评审不属于当前小说。', 404);
        }
        if ((string) $reviewSource->chapter_version_id !== $body['id'] || $reviewSource->body_hash !== $body['body_hash']) {
            throw new NovelProblem('REVIEW_STALE', '旧评审不能绑定修改后的正文版本。');
        }
        $extraction = app(ExtractionService::class)->read($actor, $novel, $d['event_artifact_id']);
        if ($extraction['chapter_version_id'] !== $body['id'] || ! $extraction['current_eligible']) {
            throw new NovelProblem('EXTRACTION_NOT_ELIGIBLE', '提取不属于当前正文或完整硬门禁已失效。');
        }
        // 正文源运行必须仍然匹配本次正式前序状态，不能在新游标上重用旧生成上下文。
        foreach ($body['sources'] as $source) {
            $scene = DB::table('scene_versions')->where('novel_id', $novel)->where('id', $source['id'])->first();
            while ($scene && ! $scene->run_id && $scene->parent_version_id) {
                $scene = DB::table('scene_versions')->where('novel_id', $novel)->where('id', $scene->parent_version_id)->first();
            }
            $run = $scene && $scene->run_id ? DB::table('generation_runs')->where('novel_id', $novel)->where('id', $scene->run_id)->first() : null;
            $frozen = $run ? json_decode($run->frozen_config, true) : null;
            if (! $run || $run->status !== 'succeeded' || (int) $run->expected_state_version !== $book->state_version || $this->hash($frozen) !== $run->input_hash || ($frozen['context']['final_prompt_hash'] ?? null) !== hash('sha256', $frozen['context']['final_prompt'] ?? '') || ($frozen['configuration']['rendered_prompt'] ?? null) !== ($frozen['context']['final_prompt'] ?? null)) {
                throw new NovelProblem('DEPENDENCY_MISMATCH', '正文生成上下文、来源运行或前序状态已失效，需重建上下文和复评。');
            }
        }
        $previous = DB::table('canonical_chapters as m')->join('canonical_commits as c', 'c.id', '=', 'm.commit_id')->where('m.revision_id', $revision->id)->where('m.sequence', $chapter->sequence - 1)->first(['c.id', 'c.event_artifact_id', 'c.new_state_version', 'c.response_hash']);
        if (($chapter->sequence === 1 && $extraction['baseline_id'] !== null) || ($chapter->sequence > 1 && (! $previous || $previous->new_state_version !== $book->state_version || (string) $previous->event_artifact_id !== $extraction['baseline_id']))) {
            throw new NovelProblem('INVALID_CANONICAL_PREDECESSOR', '候选提取必须绑定紧邻前章的真实正式提交。');
        }
        $context = $extraction['context_id'] ? app(StoryConsistencyService::class)->read($actor, $novel, $extraction['context_id']) : null;
        if (! $context || ! app(StoryConsistencyValidator::class)->evaluate($context['payload'], array_column($context['events'], null, 'id'))['eligible']) {
            throw new NovelProblem('INVALID_STORY_STATE', '时间、知识或世界规则重验失败。');
        }
        $local = DB::table('extraction_events')->where('attempt_id', $extraction['id'])->pluck('event_id')->map(fn ($id) => (string) $id)->all();
        $prefix = DB::table('story_events as e')->join('canonical_commits as c', 'c.id', '=', 'e.commit_id')->where('e.revision_id', $revision->id)->orderBy('c.chapter_sequence')->orderBy('e.narrative_order')->pluck('e.proposed_event_id')->map(fn ($id) => (string) $id)->all();
        $ids = array_column($context['events'], 'id');
        $expectedIds = [...$prefix, ...$local];
        $sorted = $ids;
        $sortedExpected = $expectedIds;
        sort($sorted);
        sort($sortedExpected);
        if ($sorted !== $sortedExpected || count($local) !== count($extraction['output']['events'])) {
            throw new NovelProblem('CANONICAL_STREAM_MISMATCH', '事件流必须恰好由正式前缀和本章精确事件组成。');
        }
        app(StoryStateService::class)->assertSource($actor, $novel, $local, $extraction['source_id'], $body['body_hash']);
        $replay = app(StoryStateService::class)->replay($actor, $novel, $ids, $chapter->sequence);
        if ($replay['pending_event_ids']) {
            throw new NovelProblem('UNCONFIRMED_EVENT', '待确认事件不能成为正式事实。');
        }
        foreach ($context['events'] as $event) {
            app(WorldService::class)->assertReference($actor, $novel, $event['definition_version_id']);
        }
        // 用同一E03原始响应重跑全部硬门禁；保存点回滚所有临时提取、事件与审计。
        DB::beginTransaction();
        try {
            $fresh = app(ExtractionService::class)->extract($actor, $novel, ['chapter_version_id' => $body['id'], 'body_hash' => $body['body_hash'], 'expected_version' => $book->row_version, 'expected_state_version' => $book->state_version, 'baseline_id' => $extraction['baseline_id'], 'rule_context_id' => $extraction['frozen_input']['rule_context_id'], 'offline_output' => $extraction['output'], 'reason' => '正式提交事务内重验'], 'commit-check:'.Str::uuid());
            if (! $fresh['report']['eligible']) {
                throw new NovelProblem('HARD_VALIDATION_FAILED', '提交重验未通过：'.NovelIntentService::canonical($fresh['report']['issues']));
            }
            $this->assertRevalidation($actor, $novel, $extraction, $context, $fresh);
        } finally {
            DB::rollBack();
        }
        $review = app(ChapterReviewService::class)->read($actor, $novel, $d['review_id']);
        if ($review['chapter_version_id'] !== $body['id'] || $review['extraction_id'] !== $extraction['id'] || $review['body_hash'] !== $body['body_hash'] || $review['stale']) {
            throw new NovelProblem('REVIEW_STALE', '评审与当前正文、提取或上下文不一致。');
        }
        if (! $review['current_eligible'] || ! $review['report']['hard_pass'] || ! $review['report']['literary_pass'] || $review['report']['unresolved_blocking'] !== 0 || collect($review['issues'])->contains(fn ($i) => $i['severity'] === 'blocking' || $i['category'] === 'structure')) {
            throw new NovelProblem('REVIEW_BLOCKED', '评审未通过；人工意见不能绕过结构门禁。');
        }
        $changes = $this->foreshadowChanges($actor, $novel, $revision, $chapter, $plan, $extraction);
        $dependency_hash = $this->hash(['book_row' => $book->row_version, 'state' => $book->state_version, 'revision' => (string) $revision->id, 'cursor' => $revision->cursor, 'previous' => $previous ? (array) $previous : null, 'body' => $body['body_hash'], 'review' => $review['input_hash'], 'review_output' => $review['output_hash'], 'plan' => $plan['content_hash'], 'extraction' => [$extraction['input_hash'], $extraction['output_hash'], $extraction['report']], 'stream' => $replay['stream_hash'], 'changes' => $changes]);

        return compact('revision', 'chapter', 'body', 'plan', 'node', 'extraction', 'context', 'local', 'prefix', 'replay', 'review', 'changes', 'dependency_hash');
    }

    private function foreshadowChanges(User $actor, string $novel, object $revision, object $chapter, array $plan, array $extraction): array
    {
        $history = DB::table('foreshadow_occurrences as o')->join('foreshadow_plans as p', 'p.id', '=', 'o.plan_id')->where('o.revision_id', $revision->id)->get(['o.kind', 'o.plan_id', 'p.lineage_id']);
        $changes = DB::table('extraction_foreshadows')->where('attempt_id', $extraction['id'])->orderBy('id')->get()->map(fn ($c) => ['plan_id' => (string) $c->plan_id, 'event_id' => (string) $c->event_id, 'kind' => $c->kind])->all();
        $plans = app(ForeshadowLedgerService::class)->ledger($actor, $novel, ['chapter' => $chapter->sequence, 'blueprint_version_id' => $plan['blueprint_version_id']])['items'];
        foreach ($changes as $change) {
            $p = collect($plans)->firstWhere('id', $change['plan_id']);
            if (! $p || ! $p['frozen'] || $p['lifecycle'] !== 'planned') {
                throw new NovelProblem('INVALID_FORESHADOW_SOURCE', '正式伏笔必须来自当前冻结计划。');
            }
            $prior = $history->where('lineage_id', (int) $p['lineage_id']);
            if ($prior->contains('kind', 'payoff') || ($change['kind'] === 'plant' && $prior->contains('kind', 'plant')) || ($change['kind'] !== 'plant' && ! $prior->contains('kind', 'plant'))) {
                throw new NovelProblem('INVALID_FORESHADOW_TRANSITION', '正式伏笔转换缺少真实埋设或已完成回收。');
            }
            foreach ($p['payload']['prerequisite_event_ids'] as $pre) {
                if (! in_array($pre, $extraction['candidate_event_ids'], true) || ! DB::table('story_events')->where('revision_id', $revision->id)->where('proposed_event_id', $pre)->exists()) {
                    throw new NovelProblem('FORESHADOW_PREREQUISITE_MISSING', '伏笔前置事件尚未正式成立。');
                }
            }
        }
        foreach ($plans as $p) {
            if (! $p['required'] || ! $p['frozen'] || $p['lifecycle'] !== 'planned') {
                continue;
            }
            $prior = $history->where('lineage_id', (int) $p['lineage_id']);
            $local = array_column(array_values(array_filter($changes, fn ($c) => $c['plan_id'] === $p['id'])), 'kind');
            if (($chapter->sequence >= $p['plant_end'] && ! $prior->contains('kind', 'plant') && ! in_array('plant', $local, true)) || ($chapter->sequence >= $p['payoff_end'] && ! $prior->contains('kind', 'payoff') && ! in_array('payoff', $local, true))) {
                throw new NovelProblem('REQUIRED_FORESHADOW_MISSING', '到期强制伏笔「'.$p['name'].'」缺少正式正文证据。');
            }
        }

        return $changes;
    }

    public function commit(User $actor, string $novel, array $d, string $key): array
    {
        $this->key($key);
        $this->input($d, true);

        return DB::transaction(function () use ($actor, $novel, $d, $key) {
            $book = app(NovelIntentService::class)->novel($actor, $novel, true);
            if ($old = DB::table('canonical_commits')->where('novel_id', $novel)->where('idempotency_key', $key)->first()) {
                if ($old->request_hash !== $this->hash($d)) {
                    throw new NovelProblem('IDEMPOTENCY_CONFLICT', '正式提交键已用于不同载荷。');
                }
                if ($old->status !== 'committed') {
                    throw new NovelProblem('CANONICAL_CONFLICT', '提交尚未完整落盘。');
                }
                $response = json_decode($old->response, true);
                if ($this->hash($response) !== $old->response_hash) {
                    throw new NovelProblem('INVALID_COMMIT_HASH', '提交回执哈希失配。');
                }

                return $response + ['replayed' => true];
            }
            $p = DB::table('canonical_preflights')->where('novel_id', $novel)->where('chapter_id', $d['chapter_id'])->where('id', $d['validation_artifact_id'])->first();
            if (! $p) {
                throw new NovelProblem('NOT_FOUND', '同书同章预检不存在。', 404);
            }
            $proof = $this->decodePreflight($p);
            $request = array_diff_key($d, ['validation_artifact_id' => true]);
            if ($p->request_hash !== $this->hash($request) || $proof['status'] !== 'ready') {
                throw new NovelProblem('INVALID_PREFLIGHT', '预检未通过或请求与预检不同。');
            }
            $g = $this->gates($actor, $novel, $book, $d);
            if ($g['dependency_hash'] !== $proof['dependency_hash']) {
                throw new NovelProblem('STALE_STATE', '预检依赖已变化，请重新预检。');
            }
            $this->audit($d['reason']);
            $id = (string) DB::table('canonical_commits')->insertGetId(['novel_id' => $novel, 'revision_id' => $d['expected_revision_id'], 'chapter_id' => $d['chapter_id'], 'chapter_version_id' => $d['chapter_version_id'], 'chapter_sequence' => $g['chapter']->sequence, 'review_id' => $d['review_id'], 'event_artifact_id' => $d['event_artifact_id'], 'validation_artifact_id' => $d['validation_artifact_id'], 'plan_node_version_id' => $d['plan_node_version_id'], 'previous_state_version' => $book->state_version, 'new_state_version' => $book->state_version + 1, 'idempotency_key' => $key, 'request' => NovelIntentService::canonical($d), 'request_hash' => $this->hash($d), 'status' => 'building', 'actor_id' => $actor->id, 'reason' => $d['reason']]);
            DB::select("SELECT set_config('novelforge.canonical_commit', ?, true)", [$id]);
            $revision = $d['expected_revision_id'];
            $local = collect($g['context']['events'])->whereIn('id', $g['local'])->sortBy('narrative_order');
            foreach ($local as $e) {
                $source = DB::table('proposed_events')->find($e['id']);
                $formal = DB::table('proposed_events')->where('id', $e['id'])->selectRaw('to_jsonb(proposed_events) AS payload')->value('payload');
                $supersedes = $e['supersedes_id'] ? DB::table('story_events')->where('revision_id', $revision)->where('proposed_event_id', $e['supersedes_id'])->value('id') : null;
                DB::table('story_events')->insert(['novel_id' => $novel, 'proposed_event_id' => $e['id'], 'revision_id' => $revision, 'commit_id' => $id, 'chapter_version_id' => $d['chapter_version_id'], 'evidence_id' => $source->evidence_id, 'narrative_order' => $source->narrative_order, 'payload' => $formal, 'supersedes_id' => $supersedes]);
            }
            DB::table('canonical_chapters')->insert(['novel_id' => $novel, 'revision_id' => $revision, 'chapter_id' => $d['chapter_id'], 'chapter_version_id' => $d['chapter_version_id'], 'commit_id' => $id, 'sequence' => $g['chapter']->sequence, 'body_hash' => $g['body']['body_hash']]);
            [$payload,$map] = $this->projection($actor, $novel, $revision, $g['context'], $g['chapter']->sequence, $id);
            $snapshot = (string) DB::table('state_snapshots')->insertGetId(['novel_id' => $novel, 'revision_id' => $revision, 'commit_id' => $id, 'formal_state_version' => $book->state_version + 1, 'stream_hash' => $this->hash(array_values($map)), 'chapter_sequence' => $g['chapter']->sequence, 'local_state_version' => $book->state_version + 1, 'scope' => 'formal', 'payload' => NovelIntentService::canonical($payload), 'payload_hash' => $this->hash($payload), 'event_ids' => NovelIntentService::canonical(array_values($map))]);
            $entities = [];
            foreach ($payload['state'] as $kind => $values) {
                foreach ($values as $k => $v) {
                    $entities[$v['entity_id']][$kind][$k] = $v;
                }
            }
            foreach ($entities as $entity => $attributes) {
                DB::table('entity_state_versions')->insert(['novel_id' => $novel, 'snapshot_id' => $snapshot, 'entity_id' => $entity, 'attributes' => NovelIntentService::canonical($attributes)]);
            }
            foreach ($g['changes'] as $change) {
                $e = DB::table('story_events')->find($map[$change['event_id']]);
                $plan = app(ForeshadowLedgerService::class)->read($actor, $novel, $change['plan_id']);
                $quote = DB::table('evidence_spans')->where('id', $e->evidence_id)->value('quote');
                $occ = DB::table('foreshadow_occurrences')->insertGetId(['novel_id' => $novel, 'plan_id' => $change['plan_id'], 'event_id' => $e->id, 'evidence_id' => $e->evidence_id, 'commit_id' => $id, 'chapter_version_id' => $d['chapter_version_id'], 'revision_id' => $revision, 'kind' => $change['kind'], 'causal_role' => $plan['payload']['causal_role'], 'new_information' => $quote]);
                DB::table('canonical_foreshadow_states')->insert(['novel_id' => $novel, 'revision_id' => $revision, 'commit_id' => $id, 'plan_id' => $change['plan_id'], 'occurrence_id' => $occ, 'status' => ['plant' => 'planted', 'reinforce' => 'reinforced', 'payoff' => 'paid_off'][$change['kind']]]);
            }
            DB::table('chapters')->where('id', $d['chapter_id'])->update(['current_canonical_version_id' => $d['chapter_version_id'], 'canonical_commit_id' => $id]);
            DB::table('canonical_revisions')->where('id', $revision)->update(['cursor' => $g['chapter']->sequence, 'local_state_version' => $book->state_version + 1]);
            $previous = $book->state_version;
            $book->state_version++;
            $book->save();
            $response = ['id' => $id, 'novel_id' => $novel, 'revision_id' => $revision, 'chapter_id' => $d['chapter_id'], 'chapter_version_id' => $d['chapter_version_id'], 'previous_state_version' => $previous, 'new_state_version' => $book->state_version, 'chapter_sequence' => $g['chapter']->sequence, 'snapshot_id' => $snapshot, 'body_hash' => $g['body']['body_hash'], 'canonical' => true, 'status' => 'committed', 'memory_status' => 'pending'];
            DB::table('canonical_outbox')->insert(['novel_id' => $novel, 'revision_id' => $revision, 'commit_id' => $id, 'topic' => 'canonical.committed', 'payload' => NovelIntentService::canonical($response), 'payload_hash' => $this->hash($response)]);
            DB::table('canonical_commits')->where('id', $id)->update(['status' => 'committed', 'snapshot_id' => $snapshot, 'response' => NovelIntentService::canonical($response), 'response_hash' => $this->hash($response)]);

            return $response + ['replayed' => false];
        });
    }

    /** 正式知识与关系写入独立强类型投影，不覆盖D02的候选上下文。 */
    private function projection(User $actor, string $novel, string $revision, array $context, int $chapter, ?string $commit = null): array
    {
        $map = DB::table('story_events as e')->join('canonical_commits as c', 'c.id', '=', 'e.commit_id')->where('e.revision_id', $revision)->where('c.chapter_sequence', '<=', $chapter)->orderBy('c.chapter_sequence')->orderBy('e.narrative_order')->pluck('e.id', 'e.proposed_event_id')->map(fn ($id) => (string) $id)->all();
        $replay = app(StoryStateService::class)->replay($actor, $novel, array_map('strval', array_keys($map)), $chapter);
        $state = $replay['state'];
        foreach ($state as &$values) {
            foreach ($values as &$v) {
                $v['proposed_event_id'] = $v['source_event_id'];
                $v['source_event_id'] = $map[$v['source_event_id']];
            }unset($v);
        }unset($values);
        $knowledge = [];
        $eventMap = array_column($context['events'], null, 'id');
        foreach (array_unique(array_column($context['payload']['knowledge'], 'knower_id')) as $knower) {
            $pov = app(StoryConsistencyValidator::class)->pov($context['payload'], $eventMap, $knower, $chapter);
            foreach ($pov['knowledge'] as $k) {
                $row = ['novel_id' => $novel, 'revision_id' => $revision, 'knower_id' => $k['knower_id'], 'fact_event_id' => $map[$k['fact_event_id']], 'acquired_event_id' => $map[$k['acquired_event_id']], 'method' => $k['method'], 'knowledge_kind' => $k['knowledge_kind'], 'source_knower_id' => $k['source_knower_id'], 'introduced_chapter' => $k['introduced_at']];
                if ($commit !== null && ! DB::table('canonical_knowledge_facts')->where(array_intersect_key($row, array_flip(['revision_id', 'knower_id', 'fact_event_id', 'acquired_event_id'])))->exists()) {
                    DB::table('canonical_knowledge_facts')->insert($row + ['commit_id' => $commit]);
                }
                $knowledge[] = $row;
            }
        }
        $relationships = [];
        $active = [];
        foreach ($context['events'] as $event) {
            if ($event['supersedes_id']) {
                unset($active[$event['supersedes_id']]);
            }
            if ($event['action'] !== 'revoke') {
                $active[$event['id']] = $event;
            }
        }
        $relationRows = $context['payload']['relationships'];
        usort($relationRows, fn ($a, $b) => [$eventMap[$a['event_id']]['chapter_sequence'], $eventMap[$a['event_id']]['narrative_order']] <=> [$eventMap[$b['event_id']]['chapter_sequence'], $eventMap[$b['event_id']]['narrative_order']]);
        foreach ($relationRows as $r) {
            if (! isset($active[$r['event_id']]) || $active[$r['event_id']]['fact_kind'] !== 'fact') {
                continue;
            }
            $row = ['novel_id' => $novel, 'revision_id' => $revision, 'event_id' => $map[$r['event_id']], 'from_entity_id' => $r['from_entity_id'], 'to_entity_id' => $r['to_entity_id'], 'relation_type' => $r['relation_type'], 'state' => $r['state']];
            if ($commit !== null && ! DB::table('canonical_relationships')->where(array_intersect_key($row, array_flip(['revision_id', 'event_id', 'from_entity_id', 'to_entity_id', 'relation_type'])))->exists()) {
                DB::table('canonical_relationships')->insert($row + ['commit_id' => $commit]);
            }
            $relationships[$r['from_entity_id'].':'.$r['to_entity_id'].':'.$r['relation_type']] = $row;
        }

        return [['schema' => 'formal-state-v1', 'state' => $state, 'knowledge' => $knowledge, 'relationships' => array_values($relationships)], $map];
    }

    public function read(User $actor, string $novel, string $id): array
    {
        app(NovelIntentService::class)->novel($actor, $novel);
        Validator::make(['id' => $id], ['id' => 'required|integer|min:1'])->validate();
        $c = DB::table('canonical_commits')->where('novel_id', $novel)->where('id', $id)->where('status', 'committed')->first() ?? throw new NovelProblem('NOT_FOUND', '正式提交不存在。', 404);
        $r = json_decode($c->response, true);
        $s = DB::table('state_snapshots')->where('novel_id', $novel)->where('id', $c->snapshot_id)->first();
        $payload = $s ? json_decode($s->payload, true) : null;
        if (! $s || $this->hash($r) !== $c->response_hash || $this->hash($payload) !== $s->payload_hash) {
            throw new NovelProblem('INVALID_COMMIT_HASH', '正式回执或状态快照哈希失配。');
        }

        return $r + ['idempotency_key' => $c->idempotency_key, 'actor_id' => (string) $c->actor_id, 'reason' => $c->reason, 'created_at' => $c->created_at, 'request' => json_decode($c->request, true), 'state' => $payload];
    }

    /** 从正式事件与提交绑定的知识上下文回放，不能读取最新候选替代历史。 */
    public function replay(User $actor, string $novel, ?string $revision = null, ?int $chapter = null): array
    {
        $committed = $this->state($actor, $novel, $revision, $chapter);
        if (! isset($committed['id'])) {
            return $committed + ['replayed_from_events' => true];
        }
        $proof = DB::table('extraction_attempts')->where('novel_id', $novel)->where('id', $committed['request']['event_artifact_id'])->first();
        $context = app(StoryConsistencyService::class)->read($actor, $novel, (string) $proof->context_id);
        [$payload, $map] = $this->projection($actor, $novel, $committed['revision_id'], $context, $committed['chapter_sequence']);
        if ($this->hash($payload) !== $this->hash($committed['state'])) {
            throw new NovelProblem('FORMAL_REPLAY_MISMATCH', '正式事件回放与提交快照不一致。');
        }

        return $committed + ['replayed_from_events' => true, 'formal_event_ids' => array_values($map)];
    }

    public function listing(User $actor, string $novel, array $filters = []): array
    {
        $book = app(NovelIntentService::class)->novel($actor, $novel);
        Validator::make($filters, ['chapter_id' => 'sometimes|integer|min:1', 'revision_id' => 'sometimes|integer|min:1', 'from' => 'sometimes|date_format:Y-m-d', 'to' => 'sometimes|date_format:Y-m-d', 'error_code' => 'sometimes|string|max:80', 'status' => 'sometimes|in:ready,blocked,committed', 'page' => 'sometimes|integer|min:1|max:100000'])->validate();
        $commits = DB::table('canonical_commits')->where('novel_id', $novel)->where('status', 'committed');
        $preflights = DB::table('canonical_preflights')->where('novel_id', $novel);
        if (isset($filters['chapter_id'])) {
            $commits->where('chapter_id', $filters['chapter_id']);
            $preflights->where('chapter_id', $filters['chapter_id']);
        }
        if (isset($filters['revision_id'])) {
            $commits->where('revision_id', $filters['revision_id']);
            $preflights->where('request->expected_revision_id', (string) $filters['revision_id']);
        }
        if (isset($filters['error_code'])) {
            $preflights->where('report->code', $filters['error_code']);
            $commits->whereRaw('false');
        }
        foreach (['from' => '>=', 'to' => '<='] as $key => $op) {
            if (isset($filters[$key])) {
                $commits->whereDate('created_at', $op, $filters[$key]);
                $preflights->whereDate('created_at', $op, $filters[$key]);
            }
        }
        if (isset($filters['status'])) {
            if ($filters['status'] === 'committed') {
                $preflights->whereRaw('false');
            } else {
                $commits->whereRaw('false');
                $preflights->where('report->status', $filters['status']);
            }
        }
        $page = (int) ($filters['page'] ?? 1);
        $commitTotal = (clone $commits)->count();
        $preflightTotal = (clone $preflights)->count();
        $commits->offset(($page - 1) * 50);
        $preflights->offset(($page - 1) * 50);
        $revision = $book->active_canonical_revision_id ? DB::table('canonical_revisions')->where('id', $book->active_canonical_revision_id)->first() : null;

        return ['novel_id' => $novel, 'state_version' => $book->state_version, 'revision' => $revision ? array_replace((array) $revision, ['id' => (string) $revision->id, 'novel_id' => $novel, 'actor_id' => (string) $revision->actor_id]) : null, 'commits' => $commits->orderByDesc('id')->limit(50)->pluck('id')->map(fn ($id) => $this->read($actor, $novel, (string) $id))->all(), 'preflights' => $preflights->orderByDesc('id')->limit(50)->get()->map(fn ($r) => $this->decodePreflight($r))->all(), 'page' => $page, 'commits_total' => $commitTotal, 'preflights_total' => $preflightTotal, 'next_page' => max($commitTotal, $preflightTotal) > $page * 50 ? $page + 1 : null, 'outbox_pending' => app(CanonicalMemoryService::class)->dashboard($actor, $novel)['pending_count'], 'memory_consumer' => 'connected_E07'];
    }

    /** 当前或指定历史章只读取该正式基线的唯一映射，不回退候选。 */
    public function state(User $actor, string $novel, ?string $revision = null, ?int $chapter = null): array
    {
        $book = app(NovelIntentService::class)->novel($actor, $novel);
        Validator::make(['revision' => $revision, 'chapter' => $chapter], ['revision' => 'nullable|integer|min:1', 'chapter' => 'nullable|integer|min:1'])->validate();
        $revision ??= $book->active_canonical_revision_id === null ? null : (string) $book->active_canonical_revision_id;
        if (! $revision || ! DB::table('canonical_revisions')->where('novel_id', $novel)->where('id', $revision)->exists()) {
            throw new NovelProblem('NOT_FOUND', '正式基线不存在。', 404);
        }
        $q = DB::table('canonical_chapters')->where('novel_id', $novel)->where('revision_id', $revision);
        $map = $chapter === null ? $q->orderByDesc('sequence')->first() : $q->where('sequence', $chapter)->first();
        if (! $map) {
            if ($chapter !== null) {
                throw new NovelProblem('NOT_FOUND', '该正式章尚未提交。', 404);
            }

            return ['canonical' => true, 'revision_id' => $revision, 'chapter_sequence' => 0, 'state_version' => 0, 'state' => ['schema' => 'formal-state-v1', 'state' => array_fill_keys(StoryStateService::KINDS, []), 'knowledge' => [], 'relationships' => []]];
        }

        return $this->read($actor, $novel, (string) $map->commit_id);
    }
}
