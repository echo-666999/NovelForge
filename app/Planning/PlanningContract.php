<?php

namespace App\Planning;

use App\Services\BlueprintService;
use App\Services\StructureService;

/** E01 五个规划阶段分别冻结有界输出契约，不接受任意业务 JSON。 */
final class PlanningContract
{
    public const PHASES = ['intake', 'blueprint', 'outline', 'chapter_plan', 'scene_plan'];

    public static function object(array $properties, ?array $required = null): array
    {
        return ['type' => 'object', 'properties' => $properties, 'required' => $required ?? array_keys($properties), 'additionalProperties' => false];
    }

    public static function list(array $item, int $max = 200): array
    {
        return ['type' => 'array', 'items' => $item, 'maxItems' => $max];
    }

    public static function schema(string $phase): array
    {
        $text = ['type' => 'string', 'maxLength' => 20000];
        $id = ['type' => 'string', 'maxLength' => 19];
        $number = ['type' => 'integer'];
        $details = array_fill_keys(StructureService::DETAIL_FIELDS, $text);
        foreach (['min_words', 'target_words', 'max_words'] as $key) {
            $details[$key] = $number;
        }
        $details['pov_version_id'] = $id;
        $node = self::object(['key' => $id, 'parent_key' => $id, 'type' => $id, 'node_id' => $id, 'parent_id' => $id, 'sequence' => $number, 'title' => $text, 'objective' => $text, 'summary' => $text, 'status' => $id, 'start_chapter' => $number, 'end_chapter' => $number, 'details' => self::object($details, []), 'thread_ids' => self::list($id)]);
        if ($phase === 'intake') {
            return self::object(['requirements' => self::list(self::object(['requirement_id' => $id, 'source_quote' => $text, 'content' => $text, 'category' => $text, 'strength' => $id, 'critical' => ['type' => 'boolean']]))]);
        }
        if ($phase === 'blueprint') {
            $ending = array_fill_keys(BlueprintService::ENDING_TEXT, $text) + ['target_chapters' => $number, 'start_chapter' => $number, 'end_chapter' => $number, 'outcomes' => self::list(self::object(['entity_definition_version_id' => $id, 'outcome' => $text])), 'required_foreshadows' => self::list($text), 'open_items' => self::list($text)];

            return self::object(['foundation_nodes' => self::list($node), 'body' => self::object(array_fill_keys(BlueprintService::FIELDS, $text)), 'entity_version_ids' => self::list($id), 'threads' => self::list(self::object(['thread_id' => $id, 'kind' => $id, 'goal' => $text, 'resolution' => $text, 'turning_points' => $text, 'character_arc' => $text])), 'ending' => self::object($ending), 'coverages' => self::list(self::object(['requirement_version_id' => $id, 'field' => $text, 'quote' => $text]))]);
        }
        if (! in_array($phase, self::PHASES, true)) {
            throw new \InvalidArgumentException('未注册的规划阶段。');
        }

        return self::object(['nodes' => self::list($node), 'coverages' => self::list(self::object(['requirement_version_id' => $id, 'node_key' => $id, 'field' => $text, 'quote' => $text]))]);
    }
}
