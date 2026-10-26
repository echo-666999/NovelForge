<?php

namespace Tests\Unit;

use App\Context\ContextComposer;
use PHPUnit\Framework\TestCase;

/** 组装容量按实际拼接文本计量，去重不得依赖强制条目出现顺序。 */
class ContextComposerTest extends TestCase
{
    private function item(string $text, bool $required = false, string $zone = '作者'): array
    {
        return ['category' => 'memory_episodic', 'zone' => $zone, 'text' => $text, 'required' => $required];
    }

    public function test_required_copy_wins_over_earlier_optional_copy_without_losing_provenance(): void
    {
        $items = [$this->item('不可逆事实'), $this->item('不可逆事实', true)];
        $composer = new ContextComposer;
        $ample = $composer->compose($items, 1000, 10, 0, 'none', 5);
        $this->assertSame([false, true], array_column($ample['items'], 'selected'));
        $expected = $composer->compose([$items[1]], 1000, 10, 0, 'none', 5);
        $actual = $composer->compose($items, $expected['budget']['total_tokens'], 10, 0, 'none', 5);
        $this->assertSame('duplicate', $actual['items'][0]['trim_reason']);
        $this->assertFalse($actual['items'][0]['selected']);
        $this->assertSame('不可逆事实', $actual['items'][0]['text']);
        $this->assertTrue($actual['items'][1]['selected']);
        $this->assertSame(1, $actual['items'][1]['final_order']);
        $this->assertSame($expected['final_prompt_hash'], $actual['final_prompt_hash']);
        $this->assertSame('assembled', $actual['status']);
    }

    public function test_same_text_in_author_and_pov_zones_is_not_deduplicated(): void
    {
        $r = (new ContextComposer)->compose([$this->item('已知线索'), $this->item('已知线索', true, 'POV')], 1000, 0, 0, 'none', 0);
        $this->assertSame([true, true], array_column($r['items'], 'selected'));
        $this->assertSame([1, 2], array_column($r['items'], 'final_order'));
    }

    public function test_exact_capacity_counts_headers_unicode_and_separators_and_blocks_one_byte_over(): void
    {
        $items = [$this->item('甲𠮷😀', true), $this->item('因果事实', true, 'POV')];
        $c = new ContextComposer;
        $baseline = $c->compose($items, 10000, 17, 23, 'separate', 7);
        $this->assertSame(strlen($baseline['final_prompt']), $baseline['budget']['input_tokens']);
        $this->assertGreaterThan(strlen('甲𠮷😀因果事实'), $baseline['budget']['input_tokens']);
        $capacity = $baseline['budget']['total_tokens'];
        $this->assertSame('assembled', $c->compose($items, $capacity, 17, 23, 'separate', 7)['status']);
        $blocked = $c->compose($items, $capacity - 1, 17, 23, 'separate', 7);
        $this->assertSame('over_capacity', $blocked['status']);
        $this->assertSame($baseline['final_prompt'], $blocked['final_prompt']);
        $this->assertSame([true, true], array_column($blocked['items'], 'selected'));
        $included = $c->compose($items, $capacity, 17, 23, 'included', 7);
        $this->assertSame(0, $included['budget']['separate_reasoning_tokens']);
        $this->assertSame(23, $capacity - $included['budget']['total_tokens']);
    }
}
