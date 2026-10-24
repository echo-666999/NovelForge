<?php

namespace App\Filament\Pages;

use App\Filament\Concerns\ListsAuthoringConfigurations;
use Filament\Pages\Page;

/** C04 配置管理入口；发布只影响新运行。 */
class StyleProfiles extends Page
{
    use ListsAuthoringConfigurations;

    protected static ?string $title = '文风与评分策略';

    protected static string|\UnitEnum|null $navigationGroup = '系统配置';

    protected string $view = 'filament.authoring-configurations';

    public function configurationKind(): string
    {
        return 'style';
    }
}
