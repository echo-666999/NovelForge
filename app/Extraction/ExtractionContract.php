<?php

namespace App\Extraction;

use App\Exceptions\NovelProblem;
use App\Planning\PlanningContract;
use Illuminate\Support\Facades\Validator;

/** 显式离线响应协议；事件只提议事实，规则上限不接受模型提供。 */
final class ExtractionContract
{
    /** 网关结构化输出采用有界契约；规则和状态批准字段不向模型开放。 */
    public static function schema(): array
    {
        $o = fn ($p, $required = null) => PlanningContract::object($p, $required);
        $list = fn ($p, $max = 200) => PlanningContract::list($p, $max);
        $id = ['type' => 'string', 'maxLength' => 100];
        $text = ['type' => 'string', 'maxLength' => 20000];
        $number = ['type' => 'integer'];
        $time = $o(['kind' => $id, 'clock' => $id, 'start' => $number, 'end' => $number], ['kind']);
        $span = $o(['source_hash' => ['type' => 'string', 'maxLength' => 64], 'start_offset' => $number, 'end_offset' => $number, 'quote' => ['type' => 'string', 'maxLength' => 10000]]);
        $fields = ['key' => $id, 'definition_version_id' => $id, 'holder_id' => $id, 'fact_kind' => $id, 'action' => $id, 'supersedes_id' => $id, 'attribute' => $id, 'value' => $text, 'confidence' => $id, 'time' => $time, 'reason' => $text, 'evidence' => $span, 'activity' => ['type' => 'boolean'], 'location_id' => $id, 'ability_id' => $id, 'ability_units' => $number, 'previous_owner_id' => $id, 'revival_rule_id' => $id, 'uses' => $list($o(['fact_ref' => $id, 'knower_id' => $id]), 100)];

        return $o(['events' => $list($o($fields, ['key', 'definition_version_id', 'fact_kind', 'action', 'attribute', 'value', 'confidence', 'time', 'reason', 'evidence', 'uses'])), 'dependencies' => $list($o(['predecessor_ref' => $id, 'successor_ref' => $id, 'relation_type' => $id]), 400), 'knowledge' => $list($o(['knower_id' => $id, 'fact_ref' => $id, 'acquired_ref' => $id, 'method' => $id, 'source_knower_id' => $id], ['knower_id', 'fact_ref', 'acquired_ref', 'method']), 400), 'relationships' => $list($o(['event_ref' => $id, 'from_entity_id' => $id, 'to_entity_id' => $id, 'relation_type' => $id, 'state' => $text]), 400), 'foreshadows' => $list($o(['plan_id' => $id, 'event_ref' => $id, 'kind' => $id]))]);
    }

    /** PostgreSQL文本/JSONB不能保存零字节，非法UTF8也须在编码前明确拒绝。 */
    public static function transport(mixed $value): void
    {
        if (is_array($value)) {
            foreach ($value as $key => $child) {
                self::transport($key);
                self::transport($child);
            }
        } elseif (is_string($value) && (! mb_check_encoding($value, 'UTF-8') || str_contains($value, chr(0)))) {
            throw new NovelProblem('INVALID_EXTRACTION_CHARACTER', '提取输入必须是不含零字节的有效UTF8。', 422);
        }
    }

    public static function validate(array $p): void
    {
        Validator::make(['output' => $p], ['output' => 'required|array:events,dependencies,knowledge,relationships,foreshadows', 'output.events' => 'required|array|min:1|max:200', 'output.dependencies' => 'present|array|max:400', 'output.knowledge' => 'present|array|max:400', 'output.relationships' => 'present|array|max:400', 'output.foreshadows' => 'present|array|max:200'])->validate();
        foreach ($p as $rows) {
            if (! array_is_list($rows)) {
                throw new NovelProblem('INVALID_EXTRACTION', '提取条目必须是有序列表。', 422);
            }
        }
        Validator::make($p, [
            'events.*' => 'required|array:key,definition_version_id,holder_id,fact_kind,action,supersedes_id,attribute,value,confidence,time,reason,evidence,activity,location_id,ability_id,ability_units,previous_owner_id,revival_rule_id,uses',
            'events.*.key' => ['required', 'string', 'distinct', 'regex:/^[a-z][a-z0-9_]{0,79}$/D'],
            'events.*.evidence' => 'required|array:source_hash,start_offset,end_offset,quote',
            'events.*.evidence.source_hash' => 'required|string|size:64',
            'events.*.evidence.start_offset' => 'required|integer|min:0',
            'events.*.evidence.end_offset' => 'required|integer|min:1',
            'events.*.evidence.quote' => 'required|string|max:10000',
            'events.*.time' => 'required|array:kind,clock,start,end',
            'events.*.uses' => 'present|array|max:100',
            'events.*.uses.*' => 'required|array:fact_ref,knower_id',
            'events.*.uses.*.fact_ref' => 'required|string|max:100',
            'events.*.uses.*.knower_id' => 'required|string|max:19',
            'dependencies.*' => 'required|array:predecessor_ref,successor_ref,relation_type',
            'knowledge.*' => 'required|array:knower_id,fact_ref,acquired_ref,method,source_knower_id',
            'relationships.*' => 'required|array:event_ref,from_entity_id,to_entity_id,relation_type,state',
            'foreshadows.*' => 'required|array:plan_id,event_ref,kind',
            'foreshadows.*.plan_id' => 'required|string|max:19',
            'foreshadows.*.event_ref' => 'required|string|max:100',
            'foreshadows.*.kind' => 'required|in:plant,reinforce,payoff',
        ])->validate();
    }
}
