<?php

namespace App\Services;

use App\Models\BlueprintApproval;
use App\Models\BlueprintVersion;
use App\Models\Chapter;
use App\Models\Novel;
use App\Models\RollingPlan;
use App\Models\User;
use App\Models\WorldEntity;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;

class WorkspaceService
{
    public const LINKS = ['workspace' => '创作工作台', 'intent' => '原始要求', 'blueprints' => '蓝图与终局', 'structure' => '五层结构', 'world' => '世界与人物', 'plans' => '章节场景计划'];

    public function navigation(User $actor, string $id): array
    {
        $novel = app(NovelIntentService::class)->novel($actor, $id);

        // 每条链接显式携带同一本书，不用跨标签页共享的“当前小说”会话变量。
        return ['id' => $novel->id, 'title' => $novel->title, 'status' => $novel->status, 'baseline' => null, 'links' => collect(self::LINKS)->map(fn ($label, $key) => ['label' => $label, 'url' => '/admin/novels/'.$novel->id.'/'.$key])->all()];
    }

    public function overview(User $actor, array $filters = []): array
    {
        app(NovelIntentService::class)->authorize($actor);
        $d = Validator::make($filters, ['status' => ['nullable', Rule::in(['draft', 'planning', 'ready', 'generating', 'paused', 'completed', 'archived'])], 'from' => 'nullable|date_format:Y-m-d', 'until' => 'nullable|date_format:Y-m-d|after_or_equal:from', 'page' => 'sometimes|integer|min:1', 'limit' => ['sometimes', Rule::in([10, 25, 50, 100])]])->validate();
        $q = Novel::where('owner_id', $actor->id);
        if (! empty($d['status'])) {
            $q->where('status', $d['status']);
        }
        // 日期采用作者时区边界，数据库仍保存统一时间。
        if (! empty($d['from'])) {
            $q->where('updated_at', '>=', CarbonImmutable::parse($d['from'], 'Asia/Shanghai')->startOfDay()->utc());
        }
        if (! empty($d['until'])) {
            $q->where('updated_at', '<', CarbonImmutable::parse($d['until'], 'Asia/Shanghai')->addDay()->startOfDay()->utc());
        }
        $rows = $q->orderByDesc('updated_at')->orderByDesc('id')->paginate($d['limit'] ?? 25, ['id', 'title', 'genre', 'status', 'target_chapters', 'updated_at'], 'page', $d['page'] ?? 1);

        return ['items' => $rows->items(), 'total' => $rows->total(), 'page' => $rows->currentPage(), 'last_page' => $rows->lastPage(), 'has_books' => Novel::where('owner_id', $actor->id)->exists()];
    }

    public function summary(User $actor, string $id, array $filters = []): array
    {
        $d = Validator::make($filters, ['from' => 'nullable|integer|min:1|max:100000', 'until' => 'nullable|integer|min:1|max:100000'.(! empty($filters['from']) ? '|gte:from' : ''), 'status' => ['nullable', Rule::in(['skeleton', 'detailed', 'ready', 'frozen', 'blocked', 'stale'])], 'page' => 'sometimes|integer|min:1', 'limit' => ['sometimes', Rule::in([10, 25, 50, 100])], 'issues_only' => 'sometimes|boolean'])->validate();

        return DB::transaction(function () use ($actor, $id, $d) {
            $intent = app(NovelIntentService::class);
            $intent->novel($actor, $id);
            // 只读共享锁与各写服务的小说行锁配合，防止同一摘要混入两次规划修改。
            $novel = Novel::whereKey($id)->sharedLock()->firstOrFail();
            $requirements = $intent->requirements($novel);
            $pending = collect($requirements)->filter(fn ($r) => ! in_array($r['decision_status'], ['accepted', 'waived']))->count();
            $problems = [];
            $add = function ($code, $message, $page) use (&$problems, $id) {
                $problems[] = ['code' => $code, 'message' => $message, 'url' => '/admin/novels/'.$id.'/'.$page];
            };
            if (! $requirements) {
                $add('REQUIREMENTS_MISSING', '请先保存原始输入并确认创作要求。', 'intent');
            }
            if ($pending) {
                $add('DECISIONS_PENDING', '有 '.$pending.' 项原始要求等待决定或解决冲突。', 'intent');
            }
            $approval = BlueprintApproval::where('novel_id', $id)->latest('id')->first();
            $blueprint = $approval ? BlueprintVersion::where('novel_id', $id)->select(['id', 'version', 'content_hash'])->find($approval->blueprint_version_id) : null;
            if (! $approval) {
                $add('BLUEPRINT_REQUIRED', '尚无批准蓝图，请完善蓝图并检查批准。', 'blueprints');
            }
            $entityCount = WorldEntity::where('novel_id', $id)->count();
            if (! $entityCount) {
                $add('WORLD_REQUIRED', '尚未建立世界与人物设定。', 'world');
            }
            $latest = RollingPlan::where('novel_id', $id)->where('status', 'sealed')->latest('version')->first();
            $plan = $latest ? app(RollingPlanService::class)->read($actor, $id, $latest->id) : null;
            if (! $plan) {
                $add('PLAN_REQUIRED', '尚无滚动计划；建立全书骨架、细化前十章并保存计划。', 'plans');
            }
            if ($plan) {
                foreach ($plan['report']['blockers'] as $b) {
                    $problems[] = $b + ['url' => '/admin/novels/'.$id.'/plans?selectedId='.$plan['id']];
                }
                if ($plan['report']['passed'] && ! $plan['freeze']) {
                    $add('PLAN_FREEZE_REQUIRED', '计划检查通过，尚需保存检查报告并冻结窗口。', 'plans');
                }
            }
            // 仅统计当前依赖仍有效的冻结窗口，历史冻结事实不会冒充当前资格。
            $frozen = [];
            $stale = [];
            foreach (RollingPlan::where('novel_id', $id)->whereIn('id', DB::table('plan_freezes')->select('rolling_plan_id'))->get() as $p) {
                $r = app(RollingPlanService::class)->read($actor, $id, $p->id);
                if ($r['report']['passed']) {
                    foreach (range($p->start_chapter, $p->end_chapter) as $n) {
                        $frozen[$n] = true;
                    }
                } else {
                    foreach (range($p->start_chapter, $p->end_chapter) as $n) {
                        $stale[$n] = true;
                    } if (! $latest || $p->id !== $latest->id) {
                        $add('FROZEN_PLAN_STALE', '冻结计划 v'.$p->version.' 的当前依赖存在阻塞，请打开计划复核。', 'plans');
                    }
                }
            }
            $chapters = Chapter::where('novel_id', $id)->orderBy('sequence')->get(['id', 'sequence', 'title', 'status']);
            $rows = $chapters->map(function ($c) use ($plan, $frozen, $stale) {
                $in = $plan && $c->sequence >= $plan['start_chapter'] && $c->sequence <= $plan['end_chapter'];
                $issues = $in ? array_values(array_filter($plan['report']['blockers'], fn ($b) => ! str_starts_with($b['path'] ?? '', 'chapter.') || preg_match('/^chapter\.'.$c->sequence.'(?:\.|$)/', $b['path']))) : [];

                return $c->toArray() + ['planning_status' => isset($frozen[$c->sequence]) ? 'frozen' : ($in ? $plan['display_status'] : (isset($stale[$c->sequence]) ? 'stale' : $c->status)), 'planning_issue_count' => count($issues), 'review' => null, 'formal_version' => null];
            });
            $rows = $rows->filter(fn ($r) => (empty($d['from']) || $r['sequence'] >= $d['from']) && (empty($d['until']) || $r['sequence'] <= $d['until']) && (empty($d['status']) || $r['planning_status'] === $d['status']) && (empty($d['issues_only']) || $r['planning_issue_count'] > 0))->values();
            $limit = (int) ($d['limit'] ?? 25);
            $page = (int) ($d['page'] ?? 1);
            $state = $novel->status === 'archived' ? 'archived' : ($pending ? 'needs_decision' : ($problems ? 'planning_blocked' : 'planning_frozen'));

            return [
                'novel' => $novel->only(['id', 'title', 'genre', 'status', 'target_chapters', 'chapter_length_policy', 'row_version']),
                'navigation' => $this->navigation($actor, $id), 'planning_status' => $state, 'pending_decisions' => $pending,
                'blueprint' => $blueprint?->only(['id', 'version', 'content_hash']), 'plan' => $plan ? ['id' => $plan['id'], 'version' => $plan['version'], 'status' => $plan['display_status'], 'start_chapter' => $plan['start_chapter'], 'end_chapter' => $plan['end_chapter'], 'passed' => $plan['report']['passed']] : null,
                'planning_counts' => ['chapters' => $chapters->count(), 'target' => $novel->target_chapters, 'valid_frozen_chapters' => count($frozen), 'entities' => $entityCount],
                'blockers' => $problems, 'chapters' => ['items' => $rows->slice(($page - 1) * $limit, $limit)->values()->all(), 'total' => $rows->count(), 'page' => $page, 'last_page' => max(1, (int) ceil($rows->count() / $limit))],
                // 尚未接入的真实生产指标保持 null，不把规划章数或 state_version 当正式章数。
                'production' => array_fill_keys(['canonical_baseline', 'committed_chapters', 'next_chapter', 'review_issues', 'today_cost', 'budget_remaining', 'worker_heartbeat', 'index_lag', 'ending_readiness', 'recent_run'], null),
                'generation_eligible' => false, 'unavailable_reason' => $novel->status === 'archived' ? '小说已归档，请从小说列表恢复后继续规划。' : '生成、运行、评审、正式提交、阅读器与导出尚未接入。',
            ];
        });
    }
}
