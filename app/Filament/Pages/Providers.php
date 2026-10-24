<?php

namespace App\Filament\Pages;

use App\Services\ProviderConfigurationService;
use Filament\Pages\Page;
use Livewire\Attributes\Url;

/** 只读安全投影与原生POST表单，密钥永不进入页面状态。 */
class Providers extends Page
{
    protected static ?string $title = '供应商与模型';

    protected static string|\UnitEnum|null $navigationGroup = '系统配置';

    protected string $view = 'filament.providers';

    #[Url]
    public string $q = '';

    #[Url]
    public string $interface = '';

    #[Url]
    public string $enabled = '';

    #[Url]
    public int $page = 1;

    #[Url]
    public string $structured = '';

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
        return app(ProviderConfigurationService::class)->listing(auth()->user(), ['q' => $this->q ?: null, 'interface_type' => $this->interface ?: null, 'enabled' => $this->enabled === '' ? null : $this->enabled, 'declared_structured_output' => $this->structured ?: null, 'page' => $this->page]);
    }
}
