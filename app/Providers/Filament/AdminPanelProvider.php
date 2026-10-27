<?php

namespace App\Providers\Filament;

use App\Filament\Pages\Overview;
use App\Filament\Pages\ProjectStatus;
use App\Http\Middleware\ValidateNovelNavigation;
use App\Services\NovelNavigationService;
use Filament\Actions\Action;
use Filament\FontProviders\LocalFontProvider;
use Filament\Http\Middleware\Authenticate;
use Filament\Http\Middleware\AuthenticateSession;
use Filament\Http\Middleware\DisableBladeIconComponents;
use Filament\Http\Middleware\DispatchServingFilamentEvent;
use Filament\Panel;
use Filament\PanelProvider;
use Filament\Support\Colors\Color;
use Filament\Support\Enums\Width;
use Filament\View\PanelsRenderHook;
use Filament\Widgets\AccountWidget;
use Illuminate\Cookie\Middleware\AddQueuedCookiesToResponse;
use Illuminate\Cookie\Middleware\EncryptCookies;
use Illuminate\Foundation\Http\Middleware\PreventRequestForgery;
use Illuminate\Routing\Middleware\SubstituteBindings;
use Illuminate\Session\Middleware\StartSession;
use Illuminate\View\Middleware\ShareErrorsFromSession;

class AdminPanelProvider extends PanelProvider
{
    public function panel(Panel $panel): Panel
    {
        return $panel
            ->default()
            ->id('admin')
            ->brandName('NovelForge · 小说工坊')
            ->path('admin')
            ->login()
            // 五组目录及次级关于入口统一维护，旧状态页URL继续可访问。
            ->navigationGroups(NovelNavigationService::GROUPS)
            ->userMenuItems([Action::make('implementation-status')->label('关于与实施状态')->icon('heroicon-o-information-circle')->url(fn (): string => ProjectStatus::getUrl())])
            // UI02 仅接入主题与本机字体，不修改导航、页面数据或业务动作。
            ->viteTheme('resources/css/filament/admin/theme.css')
            // UI15：统一错误定位，不改变原服务命令与控件焦点边界。
            ->renderHook(PanelsRenderHook::BODY_END, fn () => view('filament.validation-focus'))
            ->font('PingFang SC', provider: LocalFontProvider::class)
            ->colors([
                'primary' => Color::Indigo,
            ])
            ->discoverResources(in: app_path('Filament/Resources'), for: 'App\Filament\Resources')
            ->discoverPages(in: app_path('Filament/Pages'), for: 'App\Filament\Pages')
            ->pages([
                Overview::class,
            ])
            ->discoverWidgets(in: app_path('Filament/Widgets'), for: 'App\Filament\Widgets')
            ->widgets([
                AccountWidget::class,

            ])
            ->middleware([
                EncryptCookies::class,
                AddQueuedCookiesToResponse::class,
                StartSession::class,
                AuthenticateSession::class,
                ShareErrorsFromSession::class,
                PreventRequestForgery::class,
                SubstituteBindings::class,
                DisableBladeIconComponents::class,
                DispatchServingFilamentEvent::class,
            ])
            ->maxContentWidth(Width::Full)
            ->authMiddleware([
                Authenticate::class,
                ValidateNovelNavigation::class,
            ]);
    }
}
