<?php

namespace App\Filament\Pages;

use App\Filament\Concerns\HasProductionChoices;
use App\Filament\Concerns\ReadsProductionFrames;
use App\Services\RunCenterService;
use Filament\Pages\Page;
use Livewire\Attributes\Locked;
use Livewire\Attributes\Url;

/** 审计只追加；后台没有删除或重写历史动作。 */
class AuditCenter extends Page
{
    use HasProductionChoices;
    use ReadsProductionFrames;

    // UI03：分组与顺序显式固定，不依赖页面发现次序。
    protected static string|\UnitEnum|null $navigationGroup = '配置与运维';

    protected static ?int $navigationSort = 60;

    protected static ?string $title = '审计与系统健康';

    protected string $view = 'filament.audit-center';

    #[Url]
    public string $trace = '';

    #[Url]
    public string $novel = '';

    #[Url]
    public string $action = '';

    #[Url]
    public string $status = '';

    #[Url]
    public string $dateFrom = '';

    #[Url]
    public string $dateTo = '';

    #[Url]
    public bool $highRisk = false;

    #[Url]
    public int $page = 1;

    /** UI24只保存当前授权页中已脱敏的只读投影，不提供审计新增或改写。 */
    #[Locked]
    public ?array $auditDetail = null;

    public function inspectAudit(string $id): void
    {
        $row = collect($this->catalog()['rows'])->firstWhere('id', $id);
        abort_unless($row, 404);
        $this->auditDetail = $row;
        $this->dispatch('open-modal', id: 'nf-audit-detail-modal');
    }

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

    public function filters(): array
    {
        return ['trace_id' => $this->trace ?: null, 'novel_id' => $this->novel ?: null, 'action' => $this->action ?: null, 'status' => $this->status ?: null, 'date_from' => $this->dateFrom ?: null, 'date_to' => $this->dateTo ?: null, 'high_risk' => $this->highRisk ? true : null, 'page' => $this->page];
    }

    public function catalog(): array
    {
        return app(RunCenterService::class)->audit(auth()->user(), $this->filters());
    }

    public function health(): array
    {
        return app(RunCenterService::class)->health(auth()->user());
    }
}
