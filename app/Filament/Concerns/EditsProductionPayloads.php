<?php

namespace App\Filament\Concerns;

use App\Filament\Support\JsonFields;
use App\Filament\Support\ProductionSchemas;
use App\Services\StructureService;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Locked;

/** 固定契约字段复用UI04锁定类型树和扁平绑定；旧字符串属性仅作为兼容传输层。 */
trait EditsProductionPayloads
{
    use EditsJsonFields;

    #[Locked]
    public array $productionHashes = [];

    public array $productionImports = [];

    protected function jsonTreeRoots(): array
    {
        return array_map(Str::snake(...), array_keys($this->productionPayloads()));
    }

    /** 仅页面显式声明的字段可被加载，不能借属性名读写其他页面状态。 */
    public function productionEditor(string $property): array
    {
        $schema = $this->productionPayloads()[$property] ?? throw ValidationException::withMessages(['production' => '未知的生产字段。']);
        $root = Str::snake($property);
        $raw = $this->$property;
        $hash = hash('sha256', $raw);
        if (! isset($this->trees[$root]) || ($this->productionHashes[$root] ?? null) !== $hash) {
            $value = trim($raw) === '' ? ProductionSchemas::seed($schema) : JsonFields::parse($raw);
            $this->trees[$root] = JsonFields::node($value);
            $this->productionHashes[$root] = $hash;
            $this->syncJsonInputs();
        }

        return ['node' => $this->trees[$root], 'path' => 'trees.'.$root, 'schema' => $schema];
    }

    /** 保存前再次从当前树校验，输入错误不能悄悄改为提交旧的有效字符串。 */
    protected function productionValue(string $property, string $errorPrefix = ''): mixed
    {
        $e = $this->productionEditor($property);
        $this->productionErrorRoots[Str::snake($property)] = ['prefix' => $errorPrefix, 'schema' => $e['schema']];
        $value = JsonFields::serviceValue(JsonFields::value($e['node'], $e['path']), $e['path']);

        return $value;
    }

    /** 路径与结构只由锁定树推导，客户端不提供Schema或任意动态属性路径。 */
    public function productionSchemaAt(string $path): array
    {
        $this->treePath($path);
        $parts = explode('.', $path);
        $root = $parts[1];
        $property = collect(array_keys($this->productionPayloads()))->first(fn ($p) => Str::snake($p) === $root);
        $schema = $this->productionPayloads()[$property];
        $node = $this->trees[$root];
        for ($i = 2; $i < count($parts); $i += 3) {
            $row = $node['children'][(int) $parts[$i + 1]] ?? throw ValidationException::withMessages(['production' => '节点不存在。']);
            $schema = ($node['type'] === 'array' ? $schema['items'] ?? [] : $schema['properties'][$row['key']] ?? []);
            $node = $row['node'];
        }

        return $schema;
    }

    public function addProductionField(string $path, string $key = ''): void
    {
        $schema = $this->productionSchemaAt($path);
        $node = data_get($this, $path);
        if ($node['type'] === 'array') {
            if (count($node['children']) >= min(1000, $schema['maxItems'] ?? 1000)) {
                throw ValidationException::withMessages([$path => '已达到契约列表上限。']);
            }
            $node['children'][] = ['key' => '', 'node' => JsonFields::node(ProductionSchemas::seed($schema['items'] ?? ['type' => 'string']))];
        } else {
            if ($node['type'] !== 'object' || ! isset($schema['properties'][$key]) || in_array($key, array_column($node['children'], 'key'), true)) {
                throw ValidationException::withMessages([$path => '字段不存在或已经添加。']);
            }
            $node['children'][] = ['key' => $key, 'node' => JsonFields::node(ProductionSchemas::seed($schema['properties'][$key]))];
        }
        data_set($this, $path, $node);
        $this->syncJsonInputs();
    }

    /** 高级导入失败不触碰原树；重复键、深度与200KB限制沿用既有组件。 */
    public function importProductionPayload(string $property): void
    {
        if (! array_key_exists($property, $this->productionPayloads())) {
            throw ValidationException::withMessages(['production' => '未知字段。']);
        }
        $this->beginProductionAction();
        $raw = $this->productionImports[$property] ?? '';
        try {
            JsonFields::parse($raw);
        } catch (ValidationException $error) {
            // 导入错误只属于当前输入框，不能污染其他载荷的高级导入区。
            $this->setErrorBag(['productionImports.'.$property => $error->validator->errors()->all()]);
            $this->dispatch('nf-validation-failed', sourceId: $this->getId());

            return;
        }
        $this->$property = $raw;
        $this->productionEditor($property);
    }

    /** 切换未知用量是作者明确的清空动作，仅契约允许nullable的字段可操作。 */
    public function setProductionUsage(string $path, bool $known): void
    {
        $schema = $this->productionSchemaAt($path);
        if (! ($schema['nullable'] ?? false)) {
            throw ValidationException::withMessages([$path => '此字段不接受未知值。']);
        }
        unset($schema['default']);
        data_set($this, $path, JsonFields::node($known ? ProductionSchemas::seed($schema) : null));
        $this->syncJsonInputs();
    }

    /** 按字段含义列出同书实际引用；local事件键来自当前锁定编辑树，不猜测关联ID。 */
    public function productionFieldChoices(string $path, string $field): ?array
    {
        $kind = ['model_version_id' => 'model', 'request_id' => 'request', 'scene_version_id' => 'scene_version', 'definition_version_id' => 'definition', 'entity_definition_version_id' => 'definition', 'entity_version_ids' => 'definition', 'requirement_id' => 'requirement', 'requirement_version_id' => 'requirement', 'knower_id' => 'character', 'source_knower_id' => 'character', 'holder_id' => 'character', 'previous_owner_id' => 'character', 'from_entity_id' => 'character', 'to_entity_id' => 'character', 'issue_ids' => 'review_issue'][$field] ?? null;
        if (in_array($field, ['node_id', 'parent_id'], true)) {
            $parent = preg_replace('/\.children\.[0-9]+\.node$/', '', $path);
            $rows = data_get($this, $parent.'.children', []);
            $type = collect($rows)->firstWhere('key', 'type')['node']['value'] ?? '';
            if ($field === 'parent_id') {
                $type = StructureService::PARENTS[$type] ?? '';
            }

            return in_array($type, ['volume', 'story_arc', 'chapter', 'scene'], true) ? $this->productionChoices($type) : [];
        }
        if ($kind) {
            return $this->productionChoices($kind);
        }
        if (in_array($field, ['event_ref', 'fact_ref', 'acquired_ref', 'predecessor_ref', 'successor_ref'], true)) {
            $out = [];
            foreach ($this->productionChoices('event') as $id => $text) {
                $out['event:'.$id] = $text;
            }
            $root = explode('.', $path)[1];
            $events = collect($this->trees[$root]['children'])->firstWhere('key', 'events')['node']['children'] ?? [];
            foreach ($events as $row) {
                $key = collect($row['node']['children'])->firstWhere('key', 'key')['node']['value'] ?? '';
                if ($key !== '') {
                    $out['local:'.$key] = '本次候选事件 · '.$key;
                }
            }

            return $out;
        }

        return null;
    }

    /** 默认展开的浅层对象也可真实收起；深对象继续按需渲染。 */
    public function toggleProductionNode(string $path): void
    {
        $this->treePath($path);
        $node = data_get($this, $path);
        $default = $node['type'] === 'object' && substr_count($path, '.children.') <= 2;
        $this->expandedJson[$path] = ! ($this->expandedJson[$path] ?? $default);
    }
}
