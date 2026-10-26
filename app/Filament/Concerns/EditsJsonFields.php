<?php

namespace App\Filament\Concerns;

use App\Filament\Support\JsonFields;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Locked;

/** 操作只允许类型树内部节点，不能借动态路径修改页面身份或其他公有属性。 */
trait EditsJsonFields
{
    #[Locked]
    public array $trees = [];

    public array $jsonInputs = [];

    #[Locked]
    public array $jsonPaths = [];

    /** 扁平绑定表是类型树的可编辑投影；映射不能由客户端提供或重定向。 */
    private function syncJsonInputs(): void
    {
        $this->jsonPaths = [];
        $this->jsonInputs = [];
        $walk = function (array $node, string $path) use (&$walk): void {
            foreach (['type', 'value'] as $field) {
                $id = substr(JsonFields::binding($path.'.'.$field), strlen('jsonInputs.'));
                $this->jsonPaths[$id] = $path.'.'.$field;
                $this->jsonInputs[$id] = $node[$field];
            }
            foreach ($node['children'] as $index => $row) {
                $field = $path.'.children.'.$index.'.key';
                $id = substr(JsonFields::binding($field), strlen('jsonInputs.'));
                $this->jsonPaths[$id] = $field;
                $this->jsonInputs[$id] = $row['key'];
                $walk($row['node'], $path.'.children.'.$index.'.node');
            }
        };
        foreach ($this->trees as $key => $node) {
            $walk($node, 'trees.'.$key);
        }
    }

    public function updatedJsonInputs(mixed $value, string $key): void
    {
        $path = $this->jsonPaths[$key] ?? null;
        if (! is_string($path) || ! is_scalar($value)) {
            throw ValidationException::withMessages(['trees' => '字段索引或值类型无效。']);
        }
        data_set($this, $path, $value);
        $this->updatedTrees($value, substr($path, strlen('trees.')));
        $this->syncJsonInputs();
    }

    public array $expandedJson = [];

    public array $advancedTrees = [];

    /** 按需渲染递归节点，折叠区不生成全部控件，避免隐藏DOM占满内存。 */
    public function toggleJsonNode(string $path): void
    {
        $this->treePath($path);
        $this->expandedJson[$path] = ! ($this->expandedJson[$path] ?? false);
    }

    public function toggleAdvancedTree(string $key): void
    {
        if (! in_array($key, ['variable_schema', 'output_schema'], true)) {
            throw ValidationException::withMessages(['trees' => '未知结构。']);
        }
        $this->advancedTrees[$key] = ! ($this->advancedTrees[$key] ?? false);
    }

    private function treePath(string $path): void
    {
        if (! preg_match('/^trees\.(variable_schema|output_schema|examples|rubric)(\.children\.[0-9]+\.node)*$/D', $path) || substr_count($path, '.children.') > 24 || ! is_array(data_get($this, $path))) {
            throw ValidationException::withMessages(['trees' => '字段路径不存在。']);
        }
    }

    public function addJsonField(string $path): void
    {
        $this->treePath($path);
        $node = data_get($this, $path);
        if (! in_array($node['type'], ['object', 'array'], true) || count($node['children']) >= 1000) {
            throw ValidationException::withMessages([$path => '仅对象或列表可以添加，最多1000行。']);
        }
        $node['children'][] = ['key' => '', 'node' => JsonFields::node('')];
        data_set($this, $path, $node);
        $this->syncJsonInputs();
    }

    public function changeJsonRow(string $path, int $index, string $action): void
    {
        $this->treePath($path);
        $node = data_get($this, $path);
        if (! isset($node['children'][$index]) || ! in_array($action, ['remove', 'up', 'down'], true)) {
            throw ValidationException::withMessages([$path => '行操作无效。']);
        }
        if ($action === 'remove') {
            array_splice($node['children'], $index, 1);
        } else {
            $target = $index + ($action === 'up' ? -1 : 1);
            if (isset($node['children'][$target])) {
                [$node['children'][$index], $node['children'][$target]] = [$node['children'][$target], $node['children'][$index]];
            }
        }
        data_set($this, $path, $node);
        $this->syncJsonInputs();
    }

    /** Schema类型切换只补缺失结构，不擅自删掉旧约束或扩展关键词。 */
    public function updatedTrees(mixed $value, string $key): void
    {
        if (! preg_match('/^(variable_schema|output_schema)(.*)\.children\.([0-9]+)\.node\.value$/D', $key, $m)) {
            return;
        }
        $path = 'trees.'.$m[1].$m[2];
        $this->treePath($path);
        if (data_get($this, $path.'.children.'.$m[3].'.key') !== 'type') {
            return;
        }
        if ($value === 'object') {
            $this->ensureSchemaKeyword($path, 'properties', []);
            $this->ensureSchemaKeyword($path, 'required', []);
            $this->ensureSchemaKeyword($path, 'additionalProperties', false);
        } elseif ($value === 'array') {
            $this->ensureSchemaKeyword($path, 'items', ['type' => 'string']);
        }
    }

    private function schemaKeyword(string $path, string $key): int|false
    {
        $this->treePath($path);

        return array_search($key, array_column(data_get($this, $path.'.children'), 'key'), true);
    }

    private function ensureSchemaKeyword(string $path, string $key, mixed $value): int
    {
        $index = $this->schemaKeyword($path, $key);
        if ($index !== false) {
            return $index;
        }
        $node = data_get($this, $path);
        $node['children'][] = ['key' => $key, 'node' => JsonFields::node($value)];
        data_set($this, $path, $node);

        return count($node['children']) - 1;
    }

    public function addSchemaProperty(string $path): void
    {
        $type = $this->schemaKeyword($path, 'type');
        if ($type === false || data_get($this, $path.'.children.'.$type.'.node.value') !== 'object') {
            throw ValidationException::withMessages([$path => '只有对象结构可以添加属性。']);
        }
        $i = $this->ensureSchemaKeyword($path, 'properties', []);
        $this->ensureSchemaKeyword($path, 'required', []);
        $this->ensureSchemaKeyword($path, 'additionalProperties', false);
        $propsPath = $path.'.children.'.$i.'.node';
        $props = data_get($this, $propsPath);
        if (! in_array($props['type'], ['object', 'array'], true) || count($props['children']) >= 100) {
            throw ValidationException::withMessages([$path => '属性结构无效或已达到100个属性。']);
        }
        $names = array_column($props['children'], 'key');
        $n = count($names) + 1;
        while (in_array('field_'.$n, $names, true)) {
            $n++;
        }
        $props['type'] = 'object';
        $props['children'][] = ['key' => 'field_'.$n, 'node' => JsonFields::node(['type' => 'string'])];
        data_set($this, $propsPath, $props);
        $this->syncJsonInputs();
    }

    public function toggleSchemaRequired(string $path, int $index): void
    {
        $i = $this->schemaKeyword($path, 'properties');
        $name = $i === false ? null : data_get($this, $path.'.children.'.$i.'.node.children.'.$index.'.key');
        if (! is_string($name) || $name === '') {
            throw ValidationException::withMessages([$path => '请先填写实际属性名。']);
        }
        $r = $this->ensureSchemaKeyword($path, 'required', []);
        $requiredPath = $path.'.children.'.$r.'.node';
        $required = data_get($this, $requiredPath);
        if ($required['type'] !== 'array') {
            throw ValidationException::withMessages([$path => 'required必须为有序文本列表，请先修正高级字段。']);
        }
        $found = false;
        foreach ($required['children'] as $key => $row) {
            if ($row['node']['value'] === $name) {
                unset($required['children'][$key]);
                $found = true;
            }
        }
        if (! $found) {
            $required['children'][] = ['key' => '', 'node' => JsonFields::node($name)];
        }
        $required['children'] = array_values($required['children']);
        data_set($this, $requiredPath, $required);
        $this->syncJsonInputs();
    }

    public function removeSchemaProperty(string $path, int $index): void
    {
        $i = $this->schemaKeyword($path, 'properties');
        $name = $i === false ? null : data_get($this, $path.'.children.'.$i.'.node.children.'.$index.'.key');
        if (! is_string($name)) {
            throw ValidationException::withMessages([$path => '属性不存在。']);
        }
        $r = $this->schemaKeyword($path, 'required');
        if ($r !== false) {
            $requiredPath = $path.'.children.'.$r.'.node';
            $required = data_get($this, $requiredPath);
            $required['children'] = array_values(array_filter($required['children'], fn ($row) => $row['node']['value'] !== $name));
            data_set($this, $requiredPath, $required);
        }
        $this->changeJsonRow($path.'.children.'.$i.'.node', $index, 'remove');
        // 空属性仍保持对象类型；现有服务不能往返时由保存门禁明确阻止，不能转成列表。
        $this->syncJsonInputs();
    }

    public function addSchemaLimit(string $path, string $keyword): void
    {
        if (! in_array($keyword, ['maxLength', 'maxItems'], true)) {
            throw ValidationException::withMessages([$path => '不支持此限制字段。']);
        }
        $i = $this->ensureSchemaKeyword($path, $keyword, 0);
        // 留空等待作者给出上限，避免把默认数字误当作实际约束。
        data_set($this, $path.'.children.'.$i.'.node.value', '');
        $this->syncJsonInputs();
    }
}
