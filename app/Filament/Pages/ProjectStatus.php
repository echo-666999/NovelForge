<?php

namespace App\Filament\Pages;

use Filament\Pages\Page;

class ProjectStatus extends Page
{
    protected static ?string $title = '实施状态';

    protected static ?string $navigationLabel = '实施状态';

    protected static string|\UnitEnum|null $navigationGroup = '系统管理';

    protected string $view = 'filament.pages.project-status';
}
