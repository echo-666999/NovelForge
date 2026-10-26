<?php

namespace Tests\Feature;

use App\Revision\RevisionContract;
use App\Services\NovelIntentService;
use Tests\TestCase;

/** 小型纯离线规则回归：Unicode坐标、LF与事实引用不需要真实模型或分词。 */
class RevisionContractTest extends TestCase
{
    public function test_lf_normalization_and_unicode_patches_keep_original_coordinates(): void
    {
        $body = "😀甲\n乙";
        $p = ['patches' => [['key' => 'one', 'issue_ids' => ['1'], 'start' => 0, 'end' => 1, 'quote' => '😀', 'replacement' => "😀\r\n", 'reason' => '只添加合法换行']]];
        $scope = ['kind' => 'chapter', 'ranges' => [['start' => 0, 'end' => mb_strlen($body), 'quote' => $body]]];
        $result = RevisionContract::patches($body, $scope, [['start' => 1, 'end' => 2, 'quote' => '甲']], $p, ['1']);
        $this->assertSame("😀\n甲\n乙", RevisionContract::apply($body, $result));
        $this->assertSame("😀\r\n", $p['patches'][0]['replacement']);
    }

    public function test_fact_refs_normalize_keys_but_actual_narrative_order_and_literal_values_remain_meaningful(): void
    {
        $p = ['events' => [['key' => 'first', 'value' => 'local:first', 'uses' => [], 'evidence' => ['start_offset' => 0, 'end_offset' => 1]], ['key' => 'second', 'value' => '原事实', 'uses' => [['fact_ref' => 'local:first', 'knower_id' => '1']], 'evidence' => ['start_offset' => 5, 'end_offset' => 6]]], 'dependencies' => [['predecessor_ref' => 'local:first', 'successor_ref' => 'local:second', 'relation_type' => 'causes']], 'knowledge' => [], 'relationships' => [], 'foreshadows' => []];
        $hash = fn ($d) => hash('sha256', NovelIntentService::canonical(RevisionContract::facts($d)));
        $original = $hash($p);
        $q = $p;
        $q['events'] = array_reverse($q['events']);
        $this->assertSame($original, $hash($q));
        $q = $p;
        $q['events'][0]['key'] = 'renamed';
        $q['events'][1]['uses'][0]['fact_ref'] = 'local:renamed';
        $q['dependencies'][0]['predecessor_ref'] = 'local:renamed';
        $this->assertSame($original, $hash($q));
        $q = $p;
        $q['events'][0]['evidence'] = ['start_offset' => 7, 'end_offset' => 8];
        $this->assertNotSame($original, $hash($q));
        $q = $p;
        $q['events'][0]['value'] = '另一个事实';
        $this->assertNotSame($original, $hash($q));
    }
}
