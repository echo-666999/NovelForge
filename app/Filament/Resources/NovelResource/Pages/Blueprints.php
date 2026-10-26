<?php

namespace App\Filament\Resources\NovelResource\Pages;

use App\Exceptions\NovelProblem;
use App\Filament\Resources\NovelResource;
use App\Models\BlueprintVersion;
use App\Models\EntityApproval;
use App\Models\EntityDefinitionVersion;
use App\Models\OutlineVersion;
use App\Models\Thread;
use App\Services\BlueprintService;
use App\Services\NovelIntentService;
use Filament\Actions\Action;
use Filament\Forms\Components\Hidden;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TagsInput;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\Page;
use Filament\Schemas\Components\Tabs;
use Filament\Schemas\Components\Tabs\Tab;
use Filament\Support\Exceptions\Halt;
use Illuminate\Support\Str;
use Livewire\Attributes\Locked;
use Livewire\Attributes\Url;

class Blueprints extends Page
{
    protected static string $resource = NovelResource::class;

    protected static ?string $title = '蓝图与终局契约';

    protected string $view = 'filament.blueprints';

    #[Locked]
    public string $novelId;

    #[Url]
    public string $selectedId = '';

    #[Url]
    public string $compareId = '';

    #[Url]
    public string $cursor = '';

    #[Url]
    public string $status = '';

    public const FIELDS = ['logline' => '故事一句话', 'synopsis' => '长简介', 'world_overview' => '世界规则概览', 'character_initial_states' => '人物初始设定', 'structure_summary' => '卷弧结构与不可逆变化', 'pacing' => '节奏曲线', 'information_reveals' => '信息揭示计划', 'growth_boundaries' => '能力成长边界', 'sacrifices' => '牺牲与代价', 'foreshadows' => '全书伏笔规划', 'turning_points' => '主要转折', 'character_arcs' => '人物弧', 'conflicts' => '待决冲突'];

    public const ENDING = ['resolution' => '主线解决方式', 'final_choice' => '主人公最终选择', 'core_reversal' => '核心反转 / 终局答案', 'final_relationships' => '最终关系', 'forbidden_solutions' => '禁止的临时解法', 'evidence_requirements' => '完成证据要求'];

    public const CHECKS = ['protagonist_goal' => '主角主动目标明确', 'sustained_conflict' => '冲突可支撑目标篇幅', 'independent_motives' => '重要人物有独立动机', 'world_consistency' => '世界与关键设定无未决矛盾', 'volume_changes' => '每卷具有不可逆变化', 'first_ten_scenes' => '已审阅前十章场景可执行性'];

    public const STATUS = ['draft' => '候选', 'needs_changes' => '检查未通过', 'approved' => '已批准冻结', 'superseded' => '历史批准版本'];

    public function mount(string $record): void
    {
        $this->novelId = app(NovelIntentService::class)->novel(auth()->user(), $record)->id;
    }

    public function listing(): array
    {
        return app(BlueprintService::class)->listing(auth()->user(), $this->novelId, ['cursor' => $this->cursor ?: null, 'status' => $this->status ?: null]);
    }

    public function updatedStatus(): void
    {
        $this->cursor = '';
    }

    public function selected(): ?array
    {
        return $this->selectedId ? app(BlueprintService::class)->read(auth()->user(), $this->novelId, $this->selectedId) : null;
    }

    public function comparison(): ?array
    {
        return $this->compareId ? app(BlueprintService::class)->read(auth()->user(), $this->novelId, $this->compareId) : null;
    }

    public function editable(): bool
    {
        return in_array(app(NovelIntentService::class)->novel(auth()->user(), $this->novelId)->status, ['draft', 'planning']);
    }

    private function base(): array
    {
        return ['expected_version' => app(NovelIntentService::class)->novel(auth()->user(), $this->novelId)->row_version, 'command_key' => (string) Str::uuid()];
    }

    private function common(): array
    {
        return [Hidden::make('expected_version'), Hidden::make('command_key'), Textarea::make('reason')->label('本次原因')->required()];
    }

    public function options(): array
    {
        return BlueprintVersion::where('novel_id', $this->novelId)->where('status', 'sealed')->orderByDesc('version')->get()->mapWithKeys(fn ($v) => [$v->id => '蓝图 v'.$v->version.' · '.$v->reason])->all();
    }

    private function entities(): array
    {
        return EntityDefinitionVersion::where('novel_id', $this->novelId)->where('status', 'sealed')->get()->mapWithKeys(fn ($v) => [$v->id => $v->definition['name'].' v'.$v->version.(EntityApproval::where('entity_definition_version_id', $v->id)->exists() ? ' 已批准' : ' 待批准')])->all();
    }

    private function run(string $command, array $data): void
    {
        $key = $data['command_key'];
        unset($data['command_key']);
        try {
            $r = app(BlueprintService::class)->execute(auth()->user(), $this->novelId, $command, $data, $key);
            if (isset($r['blueprint'])) {
                $this->selectedId = $r['blueprint']['id'];
            } elseif (isset($data['blueprint_version_id'])) {
                $this->selectedId = (string) $data['blueprint_version_id'];
            }Notification::make()->success()->title(isset($r['validation']) && ! $r['validation']['report']['passed'] ? '检查已保存，请处理阻塞项' : '已保存；尚未开放生成')->send();
        } catch (NovelProblem $e) {
            Notification::make()->danger()->title($e->getMessage())->send();
            throw new Halt;
        }
    }

    public function saveAction(): Action
    {
        $fields = [Select::make('outline_version_id')->label('绑定不可变结构版本')->options(fn () => OutlineVersion::where('novel_id', $this->novelId)->where('status', 'sealed')->orderByDesc('version')->get()->mapWithKeys(fn ($v) => [$v->id => '结构 v'.$v->version])->all())->required(), Select::make('entity_version_ids')->label('世界 / 人物设定版本')->multiple()->options(fn () => $this->entities())];
        foreach (self::FIELDS as $k => $label) {
            $fields[] = Textarea::make('body.'.$k)->label($label)->maxLength(50000);
        }
        $fields[] = Repeater::make('threads')->label('主支线规划')->defaultItems(0)->schema([Select::make('thread_id')->label('全书线索')->options(fn () => Thread::where('novel_id', $this->novelId)->pluck('title', 'id')->all())->required(), Select::make('kind')->label('类型')->options(['main' => '主线', 'sub' => '支线'])->required(), Textarea::make('goal')->label('目标'), Textarea::make('resolution')->label('解决方式'), Textarea::make('turning_points')->label('转折'), Textarea::make('character_arc')->label('承接人物弧')]);
        foreach (['target_chapters' => '终局目标章数', 'start_chapter' => '允许结束窗口起始章', 'end_chapter' => '允许结束窗口结束章'] as $k => $label) {
            $fields[] = TextInput::make('ending.'.$k)->label($label)->integer()->minValue(1)->required();
        }
        foreach (self::ENDING as $k => $label) {
            $fields[] = Textarea::make('ending.'.$k)->label($label);
        }
        $fields[] = Repeater::make('ending.outcomes')->label('主要人物结局（含生死）')->defaultItems(0)->schema([Select::make('entity_definition_version_id')->label('人物设定版本')->options(fn () => $this->entities())->required(), Textarea::make('outcome')->label('结局承诺')->required()]);
        $fields[] = TagsInput::make('ending.required_foreshadows')->label('必须回收的伏笔承诺（规划文字）');
        $fields[] = TagsInput::make('ending.open_items')->label('允许开放事项');
        $coverage = [];
        foreach (array_diff_key(self::FIELDS, ['conflicts' => true]) as $k => $label) {
            $coverage['body.'.$k] = $label;
        }foreach (self::ENDING as $k => $label) {
            $coverage['ending.'.$k] = $label;
        }
        $fields[] = Repeater::make('coverages')->label('原始要求 → 蓝图原文覆盖')->defaultItems(0)->schema([Select::make('requirement_version_id')->label('当前要求版本')->options(fn () => collect(app(NovelIntentService::class)->requirements(app(NovelIntentService::class)->novel(auth()->user(), $this->novelId)))->mapWithKeys(fn ($v) => [$v['id'] => '#'.$v['id'].' '.$v['source_quote'].' / '.$v['decision_status']])->all())->required(), Select::make('field')->label('承接字段')->options($coverage)->required(), Textarea::make('quote')->label('准确引用该字段中的原文')->required()]);
        foreach (self::CHECKS as $k => $label) {
            $fields[] = Toggle::make('author_checks.'.$k)->label($label);
        }

        // 来源、正文、主支线、终局与作者确认分签，保存仍是同一次版本命令。
        $groups = [];
        foreach ($fields as $field) {
            $name = $field->getName();
            $group = match (true) {
                str_starts_with($name, 'body.') => '故事蓝图',str_starts_with($name, 'ending.') => '终局契约',
                $name === 'threads' => '主支线', $name === 'coverages' || str_starts_with($name, 'author_checks.') => '覆盖与确认', default => '来源与设定',
            };
            if ($field instanceof Textarea || $field instanceof Repeater) {
                $field->columnSpanFull();
            }
            $groups[$group][] = $field;
        }
        $fields = [Tabs::make('蓝图编辑')->tabs(array_map(fn ($label, $fields) => Tab::make($label)->schema($fields)->columns(2), array_keys($groups), array_values($groups)))];

        return Action::make('save')->label('新增 / 复制修改候选')->modalWidth('7xl')->visible(fn () => $this->editable())->modalDescription('每次保存追加新版本及新终局契约。勾选检查项代表作者判断，系统不会代替文学审查。')->schema([...$fields, ...$this->common()])->fillForm(function (array $arguments) {
            $data = $this->base() + ['body' => [], 'entity_version_ids' => [], 'threads' => [], 'ending' => ['outcomes' => [], 'required_foreshadows' => [], 'open_items' => []], 'coverages' => [], 'author_checks' => []];
            if (isset($arguments['blueprint'])) {
                $v = app(BlueprintService::class)->read(auth()->user(), $this->novelId, (string) $arguments['blueprint']);
                $copy = array_intersect_key($v['content'], array_flip(['body', 'outline_version_id', 'entity_version_ids', 'threads', 'ending', 'coverages', 'author_checks']));
                foreach ($copy['threads'] as &$t) {
                    unset($t['identity_snapshot']);
                }unset($t);
                $copy['author_checks'] = array_fill_keys(BlueprintService::CHECKS, false);
                $data = array_replace($data, $copy);
            }

            return $data;
        })->action(fn (array $data) => $this->run('save', $data));
    }

    public function validateAction(): Action
    {
        return Action::make('validate')->label('检查此版本')->visible(fn () => $this->editable())->schema([Select::make('blueprint_version_id')->label('蓝图版本')->options(fn () => $this->options())->required(), ...$this->common()])->fillForm(fn (array $arguments) => $this->base() + ['blueprint_version_id' => $arguments['blueprint'] ?? $this->selectedId])->action(fn (array $data) => $this->run('validate', $data));
    }

    public function approveAction(): Action
    {
        return Action::make('approve')->label('批准冻结')->visible(fn () => $this->editable())->modalDescription('批准前再次检查依赖和覆盖。确认已阅读变更影响；此批准不开放生成，不改变正文或正式状态。')->schema([Hidden::make('blueprint_version_id'), Hidden::make('validation_id'), Hidden::make('report_hash'), Toggle::make('acknowledge_impact')->label('已阅读终局变更及影响范围')->accepted(), ...$this->common()])->fillForm(function (array $arguments) {
            $id = (string) ($arguments['blueprint'] ?? $this->selectedId);
            $v = app(BlueprintService::class)->read(auth()->user(), $this->novelId, $id);

            return $this->base() + ['blueprint_version_id' => $id, 'validation_id' => $v['validation']['id'] ?? null, 'report_hash' => $v['validation']['report_hash'] ?? null, 'acknowledge_impact' => false];
        })->action(fn (array $data) => $this->run('approve', $data));
    }
}
