<?php

namespace App\Services;

use App\Exceptions\NovelProblem;
use App\Extraction\ExtractionContract;
use App\Models\User;
use App\Revision\RevisionContract;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

/** E05受限候选修订：冻结原文，先选择后改候选，重新取证和整章复评不能省略。 */
final class RevisionService
{
    public function hash(mixed $v): string
    {
        return hash('sha256', NovelIntentService::canonical($v));
    }

    private function command(User $actor, string $novel, string $action, array $d, string $key, callable $fn): array
    {
        ExtractionContract::transport($d);
        Validator::make(['key' => $key], ['key' => ['required', 'string', 'max:128', 'regex:/^[\x20-\x7E]+$/D']])->validate();
        if (strlen(NovelIntentService::canonical($d)) > 1000000) {
            throw new NovelProblem('REQUEST_TOO_LARGE', '修订输入超过一百万字节。', 422);
        }

        return DB::transaction(function () use ($actor, $novel, $action, $d, $key, $fn) {
            $book = app(NovelIntentService::class)->novel($actor, $novel, true);
            $where = ['actor_id' => $actor->id, 'novel_scope' => $novel, 'command' => 'revision.'.$action, 'key' => $key];
            if ($old = DB::table('novel_commands')->where($where)->first()) {
                if ($old->request_hash !== $this->hash($d)) {
                    throw new NovelProblem('IDEMPOTENCY_CONFLICT', '修订操作键对应不同输入。');
                }

                return json_decode(NovelIntentService::canonical(json_decode($old->response, true)), true);
            }
            if ($book->status === 'archived') {
                throw new NovelProblem('STALE_STATE', '归档小说不可修订。');
            }
            $r = json_decode(NovelIntentService::canonical($fn($book)), true);
            DB::table('novel_commands')->insert($where + ['request_hash' => $this->hash($d), 'response' => NovelIntentService::canonical($r), 'created_at' => now()]);

            return $r;
        });
    }

    private function fields(array $d, array $allowed): void
    {
        if (array_diff(array_keys($d), $allowed)) {
            throw new NovelProblem('INVALID_REVISION_INPUT', '修订操作包含未知字段。', 422);
        }
    }

    /** 对外ID统一为十进制字符串，时间使用UTC ISO格式；正文和列表不改写。 */
    private function identities(array $row): array
    {
        foreach ($row as $key => $value) {
            if ($value !== null && ($key === 'id' || str_ends_with($key, '_id'))) {
                $row[$key] = (string) $value;
            }
        }
        if (isset($row['created_at'])) {
            $row['created_at'] = CarbonImmutable::parse($row['created_at'])->toISOString();
        }

        return $row;
    }

    private function task(User $actor, string $novel, string $id): array
    {
        app(NovelIntentService::class)->novel($actor, $novel);
        Validator::make(['id' => $id], ['id' => 'required|integer|min:1'])->validate();
        $row = DB::table('revision_tasks')->where('novel_id', $novel)->find($id) ?? throw new NovelProblem('NOT_FOUND', '修订任务不存在。', 404);
        $t = $this->identities((array) $row);
        foreach ($t as $k => $v) {
            if ($v !== null && ($k === 'id' || str_ends_with($k, '_id'))) {
                $t[$k] = (string) $v;
            }
        }
        $t['input'] = json_decode($t['input'], true);
        if ($this->hash($t['input']) !== $t['input_hash']) {
            throw new NovelProblem('INVALID_REVISION_HASH', '修订输入哈希不匹配。');
        }

        // 快照用于审计，实际问题和必保事件还须有完整的同书强关联。
        $issueIds = DB::table('revision_task_issues')->where('task_id', $id)->pluck('issue_id')->map(fn ($v) => (string) $v)->all();
        $factIds = DB::table('revision_task_facts')->where('task_id', $id)->pluck('event_id')->map(fn ($v) => (string) $v)->all();
        if (count($issueIds) !== count($t['input']['issue_ids']) || array_diff($issueIds, $t['input']['issue_ids']) || count($factIds) !== count($t['input']['preserve_facts']) || array_diff($factIds, $t['input']['preserve_facts'])) {
            throw new NovelProblem('INVALID_REVISION_REFERENCES', '冻结问题或保留事实的实际关联不完整。');
        }

        return $t;
    }

    public function verifySource(User $actor, string $novel, string $id): array
    {
        $t = $this->task($actor, $novel, $id);
        $r = app(ChapterReviewService::class)->read($actor, $novel, $t['review_id']);
        if ($r['stale'] || $r['input_hash'] !== $t['input']['review_hash']) {
            throw new NovelProblem('DEPENDENCY_MISMATCH', '修订来源正文或完整评审已过期，不能重绑定补丁。');
        }

        return $t;
    }

    public function create(User $actor, string $novel, array $d, string $key): array
    {
        $this->fields($d, ['review_id', 'issue_ids', 'allowed_scope', 'preserve_facts', 'preserve_quotes', 'mode', 'expected_version', 'expected_state_version', 'reason']);
        Validator::make($d, ['review_id' => 'required|integer|min:1', 'issue_ids' => 'required|array|min:1|max:100', 'issue_ids.*' => 'required|string|distinct', 'allowed_scope' => 'required|array:kind,ranges,scene_version_id', 'allowed_scope.kind' => 'required|in:paragraph,scene,chapter', 'allowed_scope.ranges' => 'required|array|min:1|max:5', 'allowed_scope.ranges.*' => 'required|array:start,end,quote', 'preserve_facts' => 'present|array|max:200', 'preserve_facts.*' => 'required|string|distinct', 'preserve_quotes' => 'present|array|max:100', 'preserve_quotes.*' => 'required|array:start,end,quote', 'mode' => 'required|in:offline,manual,production', 'expected_version' => 'required|integer|min:1', 'expected_state_version' => 'required|integer|min:0', 'reason' => 'required|string|max:2000'])->validate();

        return $this->command($actor, $novel, 'create', $d, $key, function ($book) use ($actor, $novel, $d) {
            if ($book->row_version !== (int) $d['expected_version'] || $book->state_version !== (int) $d['expected_state_version']) {
                throw new NovelProblem('STALE_STATE', '书级输入或正式状态已变化。');
            }
            $r = app(ChapterReviewService::class)->read($actor, $novel, (string) $d['review_id']);
            if ($r['stale']) {
                throw new NovelProblem('STALE_REVIEW', '过期评审只能查阅，不能据此启动新修订。');
            }
            if (app(ReviewPolicyService::class)->read($actor, $r['policy_version_id'])['status'] !== 'published') {
                throw new NovelProblem('UNPUBLISHED_REFERENCE', '新修订任务必须采用已发布的评审策略。');
            }
            $body = app(DraftingService::class)->read($actor, $novel, 'chapter', $r['chapter_version_id']);
            $e = app(ExtractionService::class)->read($actor, $novel, $r['extraction_id']);
            if (array_diff($d['issue_ids'], array_column($r['issues'], 'id')) || array_diff($d['preserve_facts'], $e['candidate_event_ids'])) {
                throw new NovelProblem('INVALID_REVISION_REFERENCE', '问题或保留事实不属于本次同书评审。', 422);
            }
            $last = DB::table('revision_tasks')->where('novel_id', $novel)->where('chapter_node_id', $body['node_id'])->orderByDesc('id')->first();
            $lastState = $last ? $this->read($actor, $novel, (string) $last->id) : null;
            if ($lastState && ! $lastState['stale'] && in_array($lastState['status'], ['draft', 'running', 'awaiting_selection', 'reviewing'], true)) {
                throw new NovelProblem('REVISION_IN_PROGRESS', '本章已有未完成修订，请先采纳、复评或放弃。');
            }
            $first = DB::table('revision_tasks')->where('novel_id', $novel)->where('chapter_node_id', $body['node_id'])->whereIn('mode', ['offline', 'production'])->orderBy('id')->first();
            $limit = $first ? $first->max_rounds : min(3, $r['frozen_input']['policy']['content']['max_revision_rounds']);
            $round = (int) DB::table('revision_tasks')->where('novel_id', $novel)->where('chapter_node_id', $body['node_id'])->whereIn('mode', ['offline', 'production'])->where('initial_status', 'draft')->count() + (in_array($d['mode'], ['offline', 'production'], true) ? 1 : 0);
            $manual = in_array($d['mode'], ['offline', 'production'], true) && ($round > $limit || ! $r['report']['hard_pass'] || ($lastState && ($lastState['manual_required'] || $lastState['stale'])) || ($first && json_decode($first->input, true)['policy_id'] !== $r['policy_version_id']));
            if (! $e['context_id']) {
                throw new NovelProblem('INVALID_REVISION_SOURCE', '先修正原文的无效提取并建立完整评审，再启动文学修订。');
            }
            $scope = $d['allowed_scope'];
            $this->scope($body, $scope);
            $protected = [];
            foreach ($e['events'] as $event) {
                if ((string) $event['evidence']['source_version_id'] === $e['source_id']) {
                    $span = $event['evidence'];
                    $protected[] = ['start' => $span['start_offset'], 'end' => $span['end_offset'], 'quote' => $span['quote']];
                }
            }
            foreach ($d['preserve_quotes'] as $span) {
                $this->span($body['body'], $span);
                $protected[] = $span;
            }
            $input = ['source' => $body, 'review_id' => $r['id'], 'review_hash' => $r['input_hash'], 'policy_id' => $r['policy_version_id'], 'style_version_id' => $r['frozen_input']['policy']['style_version_id'], 'issue_ids' => $d['issue_ids'], 'issues' => array_values(array_filter($r['issues'], fn ($i) => in_array($i['id'], $d['issue_ids'], true))), 'allowed_scope' => $scope, 'protected' => $protected, 'preserve_facts' => array_values(array_unique([...$e['candidate_event_ids'], ...$d['preserve_facts']])), 'fact_set' => RevisionContract::facts($e['output']), 'extraction' => $e, 'source_report' => $r['report'], 'round_limit' => $limit, 'canonical' => false];
            // 将只读意见对象转成JSON数据后再规范化，避免JSONB对象键顺序影响哈希。
            $input = json_decode(json_encode($input, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR), true);
            $id = (string) DB::table('revision_tasks')->insertGetId(['novel_id' => $novel, 'chapter_node_id' => $body['node_id'], 'source_version_id' => $body['id'], 'review_id' => $r['id'], 'predecessor_id' => $last->id ?? null, 'mode' => $d['mode'], 'automatic_round' => $round, 'max_rounds' => $limit, 'input' => NovelIntentService::canonical($input), 'input_hash' => $this->hash($input), 'initial_status' => $manual ? 'manual_required' : 'draft', 'actor_id' => $actor->id, 'reason' => $d['reason']]);

            foreach ($input['issue_ids'] as $issue) {
                DB::table('revision_task_issues')->insert(['novel_id' => $novel, 'task_id' => $id, 'review_id' => $r['id'], 'issue_id' => $issue]);
            }
            foreach ($input['preserve_facts'] as $event) {
                DB::table('revision_task_facts')->insert(['novel_id' => $novel, 'task_id' => $id, 'event_id' => $event]);
            }

            return $this->read($actor, $novel, $id);
        });
    }

    private function span(string $body, array $s): void
    {
        Validator::make($s, ['start' => 'required|integer|min:0', 'end' => 'required|integer|min:1', 'quote' => 'required|string|max:100000'])->validate();
        if ($s['end'] <= $s['start'] || $s['end'] > mb_strlen($body) || mb_substr($body, $s['start'], $s['end'] - $s['start']) !== $s['quote']) {
            throw new NovelProblem('INVALID_REVISION_SPAN', '范围或必须保留的引文与实际正文不匹配。', 422);
        }
    }

    private function scope(array $body, array $scope): void
    {
        $previous = -1;
        foreach ($scope['ranges'] as $s) {
            $this->span($body['body'], $s);
            if ($s['start'] < $previous) {
                throw new NovelProblem('INVALID_REVISION_SCOPE', '允许范围须按原文顺序互不重叠。', 422);
            }$previous = $s['end'];
            if ($scope['kind'] === 'paragraph' && (($s['start'] > 0 && mb_substr($body['body'], $s['start'] - 1, 1) !== "\n") || ($s['end'] < mb_strlen($body['body']) && mb_substr($body['body'], $s['end'], 1) !== "\n"))) {
                throw new NovelProblem('INVALID_REVISION_SCOPE', '段落范围须沿真实换行边界，不默认扩展到整章。', 422);
            }
        }
        if ($scope['kind'] === 'chapter' && (count($scope['ranges']) !== 1 || $scope['ranges'][0]['start'] !== 0 || $scope['ranges'][0]['end'] !== mb_strlen($body['body']))) {
            throw new NovelProblem('INVALID_REVISION_SCOPE', '整章修订必须由作者明确选择完整正文。', 422);
        }
        if ($scope['kind'] === 'scene') {
            $id = (string) ($scope['scene_version_id'] ?? '');
            $source = collect($body['sources'])->firstWhere('id', $id);
            $row = $source ? DB::table('scene_versions')->where('novel_id', $body['novel_id'])->find($id) : null;
            if (! $row || count($scope['ranges']) !== 1 || $scope['ranges'][0]['quote'] !== $row->body || mb_substr_count($body['body'], $row->body) !== 1) {
                throw new NovelProblem('INVALID_REVISION_SCOPE', '场景范围须对应本章唯一、完整的实际来源场景。', 422);
            }
        }
    }

    public function propose(User $actor, string $novel, string $id, array $d, string $key, ?array $origin = null): array
    {
        $this->fields($d, ['offline_output', 'reason']);
        Validator::make($d, ['offline_output' => 'required|array', 'reason' => 'required|string|max:2000'])->validate();

        return $this->command($actor, $novel, 'propose.'.$id, $d, $key, function () use ($actor, $novel, $id, $d, $origin) {
            $t = $this->verifySource($actor, $novel, $id);
            if ((! in_array($t['mode'], ['offline', 'production'], true) || ($t['mode'] === 'production' && empty($origin['source_request_id']))) || $t['initial_status'] !== 'draft' || DB::table('revision_proposals')->where('task_id', $id)->exists() || DB::table('revision_decisions')->where('task_id', $id)->exists()) {
                throw new NovelProblem('REVISION_MANUAL_REQUIRED', '此任务不能再生成自动提案，请人工处理或明确结束本轮。');
            }
            // 同一轮只能选择纯回放或已冻结网关运行，不能用旁路提案抢先覆盖请求。
            if (! $origin && DB::table('generation_runs')->where('frozen_config->schema_version', 'revision-v1')->where('frozen_config->task_id', $id)->exists()) {
                throw new NovelProblem('REVISION_RUN_EXISTS', '本轮已有离线网关运行，请通过原运行恢复或转人工。');
            }
            $report = ['valid' => true, 'issues' => []];
            try {
                RevisionContract::patches($t['input']['source']['body'], $t['input']['allowed_scope'], $t['input']['protected'], $d['offline_output'], $t['input']['issue_ids']);
            } catch (NovelProblem|ValidationException $e) {
                $report = ['valid' => false, 'issues' => [['code' => $e instanceof NovelProblem ? $e->errorCode : 'INVALID_REVISION', 'message' => $e->getMessage(), 'claimed_output' => $d['offline_output']]]];
            }
            DB::table('revision_proposals')->insert(['source_request_id' => $origin['source_request_id'] ?? null, 'novel_id' => $novel, 'task_id' => $id, 'run_id' => $origin['run_id'] ?? null, 'artifact_id' => $origin['artifact_id'] ?? null, 'output' => NovelIntentService::canonical($d['offline_output']), 'output_hash' => $this->hash($d['offline_output']), 'report' => NovelIntentService::canonical($report)]);

            return $this->read($actor, $novel, $id);
        });
    }

    public function decide(User $actor, string $novel, string $id, array $d, string $key): array
    {
        $this->fields($d, ['kind', 'proposal_id', 'patch_keys', 'manual_output', 'expected_version', 'reason']);
        Validator::make($d, ['kind' => 'required|in:accept,manual,reject,abandon', 'proposal_id' => 'nullable|integer|min:1', 'patch_keys' => 'sometimes|array|max:50', 'patch_keys.*' => 'required|string|distinct', 'manual_output' => 'sometimes|array', 'expected_version' => 'required|integer|min:1', 'reason' => 'required|string|max:2000'])->validate();

        return $this->command($actor, $novel, 'decide.'.$id, $d, $key, function () use ($actor, $novel, $id, $d, $key) {
            $t = $this->task($actor, $novel, $id);
            $closed = DB::table('revision_decisions')->where('task_id', $id)->orderByDesc('id')->first();
            if ($closed && ($d['kind'] !== 'abandon' || $closed->kind === 'abandon')) {
                throw new NovelProblem('REVISION_CLOSED', '此轮已有决定，历史不能覆盖。');
            }
            $before = $t['input']['source']['body'];
            $target = null;
            $patches = [];
            $proposal = null;
            if (in_array($d['kind'], ['accept', 'manual'], true)) {
                $this->verifySource($actor, $novel, $id);
                $body = $t['input']['source'];
                if ($body['version'] !== (int) $d['expected_version']) {
                    throw new NovelProblem('STALE_CANDIDATE', '候选版本与预期不符。');
                }
                if ($d['kind'] === 'accept') {
                    $proposal = DB::table('revision_proposals')->where('task_id', $id)->where('id', $d['proposal_id'] ?? null)->first();
                    $p = $proposal ? json_decode($proposal->output, true) : null;
                    if (! $proposal || ! json_decode($proposal->report, true)['valid'] || empty($d['patch_keys'])) {
                        throw new NovelProblem('INVALID_SELECTION', '请选择本轮有效提案中的具体补丁。', 422);
                    }if (array_diff($d['patch_keys'], array_column($p['patches'], 'key'))) {
                        throw new NovelProblem('INVALID_SELECTION', '包含不属于本次提案的补丁。', 422);
                    }$output = ['patches' => array_values(array_filter($p['patches'], fn ($p) => in_array($p['key'], $d['patch_keys'], true)))];
                } else {
                    $output = $d['manual_output'] ?? [];
                }
                $patches = RevisionContract::patches($before, $t['input']['allowed_scope'], $t['input']['protected'], $output, $t['input']['issue_ids']);
                $after = RevisionContract::apply($before, $patches);
                $target = app(DraftingService::class)->save($actor, $novel, 'chapter', $t['chapter_node_id'], ['plan_id' => $body['plan_id'], 'source_version_id' => $t['source_version_id'], 'body' => $after, 'title' => $body['title'], 'expected_version' => $d['expected_version'], 'reason' => $d['reason']], 'e05:'.hash('sha256', $id.':'.$key));
                $target = $target['id'];
            }
            DB::table('revision_decisions')->insert(['novel_id' => $novel, 'task_id' => $id, 'proposal_id' => $proposal->id ?? null, 'kind' => $d['kind'], 'selection' => NovelIntentService::canonical($patches), 'diff' => NovelIntentService::canonical(['before' => $before, 'after' => $target ? $after : $before, 'patches' => $patches]), 'target_version_id' => $target, 'actor_id' => $actor->id, 'reason' => $d['reason']]);

            return $this->read($actor, $novel, $id);
        });
    }

    public function assess(User $actor, string $novel, string $id, array $d, string $key): array
    {
        $this->fields($d, ['extraction_output', 'review_output', 'reviewer_type', 'reason']);
        Validator::make($d, ['extraction_output' => 'required|array', 'review_output' => 'required|array', 'reviewer_type' => 'required|in:offline,human', 'reason' => 'required|string|max:2000'])->validate();

        return $this->command($actor, $novel, 'assess.'.$id, $d, $key, function ($book) use ($actor, $novel, $id, $d, $key) {
            $t = $this->task($actor, $novel, $id);
            $old = $t['input']['extraction'];
            // 自己采纳新正文不改变书级输入或正式版本；二者外部变化仍须整轮失效。
            if ((string) $book->row_version !== (string) $old['row_version'] || (string) $book->state_version !== (string) $old['state_version']) {
                throw new NovelProblem('DEPENDENCY_MISMATCH', '修订冻结的作者输入或正式状态已改变。');
            }
            $decision = DB::table('revision_decisions')->where('task_id', $id)->whereNotNull('target_version_id')->first() ?? throw new NovelProblem('REVISION_NOT_ADOPTED', '先选择修改并保存候选，再重新取证和整章评审。');
            $latestDecision = DB::table('revision_decisions')->where('task_id', $id)->orderByDesc('id')->first();
            if ($latestDecision->kind === 'abandon') {
                throw new NovelProblem('REVISION_CLOSED', '本轮已放弃；旧候选和失败保留，不能继续复评。');
            }
            $body = app(DraftingService::class)->read($actor, $novel, 'chapter', (string) $decision->target_version_id);
            $latest = (string) DB::table('chapter_versions')->where('novel_id', $novel)->where('node_id', $t['chapter_node_id'])->orderByDesc('version')->value('id');
            if ($latest !== $body['id'] || $body['stale']) {
                throw new NovelProblem('STALE_CANDIDATE', '新正文已换版，不能对旧版复评。');
            }
            $e = app(ExtractionService::class)->extract($actor, $novel, ['chapter_version_id' => $body['id'], 'body_hash' => $body['body_hash'], 'expected_version' => $book->row_version, 'expected_state_version' => $book->state_version, 'baseline_id' => $old['baseline_id'], 'rule_context_id' => $old['frozen_input']['rule_context_id'], 'offline_output' => $d['extraction_output'], 'reason' => $d['reason']], 'e05-extract:'.hash('sha256', $id.':'.$key));
            $same = $e['current_eligible'] && $this->hash(RevisionContract::facts($e['output'])) === $this->hash($t['input']['fact_set']);
            $report = ['eligible' => $e['current_eligible'] && $same, 'facts_preserved' => $same, 'extraction_eligible' => $e['current_eligible'], 'semantic_equivalence' => 'not_proven_by_offline_replay', 'canonical' => false];
            $v = (string) DB::table('revision_validations')->insertGetId(['novel_id' => $novel, 'task_id' => $id, 'decision_id' => $decision->id, 'extraction_id' => $e['id'], 'report' => NovelIntentService::canonical($report), 'content_hash' => $this->hash($report)]);
            // 任务创建时已冻结策略；退役只阻止新任务，不能改动此轮的阈值或文风。
            $reviewService = app(ChapterReviewService::class);
            $reviewData = ['chapter_version_id' => $body['id'], 'body_hash' => $body['body_hash'], 'extraction_id' => $e['id'], 'policy_version_id' => $t['input']['policy_id'], 'expected_version' => $book->row_version, 'expected_state_version' => $book->state_version, 'offline_output' => $d['review_output'], 'reviewer_type' => $d['reviewer_type'], 'reason' => $d['reason']];
            $frozenReview = $reviewService->snapshot($actor, $novel, $reviewData, false);
            if ($this->hash($frozenReview['policy']['content']) !== $this->hash($t['input']['source_report']['thresholds']) || $frozenReview['policy']['style_version_id'] !== $t['input']['style_version_id']) {
                throw new NovelProblem('DEPENDENCY_MISMATCH', '复评策略或文风与修订冻结输入不同。');
            }
            $review = $reviewService->review($actor, $novel, $reviewData, 'e05-review:'.hash('sha256', $id.':'.$key), null, $frozenReview);
            $previous = $t['input']['source_report'];
            $now = $review['report'];
            $before = array_sum(array_column($previous['scores'], 'score'));
            $after = array_sum(array_column($now['scores'], 'score'));
            $improved = $now['hard_pass'] && ($after > $before || $now['unresolved_blocking'] < $previous['unresolved_blocking']);
            $status = $review['current_eligible'] && $improved ? 'accepted' : ((! $improved || ! $now['hard_pass'] || $t['automatic_round'] >= $t['max_rounds']) ? 'manual_required' : 'revision_required');
            DB::table('revision_assessments')->insert(['novel_id' => $novel, 'task_id' => $id, 'validation_id' => $v, 'review_id' => $review['id'], 'report' => NovelIntentService::canonical(['status' => $status, 'improved' => $improved, 'score_before' => $before, 'score_after' => $after, 'issue_ids' => $t['input']['issue_ids'], 'issue_resolution' => 'requires_author_confirmation', 'canonical' => false])]);

            return $this->read($actor, $novel, $id);
        });
    }

    /** 无改善后只允许作者明确接受已过硬门禁的非阻塞文学候选，不修改旧评分。 */
    public function confirm(User $actor, string $novel, string $id, array $d, string $key): array
    {
        $this->fields($d, ['reason']);
        Validator::make($d, ['reason' => 'required|string|max:2000'])->validate();

        return $this->command($actor, $novel, 'confirm.'.$id, $d, $key, function () use ($actor, $novel, $id, $d) {
            $t = $this->read($actor, $novel, $id);
            if ($t['stale'] || $t['status'] === 'rejected' || ! $t['assessment']) {
                throw new NovelProblem('INVALID_REVISION_CONFIRMATION', '候选已过期、放弃或尚无完整复评，不能确认。');
            }
            $a = DB::table('revision_assessments')->where('task_id', $id)->orderByDesc('id')->first();
            $review = app(ChapterReviewService::class)->read($actor, $novel, (string) $a->review_id);
            if (! $review['current_eligible']) {
                throw new NovelProblem('HARD_ISSUE_NOT_WAIVABLE', '人工确认不能越过硬门禁、文学最低线或保留事实验证。');
            }
            $report = json_decode($a->report, true);
            $report['status'] = 'accepted';
            $report['manual_confirmation'] = true;
            $report['confirmation_reason'] = $d['reason'];
            DB::table('revision_assessments')->insert(['novel_id' => $novel, 'task_id' => $id, 'validation_id' => $a->validation_id, 'review_id' => $a->review_id, 'report' => NovelIntentService::canonical($report)]);

            return $this->read($actor, $novel, $id);
        });
    }

    public function read(User $actor, string $novel, string $id): array
    {
        $t = $this->task($actor, $novel, $id);
        $status = $t['initial_status'];
        $proposals = DB::table('revision_proposals')->where('task_id', $id)->orderBy('id')->get()->map(function ($p) {
            $p = $this->identities((array) $p);
            foreach (['id', 'task_id', 'novel_id', 'run_id', 'artifact_id'] as $k) {
                if ($p[$k] !== null) {
                    $p[$k] = (string) $p[$k];
                }
            }$p['output'] = json_decode($p['output'], true);
            $p['report'] = json_decode($p['report'], true);
            if ($this->hash($p['output']) !== $p['output_hash']) {
                throw new NovelProblem('INVALID_REVISION_HASH', '补丁哈希不一致。');
            }

            return $p;
        })->all();
        $decision = DB::table('revision_decisions')->where('task_id', $id)->orderByDesc('id')->first();
        $assessment = DB::table('revision_assessments')->where('task_id', $id)->orderByDesc('id')->first();
        $run = DB::table('generation_runs')->where('frozen_config->schema_version', 'revision-v1')->where('frozen_config->task_id', $id)->orderByDesc('id')->first();
        if ($run) {
            $status = in_array($run->status, ['deferred', 'blocked', 'failed', 'needs_attention'], true) ? 'manual_required' : 'running';
        }
        if ($proposals) {
            $status = end($proposals)['report']['valid'] ? 'awaiting_selection' : 'manual_required';
        }
        if ($decision) {
            $status = $decision->target_version_id ? 'reviewing' : 'rejected';
        }
        if ($assessment) {
            $status = json_decode($assessment->report, true)['status'];
        }
        $manualRequired = $t['initial_status'] === 'manual_required' || ($run && in_array($run->status, ['deferred', 'blocked', 'failed', 'needs_attention'], true)) || ($proposals && ! end($proposals)['report']['valid']) || ($assessment && json_decode($assessment->report, true)['status'] === 'manual_required');
        if ($decision && $decision->kind === 'abandon') {
            $status = 'rejected';
        }
        $adopted = DB::table('revision_decisions')->where('task_id', $id)->whereNotNull('target_version_id')->first();
        $book = app(NovelIntentService::class)->novel($actor, $novel);
        $stale = (string) $book->row_version !== (string) $t['input']['extraction']['row_version'] || (string) $book->state_version !== (string) $t['input']['extraction']['state_version'];
        try {
            if (! $adopted) {
                $this->verifySource($actor, $novel, $id);
            } else {
                $body = app(DraftingService::class)->read($actor, $novel, 'chapter', (string) $adopted->target_version_id);
                $stale = $stale || $body['stale'] || (string) DB::table('chapter_versions')->where('novel_id', $novel)->where('node_id', $t['chapter_node_id'])->orderByDesc('version')->value('id') !== $body['id'];
                if ($assessment) {
                    $stale = $stale || app(ChapterReviewService::class)->read($actor, $novel, (string) $assessment->review_id)['stale'];
                }
            }
        } catch (NovelProblem) {
            $stale = true;
        }
        $t['status'] = $status;
        $t['manual_required'] = (bool) $manualRequired;
        $t['stale'] = $stale;
        $t['display_status'] = $stale ? 'stale' : $status;
        $t['proposals'] = $proposals;
        $t['run_id'] = $run ? (string) $run->id : null;
        $t['decision'] = $decision ? $this->identities(array_replace((array) $decision, ['id' => (string) $decision->id, 'target_version_id' => $decision->target_version_id ? (string) $decision->target_version_id : null, 'selection' => json_decode($decision->selection, true), 'diff' => json_decode($decision->diff, true)])) : null;
        $t['assessment'] = $assessment ? $this->identities(array_replace((array) $assessment, ['review_id' => (string) $assessment->review_id, 'report' => json_decode($assessment->report, true)])) : null;
        $t['decisions'] = DB::table('revision_decisions')->where('task_id', $id)->orderBy('id')->get()->map(fn ($row) => ['id' => (string) $row->id, 'kind' => $row->kind, 'target_version_id' => $row->target_version_id ? (string) $row->target_version_id : null, 'selection' => json_decode($row->selection, true), 'diff' => json_decode($row->diff, true), 'reason' => $row->reason])->all();
        $t['canonical'] = false;

        return $t;
    }

    public function listing(User $actor, string $novel): array
    {
        app(NovelIntentService::class)->novel($actor, $novel);

        return DB::table('revision_tasks')->where('novel_id', $novel)->orderByDesc('id')->limit(50)->pluck('id')->map(fn ($id) => $this->read($actor, $novel, (string) $id))->all();
    }
}
