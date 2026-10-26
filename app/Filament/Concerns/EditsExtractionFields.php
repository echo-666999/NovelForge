<?php

namespace App\Filament\Concerns;

use App\Extraction\ExtractionContract;
use App\Filament\Support\JsonFields;
use App\Services\ExtractionService;
use App\Services\NovelIntentService;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/** UI05：候选响应按契约字段编辑，树与路径锁定，前端继续采用UI04扁平绑定。 */
trait EditsExtractionFields
{
    use EditsJsonFields { updatedJsonInputs as protected updateResponseInput; }

    public array $activeRows = [];

    public array $priceSelections = [];

    public string $importResponse = '';

    public string $historyQuery = '';

    public int $historyPage = 1;

    protected ?array $selectionCache = null;

    /** 空值字段明确选择新值时才恢复契约类型，避免null吞掉用户选项。 */
    public function updatedJsonInputs(mixed $value, string $key): void
    {
        if (! is_scalar($value)) {
            $this->updateResponseInput($value, $key);

            return;
        }
        $path = $this->jsonPaths[$key] ?? '';
        if (str_ends_with($path, '.value') && $value !== '') {
            $parent = substr($path, 0, -6);
            if (data_get($this, $parent.'.type') === 'null') {
                $schema = $this->responseSchema($parent);
                if (isset($schema['type']) && in_array($schema['type'], ['string', 'integer', 'boolean'], true)) {
                    data_set($this, $parent.'.type', $schema['type']);
                }
            }
        }
        $this->updateResponseInput($value, $key);
    }

    protected function jsonTreeRoots(): array
    {
        return ['extraction'];
    }

    /** 内部方法不作为Livewire动作开放，避免客户端传入任意树。 */
    protected function loadResponseFields(array $value): void
    {
        $this->trees = ['extraction' => JsonFields::node($value)];
        $this->activeRows = [];
        $this->syncJsonInputs();
    }

    /** 字段路径只由实际契约和已锁定的树派生，不接受客户端任意数据路径。 */
    public function responseRowTitle(array $node): string
    {
        $parts = [];
        foreach ($node['children'] as $field) {
            if (in_array($field['key'], ['key', 'fact_ref', 'event_ref', 'relation_type', 'method'], true)) {
                $parts[] = (string) $field['node']['value'];
            }
        }

        return implode(' · ', $parts) ?: '待填写条目';
    }

    public function responseField(string $key): string
    {
        foreach ($this->trees['extraction']['children'] ?? [] as $i => $row) {
            if ($row['key'] === $key) {
                return 'trees.extraction.children.'.$i.'.node';
            }
        }
        throw ValidationException::withMessages(['response' => '响应缺少字段：'.$key]);
    }

    public function responseSchema(string $path): array
    {
        $this->treePath($path);
        $schema = ExtractionContract::schema();
        $node = $this->trees['extraction'];
        preg_match_all('/\.children\.([0-9]+)\.node/', $path, $matches);
        foreach ($matches[1] as $i) {
            $row = $node['children'][(int) $i];
            $schema = $node['type'] === 'array' ? ($schema['items'] ?? []) : ($schema['properties'][$row['key']] ?? []);
            $node = $row['node'];
        }

        return $schema;
    }

    protected function responseDefault(array $schema): mixed
    {
        return match ($schema['type'] ?? '') {
            'object' => collect($schema['properties'])->only($schema['required'] ?? array_keys($schema['properties']))->map(fn ($v) => $this->responseDefault($v))->all(),
            'array' => [], 'integer' => '', 'boolean' => false, default => '',
        };
    }

    public function addResponseRow(string $path): void
    {
        $schema = $this->responseSchema($path);
        $node = data_get($this, $path);
        if (($schema['type'] ?? '') !== 'array' || count($node['children']) >= ($schema['maxItems'] ?? 200)) {
            throw ValidationException::withMessages([$path => '列表无效或已达到契约上限。']);
        }
        $value = $this->responseDefault($schema['items']);
        // 事件键保持稳定，排序只影响叙述顺序，不重写已有引用。
        if (isset($value['key'])) {
            $keys = [];
            foreach ($node['children'] as $r) {
                foreach ($r['node']['children'] as $field) {
                    if ($field['key'] === 'key') {
                        $keys[] = $field['node']['value'];
                    }
                }
            }
            $n = count($keys) + 1;
            while (in_array('event_'.$n, $keys, true)) {
                $n++;
            }
            $value['key'] = 'event_'.$n;
            $value['evidence']['source_hash'] = $this->body['body_hash'] ?? '';
        }
        $new = JsonFields::node($value);
        // 未填写的整数仍保留整数类型，保存前由编解码器定位缺值。
        if (isset($value['evidence'])) {
            foreach ($new['children'] as &$field) {
                if ($field['key'] === 'evidence') {
                    foreach ($field['node']['children'] as &$part) {
                        if (in_array($part['key'], ['start_offset', 'end_offset'], true)) {
                            $part['node'] = ['type' => 'integer', 'value' => '', 'children' => []];
                        }
                    }
                    unset($part);
                }
            }
            unset($field);
        }
        $node['children'][] = ['key' => '', 'node' => $new];
        data_set($this, $path, $node);
        $this->activeRows['r'.md5($path)] = count($node['children']) - 1;
        $this->syncJsonInputs();
    }

    public function addResponseField(string $path, string $key): void
    {
        $schema = $this->responseSchema($path);
        if (! isset($schema['properties'][$key])) {
            throw ValidationException::withMessages([$path => '不支持此字段。']);
        }
        $node = data_get($this, $path);
        if (in_array($key, array_column($node['children'], 'key'), true)) {
            return;
        }
        $v = $this->responseDefault($schema['properties'][$key]);
        $field = JsonFields::node($v);
        if (($schema['properties'][$key]['type'] ?? '') === 'integer') {
            $field['type'] = 'integer';
        }
        $node['children'][] = ['key' => $key, 'node' => $field];
        data_set($this, $path, $node);
        $this->syncJsonInputs();
    }

    public function removeResponseField(string $path, int $index): void
    {
        $schema = $this->responseSchema($path);
        $node = data_get($this, $path);
        $key = $node['children'][$index]['key'] ?? null;
        if ($key === null || in_array($key, $schema['required'] ?? [], true)) {
            throw ValidationException::withMessages([$path => '必填字段不能移除。']);
        }
        $this->changeJsonRow($path, $index, 'remove');
    }

    public function importResponseFields(): void
    {
        $this->resetValidation();
        $this->notice = '';
        $v = JsonFields::parse($this->importResponse);
        if (! $v instanceof \stdClass) {
            throw ValidationException::withMessages(['importJson' => '响应必须是对象。']);
        }
        foreach (['events', 'dependencies', 'knowledge', 'relationships', 'foreshadows'] as $key) {
            if (! isset($v->{$key}) || ! is_array($v->{$key})) {
                throw ValidationException::withMessages(['importJson' => '五类字段必须是列表，原表单未覆盖。']);
            }
        }
        $this->trees = ['extraction' => JsonFields::node($v)];
        $this->activeRows = [];
        $this->syncJsonInputs();
        $this->notice = '已载入逐字段编辑区，尚未验证或保存。';
    }

    protected function responsePayload(): array
    {
        return JsonFields::serviceValue(JsonFields::value($this->trees['extraction'], 'trees.extraction'), 'trees.extraction');
    }

    public function focusExtractionIssue(string $key): void
    {
        $path = $this->responseField('events');
        foreach (data_get($this, $path.'.children', []) as $i => $row) {
            foreach ($row['node']['children'] as $field) {
                if ($field['key'] === 'key' && $field['node']['value'] === $key) {
                    $this->activeRows['r'.md5($path)] = $i;
                    $this->dispatch('extraction-editor-focused', id: 'er-'.md5($path).'-'.$i);

                    return;
                }
            }
        }
        $this->addError('response', '问题事件不在当前草稿；请先复制对应历史响应。');
    }

    public function exportResponseFields()
    {
        $json = json_encode(JsonFields::value($this->trees['extraction']), JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR);

        return response()->streamDownload(fn () => print ($json), 'extraction-response.json');
    }

    /** 选择器仅读取当前授权小说的真实版本；不初始化基线或查询模型。 */
    public function selections(): array
    {
        if ($this->novel === '') {
            return [];
        }
        if ($this->selectionCache !== null) {
            return $this->selectionCache;
        }
        app(NovelIntentService::class)->novel(auth()->user(), $this->novel);
        $n = $this->novel;
        $definitions = DB::table('entities as e')->join('entity_definition_versions as d', 'd.id', '=', 'e.approved_version_id')->where('e.novel_id', $n)->where('e.retired', false)->get(['e.id', 'e.name', 'e.type', 'd.id as definition_id', 'd.version']);
        $allEntities = $definitions->mapWithKeys(fn ($e) => [(string) $e->id => $e->name.' · '.$e->type.' · #'.$e->id])->all();
        $characters = $definitions->where('type', 'character')->mapWithKeys(fn ($e) => [(string) $e->id => $e->name.' · #'.$e->id])->all();
        $versions = DB::table('chapter_versions as v')->join('chapters as c', 'c.id', '=', 'v.node_id')->where('v.novel_id', $n)->orderBy('c.sequence')->orderByDesc('v.version')->get(['v.id', 'v.title', 'v.version', 'c.sequence'])->mapWithKeys(fn ($v) => [(string) $v->id => '第'.$v->sequence.'章 '.$v->title.' · v'.$v->version.' · #'.$v->id])->all();
        $attempts = app(ExtractionService::class)->listing(auth()->user(), $n);
        $baselines = [];
        $parents = [];
        $sequence = isset($versions[$this->version]) ? DB::table('chapter_versions as v')->join('chapters as c', 'c.id', '=', 'v.node_id')->where('v.novel_id', $n)->where('v.id', $this->version)->value('c.sequence') : null;
        foreach ($attempts as $a) {
            $label = '第'.$a['chapter_sequence'].'章 · #'.$a['id'].' · '.$a['display_status'];
            if ($sequence !== null && $a['chapter_sequence'] === $sequence - 1 && app(ExtractionService::class)->baseline(auth()->user(), $n, $a['id'])['baseline_eligible']) {
                $baselines[$a['id']] = $label;
            }
            if ($a['chapter_version_id'] === $this->version) {
                $parents[$a['id']] = $label;
            }
        }
        $previous = $this->baseline !== '' ? app(ExtractionService::class)->read(auth()->user(), $n, $this->baseline)['events'] : [];
        $refs = [];
        $eventIds = [];
        foreach ($previous as $e) {
            $refs['event:'.$e['id']] = '前章 #'.$e['id'].' · '.$e['attribute'];
            $eventIds[$e['id']] = $refs['event:'.$e['id']];
        }
        foreach (data_get($this, $this->responseField('events').'.children', []) as $row) {
            foreach ($row['node']['children'] ?? [] as $field) {
                if ($field['key'] === 'key' && $field['node']['value'] !== '') {
                    $refs['local:'.$field['node']['value']] = '本次 · '.$field['node']['value'];
                }
            }
        }
        $entitiesByType = fn ($type) => $definitions->where('type', $type)->mapWithKeys(fn ($e) => [(string) $e->id => $e->name.' · #'.$e->id])->all();
        $this->selectionCache = compact('versions', 'baselines', 'parents', 'characters', 'allEntities', 'refs', 'eventIds') + [
            'definitions' => $definitions->mapWithKeys(fn ($e) => [(string) $e->definition_id => $e->name.' · v'.$e->version.' · #'.$e->definition_id])->all(),
            'locations' => $entitiesByType('location'), 'abilities' => $entitiesByType('ability'),
            'rules' => $definitions->where('type', 'rule')->mapWithKeys(fn ($e) => [(string) $e->definition_id => $e->name.' · #'.$e->definition_id])->all(),
            'contexts' => DB::table('story_context_versions')->where('novel_id', $n)->orderByDesc('version')->get(['id', 'version'])->mapWithKeys(fn ($v) => [(string) $v->id => '作者时间线 v'.$v->version.' · #'.$v->id])->all(),
            'foreshadows' => DB::table('foreshadow_plans')->where('novel_id', $n)->orderByDesc('version')->get(['id', 'name', 'version'])->mapWithKeys(fn ($v) => [(string) $v->id => $v->name.' · v'.$v->version.' · #'.$v->id])->all(),
            'runs' => DB::table('generation_runs')->where('novel_id', $n)->where('frozen_config->schema_version', 'extraction-v1')->orderByDesc('id')->get(['id', 'status'])->mapWithKeys(fn ($v) => [(string) $v->id => '离线运行 #'.$v->id.' · '.$v->status])->all(),
        ];

        return $this->selectionCache;
    }
}
