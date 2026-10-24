<?php

namespace App\Filament\Pages;

use App\Services\BillingConfigurationService;
use Filament\Pages\Page;
use Livewire\Attributes\Url;

/** 费用、预留和未知风险分开显示，不用零金额掩盖未核销请求。 */
class Billing extends Page
{
    protected static ?string $title = '价格与原子预算';

    protected static string|\UnitEnum|null $navigationGroup = '系统配置';

    protected string $view = 'filament.billing';

    #[Url]
    public string $novel = '';

    #[Url]
    public string $status = '';

    #[Url]
    public string $currency = '';

    #[Url]
    public string $category = '';

    #[Url]
    public string $run = '';

    #[Url]
    public string $model = '';

    #[Url]
    public string $requestStatus = '';

    #[Url]
    public string $dateFrom = '';

    #[Url]
    public string $dateTo = '';

    #[Url]
    public int $page = 1;

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
        return app(BillingConfigurationService::class)->listing(auth()->user(), ['novel_id' => $this->novel ?: null, 'status' => $this->status ?: null, 'currency' => $this->currency ?: null, 'category' => $this->category ?: null, 'page' => $this->page, 'run_id' => $this->run ?: null, 'model_profile_version_id' => $this->model ?: null, 'request_status' => $this->requestStatus ?: null, 'date_from' => $this->dateFrom ?: null, 'date_to' => $this->dateTo ?: null]);
    }
}
