<?php

namespace App\Filament\Pages;

use App\Exceptions\NovelProblem;
use App\Services\CanonicalCommitService;
use App\Services\NovelIntentService;
use Filament\Pages\Page;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Locked;
use Livewire\Attributes\Url;

/** UI15展示预检和正式回执，没有绕过门禁或原地重写历史的动作。 */
class CanonicalCommitCenter extends Page
{
    protected static ?string $title = '正式提交与基线';

    protected static string|\UnitEnum|null $navigationGroup = '生产与质量';

    protected string $view = 'filament.canonical-commit-center';

    #[Url]
    public string $novel = '';

    public string $candidate = '';

    public array $form = [];

    public string $chapterFilter = '';

    public string $revisionFilter = '';

    public string $errorFilter = '';

    public string $from = '';

    public string $to = '';

    public string $statusFilter = '';

    public int $page = 1;

    public string $notice = '';

    #[Locked]
    public ?array $preflight = null;

    #[Locked]
    public ?array $receipt = null;

    #[Locked]
    public string $commandKey = '';

    public function mount(): void
    {
        $this->commandKey = (string) Str::uuid();
    }

    public static function canAccess(): bool
    {
        return (bool) auth()->user()?->is_admin;
    }

    public function books(): array
    {
        return DB::table('novels')->where('owner_id', auth()->id())->get(['id', 'title'])->all();
    }

    public function updatedNovel(): void
    {
        $this->form = [];
        $this->preflight = $this->receipt = null;
        $this->candidate = '';
        $this->commandKey = (string) Str::uuid();
    }

    public function state(): ?array
    {
        if ($this->novel === '') {
            return null;
        }
        $filters = ['page' => $this->page];
        foreach (['chapterFilter' => 'chapter_id', 'revisionFilter' => 'revision_id', 'errorFilter' => 'error_code', 'statusFilter' => 'status', 'from' => 'from', 'to' => 'to'] as $p => $k) {
            if ($this->$p !== '') {
                $filters[$k] = $this->$p;
            }
        }

        return app(CanonicalCommitService::class)->listing(auth()->user(), $this->novel, $filters);
    }

    public function candidates(): array
    {
        if ($this->novel === '') {
            return [];
        }
        app(NovelIntentService::class)->novel(auth()->user(), $this->novel);

        return DB::table('chapter_versions')->where('novel_id', $this->novel)->orderByDesc('id')->limit(100)->get(['id', 'node_id', 'version', 'title'])->all();
    }

    public function initialize(): void
    {
        $this->attempt(function () {
            $book = app(NovelIntentService::class)->novel(auth()->user(), $this->novel);
            $root = app(CanonicalCommitService::class)->initialize(auth()->user(), $this->novel, ['expected_state_version' => $book->state_version, 'reason' => '作者初始化空根正式基线'], (string) Str::uuid());
            // 重复初始化不会清除既有正式历史，提示必须与实际状态一致。
            $this->notice = $root['state_version'] === 0 ? '空根基线已建立，尚无正式正文或剧情事件。' : '当前正式基线已存在，保留既有正式历史。';
        });
    }

    public function loadCandidate(): void
    {
        $this->preflight = $this->receipt = null;
        $this->attempt(function () {
            $book = app(NovelIntentService::class)->novel(auth()->user(), $this->novel);
            $body = DB::table('chapter_versions')->where('novel_id', $this->novel)->where('id', $this->candidate)->first();
            if (! $body) {
                throw new NovelProblem('NOT_FOUND', '候选正文不存在。', 404);
            }
            $review = DB::table('reviews')->where('novel_id', $this->novel)->where('chapter_version_id', $body->id)->orderByDesc('id')->first();
            $outline = DB::table('rolling_plans')->where('id', $body->plan_id)->value('outline_version_id');
            $this->form = ['novel_id' => $this->novel, 'chapter_id' => (string) $body->node_id, 'chapter_version_id' => (string) $body->id, 'review_id' => $review ? (string) $review->id : '', 'expected_revision_id' => $book->active_canonical_revision_id ? (string) $book->active_canonical_revision_id : '', 'expected_state_version' => $book->state_version, 'plan_node_version_id' => (string) DB::table('plan_nodes')->where('outline_version_id', $outline)->where('chapter_id', $body->node_id)->where('node_type', 'chapter')->value('id'), 'event_artifact_id' => $review ? (string) $review->extraction_id : '', 'reason' => ''];
            $this->commandKey = (string) Str::uuid();
        });
    }

    public function check(): void
    {
        $this->preflight = null;
        $this->attempt(function () {
            $this->preflight = app(CanonicalCommitService::class)->preflight(auth()->user(), $this->novel, $this->form, (string) Str::uuid());
            $this->notice = $this->preflight['status'] === 'ready' ? '预检通过；提交时仍将重验全部门禁。' : $this->preflight['message'];
        });
    }

    public function submit(): void
    {
        $this->attempt(function () {
            if (! $this->preflight || $this->preflight['status'] !== 'ready') {
                throw new NovelProblem('INVALID_PREFLIGHT', '请先完成本次预检。');
            }
            $this->receipt = app(CanonicalCommitService::class)->commit(auth()->user(), $this->novel, $this->form + ['validation_artifact_id' => $this->preflight['id']], $this->commandKey);
            $this->notice = '正式提交已完成；后续记忆意图已持久保存，E07尚未接入消费。';
        });
    }

    public function inspect(string $id): void
    {
        $this->attempt(fn () => $this->receipt = app(CanonicalCommitService::class)->read(auth()->user(), $this->novel, $id));
    }

    /** 筛选变更返回第一页，保留按章与时间检索较早历史的能力。 */
    public function updated(string $property): void
    {
        if (in_array($property, ['chapterFilter', 'revisionFilter', 'errorFilter', 'statusFilter', 'from', 'to', 'novel'], true)) {
            $this->page = 1;
        }
    }

    public function older(): void
    {
        if (($this->state()['next_page'] ?? null) !== null) {
            $this->page++;
        }
    }

    public function newer(): void
    {
        $this->page = max(1, $this->page - 1);
    }

    private function attempt(callable $fn): void
    {
        $this->notice = '';
        try {
            $fn();
        } catch (NovelProblem|ValidationException $e) {
            $this->notice = $e instanceof NovelProblem ? $e->getMessage() : implode(' ', $e->validator->errors()->all());
        }
    }
}
