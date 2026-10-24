<?php

namespace App\Billing;

use App\Exceptions\NovelProblem;
use Illuminate\Support\Facades\DB;

/** 金额运算交给 PostgreSQL numeric；PHP 中不使用浮点乘除或累加。 */
final class TokenMeter
{
    public const CATEGORIES = ['input', 'output', 'cache_read', 'cache_write', 'reasoning'];

    public function decimal(mixed $value): string
    {
        if (! is_string($value) || ! preg_match('/^(0|[1-9][0-9]{0,12})(\.[0-9]{1,10})?$/D', $value)) {
            throw new NovelProblem('INVALID_MONEY', '金额须为最多十位小数的非负十进制字符串。', 422);
        }

        return DB::selectOne('SELECT ?::numeric(24,10)::text AS v', [$value])->v;
    }

    public function total(array $amounts): string
    {
        return DB::selectOne('SELECT coalesce(sum(value::numeric),0)::numeric(24,10)::text AS v FROM jsonb_array_elements_text(?::jsonb)', [json_encode(array_values($amounts))])->v;
    }

    public function compare(string $a, string $b): int
    {
        return (int) DB::selectOne('SELECT CASE WHEN ?::numeric>?::numeric THEN 1 WHEN ?::numeric<?::numeric THEN -1 ELSE 0 END AS v', [$a, $b, $a, $b])->v;
    }

    public function cost(int $tokens, string $rate): string
    {
        // 每个类别向上取到十位小数，预留与核销使用同一规则，避免低估。
        return DB::selectOne('SELECT (ceil(?::numeric*?::numeric/1000000*10000000000)/10000000000)::numeric(24,10)::text AS v', [(string) $tokens, $rate])->v;
    }

    public function percent(string $amount, int $percent): string
    {
        return DB::selectOne('SELECT (floor(?::numeric*?::numeric/100*10000000000)/10000000000)::numeric(24,10)::text AS v', [$amount, $percent])->v;
    }

    /** 缺少适用字段即未知，缓存和推理只按冻结规则归一化一次。 */
    public function normalize(?array $raw, object $price): array
    {
        if ($raw === null) {
            throw new NovelProblem('USAGE_MISSING', '供应商未提供完整Usage。');
        }
        $get = function (string $key, bool $required) use ($raw): int {
            if (! array_key_exists($key, $raw)) {
                if ($required) {
                    throw new NovelProblem('USAGE_MISSING', '适用计量字段缺失。');
                }

                return 0;
            }
            if (! is_int($raw[$key]) || $raw[$key] < 0 || $raw[$key] > 1000000000) {
                throw new NovelProblem('INVALID_USAGE', 'Token必须为非负有界整数。');
            }

            return $raw[$key];
        };
        $input = $get('input_tokens', true);
        $output = $get('output_tokens', true);
        $read = $get('cache_read_tokens', $price->cache_mode !== 'none');
        $write = $get('cache_write_tokens', $price->cache_mode !== 'none');
        $reason = $get('reasoning_tokens', $price->reasoning_mode === 'separate');
        if (($price->cache_mode === 'none' && $read + $write > 0) || ($price->reasoning_mode === 'none' && $reason > 0) || ($price->reasoning_mode === 'included' && $reason > $output)) {
            throw new NovelProblem('INVALID_USAGE', '计量与冻结价格规则冲突。');
        }
        if ($price->cache_mode === 'included') {
            if ($read + $write > $input) {
                throw new NovelProblem('INVALID_USAGE', '缓存子集超过输入Token。');
            }
            $input -= $read + $write;
        }

        return ['input' => $input, 'output' => $output, 'cache_read' => $read, 'cache_write' => $write, 'reasoning' => $price->reasoning_mode === 'separate' ? $reason : 0];
    }

    public function amounts(array $tokens, object $price): array
    {
        $rates = json_decode($price->unit_prices, true);
        $result = [];
        foreach (self::CATEGORIES as $category) {
            $result[$category] = $this->cost($tokens[$category], $rates[$category]);
        }

        return $result;
    }
}
