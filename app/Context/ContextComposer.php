<?php

namespace App\Context;

/** 只裁剪可选条目；估算按 UTF-8 字节上界计量，不冒充模型分词器。 */
final class ContextComposer
{
    public static function tokens(string $text): int
    {
        return strlen($text);
    }

    public static function text(array $items): string
    {
        return implode("\n\n", array_map(fn ($i) => '['.$i['zone'].' / '.$i['category']."]\n".$i['text'], array_values(array_filter($items, fn ($i) => $i['selected']))));
    }

    public function compose(array $items, int $capacity, int $output, int $reasoning, string $mode, int $margin): array
    {
        // included 的推理已包含在最大输出中，只对 separate 额外预留一次。
        $reserve = $mode === 'separate' ? $reasoning : 0;
        $seen = [];
        foreach ($items as $n => &$item) {
            $item += ['score' => null, 'channels' => [], 'required' => false];
            $item['text_hash'] = hash('sha256', $item['text']);
            $item['token_count'] = self::tokens($item['text']);
            $item['token_method'] = 'utf8_bytes_upper_estimate_v1';
            $item['selected'] = true;
            $item['trim_reason'] = null;
            // 不跨作者区和角色区去重；强制条目永远优先于可选副本。
            $key = $item['zone'].':'.$item['text_hash'];
            if (isset($seen[$key]) && ! $item['required']) {
                $item['selected'] = false;
                $item['trim_reason'] = 'duplicate';
            } else {
                $seen[$key] = $n;
            }
        }
        unset($item);
        $total = function () use (&$items): int {
            return self::tokens(self::text($items));
        };
        // 可选记忆先按相关性淘汰；不使用字符串截断伪造摘要压缩版本。
        $optional = array_keys(array_filter($items, fn ($i) => ! $i['required'] && $i['selected']));
        $priority = fn ($i) => str_starts_with($i['category'], 'memory_') || $i['category'] === 'long_term_memory' ? 0 : ($i['category'] === 'recent_body' ? 1 : 2);
        usort($optional, fn ($a, $b) => $priority($items[$a]) <=> $priority($items[$b]) ?: ($items[$a]['score'] ?? 0) <=> ($items[$b]['score'] ?? 0) ?: $b <=> $a);
        foreach ($optional as $n) {
            if ($total() + $output + $reserve + $margin <= $capacity) {
                break;
            }
            $items[$n]['selected'] = false;
            $items[$n]['trim_reason'] = 'low_priority_capacity';
        }
        $order = 0;
        foreach ($items as &$item) {
            $item['final_order'] = $item['selected'] ? ++$order : null;
        }
        unset($item);
        $text = self::text($items);
        $input = self::tokens($text);

        return ['status' => $input + $output + $reserve + $margin <= $capacity ? 'assembled' : 'over_capacity', 'items' => $items, 'final_prompt' => $text, 'final_prompt_hash' => hash('sha256', $text), 'budget' => ['input_tokens' => $input, 'max_output_tokens' => $output, 'reasoning_mode' => $mode, 'separate_reasoning_tokens' => $reserve, 'safety_margin' => $margin, 'context_tokens' => $capacity, 'total_tokens' => $input + $output + $reserve + $margin, 'method' => 'utf8_bytes_upper_estimate_v1', 'actual_tokens' => null, 'estimation_error' => 'unknown_without_provider_tokenizer', 'transport_overhead' => 'must_be_covered_by_safety_margin_before_real_use']];
    }
}
