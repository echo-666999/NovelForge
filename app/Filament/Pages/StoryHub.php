<?php

namespace App\Filament\Pages;

use App\Filament\Concerns\HasNovelNavigation;
use Filament\Pages\Page;

/** 仅汇总当前书已有故事管理入口；没有小说时提供明确选择与建书空态。 */
class StoryHub extends Page
{
    use HasNovelNavigation;

    protected static ?string $title = '故事管理';

    protected static string|\UnitEnum|null $navigationGroup = '故事管理';

    protected static ?int $navigationSort = 10;

    protected static string|\BackedEnum|null $navigationIcon = 'heroicon-o-book-open';

    protected string $view = 'filament.story-hub';

    public static function canAccess(): bool
    {
        return (bool) auth()->user()?->is_admin;
    }
}
