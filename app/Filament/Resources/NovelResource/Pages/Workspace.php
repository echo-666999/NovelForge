<?php

namespace App\Filament\Resources\NovelResource\Pages;

use App\Exceptions\NovelProblem;
use App\Filament\Resources\NovelResource;
use App\Services\NovelIntentService;
use App\Services\WorkspaceService;
use Filament\Resources\Pages\Page;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Locked;
use Livewire\Attributes\Url;

class Workspace extends Page
{
    protected static string $resource = NovelResource::class;

    protected static ?string $title = '创作工作台';

    protected string $view = 'filament.workspace';

    #[Locked]
    public string $novelId;

    #[Url]
    public string $from = '';

    #[Url]
    public string $until = '';

    #[Url]
    public string $status = '';

    #[Url]
    public bool $issuesOnly = false;

    #[Url]
    public int $page = 1;

    #[Url]
    public int $limit = 25;

    public const STATES = ['skeleton' => '骨架', 'detailed' => '已填细节', 'ready' => '门禁通过待冻结', 'frozen' => '有效冻结', 'blocked' => '规划阻塞', 'stale' => '依赖已变化'];

    public function mount(string $record): void
    {
        $this->novelId = app(NovelIntentService::class)->novel(auth()->user(), $record)->id;
    }

    public function updated(string $property): void
    {
        if ($property !== 'page') {
            $this->page = 1;
        }
    }

    // 非法 URL 筛选可恢复默认值，不要求作者手工修改地址栏。
    public function resetFilters(): void
    {
        $this->reset('from', 'until', 'status', 'issuesOnly', 'page', 'limit');
    }

    public function summary(): array
    {
        try {
            return app(WorkspaceService::class)->summary(auth()->user(), $this->novelId, ['from' => $this->from ?: null, 'until' => $this->until ?: null, 'status' => $this->status ?: null, 'issues_only' => $this->issuesOnly, 'page' => $this->page, 'limit' => $this->limit]);
        } catch (NovelProblem $e) {
            throw $e;
        } catch (ValidationException $e) {
            return ['error' => '筛选条件无效，请调整章范围、状态或页码。'];
        } catch (\Throwable $e) {
            // 读取失败不能伪装为空列表；具体异常仅写服务端日志。
            report($e);

            return ['error' => '规划状态暂时读取失败，请重试。'];
        }
    }
}
