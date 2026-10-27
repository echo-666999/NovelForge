<?php

namespace App\Filament\Pages;

use App\Exceptions\NovelProblem;
use App\Filament\Concerns\HasNovelNavigation;
use App\Filament\Concerns\HasProductionChoices;
use App\Services\ContextAssemblyService;
use App\Services\NovelIntentService;
use App\Services\NovelNavigationService;
use Filament\Pages\Page;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Locked;
use Livewire\Attributes\Url;

/** UI23 读取冻结文本与历史；重组始终追加清单，硬约束没有移除入口。 */
class ContextInspector extends Page
{
    use HasNovelNavigation;
    use HasProductionChoices;

    // UI03：分组与顺序显式固定，不依赖页面发现次序。
    protected static string|\UnitEnum|null $navigationGroup = '生产与质量';

    protected static ?int $navigationSort = 60;

    protected static ?string $title = '上下文检查器';

    protected string $view = 'filament.context-inspector';

    #[Url]
    public string $step = '';

    public string $manifest = '';

    public string $comparison = '';

    public string $category = '';

    public string $channel = '';

    public string $trim = '';

    public bool $requiredOnly = false;

    public string $notice = '';

    #[Locked]
    public ?array $detail = null;

    #[Locked]
    public ?array $difference = null;

    public static function canAccess(): bool
    {
        return (bool) auth()->user()?->is_admin;
    }

    public function steps(): array
    {
        return DB::table('generation_steps as s')->join('novels as n', 'n.id', '=', 's.novel_id')->where('n.owner_id', auth()->id())->when($this->novel !== '', fn ($query) => $query->where('s.novel_id', $this->novel))->orderByDesc('s.id')->limit(100)->get(['s.id', 's.stage', 's.scope_key', 'n.title'])->all();
    }

    public function inspect(): void
    {
        $this->detail = null;
        $this->difference = null;
        $this->attempt(function () {
            $this->assertBookStep();
            $this->detail = app(ContextAssemblyService::class)->read(auth()->user(), $this->step, $this->manifest ?: null);
        });
    }

    public function compare(): void
    {
        $this->difference = null;
        $this->attempt(function () {
            $this->assertBookStep();
            $this->difference = app(ContextAssemblyService::class)->compare(auth()->user(), $this->step, $this->manifest, $this->comparison);
        });
    }

    public function reassemble(): void
    {
        $this->attempt(function () {
            $this->assertBookStep();
            // 先重新授权并从数据库取原始参数，不能信任浏览器回传的快照内容。
            $current = app(ContextAssemblyService::class)->read(auth()->user(), $this->step, $this->manifest ?: null);
            $row = DB::table('context_manifests')->find($current['id']);
            $input = json_decode($row->input, true, flags: JSON_THROW_ON_ERROR);
            $input['expected_version'] = app(NovelIntentService::class)->novel(auth()->user(), $current['novel_id'])->row_version;
            $this->detail = app(ContextAssemblyService::class)->assemble(auth()->user(), $this->step, $input, (string) Str::uuid());
            $this->manifest = $this->detail['id'];
            $this->notice = '已追加新的组装清单，尚未发送模型请求，也未修改阶段尝试。';
        });
    }

    public function items(): array
    {
        // 每次渲染重新授权，避免更改阶段后仍显示其他书的已载入文本。
        if (! $this->detail) {
            return [];
        }
        $detail = app(ContextAssemblyService::class)->read(auth()->user(), $this->step, $this->detail['id']);

        return array_values(array_filter($detail['items'], fn ($i) => (! $this->requiredOnly || $i['required']) && ($this->category === '' || $i['category'] === $this->category) && ($this->channel === '' || isset($i['channels'][$this->channel])) && ($this->trim === '' || $i['trim_reason'] === $this->trim)));
    }

    // 阶段来源也要与身份条同书；旧的仅step深链接仍可从授权阶段解析小说。
    private function assertBookStep(): void
    {
        if ($this->step === '') {
            return;
        }
        $book = app(NovelNavigationService::class)->referenceNovel(auth()->user(), 'generation_steps', $this->step);
        if ($this->novel !== '' && $this->novel !== $book) {
            throw new NovelProblem('NOT_FOUND', '阶段不属于当前小说。', 404);
        }
        $this->novel = $book;
    }

    public function updatedNovel(): void
    {
        $this->reset('step', 'manifest', 'comparison', 'detail', 'difference', 'category', 'channel', 'trim', 'requiredOnly', 'notice');
    }

    public function updatedStep(): void
    {
        $this->detail = null;
        $this->difference = null;
        $this->manifest = '';
        $this->comparison = '';
    }

    private function attempt(callable $fn): void
    {
        $this->beginProductionAction();
        $this->notice = '';
        try {
            $fn();
        } catch (NovelProblem|ValidationException $e) {
            if ($e instanceof ValidationException) {
                $this->productionErrors($e);
            }
            $this->notice = $e instanceof NovelProblem ? $e->getMessage() : implode(' ', $e->validator->errors()->all());
        }
    }
}
