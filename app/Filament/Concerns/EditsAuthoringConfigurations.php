<?php

namespace App\Filament\Concerns;

use App\Exceptions\NovelProblem;
use App\Filament\Support\JsonFields;
use App\ProvidersGateway\StructuredOutput;
use App\Services\AuthoringConfigurationService;
use App\Services\NovelIntentService;
use App\Support\ConfigurationExamples;
use Filament\Actions\Action;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TagsInput;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Locked;

/** UI04仅将表单映射到原服务；保存草稿、测试、发布、退役保持独立命令。 */
trait EditsAuthoringConfigurations
{
    use EditsJsonFields;

    public array $data = [];

    #[Locked]
    public bool $editorOpen = false;

    #[Locked]
    public string $sourceVersion = '';

    #[Locked]
    public array $sourceContent = [];

    #[Locked]
    public array $sourceIdentity = [];

    #[Locked]
    public string $saveKey = '';

    public string $notice = '';

    public string $importJson = '';

    #[Locked]
    public string $sourceJson = '';

    #[Locked]
    public ?array $configurationDetail = null;

    private function configurationAuthorize(): void
    {
        app(NovelIntentService::class)->authorize(auth()->user());
    }

    public function beginConfiguration(string $id = ''): void
    {
        $this->configurationAuthorize();
        $this->resetValidation();
        $this->notice = '';
        $p = ConfigurationExamples::all()[$this->configurationKind()];
        $this->sourceVersion = $id;
        $this->expandedJson = [];
        $this->advancedTrees = [];
        if ($id !== '') {
            $v = app(AuthoringConfigurationService::class)->version($this->configurationKind(), $id);
            $p = array_intersect_key($v, array_flip(['key', 'stage', 'language', 'content']));
            // 复制旧历史也以打开时的最新版本作乐观锁，不在保存时偷偷刷新。
            $p['expected_version'] = (int) DB::table($this->configurationKind().'_versions')->where('parent_id', $v['parent_id'])->max('version');
            $p['reason'] = '';
        } else {
            $p['key'] = '';
            $p['reason'] = '';
            if ($this->configurationKind() === 'route') {
                foreach (['prompt_version_id', 'style_version_id', 'primary_model_version_id'] as $key) {
                    $p['content'][$key] = '';
                }
            }
        }
        $this->sourceIdentity = array_intersect_key($p, array_flip(['key', 'stage', 'language', 'expected_version']));
        $this->loadConfigurationContent($p['content']);
        $this->syncJsonInputs();
        $this->configurationForm->fill($p);
        $this->saveKey = (string) Str::uuid();
        $this->importJson = '';
        $this->editorOpen = true;
        $this->dispatch('configuration-editor-opened');
    }

    private function loadConfigurationContent(array $content): void
    {
        $this->sourceContent = $content;
        $this->sourceJson = json_encode($content, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE);
        $this->trees = [];
        foreach (['variable_schema', 'output_schema', 'examples', 'rubric'] as $key) {
            if (array_key_exists($key, $content)) {
                $this->trees[$key] = JsonFields::node($content[$key]);
            }
        }
    }

    public function configurationForm(Schema $schema): Schema
    {
        $fixed = [
            TextInput::make('key')->label('配置键')->required()->maxLength(100)->readOnly(fn () => $this->sourceVersion !== '')->dehydrated(),
            TextInput::make('stage')->label('使用阶段')->required()->readOnly(fn () => $this->sourceVersion !== '')->dehydrated()->helperText('稳定阶段标识，例如 test.echo；阶段和语言不能通过新版本改名。'),
            TextInput::make('language')->label('语言')->required()->readOnly(fn () => $this->sourceVersion !== '')->dehydrated(),
            Textarea::make('reason')->label('本次变更说明')->required()->maxLength(2000)->rows(2)->columnSpanFull(),
        ];
        $fields = match ($this->configurationKind()) {
            'prompt' => [
                TextInput::make('content.purpose')->label('模板用途')->required()->maxLength(1000),
                Toggle::make('content.capability_requirements.structured_output')->label('要求结构化输出')->default(true),
                Textarea::make('content.body')->label('Prompt 正文')->required()->rows(9)->maxLength(30000)->columnSpanFull()->helperText('变量写作 {{json:变量名}}；仅作文本保存，不执行模板。下方逐字段声明变量和输出结构。'),
            ],
            'style' => [
                TextInput::make('content.genre')->label('适用题材')->required(),
                TextInput::make('content.voice.pov')->label('叙事视角')->required(),
                TextInput::make('content.voice.tone')->label('叙事语气')->required(),
                TextInput::make('content.voice.pace')->label('叙事节奏')->required(),
                Textarea::make('content.voice.description')->label('描写要求')->required()->rows(3)->columnSpanFull(),
                TagsInput::make('content.voice.forbidden_patterns')->label('禁用套路')->helperText('每项为完整文本，空列表允许。')->columnSpanFull(),
                Repeater::make('content.voice.licensed_examples')->label('授权样例')->simple(Textarea::make('text')->label('样例正文')->rows(3))->maxItems(10)->columnSpanFull(),
                TextInput::make('content.max_revision_rounds')->label('最多修订轮次')->integer()->minValue(0)->maxValue(10)->required(),
            ],
            'route' => [
                Select::make('content.prompt_version_id')->label('Prompt 版本')->options(fn () => $this->configurationOptions('prompt'))->searchable()->required(),
                Select::make('content.style_version_id')->label('文风版本')->options(fn () => $this->configurationOptions('style'))->searchable()->required(),
                Select::make('content.primary_model_version_id')->label('主模型能力版本')->options(fn () => $this->configurationOptions('model'))->searchable()->required()->live(),
                Select::make('content.mode')->native(false)->placeholder('请选择模式')->label('路由模式')->options(['offline_fake' => '离线模拟', 'production' => '生产声明（当前发布门禁不支持）'])->required(),
                TextInput::make('content.parameters.max_output_tokens')->label('输出 Token 上限')->integer()->minValue(1)->maxValue(100000)->required()->helperText('保存和离线回归继续由既有服务核验所选能力版本，声明能力不等于实测。'),
                Repeater::make('content.fallbacks')->label('有序备用模型')->schema([
                    Select::make('model_version_id')->label('备用能力版本')->options(fn () => $this->configurationOptions('model'))->searchable()->required(),
                    Toggle::make('approved')->label('明确批准此备用模型')->required(),
                    TextInput::make('reason')->label('批准理由')->required(),
                ])->maxItems(2)->reorderableWithButtons()->columnSpanFull(),
            ],
        };

        return $schema->statePath('data')->components([
            Section::make($this->sourceVersion === '' ? '新配置身份' : '复制为新版本 · 来源 #'.$this->sourceVersion)->schema($fixed)->columns(2),
            Section::make(match ($this->configurationKind()) {
                'prompt' => '模板内容', 'style' => '文风规则', 'route' => '实际版本引用与能力参数'
            })->schema($fields)->columns(2),
        ]);
    }

    /** 下拉取实际版本及状态，配置页不暴露供应商密钥或secret引用。 */
    public function configurationOptions(string $kind): array
    {
        $this->configurationAuthorize();
        if ($kind === 'model') {
            return DB::table('model_profile_versions as v')->join('model_profiles as p', 'p.id', '=', 'v.model_profile_id')->join('providers as s', 's.id', '=', 'v.provider_id')->orderByDesc('v.id')->get(['v.id', 'v.version', 'p.provider_model_id', 's.name'])->mapWithKeys(fn ($v) => [(string) $v->id => $v->name.' / '.$v->provider_model_id.' · v'.$v->version.' · 未验证 · #'.$v->id])->all();
        }
        abort_unless(in_array($kind, ['prompt', 'style', 'route'], true), 404);
        $parent = ['prompt' => 'prompt_templates', 'style' => 'style_profiles', 'route' => 'model_routes'][$kind];

        return DB::table($kind.'_versions as v')->join($parent.' as p', 'p.id', '=', 'v.parent_id')->orderByDesc('v.id')->get(['v.id', 'v.version', 'v.status', 'p.key', 'p.stage', 'p.language'])->mapWithKeys(fn ($v) => [(string) $v->id => $v->key.' · v'.$v->version.' · '.$v->status.' · '.$v->stage.' / '.$v->language.' · #'.$v->id])->all();
    }

    private function configurationPayload(array $state): array
    {
        $content = array_replace($this->sourceContent, $state['content'] ?? []);
        foreach (['voice', 'capability_requirements', 'parameters'] as $nested) {
            if (isset($content[$nested]) && is_array($content[$nested])) {
                $content[$nested] = array_replace($this->sourceContent[$nested] ?? [], $state['content'][$nested] ?? []);
            }
        }
        if ($this->configurationKind() === 'route') {
            foreach ($content['fallbacks'] ?? [] as $index => $row) {
                foreach ($this->sourceContent['fallbacks'] ?? [] as $original) {
                    if ((string) ($original['model_version_id'] ?? '') === (string) ($row['model_version_id'] ?? '')) {
                        $content['fallbacks'][$index] = array_replace($original, $row);
                        break;
                    }
                }
            }
        }
        $map = [];
        foreach ($this->trees as $key => $node) {
            $ui = 'trees.'.$key;
            $map += JsonFields::paths($node, $ui, 'content.'.$key);
            try {
                $value = JsonFields::value($node, $ui);
                $content[$key] = JsonFields::serviceValue($value, 'content.'.$key);
            } catch (ValidationException $e) {
                throw ValidationException::withMessages($this->configurationErrors($e->errors(), $map));
            }
            if (in_array($key, ['variable_schema', 'output_schema'], true)) {
                try {
                    app(StructuredOutput::class)->schema($content[$key]);
                } catch (NovelProblem $e) {
                    $this->advancedTrees[$key] = true;
                    throw ValidationException::withMessages([$ui => $e->getMessage().' 当前支持对象/列表/文本/整数/布尔/空值及有界关键词；原字段保留，未丢弃。']);
                }
            }
        }
        // HTML数值输入不得因字符串传输改变既有整数契约。
        if ($this->configurationKind() === 'style' && filter_var($content['max_revision_rounds'] ?? null, FILTER_VALIDATE_INT) !== false) {
            $content['max_revision_rounds'] = (int) $content['max_revision_rounds'];
        }
        if ($this->configurationKind() === 'route' && filter_var($content['parameters']['max_output_tokens'] ?? null, FILTER_VALIDATE_INT) !== false) {
            $content['parameters']['max_output_tokens'] = (int) $content['parameters']['max_output_tokens'];
        }
        $identity = $this->sourceVersion !== '' ? $this->sourceIdentity : array_intersect_key($state, array_flip(['key', 'stage', 'language']));

        return $identity + ['expected_version' => $this->sourceIdentity['expected_version'], 'reason' => $state['reason'] ?? '', 'content' => $content];
    }

    private function configurationErrors(array $errors, array $map = []): array
    {
        foreach ($this->trees as $key => $node) {
            $map += JsonFields::paths($node, 'trees.'.$key, 'content.'.$key);
        }
        // Repeater内部用UUID行键，服务错误使用列表下标；两者必须明确对应。
        foreach (array_keys($this->data['content']['fallbacks'] ?? []) as $index => $rowKey) {
            foreach (['model_version_id', 'approved', 'reason'] as $field) {
                $map['content.fallbacks.'.$index.'.'.$field] = 'data.content.fallbacks.'.$rowKey.'.'.$field;
            }
        }
        foreach (array_keys($this->data['content']['voice']['licensed_examples'] ?? []) as $index => $rowKey) {
            $map['content.voice.licensed_examples.'.$index] = 'data.content.voice.licensed_examples.'.$rowKey.'.text';
        }
        $out = [];
        foreach ($errors as $path => $messages) {
            // validateContent使用正文自身作为根，因此错误有时不带content前缀。
            $lookup = array_key_exists(explode('.', $path)[0], $this->sourceContent) ? 'content.'.$path : $path;
            $out[$map[$lookup] ?? (str_starts_with($lookup, 'trees.') || str_starts_with($lookup, 'data.') || $lookup === 'importJson' ? $lookup : 'data.'.$lookup)] = $messages;
        }

        foreach (array_keys($out) as $field) {
            if (str_starts_with($field, 'trees.')) {
                preg_match_all('/^trees\.[a-z_]+(?:\.children\.[0-9]+\.node)*/', $field, $matches);
                $nodePath = $matches[0][0] ?? '';
                while ($nodePath !== '') {
                    $this->expandedJson[$nodePath] = true;
                    $nodePath = preg_replace('/\.children\.[0-9]+\.node$/', '', $nodePath, 1, $count);
                    if (! $count) {
                        break;
                    }
                }
            }
        }

        return $out;
    }

    public function saveConfiguration(): void
    {
        $this->configurationAuthorize();
        if (! $this->editorOpen) {
            return;
        }
        $this->resetValidation();
        try {
            $p = $this->configurationPayload($this->configurationForm->getState());
            $v = app(AuthoringConfigurationService::class)->execute(auth()->user(), $this->configurationKind(), 'save', $p, $this->saveKey);
            $this->editorOpen = false;
            $this->dispatch('configuration-result');
            $this->notice = '草稿 v'.$v['version'].' 已保存（#'.$v['id'].'）；旧版本未改变。';
        } catch (ValidationException $e) {
            $this->dispatch('configuration-result');
            throw ValidationException::withMessages($this->configurationErrors($e->errors()));
        } catch (NovelProblem $e) {
            $this->dispatch('configuration-result');
            $this->addError('configuration', $e->getMessage());
        }
    }

    /** 统一使用Filament确认弹窗，保留确认步骤，不依赖浏览器原生对话框。 */
    public function configurationLifecycleAction(): Action
    {
        return Action::make('configurationLifecycle')
            ->requiresConfirmation()
            ->modalHeading(fn (array $arguments) => match ($arguments['operation'] ?? '') {
                'test' => '确认离线回归', 'publish' => '确认发布版本', 'retire' => '确认退役版本', default => '确认配置操作',
            })
            ->modalDescription(fn (array $arguments) => match ($arguments['operation'] ?? '') {
                'test' => '只执行确定性离线回归，不请求真实模型。',
                'publish' => '仅改变新运行的默认版本；旧运行、冻结快照与历史内容保持不变。',
                'retire' => '退役后不再作为新运行默认版本；旧运行引用仍保留。',
                default => '请核对操作目标。',
            })
            ->modalSubmitActionLabel('确认执行')
            ->modalCancelActionLabel('取消')
            ->action(fn (array $arguments) => $this->configurationAction($arguments['operation'] ?? '', $arguments['version'] ?? ''));
    }

    public function configurationAction(string $action, string $id): void
    {
        $this->configurationAuthorize();
        abort_unless(in_array($action, ['test', 'publish', 'retire'], true), 404);
        $this->resetValidation();
        try {
            $v = app(AuthoringConfigurationService::class)->execute(auth()->user(), $this->configurationKind(), $action, ['version_id' => $id], (string) Str::uuid());
            if (($this->configurationDetail['id'] ?? null) === $id) {
                $this->showConfiguration($id);
            }
            $this->dispatch('configuration-result');
            $this->notice = '版本 #'.$v['id'].' · '.$v['status'].'；离线回归不代表真实模型验证。';
        } catch (NovelProblem $e) {
            $this->dispatch('configuration-result');
            $this->addError('configuration', $e->getMessage());
        }
    }

    public function cancelConfiguration(): void
    {
        $this->editorOpen = false;
        $this->resetValidation();
    }

    public function importConfigurationContent(): void
    {
        $this->configurationAuthorize();
        abort_unless($this->editorOpen, 409);
        $value = JsonFields::parse($this->importJson);
        if (! $value instanceof \stdClass) {
            throw ValidationException::withMessages(['importJson' => '内容须为对象，仅导入正文，不改变配置身份或预期版本。']);
        }
        // 先完成全部解析再替换；复杂值继续放入类型树，保存时执行原契约。
        $content = json_decode(json_encode($value, JSON_THROW_ON_ERROR), true, 32, JSON_THROW_ON_ERROR);
        $this->loadConfigurationContent($content);
        $this->sourceJson = json_encode($value, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE);
        foreach (['variable_schema', 'output_schema', 'examples', 'rubric'] as $key) {
            if (property_exists($value, $key)) {
                $this->trees[$key] = JsonFields::node($value->{$key});
            }
        }
        $state = $this->data;
        $state['content'] = $content;
        $this->syncJsonInputs();
        $this->configurationForm->fill($state);
        $this->notice = '内容已导入编辑区，尚未保存；不支持的关键词会明确阻止保存。';
    }

    /** 详情是只读安全投影，延迟加载，避免列表将所有历史大正文铺进DOM。 */
    public function showConfiguration(string $id): void
    {
        $this->configurationAuthorize();
        $this->configurationDetail = app(AuthoringConfigurationService::class)->version($this->configurationKind(), $id);
        $this->dispatch('configuration-detail-opened');
    }

    /** 导出保留类型树与未知键；不能保存的值也可先带走，不以有损转换掩盖。 */
    public function exportConfigurationContent()
    {
        $this->configurationAuthorize();
        abort_unless($this->editorOpen, 409);
        $content = json_decode($this->sourceJson, false, 32, JSON_THROW_ON_ERROR);
        // 只覆盖原生表单声明过的路径，未知兄弟字段继续从原JSON保留类型。
        $paths = ['purpose', 'body', 'capability_requirements.structured_output', 'genre', 'voice.pov', 'voice.tone', 'voice.pace', 'voice.description', 'voice.forbidden_patterns', 'voice.licensed_examples', 'max_revision_rounds', 'prompt_version_id', 'style_version_id', 'primary_model_version_id', 'mode', 'parameters.max_output_tokens', 'fallbacks'];
        foreach ($paths as $path) {
            if (Arr::has($this->data['content'] ?? [], $path)) {
                $value = data_get($this->data['content'], $path);
                // 导出业务列表，绝不导出Repeater的内部UUID或simple字段外壳。
                if ($path === 'fallbacks') {
                    $value = array_values($value ?? []);
                } elseif ($path === 'voice.licensed_examples') {
                    $value = array_map(fn ($row) => is_array($row) && array_key_exists('text', $row) ? $row['text'] : $row, array_values($value ?? []));
                }
                if (in_array($path, ['max_revision_rounds', 'parameters.max_output_tokens'], true) && filter_var($value, FILTER_VALIDATE_INT) !== false) {
                    $value = (int) $value;
                }
                data_set($content, $path, $value);
            }
        }
        foreach ($this->trees as $key => $node) {
            $content->{$key} = JsonFields::value($node, 'trees.'.$key);
        }
        $json = json_encode($content, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);

        return response()->streamDownload(fn () => print ($json), 'configuration-content.json', ['Content-Type' => 'application/json']);
    }

    /** 未声明扩展字段逐字段只读，保留原JSON类型，不能凭表单缺失静默清理。 */
    public function configurationExtras(): array
    {
        if ($this->sourceJson === '') {
            return [];
        }
        $content = json_decode($this->sourceJson, false, 32, JSON_THROW_ON_ERROR);
        $known = array_keys(ConfigurationExamples::all()[$this->configurationKind()]['content']);
        $extras = [];
        foreach (get_object_vars($content) as $key => $value) {
            if (! in_array($key, $known, true)) {
                $extras[$key] = $value;
            }
        }
        foreach (['voice' => ['pov', 'tone', 'pace', 'description', 'forbidden_patterns', 'licensed_examples'], 'capability_requirements' => ['structured_output'], 'parameters' => ['max_output_tokens']] as $key => $fields) {
            if (($content->{$key} ?? null) instanceof \stdClass) {
                foreach (get_object_vars($content->{$key}) as $name => $value) {
                    if (! in_array($name, $fields, true)) {
                        $extras[$key.'.'.$name] = $value;
                    }
                }
            }
        }

        return $extras;
    }

    /** 从已有结构复制样例，日常新增不要求作者手工搭建variables/output等键。 */
    public function addConfigurationExample(): void
    {
        if (($this->trees['examples']['type'] ?? null) !== 'array' || count($this->trees['examples']['children']) >= 30) {
            throw ValidationException::withMessages(['trees.examples' => '样例须为列表且最多30条。']);
        }
        $node = $this->trees['examples']['children'][0]['node'] ?? JsonFields::node(ConfigurationExamples::all()[$this->configurationKind()]['content']['examples'][0]);
        $this->trees['examples']['children'][] = ['key' => '', 'node' => $node];
        $i = count($this->trees['examples']['children']) - 1;
        $this->expandedJson['trees.examples.children.'.$i.'.node'] = true;
        $this->syncJsonInputs();
    }

    public function addRubricDimension(): void
    {
        if (($this->trees['rubric']['type'] ?? null) !== 'object' || count($this->trees['rubric']['children']) >= 20) {
            throw ValidationException::withMessages(['trees.rubric' => '评分维度须为对象且最多20项。']);
        }
        $names = array_column($this->trees['rubric']['children'], 'key');
        $n = count($names) + 1;
        while (in_array('dimension_'.$n, $names, true)) {
            $n++;
        }
        $node = JsonFields::node(['description' => '', 'threshold' => 0]);
        $node['children'][1]['node']['value'] = '';
        $this->trees['rubric']['children'][] = ['key' => 'dimension_'.$n, 'node' => $node];
        $this->expandedJson['trees.rubric.children.'.(count($names)).'.node'] = true;
        $this->syncJsonInputs();
    }

    public function insertPromptVariable(int $index): void
    {
        $this->configurationAuthorize();
        abort_unless($this->configurationKind() === 'prompt', 404);
        $properties = $this->schemaKeyword('trees.variable_schema', 'properties');
        $name = $properties === false ? null : data_get($this->trees, 'variable_schema.children.'.$properties.'.node.children.'.$index.'.key');
        if (! is_string($name) || ! preg_match('/^[a-z][a-z0-9_]*$/D', $name)) {
            throw ValidationException::withMessages(['trees.variable_schema' => '正文变量名须为小写英文开头的字母、数字或下划线。']);
        }
        $token = '{{json:'.$name.'}}';
        if (! str_contains($this->data['content']['body'] ?? '', $token)) {
            $this->data['content']['body'] = ($this->data['content']['body'] ?? '')."\n".$token;
        }
    }
}
