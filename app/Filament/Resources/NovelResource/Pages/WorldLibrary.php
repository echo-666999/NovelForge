<?php

namespace App\Filament\Resources\NovelResource\Pages;

use App\Exceptions\NovelProblem;
use App\Filament\Resources\NovelResource;
use App\Models\EntityDefinitionVersion;
use App\Models\EntityIntroductionPolicy;
use App\Models\PremiseVersion;
use App\Services\NovelIntentService;
use App\Services\WorldService;
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
use Filament\Schemas\Components\Utilities\Get;
use Filament\Support\Exceptions\Halt;
use Illuminate\Support\Str;
use Livewire\Attributes\Locked;
use Livewire\Attributes\Url;

class WorldLibrary extends Page
{
    protected static string $resource = NovelResource::class;

    protected static ?string $title = '世界实体与人物设定';

    protected string $view = 'filament.world-library';

    #[Locked]
    public string $novelId;

    #[Url]
    public string $type = '';

    #[Url]
    public string $status = '';

    #[Url]
    public string $q = '';

    #[Url]
    public string $role = '';

    #[Url]
    public string $faction = '';

    #[Url]
    public bool $unreferenced = false;

    #[Url]
    public string $cursor = '';

    #[Url]
    public string $entityId = '';

    #[Url]
    public string $versionId = '';

    #[Url]
    public string $compareId = '';

    public const TYPES = ['character' => '人物', 'location' => '地点', 'organization' => '组织', 'item' => '物品', 'ability' => '能力', 'rule' => '规则'];

    public const STATUS = ['candidate' => '候选', 'approved' => '当前版已批准', 'conflicting' => '存在冲突', 'retired' => '已停用'];

    public const PROFILE = ['identity' => '身份', 'appearance' => '外貌', 'values' => '价值观', 'goal' => '目标', 'bottom_line' => '底线', 'voice' => '口吻 / 声音', 'abilities' => '能力', 'limitations' => '能力限制', 'faction' => '阵营'];

    public function mount(string $record): void
    {
        $this->novelId = app(NovelIntentService::class)->novel(auth()->user(), $record)->id;
    }

    public function updated(string $key): void
    {
        if (in_array($key, ['type', 'status', 'q', 'role', 'faction', 'unreferenced'])) {
            $this->cursor = '';
        }if ($key === 'entityId') {
            $this->versionId = '';
            $this->compareId = '';
        }
    }

    public function listing(): array
    {
        return app(WorldService::class)->listing(auth()->user(), $this->novelId, ['type' => $this->type ?: null, 'status' => $this->status ?: null, 'q' => $this->q, 'role' => $this->role ?: null, 'faction' => $this->faction, 'unreferenced' => $this->unreferenced, 'cursor' => $this->cursor ?: null]);
    }

    public function detail(): ?array
    {
        return $this->entityId ? app(WorldService::class)->detail(auth()->user(), $this->novelId, $this->entityId) : null;
    }

    public function selected(): ?array
    {
        $d = $this->detail();
        if (! $d) {
            return null;
        }if (! $this->versionId) {
            return $d['versions'][0];
        }$v = collect($d['versions'])->firstWhere('id', $this->versionId);
        abort_unless($v, 404);

        return $v;
    }

    public function comparison(): ?array
    {
        if (! $this->compareId) {
            return null;
        }$v = collect($this->detail()['versions'] ?? [])->firstWhere('id', $this->compareId);
        abort_unless($v, 404);

        return $v;
    }

    public function editable(): bool
    {
        return in_array(app(NovelIntentService::class)->novel(auth()->user(), $this->novelId)->status, ['draft', 'planning']);
    }

    private function base(): array
    {
        return ['expected_version' => app(NovelIntentService::class)->novel(auth()->user(), $this->novelId)->row_version, 'command_key' => (string) Str::uuid()];
    }

    private function run(string $command, array $data): void
    {
        $key = $data['command_key'];
        unset($data['command_key']);
        try {
            app(WorldService::class)->execute(auth()->user(), $this->novelId, $command, $data, $key);
            Notification::make()->success()->title('已保存；正式动态状态未改变')->send();
        } catch (NovelProblem $e) {
            Notification::make()->danger()->title($e->getMessage())->send();
            throw new Halt;
        }
    }

    private function common(): array
    {
        return [Hidden::make('expected_version'), Hidden::make('command_key'), Textarea::make('reason')->label('本次原因')->required()->maxLength(2000)];
    }

    private function versionOptions(): array
    {
        return EntityDefinitionVersion::where('novel_id', $this->novelId)->where('status', 'sealed')->orderBy('entity_id')->orderByDesc('version')->get()->mapWithKeys(fn ($v) => [$v->id => $v->definition['name'].' · v'.$v->version.' (#'.$v->entity_id.')'])->all();
    }

    public function definitionAction(): Action
    {
        $profile = [Select::make('profile.role')->label('角色类型')->options(['primary' => '主角', 'supporting' => '配角', 'extra' => '路人'])->required(fn (Get $get) => $get('type') === 'character')];
        foreach (self::PROFILE as $k => $label) {
            $profile[] = Textarea::make('profile.'.$k)->label($label)->required(fn (Get $get) => $get('type') === 'character' && in_array($k, ['identity', 'voice', 'limitations']));
        }

        return Action::make('definition')->label('新增 / 修订候选设定')->visible(fn () => $this->editable())->schema([
            Hidden::make('entity_id'), Select::make('type')->label('实体类型')->options(self::TYPES)->required()->live()->disabled(fn (Get $get) => filled($get('entity_id')))->dehydrated(),
            TextInput::make('name')->label('名称')->required()->maxLength(255), Textarea::make('description')->label('描述')->required(), TagsInput::make('aliases')->label('别名'),
            Select::make('criticality')->label('重要性')->options(['core' => '核心 / 关键实体', 'minor' => '非关键'])->required(), Toggle::make('affects_main_plot')->label('影响主线或关键解决方案')->required(),
            TextInput::make('introduced_chapter')->label('计划引入章（不是实际出场证据）')->integer()->minValue(1)->required(),
            Select::make('source_version_id')->label('原始输入来源')->options(fn () => PremiseVersion::where('novel_id', $this->novelId)->get()->mapWithKeys(fn ($v) => [$v->id => '原始输入 v'.$v->version])->all())->required(), Textarea::make('source_quote')->label('准确来源原句')->required(),
            ...array_map(fn ($f) => $f->visible(fn (Get $get) => $get('type') === 'character'), $profile),
            Textarea::make('rule')->label('世界规则'), Textarea::make('scope')->label('规则适用范围'), Textarea::make('conflict')->label('待解决冲突（非空时禁止批准）'),
            Repeater::make('relations')->label('初始关系（引用具体设定版本）')->schema([Select::make('target_definition_version_id')->label('目标设定版本')->options(fn () => $this->versionOptions())->required(), TextInput::make('relation_type')->label('关系类型')->required(), Textarea::make('description')->label('初始关系说明')->required()])->defaultItems(0), ...$this->common(),
        ])->fillForm(function (array $arguments) {
            $data = $this->base() + ['type' => 'character', 'criticality' => 'core', 'affects_main_plot' => true, 'introduced_chapter' => 1, 'aliases' => [], 'profile' => [], 'relations' => []];
            if (isset($arguments['entity'])) {
                $d = app(WorldService::class)->detail(auth()->user(), $this->novelId, (string) $arguments['entity']);
                $data = array_replace($data, $d['versions'][0]['definition'], ['entity_id' => $d['entity']['id'], 'reason' => '']);
            }

return $data;
        })->action(fn (array $data) => $this->run('definition', $data));
    }

    public function approveAction(): Action
    {
        return $this->approvalAction('approve', '人工批准当前候选', false);
    }

    public function acceptMinorAction(): Action
    {
        return $this->approvalAction('acceptMinor', '按冻结策略接纳路人', true);
    }

    private function approvalAction(string $name, string $label, bool $automatic): Action
    {
        $fields = [Select::make('definition_version_id')->label('设定版本')->options(fn () => $this->versionOptions())->required()];
        if ($automatic) {
            $fields[] = Select::make('policy_version_id')->label('冻结策略版本')->options(fn () => EntityIntroductionPolicy::where('novel_id', $this->novelId)->orderByDesc('version')->get()->mapWithKeys(fn ($p) => [$p->id => '策略 v'.$p->version.($p->enabled ? ' 已开启' : ' 已关闭')])->all())->required();
        }

        return Action::make($name)->label($label)->visible(fn () => $this->editable())->modalDescription('批准仅授予设定引用资格，不改变正文、人物存亡、位置或知识。存在冲突或未批准初始关系时将拒绝。')->schema([...$fields, ...$this->common()])->fillForm(fn (array $arguments) => $this->base() + ['definition_version_id' => $arguments['definition'] ?? null])->action(fn (array $data) => $this->run($automatic ? 'accept-minor' : 'approve', $data));
    }

    public function policyAction(): Action
    {
        return Action::make('policy')->label('批准新引入策略版本')->visible(fn () => $this->editable())->modalDescription('默认关闭。开启后仅接纳无特殊能力、无初始关系/规则、无主线影响的非关键路人。限制依据作者分类，不替代后续正文语义校验。数量限制按策略版本累计。')->schema([Toggle::make('enabled')->label('允许受限路人自动接纳')->required(), TextInput::make('start_chapter')->label('起始章')->integer()->minValue(1)->required(), TextInput::make('end_chapter')->label('结束章')->integer()->minValue(1)->required(), TextInput::make('max_acceptances')->label('此版本累计接纳上限')->integer()->minValue(1)->maxValue(1000)->required(), ...$this->common()])->fillForm(fn () => $this->base() + ['enabled' => false])->action(fn (array $data) => $this->run('policy', $data));
    }

    public function mergeAliasesAction(): Action
    {
        return Action::make('mergeAliases')->label('合并别名为候选')->visible(fn () => $this->editable())->modalDescription('将来源名称/别名复制到目标的新候选版本。不删除来源实体、不重绑历史引用，也不自动认定同名为同一实体。')->schema([Hidden::make('entity_id'), Select::make('from_definition_version_id')->label('别名来源设定版本')->options(fn () => $this->versionOptions())->required(), ...$this->common()])->fillForm(fn (array $arguments) => $this->base() + ['entity_id' => $arguments['entity'] ?? null])->action(fn (array $data) => $this->run('merge-aliases', $data));
    }

    public function retireAction(): Action
    {
        return Action::make('retire')->label('停用实体')->visible(fn () => $this->editable())->modalDescription('保留全部版本和引用，禁止新的引用及设定修改。此操作不表示人物死亡。')->schema([Hidden::make('entity_id'), ...$this->common()])->fillForm(fn (array $arguments) => $this->base() + ['entity_id' => $arguments['entity'] ?? null])->action(fn (array $data) => $this->run('retire',$data));
    }
}
