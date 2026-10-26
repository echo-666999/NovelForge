<?php

namespace App\Filament\Pages;

use Filament\Pages\Page;

class ProjectStatus extends Page
{
    // 深链接保留，入口移入用户菜单，不占据一级导航。
    protected static bool $shouldRegisterNavigation = false;

    protected static ?string $title = '实施状态';

    protected static ?string $navigationLabel = '实施状态';

    protected static string|\UnitEnum|null $navigationGroup = '配置与运维';

    protected string $view = 'filament.pages.project-status';
}
