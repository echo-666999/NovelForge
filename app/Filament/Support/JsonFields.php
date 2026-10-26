<?php

namespace App\Filament\Support;

use Illuminate\Validation\ValidationException;

/** 类型化树仅负责UI映射；不扩张C04支持的Schema、JSON类型或写入权限。 */
final class JsonFields
{
    public const TYPES = ['object' => '对象', 'array' => '有序列表', 'string' => '文本', 'integer' => '整数', 'number' => '小数', 'boolean' => '布尔', 'null' => '空值'];

    /** 前端只更新两层路径；深树路径留在服务端签名快照内。 */
    public static function binding(string $path): string
    {
        return 'jsonInputs.f'.substr(hash('sha256', $path), 0, 32);
    }

    public static function node(mixed $value): array
    {
        $type = match (true) {
            $value instanceof \stdClass => 'object', is_array($value) => array_is_list($value) ? 'array' : 'object',
            is_string($value) => 'string', is_int($value) => 'integer', is_float($value) => 'number', is_bool($value) => 'boolean', default => 'null',
        };
        $children = [];
        if (in_array($type, ['object', 'array'], true)) {
            foreach ((array) $value as $key => $child) {
                $children[] = ['key' => (string) $key, 'node' => self::node($child)];
            }
        }

        return ['type' => $type, 'value' => in_array($type, ['object', 'array', 'null'], true) ? '' : ($type === 'boolean' ? ($value ? '1' : '0') : (string) $value), 'children' => $children];
    }

    public static function value(array $node, string $path = 'trees', int $depth = 0): mixed
    {
        if ($depth > 24 || ! in_array($node['type'] ?? null, array_keys(self::TYPES), true) || ! is_array($node['children'] ?? null) || count($node['children']) > 1000) {
            self::error($path.'.type', '类型、深度或行数超出编辑范围。');
        }
        $type = $node['type'];
        if (! in_array($type, ['object', 'array'], true) && $node['children']) {
            self::error($path.'.type', '此节点仍有子字段，请先明确移除子字段，或恢复对象/列表类型；不会隐式丢弃内容。');
        }
        if (in_array($type, ['object', 'array'], true)) {
            $result = [];
            foreach ($node['children'] as $i => $row) {
                $key = $type === 'array' ? $i : ($row['key'] ?? '');
                if ($type === 'object' && (! is_string($key) || $key === '' || strlen($key) > 200 || array_key_exists($key, $result))) {
                    self::error($path.'.children.'.$i.'.key', '属性名不能为空、重复或超过200字节。');
                }
                if (! is_array($row['node'] ?? null)) {
                    self::error($path.'.children.'.$i.'.node', '字段结构无效。');
                }
                $result[$key] = self::value($row['node'], $path.'.children.'.$i.'.node', $depth + 1);
            }

            return $type === 'array' ? array_values($result) : (object) $result;
        }
        $v = $node['value'] ?? '';
        if (! is_string($v) && ! is_int($v) && ! is_bool($v)) {
            self::error($path.'.value', '请输入对应类型的单个值。');
        }
        if ($type === 'integer') {
            $int = filter_var($v, FILTER_VALIDATE_INT);
            if ($int === false) {
                self::error($path.'.value', '请输入平台整数范围内的整数；不会自动截断或四舍五入。');
            }

            return $int;
        }
        if ($type === 'number') {
            if (! is_numeric($v) || ! is_finite((float) $v)) {
                self::error($path.'.value', '请输入有限数值。');
            }

            return (float) $v;
        }
        if ($type === 'boolean' && ! in_array($v, ['0', '1', false, true, 0, 1], true)) {
            self::error($path.'.value', '请选择是或否。');
        }

        return match ($type) {
            'null' => null, 'boolean' => (bool) $v, default => (string) $v
        };
    }

    /** 现有应用服务以关联数组承载对象；不能保真的类型显式阻止，原树仍保留供编辑/导出。 */
    public static function serviceValue(mixed $value, string $path): mixed
    {
        if ($value instanceof \stdClass) {
            $rows = (array) $value;
            if ($rows === [] || array_is_list($rows)) {
                self::error($path, '现有配置服务不能无损保存空对象或连续数字属性名对象；请保留原值导出，或调整字段，不能按空列表保存。');
            }
            $value = $rows;
        }
        if (is_float($value)) {
            self::error($path, '现有配置契约不接受JSON小数；原值仍保留，请核对字段约定，本次不会自动转换。');
        }
        if (is_array($value)) {
            foreach ($value as $key => $item) {
                $value[$key] = self::serviceValue($item, $path.'.'.$key);
            }
        }

        return $value;
    }

    /** 导入前校验大小、完整语法和重复属性，失败不覆盖已有编辑状态。 */
    public static function parse(string $json): mixed
    {
        if (strlen($json) > 200000) {
            self::error('importJson', '内容超过200KB。');
        }
        try {
            $value = json_decode($json, false, 32, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            self::error('importJson', '不是有效JSON，或嵌套超过限制。');
        }
        preg_match_all('/"(?:[^"\\\\]|\\\\.)*"|[{}\[\],:]/s', $json, $matches);
        $stack = [];
        foreach ($matches[0] as $token) {
            if ($token === '{' || $token === '[') {
                $stack[] = ['object' => $token === '{', 'key' => $token === '{', 'seen' => []];
            } elseif ($token === '}' || $token === ']') {
                array_pop($stack);
            } elseif ($stack) {
                $i = array_key_last($stack);
                if ($token === ',') {
                    $stack[$i]['key'] = $stack[$i]['object'];
                } elseif (str_starts_with($token, '"') && $stack[$i]['key']) {
                    $key = json_decode($token, true, 4, JSON_THROW_ON_ERROR);
                    if (isset($stack[$i]['seen']['k:'.$key])) {
                        self::error('importJson', '对象存在重复属性：'.$key.'；拒绝会丢值的导入。');
                    }
                    $stack[$i]['seen']['k:'.$key] = true;
                    $stack[$i]['key'] = false;
                }
            }
        }

        return $value;
    }

    /** 返回实际服务字段路径到UI控件路径的映射，属性名中的点不作为树路径。 */
    public static function paths(array $node, string $ui, string $contract): array
    {
        $result = [$contract => in_array($node['type'], ['object', 'array'], true) ? $ui : $ui.'.value'];
        foreach ($node['children'] ?? [] as $i => $row) {
            $key = ($node['type'] ?? '') === 'array' ? $i : $row['key'];
            $result += self::paths($row['node'], $ui.'.children.'.$i.'.node', $contract.'.'.$key);
        }

        return $result;
    }

    /** 业务标签为主，原路径辅助定位；未知扩展保留原名。 */
    public static function labelPath(string $path): string
    {
        $key = str_replace(['~1', '~0'], ['/', '~'], substr($path, strrpos($path, '/') + 1));
        if ($path === '$') {
            return '全部字段';
        }
        if (ctype_digit($key)) {
            return '第'.((int) $key + 1).'项';
        }
        $labels = [
            'content' => '配置正文', 'regression' => '离线回归', 'purpose' => '模板用途', 'body' => 'Prompt正文',
            'variable_schema' => '变量结构', 'output_schema' => '输出结构', 'examples' => '样例',
            'voice' => '文风', 'genre' => '题材', 'pov' => '叙事视角', 'tone' => '语气', 'pace' => '节奏',
            'description' => '说明', 'forbidden_patterns' => '禁用套路', 'licensed_examples' => '授权样例',
            'rubric' => '评分维度', 'threshold' => '最低分', 'max_revision_rounds' => '最多修订轮次',
            'variables' => '样例变量', 'output' => '预期输出', 'required_facts' => '必须保留事实',
            'text' => '正文', 'scores' => '人工标注分数', 'expected_pass' => '预期通过',
            'prompt_version_id' => 'Prompt版本', 'style_version_id' => '文风版本',
            'primary_model_version_id' => '主模型能力版本', 'fallbacks' => '有序备用模型',
            'model_version_id' => '模型能力版本', 'approved' => '明确批准', 'reason' => '变更原因',
            'parameters' => '生成参数', 'mode' => '路由模式', 'type' => '类型', 'properties' => '属性定义',
            'required' => '必填字段名', 'additionalProperties' => '允许额外属性', 'items' => '元素结构',
            'maxLength' => '文本长度上限', 'maxItems' => '列表长度上限', 'interface_type' => '接口类型',
            'endpoint' => 'HTTPS端点', 'allowed_hosts' => '允许的精确主机', 'timeout_seconds' => '总超时秒数',
            'connect_timeout_seconds' => '连接超时秒数', 'requests_per_minute' => '每分钟请求上限',
            'max_concurrency' => '并发上限', 'enabled' => '启用配置', 'has_key' => '密钥是否已配置',
            'declared_capabilities' => '声明能力', 'context_tokens' => '上下文Token容量',
            'max_output_tokens' => '输出Token上限', 'structured_output' => '结构化输出', 'streaming' => '流式输出',
            'reasoning_metering' => '推理Token计量', 'source_note' => '能力声明来源',
            'verification_status' => '实测验证状态', 'id' => '记录ID', 'version' => '版本号',
            'created_at' => '创建时间', 'passed' => '是否通过', 'actual_tokens' => '实测Token',
            'real_model_verified' => '真实模型是否已验证', 'cases' => '回归样例', 'issues' => '问题', 'check' => '检查项',
        ];

        // UI05只读证据与编辑器共用业务字段名，未知键仍显示原名。
        return $labels[$key] ?? ExtractionUi::LABELS[$key] ?? $key;
    }

    /** 只读路径表与比较保留列表位置，明确区分不存在、空值、布尔和空列表。 */
    public static function rows(mixed $value, string $path = '$', int $depth = 0): array
    {
        if ($depth > 32) {
            return [$path => ['type' => '超深结构', 'value' => '请导出核对']];
        }
        $node = self::node($value);
        $rows = [$path => ['type' => self::TYPES[$node['type']], 'value' => match ($node['type']) {
            'object', 'array' => count($node['children']).'项', 'null' => '空值', 'boolean' => $value ? '是' : '否', default => (string) $value,
        }]];
        foreach ($node['children'] as $i => $row) {
            $key = $node['type'] === 'array' ? (string) $i : str_replace(['~', '/'], ['~0', '~1'], $row['key']);
            $rows += self::rows(in_array($node['type'], ['array'], true) ? $value[$i] : ((array) $value)[$row['key']], $path.'/'.$key, $depth + 1);
        }

        return $rows;
    }

    private static function error(string $path, string $message): never
    {
        throw ValidationException::withMessages([$path => $message]);
    }
}
