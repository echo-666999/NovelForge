<?php

namespace App\Services;

use App\Exceptions\NovelProblem;
use App\Extraction\ExtractionContract;
use App\Models\User;
use App\ProvidersGateway\StructuredOutput;
use App\Review\ReviewContract;
use App\Review\StyleSignals;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

/** E04候选评审：硬门禁独立于文学分数，人工评分也不能覆盖结构错误。 */
final class ChapterReviewService
{
    public function hash(mixed $value): string
    {
        return hash('sha256', NovelIntentService::canonical($value));
    }

    /** 完整上下文自行冻结；不能借用与本章无关的清单标识。 */
    public function snapshot(User $actor, string $novel, array $d, bool $new = true): array
    {
        $book = app(NovelIntentService::class)->novel($actor, $novel);
        $body = app(DraftingService::class)->read($actor, $novel, 'chapter', (string) $d['chapter_version_id']);
        $e = app(ExtractionService::class)->read($actor, $novel, (string) $d['extraction_id']);
        $p = app(ReviewPolicyService::class)->read($actor, (string) $d['policy_version_id']);
        if ($e['chapter_version_id'] !== $body['id'] || $e['body_hash'] !== $body['body_hash'] || $d['body_hash'] !== $body['body_hash']) {
            throw new NovelProblem('REVIEW_SOURCE_MISMATCH', '正文、提取记录与哈希不属于同一版本。');
        }
        if ($new && ($p['status'] !== 'published' || $book->status === 'archived' || $book->row_version !== (int) $d['expected_version'] || $book->state_version !== (int) $d['expected_state_version'])) {
            throw new NovelProblem('STALE_REVIEW_INPUT', '小说版本或策略发布状态已变化。');
        }
        $style = app(AuthoringConfigurationService::class)->version('style', $p['style_version_id']);
        // 新评审仍要求正文文风已发布；旧运行和历史核验继续使用冻结版本。
        if ($new && ($style['stage'] !== 'drafting.scene' || $style['status'] !== 'published')) {
            throw new NovelProblem('POLICY_STYLE_MISMATCH', '新评审须引用已发布的正文文风版本。');
        }
        // 发布默认指针或退役状态不改变旧运行冻结的文风内容。
        $style = array_intersect_key($style, array_flip(['id', 'content', 'content_hash', 'stage', 'language']));
        $styles = [];
        foreach ($body['sources'] as $source) {
            $scene = DB::table('scene_versions')->where('novel_id', $novel)->find($source['id']);
            while ($scene && ! $scene->run_id && $scene->parent_version_id) {
                $scene = DB::table('scene_versions')->where('novel_id', $novel)->find($scene->parent_version_id);
            }
            $cfg = $scene && $scene->run_id ? json_decode(DB::table('generation_runs')->where('novel_id', $novel)->where('id', $scene->run_id)->value('frozen_config'), true) : null;
            $styles[] = $cfg['configuration']['style']['id'] ?? null;
        }
        $hard = [];
        if (! $e['current_eligible']) {
            $hard[] = ['code' => 'EXTRACTION_NOT_ELIGIBLE', 'extraction_issues' => $e['report']['issues'], 'extraction_id' => $e['id']];
        }
        if (! $body['report']['complete'] || $body['stale']) {
            $hard[] = ['code' => 'INCOMPLETE_OR_STALE_BODY', 'report' => $body['report']];
        }
        if (! $styles || count(array_unique($styles)) !== 1 || (string) $styles[0] !== $p['style_version_id']) {
            $hard[] = ['code' => 'GENERATION_STYLE_MISMATCH', 'generation_style_ids' => $styles, 'policy_style_id' => $p['style_version_id']];
        }
        $prior = $e['baseline_id'] ? app(ExtractionService::class)->baseline($actor, $novel, $e['baseline_id']) : null;
        $priorBody = $prior ? app(DraftingService::class)->read($actor, $novel, 'chapter', $prior['chapter_version_id']) : null;
        $context = $e['context_id'] ? app(StoryConsistencyService::class)->read($actor, $novel, $e['context_id']) : null;
        $validation = $this->hash(['input' => $e['input_hash'], 'output' => $e['output_hash'], 'report' => $e['report'], 'events' => $e['candidate_event_ids'], 'patches' => $e['patches']]);

        // E05采纳的新正文不能绕开事实保留验证直接取得E04通过。
        $revisionGuard = null;
        if (Schema::hasTable('revision_decisions')) {
            $adoption = DB::table('revision_decisions')->where('novel_id', $novel)->where('target_version_id', $body['id'])->first();
            if ($adoption) {
                $v = DB::table('revision_validations')->where('decision_id', $adoption->id)->where('extraction_id', $e['id'])->orderByDesc('id')->first();
                $vr = $v ? json_decode($v->report, true) : null;
                $revisionGuard = ['decision_id' => (string) $adoption->id, 'validation_id' => $v ? (string) $v->id : null, 'validation_hash' => $v->content_hash ?? null];
                $latestDecision = DB::table('revision_decisions')->where('task_id', $adoption->task_id)->orderByDesc('id')->first();
                if ($latestDecision->kind === 'abandon') {
                    $revisionGuard['abandoned'] = true;
                    $hard[] = ['code' => 'REVISION_ABANDONED', 'revision' => $revisionGuard];
                }
                if (! $v || $this->hash($vr) !== $v->content_hash || ! $vr['eligible']) {
                    $hard[] = ['code' => 'REVISION_PRESERVATION_REQUIRED', 'revision' => $revisionGuard];
                }
            }
        }

        return ['schema' => 'review-input-v1', 'book_status' => $book->status, 'row_version' => $book->row_version, 'state_version' => $book->state_version, 'chapter_version_id' => $body['id'], 'chapter_id' => $body['node_id'], 'body_hash' => $body['body_hash'], 'source_id' => $e['source_id'], 'extraction_id' => $e['id'], 'validation_hash' => $validation, 'policy' => ['id' => $p['id'], 'content' => $p['content'], 'content_hash' => $p['content_hash'], 'style_version_id' => $p['style_version_id']], 'context' => ['body' => $body['body'], 'title' => $body['title'], 'body_report' => $body['report'], 'sources' => $body['sources'], 'plan' => app(RollingPlanService::class)->read($actor, $novel, $body['plan_id']), 'extraction' => $e['frozen_input'], 'events' => $e['events'], 'patches' => $e['patches'], 'validation' => $e['report'], 'state_context' => $context, 'previous_body' => $priorBody['body'] ?? null, 'previous_body_hash' => $priorBody['body_hash'] ?? null, 'style' => $style, 'requirements' => app(NovelIntentService::class)->requirements($book), ...($revisionGuard === null ? [] : ['revision_guard' => $revisionGuard])], 'hard' => $hard, 'canonical' => false];
    }

    public function review(User $actor, string $novel, array $d, string $key, ?array $origin = null, ?array $frozen = null): array
    {
        ExtractionContract::transport($d);
        Validator::make(['d' => $d, 'key' => $key], ['d' => 'required|array:chapter_version_id,body_hash,extraction_id,policy_version_id,expected_version,expected_state_version,offline_output,reviewer_type,reason', 'key' => ['required', 'string', 'max:128', 'regex:/^[\x20-\x7E]+$/D']])->validate();
        Validator::make($d, ['chapter_version_id' => 'required|integer|min:1', 'body_hash' => 'required|string|size:64', 'extraction_id' => 'required|integer|min:1', 'policy_version_id' => 'required|integer|min:1', 'expected_version' => 'required|integer|min:1', 'expected_state_version' => 'required|integer|min:0', 'offline_output' => 'required|array', 'reviewer_type' => 'required|in:offline,human', 'reason' => 'required|string|max:2000'])->validate();
        if (strlen(NovelIntentService::canonical($d)) > 1000000) {
            throw new NovelProblem('REQUEST_TOO_LARGE', '评审响应超过一百万字节。', 422);
        }

        return DB::transaction(function () use ($actor, $novel, $d, $key, $origin, $frozen) {
            app(NovelIntentService::class)->novel($actor, $novel, true);
            $where = ['actor_id' => $actor->id, 'novel_scope' => $novel, 'command' => $origin ? 'review.generated' : 'review.replay', 'key' => $key];
            if ($old = DB::table('novel_commands')->where($where)->first()) {
                if ($old->request_hash !== $this->hash($d)) {
                    throw new NovelProblem('IDEMPOTENCY_CONFLICT', '评审操作键已用于其他输入。');
                }

                return json_decode(NovelIntentService::canonical(json_decode($old->response, true)), true, flags: JSON_THROW_ON_ERROR);
            }
            $current = $this->snapshot($actor, $novel, $d, $frozen === null);
            if ($frozen && $this->hash($frozen) !== $this->hash($current)) {
                throw new NovelProblem('DEPENDENCY_MISMATCH', '评审冻结上下文已变化。');
            }
            $input = $frozen ?? $current;
            $output = $d['offline_output'];
            $issues = [];
            $scores = [];
            foreach ($input['hard'] as $hard) {
                $issues[] = ['origin' => 'hard', 'category' => 'structure', 'severity' => 'blocking', 'message' => '正文或完整E03硬门禁未通过：'.$hard['code'], 'suggested_fix' => '先定位原文与冲突事实；修正来源或仅修正误提取，再重新评审。', 'details' => $hard];
            }
            // 响应证据全部通过后才写入评分，失败响应仍保留原始声明。
            try {
                if (app(StructuredOutput::class)->issues(json_decode(NovelIntentService::canonical($output)), ReviewContract::schema())) {
                    throw new NovelProblem('INVALID_REVIEW_OUTPUT', '七维评分或问题结构不符合契约。', 422);
                }
                // C04有界Schema子集不支持数值区间或枚举，由业务门禁明确复核。
                Validator::make($output, ['scores.*.score' => 'required|integer|min:1|max:5', 'scores.*.reason' => 'required|string|max:2000', 'scores.*.evidence.quote' => 'required|string|max:2000', 'issues.*.category' => 'required|in:structure,plot,character,causality,pace,dialogue,naturalness,appeal,style,repetition', 'issues.*.severity' => 'required|in:blocking,warning,info', 'issues.*.message' => 'required|string|max:2000', 'issues.*.suggested_fix' => 'required|string|max:2000', 'issues.*.evidence.quote' => 'required|string|max:2000'])->validate();
                foreach ($output['scores'] as $dim => $score) {
                    $this->checkEvidence($input, $score['evidence']);
                }
                foreach ($output['issues'] as $issue) {
                    $this->checkEvidence($input, $issue['evidence']);
                }
                $scores = $output['scores'];
                foreach ($output['issues'] as $issue) {
                    $issues[] = $issue + ['origin' => 'model', 'details' => []];
                }
            } catch (NovelProblem|ValidationException $err) {
                $issues[] = ['origin' => 'hard', 'category' => 'structure', 'severity' => 'blocking', 'message' => $err->getMessage(), 'suggested_fix' => '保留正文，修正七维评分与实际引文后另建评审。', 'details' => ['code' => $err instanceof NovelProblem ? $err->errorCode : 'INVALID_REVIEW_OUTPUT', 'claimed_output' => $output]];
            }
            $policy = $input['policy']['content'];
            // 低分维度也必须保留原句与局部修复方向，不能只有一个不通过总分。
            foreach ($scores as $dim => $score) {
                if ($score['score'] < $policy['minimums'][$dim] || $score['score'] < (float) $policy['average']) {
                    $issues[] = ['origin' => 'model', 'category' => $dim, 'severity' => 'warning', 'message' => ReviewContract::LABELS[$dim].'评分偏低：'.$score['reason'], 'suggested_fix' => '围绕所引句子及其前后承接，按冻结文风检查'.ReviewContract::LABELS[$dim].'；保留事件、线索与人物限制，局部修改后重新提取和评审。', 'evidence' => $score['evidence'], 'details' => ['score' => $score['score'], 'minimum' => $policy['minimums'][$dim]]];
                }
            }
            $issues = [...$issues, ...app(StyleSignals::class)->inspect($input['context']['body'], $policy, $input['context']['style']['content'], $input['context']['previous_body'])];
            $average = $scores ? array_sum(array_column($scores, 'score')) / 7 : null;
            $literary = (bool) $scores && $average >= (float) $policy['average'];
            foreach ($scores as $dim => $score) {
                if ($score['score'] < $policy['minimums'][$dim]) {
                    $literary = false;
                }
            }
            $hardPass = ! collect($issues)->contains(fn ($i) => $i['severity'] === 'blocking' || $i['category'] === 'structure');
            $report = ['verdict' => $hardPass && $literary ? 'pass' : 'block', 'hard_pass' => $hardPass, 'literary_pass' => $literary, 'scores' => $scores, 'average' => $average === null ? null : rtrim(rtrim(number_format($average, 6, '.', ''), '0'), '.'), 'thresholds' => $policy, 'unresolved_blocking' => count(array_filter($issues, fn ($i) => $i['severity'] === 'blocking' || $i['category'] === 'structure')), 'canonical' => false, 'human_acceptance' => false, 'naturalness_confirmed_by_human' => $d['reviewer_type'] === 'human' && $hardPass && $literary];
            $id = (string) DB::table('reviews')->insertGetId(['novel_id' => $novel, 'chapter_version_id' => $input['chapter_version_id'], 'extraction_id' => $input['extraction_id'], 'policy_version_id' => $input['policy']['id'], 'source_id' => $input['source_id'], 'body_hash' => $input['body_hash'], 'context_hash' => $this->hash($input['context']), 'validation_hash' => $input['validation_hash'], 'frozen_input' => NovelIntentService::canonical($input), 'input_hash' => $this->hash($input), 'output' => NovelIntentService::canonical($output), 'output_hash' => $this->hash($output), 'report' => NovelIntentService::canonical($report), 'reviewer_type' => $d['reviewer_type'], 'run_id' => $origin['run_id'] ?? null, 'artifact_id' => $origin['artifact_id'] ?? null, 'actor_id' => $actor->id, 'reason' => $d['reason']]);
            foreach ($issues as $i) {
                $evidence = isset($i['evidence']) ? app(StoryStateService::class)->evidence($actor, $novel, $input['source_id'], $input['body_hash'], $i['evidence']['start'], $i['evidence']['end'], $i['evidence']['quote']) : null;
                DB::table('review_issues')->insert(['novel_id' => $novel, 'review_id' => $id, 'evidence_id' => $evidence, 'category' => $i['category'], 'severity' => $i['severity'], 'origin' => $i['origin'], 'message' => $i['message'], 'suggested_fix' => $i['suggested_fix'], 'details' => NovelIntentService::canonical($i['details'] ?? [])]);
            }
            $r = json_decode(NovelIntentService::canonical($this->read($actor, $novel, $id)), true, flags: JSON_THROW_ON_ERROR);
            DB::table('novel_commands')->insert($where + ['request_hash' => $this->hash($d), 'response' => NovelIntentService::canonical($r), 'created_at' => now()]);

            return $r;
        });
    }

    private function checkEvidence(array $input, array $e): void
    {
        if ($e['start'] < 0 || $e['end'] <= $e['start'] || $e['end'] > mb_strlen($input['context']['body']) || mb_substr($input['context']['body'], $e['start'], $e['end'] - $e['start']) !== $e['quote']) {
            throw new NovelProblem('INVALID_REVIEW_EVIDENCE', '评审引文与实际正文码点位置不匹配。', 422);
        }
    }

    public function read(User $actor, string $novel, string $id): array
    {
        app(NovelIntentService::class)->novel($actor, $novel);
        Validator::make(['id' => $id], ['id' => 'required|integer|min:1'])->validate();
        $row = DB::table('reviews')->where('novel_id', $novel)->find($id) ?? throw new NovelProblem('NOT_FOUND', '评审不存在。', 404);
        $r = (array) $row;
        foreach ($r as $k => $v) {
            if ($v !== null && ($k === 'id' || str_ends_with($k, '_id'))) {
                $r[$k] = (string) $v;
            }
        }
        foreach (['frozen_input', 'output', 'report'] as $k) {
            $r[$k] = json_decode($r[$k], true);
        }
        if ($this->hash($r['frozen_input']) !== $r['input_hash'] || $this->hash($r['output']) !== $r['output_hash'] || $this->hash($r['frozen_input']['context']) !== $r['context_hash']) {
            throw new NovelProblem('INVALID_REVIEW_HASH', '评审内容哈希失配。');
        }
        try {
            $f = $r['frozen_input'];
            $current = $this->snapshot($actor, $novel, ['chapter_version_id' => $r['chapter_version_id'], 'extraction_id' => $r['extraction_id'], 'policy_version_id' => $r['policy_version_id'], 'body_hash' => $r['body_hash']], false);
            $stale = $this->hash($current) !== $this->hash($f);
        } catch (NovelProblem) {
            $stale = true;
        }
        $r['created_at'] = CarbonImmutable::parse($r['created_at'])->toISOString();
        $r['stale'] = $stale;
        $r['display_status'] = $stale ? 'stale' : $r['report']['verdict'];
        $r['current_eligible'] = ! $stale && $r['report']['verdict'] === 'pass';
        $r['issues'] = DB::table('review_issues as i')->leftJoin('evidence_spans as e', 'e.id', '=', 'i.evidence_id')->where('i.review_id', $id)->orderBy('i.id')->get(['i.*', 'e.start_offset', 'e.end_offset', 'e.quote'])->map(function ($i) {
            $a = (array) $i;
            foreach (['id', 'novel_id', 'review_id', 'evidence_id'] as $k) {
                if ($a[$k] !== null) {
                    $a[$k] = (string) $a[$k];
                }
            }$a['details'] = json_decode($a['details'], true);
            $a['decision'] = DB::table('review_issue_decisions')->where('issue_id', $i->id)->orderByDesc('id')->first();

            return $a;
        })->all();

        return $r;
    }

    public function listing(User $actor, string $novel): array
    {
        app(NovelIntentService::class)->novel($actor, $novel);

        return DB::table('reviews')->where('novel_id', $novel)->orderByDesc('id')->limit(50)->pluck('id')->map(fn ($id) => $this->read($actor, $novel, (string) $id))->all();
    }

    /** 人工意见仅针对非阻塞文学提示，不改评分、报告或正式状态。 */
    public function decide(User $actor, string $novel, string $review, array $d, string $key): array
    {
        Validator::make($d, ['issue_id' => 'required|integer|min:1', 'decision' => 'required|in:accepted,rejected', 'reason' => 'required|string|max:2000'])->validate();
        Validator::make(['key' => $key], ['key' => ['required', 'string', 'max:128', 'regex:/^[\x20-\x7E]+$/D']])->validate();
        if (array_diff(array_keys($d), ['issue_id', 'decision', 'reason'])) {
            throw new NovelProblem('INVALID_DECISION', '意见含未知字段。', 422);
        }

        return DB::transaction(function () use ($actor, $novel, $review, $d, $key) {
            app(NovelIntentService::class)->novel($actor, $novel, true);
            $where = ['actor_id' => $actor->id, 'novel_scope' => $novel, 'command' => 'review.decide', 'key' => $key];
            $hash = $this->hash([$review, $d]);
            if ($old = DB::table('novel_commands')->where($where)->first()) {
                if ($hash !== $old->request_hash) {
                    throw new NovelProblem('IDEMPOTENCY_CONFLICT', '意见键冲突。');
                }

                return json_decode(NovelIntentService::canonical(json_decode($old->response, true)), true, flags: JSON_THROW_ON_ERROR);
            }
            $r = $this->read($actor, $novel, $review);
            if ($r['stale']) {
                throw new NovelProblem('STALE_REVIEW', '旧评审只能查阅，不能追加当前接受意见。');
            }
            $i = collect($r['issues'])->firstWhere('id', (string) $d['issue_id']);
            if (! $i || $i['origin'] === 'hard' || $i['category'] === 'structure' || $i['severity'] === 'blocking') {
                throw new NovelProblem('HARD_ISSUE_NOT_WAIVABLE', '硬错误或阻塞项不能人工豁免。');
            }
            $id = (string) DB::table('review_issue_decisions')->insertGetId(['novel_id' => $novel, 'review_id' => $review, 'issue_id' => $d['issue_id'], 'decision' => $d['decision'], 'actor_id' => $actor->id, 'reason' => $d['reason']]);
            $result = ['decision_id' => $id, 'review_id' => $review, 'report_unchanged' => true];
            $result = json_decode(NovelIntentService::canonical($result), true, flags: JSON_THROW_ON_ERROR);
            DB::table('novel_commands')->insert($where + ['request_hash' => $hash, 'response' => NovelIntentService::canonical($result), 'created_at' => now()]);

            return $result;
        });
    }
}
