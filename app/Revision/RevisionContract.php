<?php

namespace App\Revision;

use App\Exceptions\NovelProblem;
use App\Planning\PlanningContract as C;
use App\Services\NovelIntentService;
use Illuminate\Support\Facades\Validator;

/** 文学修订只输出原文坐标补丁，不允许模型改计划、策略或事实清单。 */
final class RevisionContract
{
    public static function schema(): array
    {
        $text = ['type' => 'string', 'maxLength' => 10000];

        return C::object(['patches' => C::list(C::object(['key' => ['type' => 'string', 'maxLength' => 80], 'issue_ids' => C::list(['type' => 'string', 'maxLength' => 19], 100), 'start' => ['type' => 'integer'], 'end' => ['type' => 'integer'], 'quote' => $text, 'replacement' => $text, 'reason' => ['type' => 'string', 'maxLength' => 2000]]), 50)]);
    }

    /** 差异坐标始终相对同一原始正文；不排序响应、不连续套用已偏移坐标。 */
    public static function patches(string $body, array $scope, array $protected, array $output, array $issues): array
    {
        Validator::make($output, ['patches' => 'required|array|min:1|max:50', 'patches.*' => 'required|array:key,issue_ids,start,end,quote,replacement,reason', 'patches.*.key' => ['required', 'string', 'distinct', 'regex:/^[a-z][a-z0-9_]{0,79}$/D'], 'patches.*.issue_ids' => 'required|array|min:1|max:100', 'patches.*.issue_ids.*' => 'required|string', 'patches.*.start' => 'required|integer|min:0', 'patches.*.end' => 'required|integer|min:1', 'patches.*.quote' => 'required|string|max:10000', 'patches.*.replacement' => 'present|string|max:10000', 'patches.*.reason' => 'required|string|max:2000'])->validate();
        if (array_keys($output) !== ['patches'] || ! array_is_list($output['patches'])) {
            throw new NovelProblem('INVALID_REVISION', '修订响应只能包含有序补丁列表。', 422);
        }
        $previous = -1;
        $normalized = [];
        foreach ($output['patches'] as $p) {
            // 派生补丁仅规范换行；原始响应保留，不trim、不作NFC。
            $p['replacement'] = str_replace(["\r\n", "\r"], "\n", $p['replacement']);
            if ($p['start'] < $previous || $p['end'] <= $p['start'] || $p['end'] > mb_strlen($body) || mb_substr($body, $p['start'], $p['end'] - $p['start']) !== $p['quote'] || $p['quote'] === $p['replacement']) {
                throw new NovelProblem('INVALID_REVISION_SPAN', '补丁须按原文位置递增、互不重叠、引文准确且实际改变正文。', 422);
            }
            $previous = $p['end'];
            if (count($p['issue_ids']) !== count(array_unique($p['issue_ids'])) || array_diff($p['issue_ids'], $issues) || ! collect($scope['ranges'])->contains(fn ($r) => $r['start'] <= $p['start'] && $r['end'] >= $p['end'])) {
                throw new NovelProblem('REVISION_OUT_OF_SCOPE', '补丁问题或坐标超出作者冻结范围。', 422);
            }
            foreach ($protected as $e) {
                if ($p['start'] < $e['end'] && $p['end'] > $e['start'] && ($p['start'] > $e['start'] || $p['end'] < $e['end'] || mb_substr_count($p['replacement'], $e['quote']) < mb_substr_count($p['quote'], $e['quote']))) {
                    throw new NovelProblem('PRESERVED_QUOTE_CHANGED', '不能删改必须保留的事实、线索或因果引文。', 422);
                }
            }
            $normalized[] = $p;
        }

        return $normalized;
    }

    public static function apply(string $body, array $patches): string
    {
        // 逆向拼接只是应用坐标，不改变保存的补丁列表顺序。
        foreach (array_reverse($patches) as $p) {
            $body = mb_substr($body, 0, $p['start']).$p['replacement'].mb_substr($body, $p['end']);
        }

        return $body;
    }

    /** 结构化事实和边的多重集合比较；只规范引用，不排序或修改存档JSON列表。 */
    public static function facts(array $output): array
    {
        $hash = fn ($v) => hash('sha256', NovelIntentService::canonical($v));
        $refs = [];
        foreach ($output['events'] ?? [] as $e) {
            $refs['local:'.$e['key']] = $hash(array_diff_key($e, array_flip(['key', 'reason', 'evidence', 'uses'])));
        }
        $map = function ($v, string $field = '') use (&$map, $refs) {
            if (is_array($v)) {
                $out = [];
                foreach ($v as $k => $child) {
                    $out[$k] = $map($child, (string) $k);
                }

                return $out;
            }

            return is_string($v) && str_ends_with($field, '_ref') && isset($refs[$v]) ? 'fact:'.$refs[$v] : $v;
        };
        $sets = [];
        foreach ($output as $field => $rows) {
            $sets[$field] = [];
            foreach ($rows as $row) {
                if ($field === 'events') {
                    $row = array_diff_key($row, array_flip(['key', 'reason', 'evidence']));
                }$k = $hash($map($row));
                $sets[$field][$k] = ($sets[$field][$k] ?? 0) + 1;
            }
        }

        // 保留真实证据派生的叙述顺序，不能靠事实集合相同掩盖倒置因果。
        $positions = [];
        foreach ($output['events'] ?? [] as $index => $event) {
            $positions[] = ['start' => $event['evidence']['start_offset'], 'end' => $event['evidence']['end_offset'], 'index' => $index, 'signature' => $refs['local:'.$event['key']]];
        }
        usort($positions, fn ($a, $b) => [$a['start'], $a['end'], $a['index']] <=> [$b['start'], $b['end'], $b['index']]);
        $sets['narrative_order'] = array_column($positions, 'signature');

        return $sets;
    }
}
