<?php

namespace App\Services;

use App\Billing\TokenMeter;
use App\Models\Novel;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;

/** UI14只读投影：正式进度限定活动基线，历史费用不因分支变化消失。 */
class WorkspaceProductionService
{
    public function read(User $actor, string $id, array $chapterIds = []): array
    {
        app(NovelIntentService::class)->novel($actor, $id);
        Validator::make(['chapters' => $chapterIds], ['chapters' => 'array|max:100', 'chapters.*' => 'integer|min:1|max:9223372036854775807'])->validate();

        return DB::transaction(function () use ($actor, $id, $chapterIds) {
            $book = Novel::whereKey($id)->where('owner_id', $actor->id)->sharedLock()->firstOrFail();
            $revision = $book->active_canonical_revision_id ? DB::table('canonical_revisions')->where('novel_id', $id)->where('id', $book->active_canonical_revision_id)->where('status', 'active')->first() : null;
            $state = $revision ? 'ready' : ($book->active_canonical_revision_id ? 'unavailable' : 'uninitialized');
            $count = null;
            $next = null;
            $versions = [];
            $issues = null;
            $pending = null;
            if ($revision) {
                $maps = $this->formal($id, (string) $revision->id);
                $stats = (clone $maps)->selectRaw('count(*)::int AS count, min(m.sequence) AS first, max(m.sequence) AS last')->first();
                // 不能把最大章序或零散映射冒充连续正式前缀。
                $valid = (int) $stats->count === (int) $revision->cursor && ((int) $revision->cursor === 0 || ((int) $stats->first === 1 && (int) $stats->last === (int) $revision->cursor));
                $valid = $valid && DB::table('canonical_chapters')->where('novel_id', $id)->where('revision_id', $revision->id)->count() === (int) $stats->count;
                if (! $valid) {
                    $state = 'unavailable';
                } else {
                    $count = (int) $stats->count;
                    $next = DB::table('chapters')->where('novel_id', $id)->where('sequence', $count + 1)->first(['id', 'sequence', 'title']);
                    $next = $next ? ['id' => (string) $next->id, 'sequence' => (int) $next->sequence, 'title' => $next->title] : null;
                    $issues = DB::table('review_issues')->where('novel_id', $id)->whereIn('review_id', (clone $maps)->select('c.review_id'))->count();
                    // 只读取当前页的正式版本和提交所用历史评审，不用新候选覆盖正式身份。
                    $versions = (clone $maps)->join('reviews as r', 'r.id', '=', 'c.review_id')->where('r.novel_id', $id)->whereColumn('r.chapter_version_id', 'v.id')->whereIn('m.chapter_id', $chapterIds)->get(['m.chapter_id', 'm.chapter_version_id', 'm.commit_id', 'v.version', 'c.review_id', 'r.report'])->mapWithKeys(fn ($r) => [(string) $r->chapter_id => ['id' => (string) $r->chapter_version_id, 'version' => (int) $r->version, 'commit_id' => (string) $r->commit_id, 'review' => ['id' => (string) $r->review_id, 'verdict' => json_decode($r->report, true, flags: JSON_THROW_ON_ERROR)['verdict'], 'scope' => 'at_commit']]])->all();
                    $pending = DB::table('canonical_outbox as o')->leftJoin('canonical_memory_tasks as t', 't.outbox_id', '=', 'o.id')->where('o.novel_id', $id)->where('o.revision_id', $revision->id)->whereIn('o.commit_id', (clone $maps)->select('c.id'))->where(fn ($q) => $q->whereNull('t.id')->orWhere('t.status', '<>', 'complete'))->count();
                }
            }
            // 最近运行是本书历史记录，不代表活动基线可继续运行或Worker在线。
            $run = DB::table('generation_runs')->where('novel_id', $id)->orderByDesc('id')->first(['id', 'status', 'expected_state_version', 'created_at']);
            $run = $run ? ['id' => (string) $run->id, 'status' => $run->status, 'expected_state_version' => (int) $run->expected_state_version, 'created_at' => CarbonImmutable::parse($run->created_at)->toISOString(), 'url' => '/admin/run-center?'.http_build_query(['novel' => $id, 'run' => $run->id])] : null;

            return ['production' => [
                'canonical_baseline' => $revision ? ['id' => (string) $revision->id, 'version' => (int) $revision->version] : null,
                'committed_chapters' => $count, 'next_chapter' => $next, 'review_issues' => $issues,
                'today_cost' => $this->costs($id), 'budget_remaining' => $this->budget($id),
                'worker_heartbeat' => null, 'index_lag' => $pending, 'ending_readiness' => null, 'recent_run' => $run,
            ], 'production_state' => $state, 'formal_versions' => $versions, 'capabilities' => $this->capabilities($book)];
        });
    }

    private function formal(string $novel, string $revision): Builder
    {
        return DB::table('canonical_chapters as m')->join('canonical_commits as c', 'c.id', '=', 'm.commit_id')->join('chapter_versions as v', 'v.id', '=', 'm.chapter_version_id')
            ->where('m.novel_id', $novel)->where('m.revision_id', $revision)->where('c.novel_id', $novel)->where('c.revision_id', $revision)->where('c.status', 'committed')
            ->whereColumn('c.chapter_version_id', 'm.chapter_version_id')->whereColumn('c.chapter_id', 'm.chapter_id')->whereColumn('c.chapter_sequence', 'm.sequence')
            ->where('v.novel_id', $novel)->whereColumn('v.node_id', 'm.chapter_id')->whereColumn('v.body_hash', 'm.body_hash');
    }

    /** 与账本一致按请求发起的UTC日统计，金额全部由PostgreSQL十进制聚合。 */
    private function costs(string $id): array
    {
        $start = CarbonImmutable::now('UTC')->startOfDay();
        $requests = DB::table('ai_requests')->where('novel_id', $id)->where('created_at', '>=', $start)->where('created_at', '<', $start->addDay());
        $rows = DB::table('budget_reservations as b')->joinSub($requests->select('id'), 'r', 'r.id', '=', 'b.request_id')
            ->selectRaw("b.currency, count(*)::int AS requests, count(*) FILTER (WHERE b.status <> 'settled')::int AS unknown_count, sum(b.actual_cost)::numeric(24,10)::text AS settled_cost, coalesce(sum(b.amount) FILTER (WHERE b.status <> 'settled'),0)::numeric(24,10)::text AS risk_reserved")
            ->groupBy('b.currency')->orderBy('b.currency')->get()->map(fn ($r) => (array) $r)->all();
        $missing = (clone $requests)->whereNotIn('id', DB::table('budget_reservations')->select('request_id'))->count();

        return ['day' => $start->toDateString(), 'timezone' => 'UTC', 'rows' => $rows, 'unbudgeted_requests' => $missing, 'state' => $missing || collect($rows)->sum('unknown_count') ? 'unknown' : ($rows ? 'known' : 'empty'), 'evidence_kind' => 'offline_simulation'];
    }

    /** 没有全书默认政策：仅展示最近预留所用政策的全书风险余量，不能当作新运行授权。 */
    private function budget(string $id): ?array
    {
        $ref = DB::table('budget_reservations as b')->join('ai_requests as r', 'r.id', '=', 'b.request_id')->where('r.novel_id', $id)->orderByDesc('b.id')->first(['b.policy_id', 'b.request_id', 'b.currency']);
        if (! $ref) {
            return null;
        }
        $policy = DB::table('budget_policies')->where('id', $ref->policy_id)->first();
        $limits = json_decode($policy->limits, true, flags: JSON_THROW_ON_ERROR);
        $limit = app(TokenMeter::class)->percent($limits['book'], $policy->stop_percent);
        $bucket = DB::table('budget_buckets')->where('scope_type', 'book')->where('scope_key', $id)->where('currency', $ref->currency)->first();
        if (! $bucket) {
            return null;
        }
        $exposure = DB::table('budget_allocations as a')->join('budget_reservations as b', 'b.id', '=', 'a.reservation_id')->where('a.bucket_id', $bucket->id)->selectRaw("coalesce(sum(coalesce(b.actual_cost,b.amount)),0)::numeric(24,10)::text AS total, count(*) FILTER (WHERE b.issue_code='ACTUAL_EXCEEDS_RESERVATION')::int AS reconciliation_count")->first();
        $remaining = DB::selectOne('SELECT (?::numeric - ?::numeric)::numeric(24,10)::text AS amount', [$limit, $exposure->total])->amount;

        return ['policy_id' => (string) $policy->id, 'policy_version' => (int) $policy->version, 'request_id' => (string) $ref->request_id, 'currency' => $ref->currency, 'stop_limit' => $limit, 'exposure' => $exposure->total, 'remaining' => $remaining, 'reconciliation_required' => $exposure->reconciliation_count > 0, 'unbudgeted_requests' => DB::table('ai_requests')->where('novel_id', $id)->whereNotIn('id', DB::table('budget_reservations')->select('request_id'))->count()];
    }

    /** 入口只导航到现有受权页面；生产写操作仍由目标服务重新核验。 */
    private function capabilities(Novel $book): array
    {
        $id = (string) $book->id;
        $nav = app(NovelNavigationService::class);
        $items = [];
        foreach (['FormalReader' => '打开阅读器', 'ExportCenter' => '导出', 'RunCenter' => '查看运行', 'ReviewCenter' => '查看评审', 'CanonicalCommitCenter' => '查看正式提交', 'CanonicalMemoryCenter' => '查看正式记忆', 'RecoveryDesk' => '运行恢复'] as $page => $label) {
            $items[] = ['label' => $label, 'available' => true, 'url' => $nav->destination($page, $id), 'reason' => null];
        }
        $reason = $book->status === 'archived' ? '小说已归档，请先恢复；真实生产启动尚未接入。' : '真实生产启动尚未接入；规划冻结不会开放生成。';
        foreach (['生成下一章', '生成连续范围'] as $label) {
            $items[] = ['label' => $label, 'available' => false, 'url' => null, 'reason' => $reason];
        }

        return $items;
    }
}
