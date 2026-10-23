<?php

namespace App\Filament\Resources\NovelResource\Pages;

use App\Filament\Resources\NovelResource;
use App\Filament\Support\IntentUi;
use App\Models\PremiseVersion;
use App\Models\RequirementVersion;
use App\Services\NovelIntentService;
use Filament\Actions\Action;
use Filament\Forms\Components\Hidden;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Resources\Pages\Page;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Str;
use Livewire\Attributes\Locked;
use Livewire\Attributes\Url;
use Livewire\WithPagination;

class NovelIntent extends Page
{
    use WithPagination;

    protected static string $resource = NovelResource::class;

    protected static ?string $title = '原始要求与覆盖矩阵';

    protected string $view = 'filament.novel-intent';

    #[Locked]
    public string $novelId;

    #[Url]
    public string $strength = '';

    #[Url]
    public string $decision = '';

    #[Url]
    public string $source = '';

    #[Url]
    public string $coverage = '';

    #[Url]
    public int $perPage = 25;

    public function updated($property): void
    {
        if (in_array($property, ['strength', 'decision', 'source', 'coverage', 'perPage'])) {
            $this->resetPage();
        }
    }

    public function mount(string $record): void
    {
        $this->novelId = app(NovelIntentService::class)->novel(auth()->user(), $record)->id;
    }

    public function snapshot(): array
    {
        $service = app(NovelIntentService::class);

        return $service->snapshot(auth()->user(), $service->novel(auth()->user(), $this->novelId));
    }

    public function rows(): LengthAwarePaginator
    {
        $rows = array_values(array_filter($this->snapshot()['requirements'], fn ($r) => (! $this->strength || $r['strength'] === $this->strength) && (! $this->decision || $r['decision_status'] === $this->decision) && (! $this->source || $r['source_version_id'] === $this->source) && (! $this->coverage || $r['coverage_status'] === $this->coverage)));
        $limit = in_array($this->perPage, [10, 25, 50, 100]) ? $this->perPage : 25;

        return new LengthAwarePaginator(array_slice($rows, ($this->getPage() - 1) * $limit, $limit), count($rows), $limit, $this->getPage());
    }

    public function sourceAction(): Action
    {
        return Action::make('source')->label('追加原始输入版本')->visible(fn () => $this->snapshot()['novel']['status'] !== 'archived')->schema([...NovelResource::inputFields(), Textarea::make('reason')->label('修改原因')->required(), Hidden::make('expected_version'), Hidden::make('command_key')])->fillForm(function () {
            $n = $this->snapshot()['novel'];

            return array_replace($n['settings'], ['title' => $n['title'], 'genre' => $n['genre'], 'expected_version' => $n['row_version'], 'command_key' => (string) Str::uuid()]);
        })->action(function (array $data) {
            $key = $data['command_key'];
            unset($data['command_key']);
            IntentUi::run('source', $this->novelId, $data, $key);
        });
    }

    public function requirementAction(): Action
    {
        return Action::make('requirement')->label('新增 / 修正要求')->visible(fn () => $this->snapshot()['novel']['status'] !== 'archived')->schema([
            Hidden::make('requirement_id'), Hidden::make('expected_version'), Hidden::make('command_key'),
            Select::make('source_version_id')->label('原始来源版本')->options(fn () => PremiseVersion::where('novel_id', $this->novelId)->orderBy('version')->get()->mapWithKeys(fn ($p) => [$p->id => '原始输入 v'.$p->version])->all())->required(),
            Textarea::make('source_quote')->label('要求原句（必须准确引用）')->required(),
            Textarea::make('content')->label('提炼条目')->required(), TextInput::make('category')->label('归类')->required(),
            Select::make('strength')->label('强度')->options(['hard' => '硬约束', 'preference' => '偏好', 'undecided' => '待决定'])->required(),
            Toggle::make('critical')->label('涉及核心世界规则、结局或人物生死'),
            Select::make('decision_status')->label('作者决定状态')->options(['unresolved' => '待决定', 'accepted' => '接受要求（不代表已覆盖）', 'conflict' => '存在冲突', 'waived' => '标记不适用（仅偏好）'])->required(),
            Textarea::make('conflict')->label('冲突说明'), Textarea::make('author_decision')->label('作者决定 / 不适用理由'), Textarea::make('reason')->label('本次修改原因')->required(),
        ])->fillForm(function (array $arguments) {
            $n = $this->snapshot()['novel'];
            $data = ['expected_version' => $n['row_version'], 'command_key' => (string) Str::uuid(), 'critical' => false, 'strength' => 'undecided', 'decision_status' => 'unresolved'];
            if (isset($arguments['requirement'])) {
                $row = collect($this->snapshot()['requirements'])->firstWhere('requirement_id', (string) $arguments['requirement']);
                abort_unless($row, 404);
                $data = array_replace($data, array_intersect_key($row, array_flip(['requirement_id', 'source_version_id', 'source_quote', 'content', 'category', 'strength', 'critical', 'decision_status', 'conflict', 'author_decision'])));
            }

            return $data;
        })->action(function (array $data) {
            $key = $data['command_key'];
            unset($data['command_key']);
            IntentUi::run('requirement', $this->novelId, $data, $key);
        });
    }

    public function history(): array
    {
        app(NovelIntentService::class)->novel(auth()->user(), $this->novelId);

        return RequirementVersion::where('novel_id', $this->novelId)->orderBy('requirement_id')->orderBy('version')->get()->toArray();
    }
}
