<?php

namespace App\Services;

use App\Exceptions\NovelProblem;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/** 只读的导航目录与小说上下文；不使用全局“当前书”会话，避免多标签页串书。 */
class NovelNavigationService
{
    public const GROUPS = ['创作工作台', '故事管理', '生产与质量', '检索与记忆', '配置与运维'];

    public const RESOURCE_PAGES = [
        'Workspace' => ['workspace', '创作工作台', '创作工作台'],
        'DraftEditor' => ['draft-editor', '候选正文', '创作工作台'],
        'NovelIntent' => ['intent', '原始要求', '故事管理'],
        'Blueprints' => ['blueprints', '蓝图与终局', '故事管理'],
        'Structure' => ['structure', '五层结构', '故事管理'],
        'RollingPlans' => ['plans', '章节场景计划', '故事管理'],
        'WorldLibrary' => ['world', '世界与人物', '故事管理'],
        'PlanningCandidates' => ['planning-candidates', '离线规划候选', '生产与质量'],
    ];

    public const PAGES = [
        'StoryHub' => ['story-hub', '故事管理', '故事管理'],
        'StoryTimeline' => ['story-timeline', '时间线与角色知识', '故事管理'],
        'ForeshadowLedger' => ['foreshadow-ledger', '伏笔与主支线账本', '故事管理'],
        'RunCenter' => ['run-center', '生成运行中心', '生产与质量'],
        'ExtractionLedger' => ['extraction-ledger', '正文提取与硬约束', '生产与质量'],
        'ReviewCenter' => ['review-center', '评审中心与策略', '生产与质量'],
        'RevisionDesk' => ['revision-desk', '受限修订工作台', '生产与质量'],
        'CanonicalCommitCenter' => ['canonical-commit-center', '正式提交与基线', '生产与质量'],
        'ContextInspector' => ['context-inspector', '上下文检查器', '生产与质量'],
        'RecoveryDesk' => ['recovery-desk', '运行恢复台', '生产与质量'],
        'MemoryLibrary' => ['memory-library', '分层记忆库', '检索与记忆'],
        'CanonicalMemoryCenter' => ['canonical-memory-center', '正式记忆与索引', '检索与记忆'],
        'SearchLab' => ['search-lab', '检索实验室与索引', '检索与记忆'],
    ];

    public function identity(User $actor, string $id): array
    {
        return DB::transaction(function () use ($actor, $id): array {
            $book = app(NovelIntentService::class)->novel($actor, $id);
            // 与提交者对小说行的写锁配合，避免身份条混读两次提交状态。
            $book = $book->newQuery()->where('owner_id', $actor->id)->whereKey($id)->sharedLock()->firstOrFail();
            $revision = $book->active_canonical_revision_id ? DB::table('canonical_revisions')
                ->where('novel_id', $id)->where('id', $book->active_canonical_revision_id)->where('status', 'active')
                ->first(['id', 'version', 'cursor', 'local_state_version']) : null;

            return ['id' => $id, 'title' => $book->title, 'status' => $book->status, 'state_version' => (int) $book->state_version,
                'baseline' => $revision ? ['id' => (string) $revision->id, 'version' => (int) $revision->version, 'cursor' => (int) $revision->cursor, 'state_version' => (int) $revision->local_state_version] : null,
                'baseline_status' => $revision ? 'ready' : ($book->active_canonical_revision_id ? 'unavailable' : 'uninitialized')];
        });
    }

    public function destination(string $page, string $id = ''): string
    {
        if (isset(self::RESOURCE_PAGES[$page])) {
            return $id === '' ? '/admin/novels' : '/admin/novels/'.$id.'/'.self::RESOURCE_PAGES[$page][0];
        }
        if (! isset(self::PAGES[$page])) {
            throw new NovelProblem('NOT_FOUND', '导航入口不存在。', 404);
        }

        return '/admin/'.self::PAGES[$page][0].($id === '' ? '' : '?'.http_build_query(['novel' => $id]));
    }

    public function context(User $actor, string $id, string $page): array
    {
        app(NovelIntentService::class)->authorize($actor);
        $book = $id === '' ? null : $this->identity($actor, $id);
        request()->attributes->set('nf.navigation.book', $id);
        $groups = [];
        if ($book) {
            foreach (self::RESOURCE_PAGES + self::PAGES as $key => [$slug, $label, $group]) {
                // 故事管理总入口与当前分组链接不重复铺放。
                if ($key === 'StoryHub') {
                    continue;
                }
                $groups[$group][] = ['label' => $label, 'url' => $this->destination($key, $id), 'active' => $key === $page];
            }
        }

        return ['book' => $book, 'page' => $page, 'active_group' => (self::RESOURCE_PAGES + self::PAGES)[$page][2] ?? '书内导航', 'groups' => $groups,
            'books' => DB::table('novels')->where('owner_id', $actor->id)->orderByDesc('updated_at')->orderByDesc('id')->get(['id', 'title', 'status'])->all()];
    }

    public function sidebarUrl(string $base): string
    {
        $id = request()->attributes->get('nf.navigation.book');
        $id ??= request()->route('record') ?? request()->query('novel', '');
        if ($id === '' || $id === null || ! auth()->user()) {
            return $base;
        }
        if (! is_string($id) && ! is_int($id)) {
            throw new NovelProblem('NOT_FOUND', '小说不存在。', 404);
        }
        app(NovelIntentService::class)->novel(auth()->user(), (string) $id);

        return $base.'?'.http_build_query(['novel' => (string) $id]);
    }

    public function referenceNovel(User $actor, string $table, string $id): string
    {
        // 白名单表和十进制ID均受控，畸形标识不能落入PostgreSQL类型转换错误。
        if (! in_array($table, ['generation_runs', 'generation_steps', 'chapter_versions', 'reviews'], true)
            || ! preg_match('/^[1-9][0-9]{0,18}$/D', $id) || (strlen($id) === 19 && strcmp($id, '9223372036854775807') > 0)) {
            throw new NovelProblem('NOT_FOUND', '来源不存在。', 404);
        }
        $novel = DB::table($table)->where('id', $id)->value('novel_id');
        if ($novel === null) {
            throw new NovelProblem('NOT_FOUND', '来源不存在。', 404);
        }
        app(NovelIntentService::class)->novel($actor, (string) $novel);

        return (string) $novel;
    }
}
