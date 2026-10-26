<?php

namespace App\Filament\Support;

/** UI05标签与枚举只映射现有事件协议，不声明新的业务字段。 */
final class ExtractionUi
{
    public const LABELS = ['events' => '候选事件', 'dependencies' => '事件依赖', 'knowledge' => '人物知识', 'relationships' => '人物关系', 'foreshadows' => '伏笔变化', 'key' => '事件键', 'definition_version_id' => '主体设定版本', 'holder_id' => '说法持有者', 'fact_kind' => '事实类别', 'action' => '变更动作', 'supersedes_id' => '替代或撤销的事件', 'attribute' => '属性键', 'value' => '事实值', 'confidence' => '确定程度', 'time' => '故事发生时间', 'reason' => '证据说明', 'evidence' => '原文证据', 'activity' => '明确角色行动', 'location_id' => '地点', 'ability_id' => '能力', 'ability_units' => '消耗单位', 'previous_owner_id' => '原持有者', 'revival_rule_id' => '复活规则版本', 'uses' => '此事件使用的知识', 'source_hash' => '原文指纹', 'start_offset' => '开始码点（从0起）', 'end_offset' => '结束码点（不包含）', 'quote' => '逐字引文', 'kind' => '类型', 'clock' => '时间标尺', 'start' => '开始时刻', 'end' => '结束时刻', 'fact_ref' => '事实引用', 'knower_id' => '知情人物', 'predecessor_ref' => '前置事件', 'successor_ref' => '后继事件', 'relation_type' => '关系类别', 'acquired_ref' => '获知事件', 'method' => '获知方式', 'source_knower_id' => '告知人物', 'event_ref' => '关联事件', 'from_entity_id' => '关系发起实体', 'to_entity_id' => '关系目标实体', 'state' => '关系状态', 'plan_id' => '伏笔计划版本'];

    public static function choices(string $key, string $path, array $s, bool $time): ?array
    {
        $group = match ($key) {
            'definition_version_id' => 'definitions', 'holder_id','knower_id','source_knower_id' => 'characters',
            'location_id' => 'locations','ability_id' => 'abilities','revival_rule_id' => 'rules','previous_owner_id','from_entity_id','to_entity_id' => 'allEntities',
            'supersedes_id' => 'eventIds','fact_ref','predecessor_ref','successor_ref','acquired_ref','event_ref' => 'refs','plan_id' => 'foreshadows',default => null,
        };
        if ($group !== null) {
            return $s[$group] ?? [];
        }

        return match ($key) {
            'fact_kind' => ['fact' => '事实', 'belief' => '信念', 'rumor' => '传闻', 'lie' => '谎言', 'inference' => '推断', 'hidden_truth' => '隐藏真相'],
            'action' => ['set' => '设置', 'revoke' => '撤销', 'replace' => '替代'],
            'confidence' => ['confirmed' => '已确认', 'uncertain' => '不确定'],
            'kind' => $time ? ['unknown' => '未知时间', 'exact' => '精确时间', 'range' => '时间范围'] : ['plant' => '埋设', 'reinforce' => '强化', 'payoff' => '回收'],
            'clock' => ['relative_seconds' => '相对秒', 'utc' => 'UTC'],
            'method' => ['witness' => '亲历', 'told' => '他人告知', 'inference' => '推断'],
            'activity' => ['1' => '是', '0' => '否'],default => null,
        };
    }
}
