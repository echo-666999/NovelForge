<?php

namespace App\Services;

/** 确定性规则只判断显式数据；缺失或不可比较的信息返回待确认。 */
final class StoryConsistencyValidator
{
    private array $issues = [];

    private array $events = [];

    public function evaluate(array $p, array $events): array
    {
        $this->issues = [];
        $this->events = $events;
        $active = $this->active($events, PHP_INT_MAX);
        foreach ($events as $e) {
            if ($e['confidence'] === 'uncertain') {
                $this->issue('UNCERTAIN_EVENT', 'ambiguous', [$e['id']], '先确认事件，不自动升级为事实。');
            }
        }
        foreach ($active as $e) {
            if ($e['entry']['time']['kind'] === 'unknown') {
                $this->issue('UNKNOWN_TIME', 'ambiguous', [$e['id']], '补充有证据支持的时间范围。');
            }
        }
        foreach ($p['dependencies'] as $d) {
            $a = $active[$d['predecessor_id']] ?? null;
            $b = $active[$d['successor_id']] ?? null;
            if (! $a || ! $b) {
                $this->issue('STALE_DEPENDENCY', 'ambiguous', [$d['predecessor_id'], $d['successor_id']], '修正依赖至当前有效事件。');

                continue;
            }
            $state = $this->relation($a, $b, $d['relation_type']);
            if ($state !== 'consistent') {
                $this->issue('TIME_DEPENDENCY', $state, [$a['id'], $b['id']], '核对实际发生时间及依赖方向，不用章序替代实际时间。');
            }
        }
        // 隐藏真相参与作者侧物理一致性校验，但保持独立类别，不能自动进入角色知识。
        $facts = array_filter($active, fn ($e) => in_array($e['fact_kind'], ['fact', 'hidden_truth'], true));
        foreach ($facts as $e) {
            $entry = $e['entry'];
            if ($entry['activity']) {
                foreach ($facts as $death) {
                    if ($death['entity_id'] !== $e['entity_id'] || $death['attribute'] !== 'life_status' || $death['value'] !== 'dead' || $death['id'] === $e['id']) {
                        continue;
                    }
                    $order = $this->relation($death, $e, 'before');
                    if ($order === 'consistent' && ! $this->revivedBetween($death, $e, $facts, $p['rules'])) {
                        $this->issue('ACTION_AFTER_DEATH', 'conflict', [$death['id'], $e['id']], '提供有效复活规则与事件，或修正行动时间/提取。');
                    } elseif ($order === 'ambiguous' || $this->relation($death, $e, 'simultaneous') === 'consistent') {
                        $this->issue('LIFE_TIME_UNCERTAIN', 'ambiguous', [$death['id'], $e['id']], '澄清死亡和行动的实际先后。');
                    }
                }
                if ($entry['ability_id']) {
                    $rule = $this->findRule($p['rules'], 'ability', $e['entity_id'], $entry['ability_id']);
                    if (! $rule) {
                        $this->issue('ABILITY_LIMIT_UNKNOWN', 'ambiguous', [$e['id']], '填写绑定批准规则的能力上限。');
                    } elseif ($entry['ability_units'] > $rule['limit_value']) {
                        $this->issue('ABILITY_EXCEEDED', 'conflict', [$e['id']], '降低能力使用量或先批准符合剧情的能力变化。');
                    }
                }
            }
            if ($e['attribute'] === 'life_status' && $e['value'] === 'alive') {
                foreach ($facts as $death) {
                    if ($death['entity_id'] === $e['entity_id'] && $death['attribute'] === 'life_status' && $death['value'] === 'dead' && $this->relation($death, $e, 'before') === 'consistent' && ! $this->revivalAllowed($e, $p['rules'])) {
                        $this->issue('UNEXPLAINED_REVIVAL', 'conflict', [$death['id'], $e['id']], '复活必须有明确事件与绑定的批准规则。');
                    }
                }
            }
        }
        $presence = array_values(array_filter($facts, fn ($e) => $e['attribute'] === 'location_id' || ($e['entry']['activity'] && $e['entry']['location_id'])));
        for ($i = 0; $i < count($presence); $i++) {
            for ($j = $i + 1; $j < count($presence); $j++) {
                $a = $presence[$i];
                $b = $presence[$j];
                if ($a['entity_id'] !== $b['entity_id']) {
                    continue;
                }
                $la = $a['attribute'] === 'location_id' ? $a['value'] : $a['entry']['location_id'];
                $lb = $b['attribute'] === 'location_id' ? $b['value'] : $b['entry']['location_id'];
                if ($la === $lb) {
                    continue;
                }
                if (! $this->comparable($a, $b)) {
                    $this->issue('LOCATION_TIME_UNCERTAIN', 'ambiguous', [$a['id'], $b['id']], '地点证据需要同一时钟的可比较时间。');

                    continue;
                }
                $ta = $a['entry']['time'];
                $tb = $b['entry']['time'];
                if ($ta['start'] > $tb['start']) {
                    [$a, $b, $ta, $tb, $la, $lb] = [$b, $a, $tb, $ta, $lb, $la];
                }
                if ($ta['kind'] === 'exact' && $tb['kind'] === 'exact' && $ta['start'] === $tb['start']) {
                    $this->issue('INCOMPATIBLE_LOCATIONS', 'conflict', [$a['id'], $b['id']], '同一角色同一时刻不能出现在不同地点。');

                    continue;
                }
                if ($ta['end'] >= $tb['start']) {
                    $this->issue('LOCATION_ORDER_UNCERTAIN', 'ambiguous', [$a['id'], $b['id']], '重叠的可能时间区间不能直接推断先后。');

                    continue;
                }
                $rule = $this->findRule($p['rules'], 'travel', $la, $lb);
                if (! $rule) {
                    $this->issue('TRAVEL_RULE_UNKNOWN', 'ambiguous', [$a['id'], $b['id']], '明确两个地点间的最少旅行时间。');

                    continue;
                }
                $max = $tb['end'] - $ta['start'];
                $min = $tb['start'] - $ta['end'];
                if ($max < $rule['limit_value']) {
                    $this->issue('TRAVEL_TOO_FAST', 'conflict', [$a['id'], $b['id']], '即使采用最长可用间隔也不足旅行，需修正时间或路线。');
                } elseif ($min < $rule['limit_value']) {
                    $this->issue('TRAVEL_UNCERTAIN', 'ambiguous', [$a['id'], $b['id']], '时间范围不足以确认旅行合法，请收窄范围。');
                }
            }
        }
        // 单一物品的后续归属变更必须明确交接前所有者；不同信念不会触发此规则。
        $owners = array_values(array_filter($facts, fn ($e) => $e['attribute'] === 'owner_id'));
        foreach ($owners as $e) {
            $earlier = [];
            foreach ($owners as $other) {
                if ($other['entity_id'] !== $e['entity_id'] || $other['id'] === $e['id']) {
                    continue;
                }
                $order = $this->relation($other, $e, 'before');
                if ($order === 'consistent') {
                    $earlier[] = $other;
                } elseif ($other['value'] !== $e['value'] && $this->relation($other, $e, 'simultaneous') === 'consistent') {
                    $this->issue('DOUBLE_OWNERSHIP', 'conflict', [$other['id'], $e['id']], '同一物品同一时刻不能属于两个角色。');
                } elseif ($order === 'ambiguous' && $other['value'] !== $e['value']) {
                    $this->issue('OWNERSHIP_TIME_UNCERTAIN', 'ambiguous', [$other['id'], $e['id']], '明确归属变化的先后及交接。');
                }
            }
            usort($earlier, fn ($a, $b) => $b['entry']['time']['end'] <=> $a['entry']['time']['end']);
            if ($earlier && $earlier[0]['value'] !== $e['value'] && $e['entry']['previous_owner_id'] !== $earlier[0]['value']) {
                $this->issue('OWNERSHIP_TRANSFER_MISSING', 'conflict', [$earlier[0]['id'], $e['id']], '交接事件必须明确原所有者。');
            }
        }
        $known = $this->knowledge($p, $active, PHP_INT_MAX, true);
        foreach ($p['relationships'] as $r) {
            $e = $active[$r['event_id']] ?? null;
            if (! $e || $e['fact_kind'] !== 'fact' || $e['entity_id'] !== $r['from_entity_id']) {
                $this->issue('RELATION_EVENT_INVALID', 'conflict', [$r['event_id']], '关系变化须绑定有效客观事件及关系主体。');
            }
        }
        $status = array_column($this->issues, 'status');

        return ['status' => in_array('conflict', $status) ? 'conflict' : (count($status) ? 'ambiguous' : 'consistent'), 'eligible' => ! count($status), 'canonical' => false, 'issues' => array_values($this->issues), 'validated_knowledge_count' => count($status) ? 0 : count($known)];
    }

    private function active(array $events, int $chapter): array
    {
        $active = [];
        foreach ($events as $e) {
            if ($e['chapter_sequence'] > $chapter || $e['confidence'] !== 'confirmed') {
                continue;
            }
            if ($e['supersedes_id']) {
                unset($active[$e['supersedes_id']]);
            }
            if ($e['action'] !== 'revoke') {
                $active[$e['id']] = $e;
            }
        }

        return $active;
    }

    private function comparable(array $a, array $b): bool
    {
        return $a['entry']['time']['kind'] !== 'unknown' && $b['entry']['time']['kind'] !== 'unknown' && $a['entry']['time']['clock'] === $b['entry']['time']['clock'];
    }

    private function relation(array $a, array $b, string $relation): string
    {
        if (! $this->comparable($a, $b)) {
            return 'ambiguous';
        }
        $a = $a['entry']['time'];
        $b = $b['entry']['time'];

        return match ($relation) {
            'before' => $a['end'] < $b['start'] ? 'consistent' : ($a['start'] >= $b['end'] ? 'conflict' : 'ambiguous'),
            'depends_on' => $a['end'] <= $b['start'] ? 'consistent' : ($a['start'] > $b['end'] ? 'conflict' : 'ambiguous'),
            'contains' => $a['start'] <= $b['start'] && $a['end'] >= $b['end'] ? 'consistent' : ($a['end'] < $b['start'] || $a['start'] > $b['end'] ? 'conflict' : 'ambiguous'),
            'simultaneous' => $a['kind'] === 'exact' && $b['kind'] === 'exact' && $a['start'] === $b['start'] ? 'consistent' : ($a['end'] < $b['start'] || $b['end'] < $a['start'] ? 'conflict' : 'ambiguous'),
        };
    }

    private function findRule(array $rules, string $kind, string $subject, ?string $target): ?array
    {
        foreach ($rules as $r) {
            if ($r['kind'] === $kind && $r['subject_id'] === $subject && $r['target_id'] === $target) {
                return $r;
            }
        }

        return null;
    }

    private function revivalAllowed(array $e, array $rules): bool
    {
        $r = $this->findRule($rules, 'revival', $e['entity_id'], null);

        return $r && $e['entry']['revival_rule_id'] === $r['rule_version_id'];
    }

    private function revivedBetween(array $death, array $action, array $facts, array $rules): bool
    {
        foreach ($facts as $e) {
            if ($e['entity_id'] === $death['entity_id'] && $e['attribute'] === 'life_status' && $e['value'] === 'alive' && $this->revivalAllowed($e, $rules) && $this->relation($death, $e, 'before') === 'consistent' && $this->relation($e, $action, 'depends_on') === 'consistent') {
                return true;
            }
        }

        return false;
    }

    private function knowledge(array $p, array $active, int $chapter, bool $recordIssues): array
    {
        $rows = $p['knowledge'];
        usort($rows, fn ($a, $b) => [$active[$a['acquired_event_id']]['chapter_sequence'] ?? PHP_INT_MAX, $active[$a['acquired_event_id']]['narrative_order'] ?? PHP_INT_MAX] <=> [$active[$b['acquired_event_id']]['chapter_sequence'] ?? PHP_INT_MAX, $active[$b['acquired_event_id']]['narrative_order'] ?? PHP_INT_MAX]);
        $known = [];
        foreach ($rows as $k) {
            $fact = $active[$k['fact_event_id']] ?? null;
            $acquire = $active[$k['acquired_event_id']] ?? null;
            $code = null;
            $status = 'conflict';
            $inferredSource = false;
            if (! $fact || ! $acquire || $acquire['entity_id'] !== $k['knower_id'] || $acquire['fact_kind'] !== 'fact' || [$fact['chapter_sequence'], $fact['narrative_order']] > [$acquire['chapter_sequence'], $acquire['narrative_order']]) {
                $code = 'KNOWLEDGE_SOURCE_INVALID';
            } elseif ($this->relation($fact, $acquire, 'depends_on') !== 'consistent') {
                $code = 'KNOWLEDGE_TIME_INVALID';
                $status = $this->relation($fact, $acquire, 'depends_on');
            } elseif ($k['method'] === 'told') {
                $source = array_filter($known, fn ($v) => $v['knower_id'] === $k['source_knower_id'] && $v['fact_event_id'] === $k['fact_event_id'] && $this->relation($active[$v['acquired_event_id']], $acquire, 'depends_on') === 'consistent' && [$active[$v['acquired_event_id']]['chapter_sequence'], $active[$v['acquired_event_id']]['narrative_order']] < [$acquire['chapter_sequence'], $acquire['narrative_order']]);
                if (! $source) {
                    $code = 'KNOWLEDGE_PATH_MISSING';
                } else {
                    $inferredSource = ! array_filter($source, fn ($v) => $v['knowledge_kind'] !== 'inference');
                }
            }
            if ($code) {
                if ($recordIssues) {
                    $this->issue($code, $status, [$k['fact_event_id'], $k['acquired_event_id']], '补充合法获得事件、知情来源与可比较时间，不能仅因作者知道而让角色知情。');
                }

                continue;
            }
            $known[] = $k + ['knowledge_kind' => ($k['method'] === 'inference' || $inferredSource) ? 'inference' : $fact['fact_kind'], 'value' => $fact['value'], 'attribute' => $fact['attribute'], 'introduced_at' => $acquire['chapter_sequence'], 'evidence' => $acquire['evidence']];
        }

        return $known;
    }

    public function pov(array $p, array $events, string $knower, int $chapter): array
    {
        // 先重验截至目标章的候选前缀。存在冲突或待确认时不把候选信息当作可用 POV。
        $prefix = array_filter($events, fn ($e) => $e['chapter_sequence'] <= $chapter);
        $prefixPayload = $p;
        $prefixPayload['dependencies'] = array_values(array_filter($p['dependencies'], fn ($d) => isset($prefix[$d['predecessor_id']], $prefix[$d['successor_id']])));
        $prefixPayload['knowledge'] = array_values(array_filter($p['knowledge'], fn ($k) => isset($prefix[$k['acquired_event_id']])));
        $prefixPayload['relationships'] = array_values(array_filter($p['relationships'], fn ($r) => isset($prefix[$r['event_id']])));
        $report = $this->evaluate($prefixPayload, $prefix);
        $active = $this->active($events, $chapter);
        $known = array_values(array_filter($this->knowledge($p, $active, $chapter, false), fn ($k) => $k['knower_id'] === $knower));
        $beliefs = array_values(array_filter($active, fn ($e) => $e['holder_id'] === $knower && in_array($e['fact_kind'], ['belief', 'rumor', 'lie', 'inference'])));
        if (! $report['eligible']) {
            $known = [];
        }
        $relationships = [];
        $relations = $p['relationships'];
        usort($relations, fn ($a, $b) => [$active[$a['event_id']]['chapter_sequence'] ?? PHP_INT_MAX, $active[$a['event_id']]['narrative_order'] ?? PHP_INT_MAX] <=> [$active[$b['event_id']]['chapter_sequence'] ?? PHP_INT_MAX, $active[$b['event_id']]['narrative_order'] ?? PHP_INT_MAX]);
        foreach ($relations as $r) {
            $e = $active[$r['event_id']] ?? null;
            if ($e && $e['fact_kind'] === 'fact' && $e['entity_id'] === $r['from_entity_id'] && in_array($r['event_id'], array_column($known, 'fact_event_id'), true)) {
                $relationships[$r['from_entity_id'].':'.$r['to_entity_id'].':'.$r['relation_type']] = $r;
            }
        }

        return ['scope' => 'candidate', 'canonical' => false, 'status' => $report['status'], 'usable' => $report['eligible'], 'knower_id' => $knower, 'chapter' => $chapter, 'knowledge' => $known, 'beliefs' => array_map(fn ($e) => array_intersect_key($e, array_flip(['id', 'fact_kind', 'entity_id', 'attribute', 'value', 'evidence'])), $beliefs), 'relationships' => array_values($relationships)];
    }

    private function issue(string $code, string $status, array $ids, string $fix): void
    {
        $key = $code.':'.implode(':', $ids);
        $this->issues[$key] = ['code' => $code, 'status' => $status, 'event_ids' => $ids, 'evidence' => array_values(array_map(fn ($id) => $this->events[$id]['evidence'] ?? null, $ids)), 'suggested_fix' => $fix, 'recheck_event_ids' => $ids];
    }
}
