<?php

namespace App\Filament\Resources;

use App\Filament\Resources\NovelResource\Pages;
use App\Filament\Support\IntentUi;
use App\Models\Novel;
use Filament\Actions\Action;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Hidden;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Resources\Resource;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Filters\TernaryFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

class NovelResource extends Resource
{
    // 只将已实现的创作入口放入导航，不显示不存在的生产页面。
    protected static string|\UnitEnum|null $navigationGroup = '创作工作台';

    protected static ?string $model = Novel::class;

    protected static ?string $modelLabel = '小说';

    protected static ?string $pluralModelLabel = '小说';

    // 小说资源保留原路由，只统一常驻导航名称与顺序。
    protected static ?int $navigationSort = 20;

    protected static ?string $navigationLabel = '小说库';

    protected static ?string $recordTitleAttribute = 'title';

    protected static ?string $slug = 'novels';

    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()->where('owner_id', auth()->id());
    }

    public static function inputFields(): array
    {
        return [
            TextInput::make('title')->label('书名')->required()->maxLength(255),
            TextInput::make('genre')->label('题材')->required()->maxLength(255),
            TextInput::make('target_reader')->label('目标读者')->maxLength(20000),
            Textarea::make('background')->label('原始背景')->required()->rows(5)->maxLength(20000),
            Textarea::make('premise_raw')->label('剧情概述（原文保留）')->required()->rows(8)->maxLength(100000),
            Textarea::make('selling_point')->label('核心卖点')->maxLength(20000),
            Textarea::make('forbidden_content')->label('禁用内容')->maxLength(20000),
            TextInput::make('target_chapters')->label('目标章节数')->integer()->minValue(1)->maxValue(100000)->required(),
            TextInput::make('length_policy.min')->label('每章字数下限')->integer()->minValue(1)->required(),
            TextInput::make('length_policy.max')->label('每章字数上限')->integer()->minValue(1)->required(),
            Textarea::make('style')->label('文风偏好')->maxLength(20000),
            Textarea::make('ending_preference')->label('结局偏好')->maxLength(20000),
            Textarea::make('budget_note')->label('预算意向（尚非生效预算）')->helperText('正式预算与币种将在成本模块中确认。')->maxLength(20000),
        ];
    }

    public static function table(Table $table): Table
    {
        return $table->columns([
            TextColumn::make('title')->label('书名')->searchable()->sortable()->url(fn (Novel $record) => static::getUrl('workspace', ['record' => $record])),
            TextColumn::make('genre')->label('题材')->searchable(),
            TextColumn::make('status')->label('状态')->formatStateUsing(fn ($state) => ['draft' => '草稿', 'planning' => '规划中', 'ready' => '就绪', 'generating' => '生成中', 'paused' => '暂停', 'completed' => '完成', 'archived' => '已归档'][$state] ?? $state)->badge(),
            TextColumn::make('formal_chapters')->label('正式章数')->state('尚未接入'),
            TextColumn::make('formal_words')->label('正文字数')->state('尚未接入'),
            TextColumn::make('cost')->label('费用')->state('未知'),
            TextColumn::make('updated_at')->label('最近活动')->dateTime('Y-m-d H:i', timezone: 'Asia/Shanghai')->sortable(),
        ])->filters([
            SelectFilter::make('genre')->label('题材')->options(fn () => Novel::where('owner_id', auth()->id())->pluck('genre', 'genre')->all()),
            SelectFilter::make('status')->label('状态')->options(['draft' => '草稿', 'planning' => '规划中', 'ready' => '就绪', 'generating' => '生成中', 'paused' => '暂停', 'completed' => '完成', 'archived' => '已归档']),
            TernaryFilter::make('archived')->label('归档')->queries(true: fn (Builder $q) => $q->where('status', 'archived'), false: fn (Builder $q) => $q->where('status', '!=', 'archived')),
            Filter::make('updated')->label('更新时间')->schema([DatePicker::make('from')->label('起始日期'), DatePicker::make('until')->label('截止日期')])->query(fn (Builder $q, array $data) => $q->when($data['from'] ?? null, fn ($q, $v) => $q->whereDate('updated_at', '>=', $v))->when($data['until'] ?? null, fn ($q, $v) => $q->whereDate('updated_at', '<=', $v))),
        ])->recordActions([
            Action::make('workspace')->label('打开工作台')->url(fn (Novel $record) => static::getUrl('workspace', ['record' => $record])),
            Action::make('plans')->label('章节场景计划')->url(fn (Novel $record) => static::getUrl('plans', ['record' => $record])),
            Action::make('blueprints')->label('蓝图与终局')->url(fn (Novel $record) => static::getUrl('blueprints', ['record' => $record])),
            Action::make('world')->label('世界与人物')->url(fn (Novel $record) => static::getUrl('world', ['record' => $record])),
            Action::make('structure')->label('五层结构')->url(fn (Novel $record) => static::getUrl('structure', ['record' => $record])),
            Action::make('intent')->label('原始要求')->url(fn (Novel $record) => static::getUrl('intent', ['record' => $record])),
            Action::make('metadata')->label('修改书名/题材')->visible(fn (Novel $record) => $record->status !== 'archived')->schema([TextInput::make('title')->label('书名')->required(), TextInput::make('genre')->label('题材')->required(), Hidden::make('expected_version')])->fillForm(fn (Novel $record) => ['title' => $record->title, 'genre' => $record->genre, 'expected_version' => $record->row_version])->action(fn (Novel $record, array $data) => IntentUi::run('update', $record->id, $data)),
            Action::make('copy')->label('复制设定为新书')->schema([TextInput::make('title')->label('新书名')->required()->maxLength(255), Hidden::make('expected_version')])->fillForm(fn (Novel $record) => ['title' => $record->title.'（新书）', 'expected_version' => $record->row_version])->action(function (Novel $record, array $data) {
                $result = IntentUi::run('copy', $record->id, $data);

                return redirect(static::getUrl('workspace', ['record' => $result['novel']['id']]));
            }),
            Action::make('archive')->label('归档')->requiresConfirmation()->visible(fn (Novel $record) => in_array($record->status, ['draft', 'planning', 'ready', 'paused', 'completed']))->schema([Hidden::make('expected_version')])->fillForm(fn (Novel $record) => ['expected_version' => $record->row_version])->action(fn (Novel $record, array $data) => IntentUi::run('archive', $record->id, $data)),
            Action::make('restore')->label('恢复')->requiresConfirmation()->visible(fn (Novel $record) => $record->status === 'archived')->schema([Hidden::make('expected_version')])->fillForm(fn (Novel $record) => ['expected_version' => $record->row_version])->action(fn (Novel $record, array $data) => IntentUi::run('restore', $record->id, $data)),
        ])->defaultSort('id', 'desc')->paginated([10, 25, 50, 100])->defaultPaginationPageOption(25)->persistFiltersInSession();
    }

    public static function getPages(): array
    {
        return ['draft-editor' => Pages\DraftEditor::route('/{record}/draft-editor'), 'planning-candidates' => Pages\PlanningCandidates::route('/{record}/planning-candidates'), 'workspace' => Pages\Workspace::route('/{record}/workspace'), 'plans' => Pages\RollingPlans::route('/{record}/plans'), 'blueprints' => Pages\Blueprints::route('/{record}/blueprints'), 'world' => Pages\WorldLibrary::route('/{record}/world'), 'structure' => Pages\Structure::route('/{record}/structure'), 'index' => Pages\ListNovels::route('/'), 'create' => Pages\CreateNovel::route('/create'), 'intent' => Pages\NovelIntent::route('/{record}/intent')];
    }
}
