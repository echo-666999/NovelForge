<?php

namespace App\Filament\Support;

use App\Planning\PlanningContract as C;
use App\Review\ReviewContract;

/** 只映射已经存在的生产契约，默认空字段不代表作者已经确认或提供了证据。 */
final class ProductionSchemas
{
    public static function infer(mixed $value): array
    {
        if (is_array($value)) {
            return array_is_list($value) ? C::list(isset($value[0]) ? self::infer($value[0]) : ['type' => 'string']) : C::object(array_map(self::infer(...), $value));
        }

        return ['type' => is_int($value) ? 'integer' : (is_bool($value) ? 'boolean' : 'string')];
    }

    public static function scope(): array
    {
        return C::object(['kind' => ['type' => 'string', 'enum' => ['paragraph', 'scene', 'chapter']], 'ranges' => C::list(self::span(), 5), 'scene_version_id' => ['type' => 'string']], ['kind', 'ranges']);
    }

    public static function span(): array
    {
        return C::object(['start' => ['type' => 'integer'], 'end' => ['type' => 'integer'], 'quote' => ['type' => 'string']]);
    }

    public static function billing(string $action): array
    {
        $text = ['type' => 'string'];
        $int = ['type' => 'integer'];

        return match ($action) {
            'price' => C::object(['model_version_id' => $text, 'expected_version' => $int, 'currency' => $text, 'unit_prices' => C::object(array_fill_keys(['input', 'output', 'cache_read', 'cache_write', 'reasoning'], $text)), 'cache_mode' => ['type' => 'string', 'enum' => ['none', 'included', 'separate']], 'reasoning_mode' => ['type' => 'string', 'enum' => ['none', 'included', 'separate']], 'effective_at' => $text, 'evidence_ref' => $text]),
            'policy' => self::infer(['key' => '', 'expected_version' => 0, 'currency' => '', 'limits' => array_fill_keys(['request', 'chapter', 'run', 'book', 'day'], ''), 'warning_percent' => 80, 'stop_percent' => 100, 'timezone' => 'UTC', 'reason' => '']),
            'reconcile' => C::object(['request_id' => $text, 'external_charge_id' => $text, 'usage' => C::object(array_fill_keys(['input_tokens', 'output_tokens', 'cache_read_tokens', 'cache_write_tokens', 'reasoning_tokens'], $int), ['input_tokens', 'output_tokens']) + ['nullable' => true, 'default' => null], 'amounts' => C::object(array_fill_keys(['input', 'output', 'cache_read', 'cache_write', 'reasoning'], $text)), 'evidence_ref' => $text], ['request_id', 'external_charge_id', 'usage', 'evidence_ref']),
        };
    }

    public static function seed(array $schema): mixed
    {
        if (array_key_exists('default', $schema)) {
            return $schema['default'];
        }

        return match ($schema['type'] ?? 'string') {
            'object' => (object) array_map(self::seed(...), array_intersect_key($schema['properties'] ?? [], array_flip($schema['required'] ?? array_keys($schema['properties'] ?? [])))),
            'array' => [], 'integer' => 0,'boolean' => false,'null' => null,default => $schema['enum'][0] ?? '',
        };
    }

    public static function label(string $key): string
    {
        return ReviewContract::LABELS[$key] ?? [
            'scores' => '七维评分', 'score' => '分数（1至5）', 'reason' => '理由', 'evidence' => '原文证据', 'start' => '起始码点', 'end' => '结束码点', 'quote' => '精确引文', 'issues' => '问题清单', 'category' => '类别', 'severity' => '严重度', 'message' => '问题说明', 'suggested_fix' => '修改建议',
            'minimums' => '各维最低分', 'average' => '平均分阈值（十进制文本）', 'max_revision_rounds' => '自动修订轮次上限', 'template_phrases' => '需关注的套语', 'exceptions' => '作者批准的例外', 'repeat_threshold' => '重复提示阈值',
            'kind' => '类型', 'ranges' => '允许修改的范围', 'scene_version_id' => '来源场景版本', 'patches' => '有序补丁', 'key' => '稳定标识', 'issue_ids' => '关联问题', 'replacement' => '替换正文',
            'events' => '候选事件', 'dependencies' => '事件依赖', 'knowledge' => '人物知情', 'relationships' => '人物关系', 'foreshadows' => '伏笔证据', 'requirements' => '要求候选', 'foundation_nodes' => '基础结构', 'nodes' => '结构节点', 'coverages' => '覆盖证据', 'body' => '故事蓝图', 'ending' => '终局承诺', 'threads' => '主支线',
            'request_id' => '费用请求', 'external_charge_id' => '供应商计费凭证号', 'usage' => '实际Token用量（未知时改为空值）', 'amounts' => '仅金额对账明细', 'evidence_ref' => '可核验凭证说明', 'model_version_id' => '模型能力版本', 'expected_version' => '预期已有版本号', 'currency' => '币种', 'unit_prices' => '每百万Token单价（十进制文本）', 'limits' => '预算上限（十进制文本）', 'effective_at' => '生效时间（含时区）', 'cache_mode' => '缓存计费方式', 'reasoning_mode' => '推理计费方式', 'warning_percent' => '警告百分比', 'stop_percent' => '停止百分比', 'timezone' => '预算时区',
            'input' => '输入', 'output' => '输出', 'cache_read' => '缓存读取', 'cache_write' => '缓存写入', 'reasoning' => '推理', 'request' => '单次请求', 'chapter' => '每章', 'run' => '每次运行', 'book' => '每本书', 'day' => '每日',
        ][$key] ?? JsonFields::labelPath('/'.$key);
    }
}
