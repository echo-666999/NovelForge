<?php

namespace App\ProvidersGateway;

use App\Exceptions\NovelProblem;
use stdClass;

/** 传输契约v1：以带标签的封装保留可选属性的缺省语义，不改业务Schema。 */
final class OpenAiTransportSchema
{
    public const VERSION = 'optional-envelope-v1';

    public function compile(array $business): array
    {
        (new StructuredOutput)->schema($business);
        if ($business['type'] !== 'object') {
            throw new NovelProblem('UNSUPPORTED_PROVIDER_SCHEMA', '严格结构化输出根节点须为对象。', 422);
        }
        $properties = 0;

        return $this->node($business, 1, $properties);
    }

    /** 分支均为封闭对象；缺省分支没有value，有值分支保留原始类型（含合法null）。 */
    private function node(array $schema, int $depth, int &$count): array
    {
        if (in_array($schema['type'], ['object', 'array'], true) && $depth > 10) {
            throw new NovelProblem('UNSUPPORTED_PROVIDER_SCHEMA', '传输封装后超过供应商嵌套深度限制。', 422);
        }
        if ($schema['type'] === 'object') {
            $fields = new stdClass;
            foreach ($schema['properties'] as $key => $child) {
                $count++;
                if (in_array($key, $schema['required'], true)) {
                    $fields->{$key} = $this->node($child, $depth + 1, $count);
                } else {
                    if ($depth + 1 > 10) {
                        throw new NovelProblem('UNSUPPORTED_PROVIDER_SCHEMA', '可选字段封装超过供应商嵌套深度限制。', 422);
                    }
                    $count += 3;
                    $fields->{$key} = ['description' => '业务字段缺省时返回state=absent；有值时返回state=present及value，不以空值代替缺省。', 'anyOf' => [
                        $this->object(['state' => ['type' => 'string', 'enum' => ['absent']]]),
                        $this->object(['state' => ['type' => 'string', 'enum' => ['present']], 'value' => $this->node($child, $depth + 2, $count)]),
                    ]];
                }
            }
            if ($count > 5000) {
                throw new NovelProblem('UNSUPPORTED_PROVIDER_SCHEMA', '传输封装后超过供应商属性数量限制。', 422);
            }
            $schema['properties'] = $fields;
            $schema['required'] = array_keys(get_object_vars($fields));
        } elseif ($schema['type'] === 'array') {
            $schema['items'] = $this->node($schema['items'], $depth + 1, $count);
        }

        return $schema;
    }

    private function object(array $properties): array
    {
        return ['type' => 'object', 'properties' => (object) $properties, 'required' => array_keys($properties), 'additionalProperties' => false];
    }

    /** 离线样例或已验证业务对象编码；不自动补业务缺项，不修改传入对象。 */
    public function encode(mixed $value, array $business): mixed
    {
        $this->compile($business);
        if ((new StructuredOutput)->issues($value, $business)) {
            throw new NovelProblem('INVALID_STRUCTURED_OUTPUT', '业务样例不符合原Schema。', 422);
        }

        return $this->encodeNode($value, $business);
    }

    private function encodeNode(mixed $value, array $schema): mixed
    {
        if ($schema['type'] === 'array') {
            return array_map(fn ($item) => $this->encodeNode($item, $schema['items']), $value);
        }
        if ($schema['type'] !== 'object') {
            return $value;
        }
        $out = new stdClass;
        foreach ($schema['properties'] as $key => $child) {
            $present = property_exists($value, $key);
            if (! in_array($key, $schema['required'], true)) {
                $out->{$key} = $present ? (object) ['state' => 'present', 'value' => $this->encodeNode($value->{$key}, $child)] : (object) ['state' => 'absent'];
            } else {
                $out->{$key} = $this->encodeNode($value->{$key}, $child);
            }
        }

        return $out;
    }

    /** 先验证传输封装，再校验还原业务结果；拒绝多余字段及伪装缺省的携值响应。 */
    public function decode(mixed $transport, array $business): mixed
    {
        $this->compile($business);
        $value = $this->decodeNode($transport, $business);
        if ((new StructuredOutput)->issues($value, $business)) {
            $this->invalid();
        }

        return $value;
    }

    private function decodeNode(mixed $value, array $schema): mixed
    {
        if ($schema['type'] === 'array') {
            if (! is_array($value) || ! array_is_list($value) || count($value) > ($schema['maxItems'] ?? 1000)) {
                $this->invalid();
            }

            return array_map(fn ($item) => $this->decodeNode($item, $schema['items']), $value);
        }
        if ($schema['type'] !== 'object') {
            if ((new StructuredOutput)->issues($value, $schema)) {
                $this->invalid();
            }

            return $value;
        }
        if (! $value instanceof stdClass || count(get_object_vars($value)) !== count($schema['properties'])
            || array_diff(array_keys(get_object_vars($value)), array_keys($schema['properties']))) {
            $this->invalid();
        }
        $out = new stdClass;
        foreach ($schema['properties'] as $key => $child) {
            $item = $value->{$key};
            if (! in_array($key, $schema['required'], true)) {
                if (! $item instanceof stdClass) {
                    $this->invalid();
                }
                $keys = array_keys(get_object_vars($item));
                if (($item->state ?? null) === 'absent' && $keys === ['state']) {
                    continue;
                }
                if (($item->state ?? null) !== 'present' || count($keys) !== 2 || ! property_exists($item, 'value')) {
                    $this->invalid();
                }
                $item = $item->value;
            }
            $out->{$key} = $this->decodeNode($item, $child);
        }

        return $out;
    }

    private function invalid(): never
    {
        throw new NovelProblem('INVALID_STRUCTURED_OUTPUT', '传输封装或还原结果不符合冻结业务Schema。', 422);
    }
}
