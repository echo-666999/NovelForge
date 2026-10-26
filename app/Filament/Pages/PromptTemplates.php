<?php

namespace App\Filament\Pages;

use App\Filament\Concerns\ListsAuthoringConfigurations;
use Filament\Pages\Page;

/** C04 配置管理入口；发布只影响新运行。 */
class PromptTemplates extends Page
{
    // UI03：分组与顺序显式固定，不依赖页面发现次序。
    protected static string|\UnitEnum|null $navigationGroup = '配置与运维';

    protected static ?int $navigationSort = 10;

    use ListsAuthoringConfigurations;

    protected static ?string $title = 'Prompt 模板与发布';

    protected string $view = 'filament.authoring-configurations';

    public function configurationKind(): string
    {
        return 'prompt';
    }
}
