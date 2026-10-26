<?php

namespace App\Filament\Resources\NovelResource\Pages;

use App\Exceptions\NovelProblem;
use App\Models\BlueprintApproval;
use App\Models\RollingPlan;
use App\Services\NovelIntentService;
use App\Services\RollingPlanService;
use App\Services\StructureService;
use Filament\Actions\Action;
use Filament\Forms\Components\Hidden;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Support\Exceptions\Halt;
use Illuminate\Database\QueryException;
use Illuminate\Support\Str;
use Livewire\Attributes\Url;

// 复用五层结构的编辑表单，新增门禁操作只调用统一应用服务。
class RollingPlans extends Structure
{
    protected static ?string $title = '滚动细化与章节场景计划';

    protected string $view = 'filament.rolling-plans';

    #[Url]
    public string $selectedId = '';

    #[Url]
    public string $before = '';

    #[Url]
    public string $planState = '';

    public const STATES = ['ready' => '检查通过，待冻结', 'blocked' => '存在阻塞', 'frozen' => '已冻结', 'stale' => '依赖已变化'];

    public function selected(): ?array
    {
        return $this->selectedId ? app(RollingPlanService::class)->read(auth()->user(), $this->novelId, $this->selectedId) : null;
    }

    public function state(): array
    {
        if ($this->selectedId) {
            $p = $this->selected();

            return app(StructureService::class)->version(auth()->user(), $this->novelId, $p['outline_version_id'])['content'];
        }

        return parent::state();
    }

    public function editable(): bool
    {
        return ! $this->selectedId && parent::editable();
    }

    public function updatedPlanState(): void
    {
        $this->before = '';
    }

    public function plans(): array
    {
        return app(RollingPlanService::class)->listing(auth()->user(), $this->novelId, ['before' => $this->before ?: null, 'status' => $this->planState ?: null]);
    }

    public function planOptions(): array
    {
        return RollingPlan::where('novel_id', $this->novelId)->where('status', 'sealed')->orderByDesc('version')->get()->mapWithKeys(fn ($p) => [$p->id => '计划 v'.$p->version.' · '.$p->start_chapter.'—'.$p->end_chapter.'章'])->all();
    }

    private function commonPlan(): array
    {
        return [Hidden::make('expected_version'), Hidden::make('command_key'), Textarea::make('reason')->label('操作原因')->required()];
    }

    private function planBase(): array
    {
        return ['expected_version' => app(NovelIntentService::class)->novel(auth()->user(), $this->novelId)->row_version, 'command_key' => (string) Str::uuid()];
    }

    private function runPlan(string $command, array $data): void
    {
        $key = $data['command_key'];
        unset($data['command_key']);
        try {
            if (in_array($command, ['split', 'merge', 'reorder'], true)) {
                foreach ($data['scene_ids'] as $id) {
                    if ($this->nodeFrozen('scene', (string) $id)) {
                        throw new NovelProblem('PLAN_FROZEN', '冻结场景不能原地拆分、合并或重排。', 422);
                    }
                }
            }
            $r = app(RollingPlanService::class)->execute(auth()->user(), $this->novelId, $command, $data, $key);
            $this->selectedId = $r['plan']['id'] ?? (string) ($data['rolling_plan_id'] ?? '');
            Notification::make()->success()->title('已记录；请查看门禁结果')->send();
        } catch (QueryException $e) {
            if ((string) $e->getCode() !== '23514' || ! Str::contains($e->getMessage(), ['Frozen structure requires history change', 'Frozen chapter cannot add or move scene', 'Formal history requires change workflow'])) {
                throw $e;
            }
            Notification::make()->danger()->title('规划或正式历史已冻结，请刷新并走历史变更流程。')->send();
            throw new Halt;
        } catch (NovelProblem $e) {
            Notification::make()->danger()->title($e->getMessage())->send();
            throw new Halt;
        }
    }

    public function savePlanAction(): Action
    {
        return Action::make('savePlan')->label('保存滚动计划版本')->schema([
            Select::make('blueprint_version_id')->label('批准蓝图')->options(fn () => BlueprintApproval::where('novel_id', $this->novelId)->orderByDesc('id')->pluck('blueprint_version_id', 'blueprint_version_id')->all())->required(),
            TextInput::make('start_chapter')->label('细化窗口起始章')->integer()->minValue(1)->required(), TextInput::make('end_chapter')->label('细化窗口结束章')->integer()->minValue(1)->required(),
            Repeater::make('coverages')->label('要求覆盖证据（弧→章→场景，每级各一项）')->defaultItems(0)->schema([
                Select::make('requirement_version_id')->label('当前原始要求')->options(fn () => collect(app(NovelIntentService::class)->requirements(app(NovelIntentService::class)->novel(auth()->user(), $this->novelId)))->pluck('content', 'id')->all())->required(),
                Select::make('type')->label('层级')->live()->options(['story_arc' => '故事弧', 'chapter' => '章节', 'scene' => '场景'])->required(),
                Select::make('node_id')->label('实际结构节点')->options(fn (Get $get) => collect($this->state()['nodes'])->where('type', $get('type'))->mapWithKeys(fn ($n) => [(string) $n['id'] => $n['sequence'].' · '.$n['title'].' · #'.$n['id']])->all())->searchable()->required(),
                Select::make('field')->label('承接字段')->options(['objective' => '目标', 'summary' => '摘要', 'details.required_events' => '必要事件', 'details.forbidden_changes' => '禁止改变', 'details.foreshadow_tasks' => '伏笔规划'])->required(),
                Textarea::make('quote')->label('精确引文')->required(), TextInput::make('start_offset')->label('起始码点（从0开始）')->integer()->minValue(0)->required(), TextInput::make('end_offset')->label('结束码点（不含）')->integer()->minValue(1)->required(),
            ]), ...$this->commonPlan(),
        ])->fillForm(function () {
            $data = $this->planBase() + ['start_chapter' => 1, 'end_chapter' => min(10, app(NovelIntentService::class)->novel(auth()->user(), $this->novelId)->target_chapters), 'coverages' => []];
            if ($p = $this->selected()) {
                // 复制的是历史证据的稳定节点引用；新版本保存时重新验证当前原文与偏移。
                $nodes = collect($p['nodes'])->keyBy('id');
                $data = array_replace($data, ['blueprint_version_id' => $p['blueprint_version_id'], 'start_chapter' => $p['start_chapter'], 'end_chapter' => $p['end_chapter'], 'coverages' => array_map(function ($c) use ($nodes) {
                    $n = $nodes->get($c['plan_node_id']);

                    return array_intersect_key($c, array_flip(['requirement_version_id', 'field', 'quote', 'start_offset', 'end_offset'])) + ['type' => $n['node_type'], 'node_id' => $n[$n['node_type'].'_id']];
                }, $p['content']['coverages'])]);
            }

            return $data;
        })->action(fn (array $data) => $this->runPlan('save', $data));
    }

    public function validatePlanAction(): Action
    {
        return Action::make('validatePlan')->label('检查计划')->schema([Select::make('rolling_plan_id')->label('计划版本')->options(fn () => $this->planOptions())->required(), ...$this->commonPlan()])->fillForm(fn () => $this->planBase() + ['rolling_plan_id' => $this->selectedId])->action(fn (array $data) => $this->runPlan('validate', $data));
    }

    public function freezePlanAction(): Action
    {
        return Action::make('freezePlan')->label('冻结详细窗口')->visible(fn () => filled($this->selectedId) && $this->selected()['display_status'] === 'ready')->modalDescription('冻结后不能原地修改窗口内的章、场景及其祖先。只冻结计划，不开启模型或正式提交。')->schema([Hidden::make('rolling_plan_id'), Hidden::make('validation_id'), Hidden::make('report_hash'), ...$this->commonPlan()])->fillForm(function () {
            $p = $this->selected();

            return $this->planBase() + ['rolling_plan_id' => $p['id'], 'validation_id' => $p['validation']['id'] ?? null, 'report_hash' => $p['validation']['report_hash'] ?? null];
        })->action(fn (array $data) => $this->runPlan('freeze', $data));
    }

    public function transformAction(): Action
    {
        $details = [];
        foreach (self::DETAILS as $key => $label) {
            $details[] = $key === 'pov_version_id' ? Select::make('details.'.$key)->label('已批准POV人物版本')->searchable()->options(fn () => $this->povOptions()) : (in_array($key, ['target_words', 'min_words', 'max_words']) ? TextInput::make('details.'.$key)->label($label)->integer()->minValue(1) : Textarea::make('details.'.$key)->label($label));
        }

        return Action::make('transform')->label('拆分 / 合并 / 排序场景')->visible(fn () => $this->editable())->schema([
            Select::make('operation')->label('操作')->options(['split' => '拆分', 'merge' => '合并相邻场景', 'reorder' => '按选择顺序重排全部场景'])->required(),
            Select::make('chapter_id')->label('所属章节')->live()->options(fn () => collect($this->state()['nodes'])->where('type', 'chapter')->pluck('title', 'id')->all())->required(),
            Repeater::make('ordered_scenes')->label('场景顺序（排序须列全本章场景）')->schema([Select::make('id')->label('实际章节场景')->options(fn (Get $get) => collect($this->state()['nodes'])->where('type', 'scene')->where('parent_id', (string) $get('../../chapter_id'))->mapWithKeys(fn ($n) => [(string) $n['id'] => $n['sequence'].' · '.$n['title']])->all())->required()])->minItems(1)->required(),
            Repeater::make('replacements')->label('替代场景（排序时留空）')->defaultItems(0)->schema([TextInput::make('title')->label('标题')->required(), Textarea::make('objective')->label('目标')->required(), Textarea::make('summary')->label('摘要'), ...$details]), ...$this->commonPlan(),
        ])->fillForm(fn () => $this->planBase() + ['replacements' => []])->action(function (array $data) {
            $op = $data['operation'];
            $data['scene_ids'] = array_column($data['ordered_scenes'], 'id');
            unset($data['operation'],$data['ordered_scenes']);
            $this->runPlan($op, $data);
        });
    }
}
