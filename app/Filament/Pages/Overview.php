<?php

namespace App\Filament\Pages;

use App\Exceptions\NovelProblem;
use App\Services\WorkspaceService;
use Filament\Pages\Dashboard;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Url;

class Overview extends Dashboard
{
    // UI03：分组与顺序显式固定，不依赖页面发现次序。
    protected static string|\UnitEnum|null $navigationGroup = '创作工作台';

    protected static ?int $navigationSort = 10;

    // 只将已实现的创作入口放入导航，不显示不存在的生产页面。

    protected static ?string $title = '创作总览';

    protected static ?string $navigationLabel = '创作总览';

    protected string $view = 'filament.overview';

    #[Url]
    public string $status = '';

    #[Url]
    public string $from = '';

    #[Url]
    public string $until = '';

    #[Url]
    public int $page = 1;

    #[Url]
    public int $limit = 25;

    public function updated(string $property): void
    {
        if ($property !== 'page') {
            $this->page = 1;
        }
    }

    public function overview(): array
    {
        try {
            return app(WorkspaceService::class)->overview(auth()->user(), ['status' => $this->status ?: null, 'from' => $this->from ?: null, 'until' => $this->until ?: null, 'page' => $this->page, 'limit' => $this->limit]);
        } catch (NovelProblem $e) {
            throw $e;
        } catch (ValidationException $e) {
            return ['error' => '筛选条件无效，请调整日期、状态或页码。'];
        } catch (\Throwable $e) {
            report($e);

            return ['error' => '小说列表暂时读取失败，请重试。'];
        }
    }
}
