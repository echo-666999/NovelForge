<?php

namespace App\Filament\Pages;

use App\Services\SingleChapterSetupService;
use Filament\Pages\Page;

/** NF-F01 的配置引导入口；本页不启动任务、不写入小说，也不发送模型请求。 */
class SingleChapterSetup extends Page
{
    protected static string|\UnitEnum|null $navigationGroup = '生产与质量';

    protected static ?int $navigationSort = 1;

    protected static ?string $title = '单章验收准备';

    protected string $view = 'filament.single-chapter-setup';

    public static function canAccess(): bool
    {
        return (bool) auth()->user()?->is_admin;
    }

    /** 失败保持为失败，不能把连接异常伪装成尚未配置。 */
    public function inventory(): array
    {
        abort_unless(static::canAccess(), 403);
        try {
            return app(SingleChapterSetupService::class)->inspect(auth()->user());
        } catch (\Throwable) {
            return ['error' => '暂时无法读取准备状态，请重试。原有配置未修改。'];
        }
    }
}
