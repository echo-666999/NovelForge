<?php

namespace App\Filament\Pages;

use App\Filament\Concerns\ListsAuthoringConfigurations;
use Filament\Pages\Page;

/** C04 配置管理入口；发布只影响新运行。 */
class PromptTemplates extends Page
{
    use ListsAuthoringConfigurations;

    protected static ?string $title = 'Prompt 模板与发布';

    protected static string|\UnitEnum|null $navigationGroup = '系统配置';

    protected string $view = 'filament.authoring-configurations';

    public function configurationKind(): string
    {
        return 'prompt';
    }
}
