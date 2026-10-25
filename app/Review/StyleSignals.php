<?php

namespace App\Review;

/** 无分词的字面规则只产生警告，不能据次数删除表达或伪装文学评分。 */
final class StyleSignals
{
    public function inspect(string $body, array $policy, array $style, ?string $previous): array
    {
        $issues = [];
        $add = function (string $category, string $message, string $quote, int $offset) use (&$issues) {
            $issues[] = ['category' => $category, 'severity' => 'warning', 'origin' => 'rule', 'message' => $message, 'suggested_fix' => '对照角色选择、具体动作与上下文人工判断，保留必要事实；可明确接受或加入未来策略例外。', 'evidence' => ['start' => $offset, 'end' => $offset + mb_strlen($quote), 'quote' => $quote]];
        };
        $phrases = array_unique([...$policy['template_phrases'], ...($style['voice']['forbidden_patterns'] ?? [])]);
        foreach ($phrases as $phrase) {
            if (in_array($phrase, $policy['exceptions'], true)) {
                continue;
            }
            $at = mb_strpos($body, $phrase);
            if ($at !== false) {
                $add('style', '模板过渡、空泛总结或禁用套路字面信号：'.$phrase, $phrase, $at);
                if (mb_substr_count($body, $phrase) >= $policy['repeat_threshold']) {
                    $add('repetition', '相同短语重复出现的字面信号', $phrase, $at);
                }
            }
        }
        preg_match_all('/[^。！？\n]+[。！？]?/u', $body, $matches, PREG_OFFSET_CAPTURE);
        $seen = [];
        $lengths = [];
        $dialogue = [];
        foreach ($matches[0] as [$sentence,$bytes]) {
            $sentence = trim($sentence);
            if ($sentence === '') {
                continue;
            }
            // 原文切片位置用字节匹配后转码点；不对存档正文做trim或重排。
            $actual = strpos($body, $sentence, $bytes);
            $at = mb_strlen(substr($body, 0, $actual));
            $lengths[] = mb_strlen($sentence);
            $prefix = mb_substr($sentence, 0, min(8, mb_strlen($sentence)));
            $seen[$prefix][] = [$sentence, $at];
        }
        foreach ($seen as $prefix => $rows) {
            if (count($rows) >= $policy['repeat_threshold'] && ! in_array((string) $prefix, $policy['exceptions'], true)) {
                $add('repetition', '重复句式开头信号，共'.count($rows).'处', $rows[0][0], $rows[0][1]);
            }
            if (count($issues) >= 80) {
                break;
            }
        }
        preg_match_all('/“([^”\n]{4,200})”/u', $body, $quotes, PREG_OFFSET_CAPTURE);
        foreach ($quotes[0] as [$quote,$bytes]) {
            $dialogue[$quote][] = mb_strlen(substr($body, 0, $bytes));
        }
        foreach ($dialogue as $quote => $positions) {
            if (count($positions) >= 2 && ! in_array($quote, $policy['exceptions'], true)) {
                $add('dialogue', '相同对白反复出现；角色同声须人工结合说话者判断', $quote, $positions[0]);
            }
        }
        if (count($lengths) >= 5 && max($lengths) - min($lengths) <= 3) {
            $add('pace', '句长变化较小的节奏信号，不能据此认定节奏失当', mb_substr($body, 0, min(80, mb_strlen($body))), 0);
        }
        if ($previous !== null) {
            foreach (['opening', 'ending'] as $part) {
                $quote = $part === 'opening' ? mb_substr($body, 0, 30) : mb_substr($body, -30);
                $other = $part === 'opening' ? mb_substr($previous, 0, 30) : mb_substr($previous, -30);
                if (mb_strlen($quote) >= 10 && $quote === $other && ! in_array($quote, $policy['exceptions'], true)) {
                    $add('repetition', '相邻候选章'.($part === 'opening' ? '开头' : '结尾').'完全相同', $quote, $part === 'opening' ? 0 : mb_strlen($body) - mb_strlen($quote));
                }
            }
        }

        return array_slice($issues, 0, 100);
    }
}
