<?php

namespace App\Filament\Pages;

use App\Services\RunCenterService;
use Filament\Pages\Page;
use Livewire\Attributes\Url;

/** 页面只读轮询数据库，离开页面不会取消队列任务。 */
class RunCenter extends Page
{
    protected static ?string $title = '生成运行中心';

    protected static string|\UnitEnum|null $navigationGroup = '生产与质量';

    protected string $view = 'filament.run-center';

    #[Url]
    public string $novel = '';

    #[Url]
    public string $run = '';

    #[Url]
    public string $status = '';

    #[Url]
    public string $error = '';

    #[Url]
    public string $trace = '';

    #[Url]
    public string $dateFrom = '';

    #[Url]
    public string $dateTo = '';

    #[Url]
    public int $page = 1;

    #[Url]
    public int $requestPage = 1;

    public string $stepStatus = '';

    public string $attempt = '';

    public string $artifactType = '';

    public static function canAccess(): bool
    {
        return (bool) auth()->user()?->is_admin;
    }

    public function updated(string $field): void
    {
        if ($field !== 'page') {
            $this->page = 1;
        }
    }

    public function catalog(): array
    {
        return app(RunCenterService::class)->listing(auth()->user(), ['novel_id' => $this->novel ?: null, 'status' => $this->status ?: null, 'error' => $this->error ?: null, 'trace_id' => $this->trace ?: null, 'date_from' => $this->dateFrom ?: null, 'date_to' => $this->dateTo ?: null, 'page' => $this->page]);
    }

    public function detail(): ?array
    {
        return $this->novel !== '' && $this->run !== '' ? app(RunCenterService::class)->detail(auth()->user(), $this->novel, $this->run, $this->requestPage) : null;
    }
}
