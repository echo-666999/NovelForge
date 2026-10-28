<?php

namespace App\Filament\Pages;

use App\Exceptions\NovelProblem;
use App\Filament\Concerns\HasNovelNavigation;
use App\Services\ExportService;
use App\Services\NovelIntentService;
use App\Services\ReadingService;
use Filament\Actions\Action;
use Filament\Pages\Page;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Locked;

/** E08默认任务列表，正常宽度动作打开结构化导出弹窗；没有发布平台动作。 */
class ExportCenter extends Page
{
    use HasNovelNavigation;

    protected static string|\UnitEnum|null $navigationGroup = '配置与运维';

    protected static ?int $navigationSort = 70;

    protected static ?string $title = '导出中心';

    protected string $view = 'filament.export-center';

    public array $form = ['scope' => 'formal', 'revision_id' => '', 'range' => ['type' => 'range', 'from' => 1, 'to' => 1, 'volume_id' => ''], 'format' => 'txt', 'options' => ['toc' => true, 'volume_titles' => true, 'author' => '', 'synopsis' => '', 'separator' => 'blank', 'filename' => '']];

    public string $formatFilter = '';

    public string $statusFilter = '';

    public string $from = '';

    public string $to = '';

    public int $page = 1;

    public string $notice = '';

    #[Locked]
    public string $commandSeed = '';

    #[Locked]
    public array $commandKeys = [];

    #[Locked]
    public bool $editing = false;

    #[Locked]
    public ?array $preview = null;

    #[Locked]
    public ?array $selected = null;

    public function mount(): void
    {
        // 种子在按钮提交前进入签名状态，失败或网络重试沿用同一身份。
        $this->commandSeed = (string) Str::uuid();
    }

    public static function canAccess(): bool
    {
        return (bool) auth()->user()?->is_admin;
    }

    /** 列表、基线和卷均先经同书授权，不向浏览器提供自由拼接来源。 */
    public function catalog(): array
    {
        if ($this->novel === '') {
            return [];
        }
        $data = app(ExportService::class)->listing(auth()->user(), $this->novel, ['page' => $this->page, 'format' => $this->formatFilter ?: null, 'status' => $this->statusFilter ?: null, 'from' => $this->from ?: null, 'to' => $this->to ?: null]);
        $data['revisions'] = DB::table('canonical_revisions')->where('novel_id', $this->novel)->orderByDesc('id')->get(['id', 'version', 'cursor'])->map(fn ($r) => (array) $r)->all();
        $data['volumes'] = DB::table('volumes')->where('novel_id', $this->novel)->orderBy('sequence')->get(['id', 'title'])->map(fn ($r) => (array) $r)->all();

        return $data;
    }

    public function frame(): array
    {
        try {
            return ['data' => $this->catalog(), 'error' => null];
        } catch (ValidationException) {
            return ['data' => [], 'error' => '筛选字段无效，请修正格式、日期或页码。'];
        } catch (NovelProblem $e) {
            return ['data' => [], 'error' => $e->getMessage()];
        } catch (QueryException) {
            return ['data' => [], 'error' => '任务暂不可读，请稍后刷新。'];
        }
    }

    public function begin(): void
    {
        $this->catalog();
        $this->resetValidation();
        $this->preview = null;
        $this->commandSeed = (string) Str::uuid();
        $this->editing = true;
        $this->dispatch('open-modal', id: 'nf-export-editor');
    }

    /** 无效分支字段不提交；草稿为明确选择，默认正式基线由服务捕获。 */
    private function input(): array
    {
        $d = $this->form;
        $d['novel_id'] = $this->novel;
        $d['revision_id'] = $d['revision_id'] ?: null;
        $d['range'] = array_intersect_key($d['range'], array_flip(match ($d['range']['type']) {
            'chapter' => ['type', 'from'],'range' => ['type', 'from', 'to'],'volume' => ['type', 'volume_id'],default => ['type']
        }));

        return $d;
    }

    private function key(array $d): string
    {
        $hash = hash('sha256', NovelIntentService::canonical($d));

        return $this->commandKeys[$hash] ??= hash('sha256', $this->commandSeed.$hash);
    }

    /** 预检只展示目录和缺项，不创建任务，也不把正文转成可执行HTML。 */
    public function preflight(): void
    {
        $this->resetValidation();
        try {
            $snapshot = app(ReadingService::class)->snapshot(auth()->user(), $this->novel, $this->input());
            foreach ($snapshot['items'] as &$item) {
                unset($item['body']);
            }unset($item);
            $this->preview = $snapshot;
        } catch (ValidationException $e) {
            $this->setErrorBag($e->validator->errors());
        } catch (NovelProblem $e) {
            $this->addError('export', $e->getMessage());
        }
    }

    public function save(): void
    {
        $this->resetValidation();
        try {
            $d = $this->input();
            $job = app(ExportService::class)->create(auth()->user(), $d, $this->key($d));
            $this->editing = false;
            $this->dispatch('close-modal', id: 'nf-export-editor');
            $this->notice = '已冻结导出 #'.$job['id'].'，等待export队列；不是平台发布。';
        } catch (ValidationException $e) {
            $errors = [];
            foreach ($e->errors() as $k => $v) {
                $errors['form.'.$k] = $v;
            }$this->setErrorBag($errors);
        } catch (NovelProblem $e) {
            $this->addError('export', $e->getMessage());
        }
    }

    public function closeEditor(): void
    {
        $this->editing = false;
        $this->resetValidation();
        $this->dispatch('close-modal', id: 'nf-export-editor');
    }

    public function inspect(string $id): void
    {
        $this->selected = $this->own($id);
        $this->dispatch('open-modal', id: 'nf-export-detail');
    }

    /** 路径ID必须仍属于当前书，切书后旧按钮不授予旧书对象操作。 */
    private function own(string $id): array
    {
        $job = app(ExportService::class)->read(auth()->user(), $id);
        abort_unless($job['novel_id'] === $this->novel, 404);

        return $job;
    }

    public function rebuild(string $id): void
    {
        try {
            $this->own($id);
            $job = app(ExportService::class)->rebuild(auth()->user(), $id, $this->key(['rebuild' => $id]));
            $this->notice = '已按原清单新建任务 #'.$job['id'].'。';
        } catch (NovelProblem $e) {
            $this->addError('export', $e->getMessage());
        }
    }

    /** 派生文件删除复用Filament原生确认，避免浏览器原生阻塞对话框。 */
    public function deleteDerivedAction(): Action
    {
        return Action::make('deleteDerived')->requiresConfirmation()->modalHeading('确认删除派生文件')->modalDescription('仅删除可重建的派生文件，正式正文、冻结清单与审计保留。')->action(fn (array $arguments) => $this->deleteDerived((string) ($arguments['export'] ?? '')));
    }

    public function deleteDerived(string $id): void
    {
        try {
            $this->own($id);
            app(ExportService::class)->deleteFile(auth()->user(), $id, $this->key(['delete' => $id]));
            $this->notice = '仅删除派生文件，正文、清单和审计保留。';
        } catch (NovelProblem $e) {
            $this->addError('export', $e->getMessage());
        }
    }
}
