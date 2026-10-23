<?php

namespace App\Filament\Resources\NovelResource\Pages;

use App\Exceptions\NovelProblem;
use App\Filament\Resources\NovelResource;
use App\Models\OutlineVersion;
use App\Models\PremiseVersion;
use App\Services\NovelIntentService;
use App\Services\StructureService;
use Filament\Actions\Action;
use Filament\Forms\Components\Hidden;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\Page;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Support\Exceptions\Halt;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Str;
use Livewire\Attributes\Locked;
use Livewire\Attributes\Url;
use Livewire\WithPagination;

class Structure extends Page
{
    use WithPagination;

    protected static string $resource = NovelResource::class;

    protected static ?string $title = '五层结构与版本';

    protected string $view = 'filament.structure';

    #[Locked]
    public string $novelId;

    #[Url]
    public string $versionId = '';

    #[Url]
    public string $compareId = '';

    #[Url]
    public string $type = '';

    #[Url]
    public string $parent = '';

    #[Url]
    public string $thread = '';

    #[Url]
    public string $status = '';

    #[Url]
    public string $from = '';

    #[Url]
    public string $until = '';

    #[Url]
    public bool $missing = false;

    #[Url]
    public int $perPage = 25;

    public const LABELS = ['volume' => '卷', 'story_arc' => '故事弧', 'chapter' => '章', 'scene' => '场景'];

    public const DETAILS = ['turning_point' => '转折', 'climax' => '高潮', 'ending_state' => '结束状态', 'pov' => 'POV / 视角', 'story_time' => '故事时间', 'location' => '地点', 'conflict' => '冲突', 'character_change' => '角色变化', 'foreshadow_tasks' => '伏笔计划任务', 'participants' => '参与者', 'transition_in' => '前场衔接', 'transition_out' => '后场衔接', 'forbidden_changes' => '禁止变更', 'required_events' => '必要事件', 'dependencies' => '前置依赖说明', 'target_words' => '目标字数'];

    public function mount(string $record): void
    {
        $this->novelId = app(NovelIntentService::class)->novel(auth()->user(), $record)->id;
    }

    public function updated(string $property): void
    {
        if ($property === 'type') {
            $this->parent = '';
        } $this->resetPage();
    }

    public function state(): array
    {
        if ($this->versionId) {
            return app(StructureService::class)->version(auth()->user(), $this->novelId, $this->versionId)['content'];
        }

        return app(StructureService::class)->working(auth()->user(), $this->novelId);
    }

    public function versions(): array
    {
        app(NovelIntentService::class)->novel(auth()->user(), $this->novelId);

        return OutlineVersion::where('novel_id', $this->novelId)->where('status', 'sealed')->orderByDesc('version')->get(['id', 'version', 'reason', 'content_hash', 'created_at'])->toArray();
    }

    public function editable(): bool
    {
        return ! $this->versionId && in_array(app(NovelIntentService::class)->novel(auth()->user(), $this->novelId)->status, ['draft', 'planning']);
    }

    public function rows(): LengthAwarePaginator
    {
        $rows = array_values(array_filter($this->state()['nodes'], function ($r) {
            return (! $this->type || $r['type'] === $this->type) && (! $this->parent || ((StructureService::PARENTS[$r['type']] ?? '').':'.($r['parent_id'] ?? '') === $this->parent)) && (! $this->status || $r['status'] === $this->status)
            && (! $this->from || $r['sequence'] >= (int) $this->from) && (! $this->until || $r['sequence'] <= (int) $this->until)
            && (! $this->thread || collect($r['threads'] ?? [])->contains('id', $this->thread))
            && (! $this->missing || (in_array($r['type'], ['chapter', 'scene']) && (empty($r['details']['pov']) || empty($r['details']['target_words']))));
        }));
        $limit = in_array($this->perPage, [10, 25, 50, 100]) ? $this->perPage : 25;

        return new LengthAwarePaginator(array_slice($rows, ($this->getPage() - 1) * $limit, $limit), count($rows), $limit, $this->getPage());
    }

    public function differences(): array
    {
        if (! $this->compareId) {
            return [];
        }
        $oldContent = app(StructureService::class)->version(auth()->user(), $this->novelId, $this->compareId)['content'];
        $current = $this->state();
        $old = $oldContent['nodes'];
        $index = fn ($rows) => collect($rows)->keyBy(fn ($r) => $r['type'].':'.$r['id'])->all();
        $before = $index($old);
        $after = $index($this->state()['nodes']);
        $result = [];
        foreach (array_unique([...array_keys($before), ...array_keys($after)]) as $key) {
            if (NovelIntentService::canonical($before[$key] ?? null) !== NovelIntentService::canonical($after[$key] ?? null)) {
                $result[] = ['key' => $key, 'before' => $before[$key] ?? null, 'after' => $after[$key] ?? null];
            }
        }
        $currentNovel = array_intersect_key($current['novel'], $oldContent['novel']);
        foreach (['novel' => [$oldContent['novel'], $currentNovel], 'threads' => [$oldContent['threads'], $current['threads']]] as $key => [$a,$b]) {
            if (NovelIntentService::canonical($a) !== NovelIntentService::canonical($b)) {
                $result[] = ['key' => $key, 'before' => ['title' => $key, 'content' => $a], 'after' => ['title' => $key, 'content' => $b]];
            }
        }

        return $result;
    }

    private function command(string $name, array $data): void
    {
        $key = $data['command_key'];
        unset($data['command_key']);
        try {
            app(StructureService::class)->execute(auth()->user(), $this->novelId, $name, $data, $key);
            Notification::make()->success()->title('已保存')->send();
        } catch (NovelProblem $e) {
            Notification::make()->danger()->title($e->getMessage())->send();
            throw new Halt;
        }
    }

    private function base(): array
    {
        return ['expected_version' => app(NovelIntentService::class)->novel(auth()->user(), $this->novelId)->row_version, 'command_key' => (string) Str::uuid()];
    }

    public function nodeAction(): Action
    {
        $fields = [
            Hidden::make('node_id'), Hidden::make('expected_version'), Hidden::make('command_key'),
            Select::make('type')->label('层级')->options(self::LABELS)->required()->live()->disabled(fn (Get $get) => filled($get('node_id')))->dehydrated(),
            Select::make('parent_id')->label('主父级')->visible(fn (Get $get) => $get('type') !== 'volume')->required(fn (Get $get) => $get('type') !== 'volume')->options(function (Get $get) {
                $type = StructureService::PARENTS[$get('type')] ?? null;

                return collect(app(StructureService::class)->working(auth()->user(), $this->novelId)['nodes'])->where('type', $type)->mapWithKeys(fn ($r) => [$r['id'] => $r['sequence'].' · '.$r['title']])->all();
            }),
            Select::make('source_version_id')->label('原始输入版本')->options(fn () => PremiseVersion::where('novel_id', $this->novelId)->get()->mapWithKeys(fn ($v) => [$v->id => '来源 v'.$v->version])->all())->required(),
            TextInput::make('sequence')->label('顺序（章序在全书唯一）')->integer()->minValue(1)->required(),
            TextInput::make('title')->label('具体标题')->required()->maxLength(255), Textarea::make('objective')->label('目标')->required(), Textarea::make('summary')->label('摘要'),
            TextInput::make('start_chapter')->label('起始章')->integer()->minValue(1)->visible(fn (Get $get) => in_array($get('type'), ['volume', 'story_arc']))->required(fn (Get $get) => in_array($get('type'), ['volume', 'story_arc'])),
            TextInput::make('end_chapter')->label('结束章')->integer()->minValue(1)->visible(fn (Get $get) => in_array($get('type'), ['volume', 'story_arc']))->required(fn (Get $get) => in_array($get('type'), ['volume', 'story_arc'])),
            Select::make('status')->label('成熟度（不代表已批准）')->options(['skeleton' => '骨架', 'detailed' => '已填写细节'])->required(),
            Select::make('thread_ids')->label('关联全书线索')->multiple()->visible(fn (Get $get) => $get('type') === 'story_arc')->options(fn () => collect(app(StructureService::class)->working(auth()->user(), $this->novelId)['threads'])->pluck('title', 'id')->all()),
        ];
        foreach (self::DETAILS as $key => $label) {
            $fields[] = $key === 'target_words' ? TextInput::make('details.'.$key)->label($label)->integer()->minValue(1) : Textarea::make('details.'.$key)->label($label)->maxLength(20000);
        }

        return Action::make('node')->label('新增 / 修改结构节点')->visible(fn () => $this->editable())->schema([...$fields, Textarea::make('reason')->label('本次变更原因')->required()])->fillForm(function (array $arguments) {
            $data = $this->base() + ['type' => 'volume', 'status' => 'skeleton', 'details' => [], 'thread_ids' => []];
            if (isset($arguments['node_type'],$arguments['node'])) {
                $row = collect(app(StructureService::class)->working(auth()->user(), $this->novelId)['nodes'])->first(fn ($r) => $r['type'] === $arguments['node_type'] && $r['id'] === (string) $arguments['node']);
                abort_unless($row, 404);
                $data = array_replace($data, $row, ['node_id' => $row['id'], 'thread_ids' => array_column($row['threads'] ?? [], 'id'), 'reason' => '']);
            }

            return $data;
        })->action(fn (array $data) => $this->command('node', $data));
    }

    public function threadAction(): Action
    {
        return Action::make('thread')->label('全书线索标识')->visible(fn () => $this->editable())->schema([Hidden::make('thread_id'), Hidden::make('expected_version'), Hidden::make('command_key'), TextInput::make('title')->label('线索名称')->required(), Textarea::make('objective')->label('线索目标')->required(), Textarea::make('reason')->label('变更原因')->required()])->fillForm(function (array $arguments) {
            $data = $this->base();
            if (isset($arguments['thread'])) {
                $row = collect($this->state()['threads'])->firstWhere('id', (string) $arguments['thread']);
                abort_unless($row, 404);
                $data = array_replace($data, $row, ['thread_id' => $row['id'], 'reason' => '']);
            }

return $data;
        })->action(fn (array $data) => $this->command('thread', $data));
    }

    public function snapshotAction(): Action
    {
        return Action::make('snapshot')->label('保存不可变结构版本')->visible(fn () => $this->editable())->modalDescription('固定当前结构及线索快照，旧版本不再改变。这不是批准规划，也不开放生成。')->schema([Hidden::make('expected_version'), Hidden::make('command_key'), Textarea::make('reason')->label('版本原因')->required()])->fillForm(fn () => $this->base())->action(fn (array $data) => $this->command('snapshot',$data));
    }
}
