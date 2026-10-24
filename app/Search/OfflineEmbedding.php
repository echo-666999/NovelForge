<?php

namespace App\Search;

use App\Exceptions\NovelProblem;

/** 通用字符一至三元组哈希，仅验证向量链路，不是语义嵌入模型。 */
final class OfflineEmbedding
{
    public static function normalize(string $text): string
    {
        return mb_strtolower(preg_replace('/[\p{P}\p{S}\s]+/u', '', $text) ?? '', 'UTF-8');
    }

    public function encode(string $text, int $dimensions): string
    {
        $text = self::normalize($text);
        if ($text === '') {
            throw new NovelProblem('EMPTY_QUERY', '检索文本不能只有标点或空白。', 422);
        }
        $vector = array_fill(0, $dimensions, 0);
        $length = mb_strlen($text, 'UTF-8');
        for ($n = 1; $n <= 3; $n++) {
            for ($i = 0; $i <= $length - $n; $i++) {
                $gram = mb_substr($text, $i, $n, 'UTF-8');
                $bucket = hexdec(substr(hash('sha256', $gram), 0, 8)) % $dimensions;
                $vector[$bucket] += $n;
            }
        }

        return '['.implode(',', $vector).']';
    }
}
