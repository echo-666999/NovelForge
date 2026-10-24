<?php

namespace App\ProvidersGateway;

use App\Exceptions\NovelProblem;

/** 有界 schema 子集；不支持的关键词必须拒绝，不能悄悄忽略校验。 */
final class StructuredOutput
{
    public function schema(array $schema, int $depth = 0): void
    {
        if ($depth > 8 || array_diff(array_keys($schema), ['type', 'properties', 'required', 'additionalProperties', 'items', 'maxLength', 'maxItems'])
            || ! in_array($schema['type'] ?? null, ['object', 'array', 'string', 'integer', 'boolean', 'null'], true)) {
            throw new NovelProblem('INVALID_SCHEMA', '输出 Schema 超出支持范围。', 422);
        }
        $allowed = match ($schema['type']) {
            'object' => ['type', 'properties', 'required', 'additionalProperties'],
            'array' => ['type', 'items', 'maxItems'], 'string' => ['type', 'maxLength'], default => ['type'],
        };
        if (array_diff(array_keys($schema), $allowed)) {
            throw new NovelProblem('INVALID_SCHEMA', '关键词不适用于当前类型。', 422);
        }
        if ($schema['type'] === 'object') {
            if (($schema['additionalProperties'] ?? null) !== false || ! is_array($schema['properties'] ?? null) || count($schema['properties']) > 100 || ! is_array($schema['required'] ?? null) || ! array_is_list($schema['required']) || count(array_filter($schema['required'], 'is_string')) !== count($schema['required']) || array_diff($schema['required'], array_keys($schema['properties']))) {
                throw new NovelProblem('INVALID_SCHEMA', '对象必须声明字段、必需字段并禁止额外字段。', 422);
            }
            foreach ($schema['properties'] as $name => $child) {
                if (! is_string($name) || ! is_array($child) || strlen($name) > 200) {
                    throw new NovelProblem('INVALID_SCHEMA', '字段定义无效。', 422);
                }
                $this->schema($child, $depth + 1);
            }
        }
        if ($schema['type'] === 'array') {
            if (! is_array($schema['items'] ?? null)) {
                throw new NovelProblem('INVALID_SCHEMA', '数组必须声明元素结构。', 422);
            }
            $this->schema($schema['items'], $depth + 1);
        }
        foreach (['maxItems', 'maxLength'] as $key) {
            if (isset($schema[$key]) && (! is_int($schema[$key]) || $schema[$key] < 0 || $schema[$key] > 100000)) {
                throw new NovelProblem('INVALID_SCHEMA', '输出大小限制无效。', 422);
            }
        }
    }

    public function issues(mixed $value, array $s, string $path = '$.output'): array
    {
        $valid = match ($s['type']) {
            'object' => $value instanceof \stdClass, 'array' => is_array($value), 'string' => is_string($value), 'integer' => is_int($value), 'boolean' => is_bool($value), 'null' => $value === null
        };
        if (! $valid) {
            return [['path' => $path, 'code' => 'TYPE_MISMATCH', 'expected' => $s['type']]];
        }
        $issues = [];
        if ($s['type'] === 'object') {
            foreach ($s['required'] as $key) {
                if (! property_exists($value, $key)) {
                    $issues[] = ['path' => $path.'.'.$key, 'code' => 'REQUIRED'];
                }
            }
            foreach (get_object_vars($value) as $key => $item) {
                $issues = array_merge($issues, isset($s['properties'][$key]) ? $this->issues($item, $s['properties'][$key], $path.'.'.$key) : [['path' => $path.'.'.$key, 'code' => 'EXTRA_PROPERTY']]);
                if (count($issues) >= 100) {
                    break;
                }
            }
        }
        if ($s['type'] === 'array') {
            if (count($value) > ($s['maxItems'] ?? 1000)) {
                return [['path' => $path, 'code' => 'TOO_MANY_ITEMS']];
            }
            foreach ($value as $i => $item) {
                $issues = array_merge($issues, $this->issues($item, $s['items'], $path.'['.$i.']'));
                if (count($issues) >= 100) {
                    break;
                }
            }
        }
        if ($s['type'] === 'string' && mb_strlen($value) > ($s['maxLength'] ?? 100000)) {
            $issues[] = ['path' => $path, 'code' => 'TOO_LONG'];
        }

        return array_slice($issues, 0, 100);
    }
}
