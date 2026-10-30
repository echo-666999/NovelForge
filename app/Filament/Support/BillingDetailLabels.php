<?php

namespace App\Filament\Support;

/** 账本详情专用中文标签；不改变原始字段、金额或其他页面的同名字段含义。 */
final class BillingDetailLabels
{
    public static function label(string $path): string
    {
        if ($path === '$') {
            return '全部字段';
        }
        $parts = explode('/', $path);
        $key = str_replace(['~1', '~0'], ['/', '~'], array_pop($parts));
        if (ctype_digit($key)) {
            return '第'.((int) $key + 1).'项';
        }
        $parent = end($parts);
        $categories = ['input' => '输入', 'output' => '输出', 'cache_read' => '缓存读取', 'cache_write' => '缓存写入', 'reasoning' => '推理'];
        if (isset($categories[$key])) {
            // 相同输出字段在报价中是单价，在用量及核销中分别是数量和金额。
            return $categories[$key].match ($parent) {
                'unit_prices' => '单价', 'usage' => '令牌用量', 'amounts' => '金额', default => '',
            };
        }
        if ($parent === 'limits') {
            return ['request' => '单次请求上限', 'chapter' => '每章上限', 'run' => '每次运行上限', 'book' => '每本书上限', 'day' => '每日上限'][$key] ?? '未命名字段';
        }

        return [
            'id' => '记录编号', 'model_version_id' => '模型能力版本', 'version' => '版本号',
            'currency' => '币种', 'unit_prices' => '每百万令牌单价', 'cache_mode' => '缓存计费方式',
            'reasoning_mode' => '推理计费方式', 'effective_at' => '生效时间', 'evidence_ref' => '可核验凭证说明',
            'actor_id' => '操作人编号', 'created_at' => '创建时间', 'key' => '标识键', 'limits' => '预算上限',
            'warning_percent' => '警告百分比', 'stop_percent' => '停止百分比', 'timezone' => '预算时区',
            'reason' => '变更原因', 'request_id' => '请求编号', 'policy_id' => '预算政策版本',
            'price_version_id' => '价格版本', 'category' => '计费类别', 'amount' => '金额', 'actual_cost' => '实际费用',
            'status' => '状态', 'issue_code' => '问题代码', 'frozen_bounds' => '冻结用量上界',
            'risk_reserved' => '风险预留', 'evidence_kind' => '证据类型', 'buckets' => '分范围预算',
            'scope' => '预算范围', 'exposure' => '已用及预留金额', 'stop_limit' => '停止额度', 'state' => '预算状态',
            'ledger' => '费用明细', 'settlements' => '核销记录', 'settlement_id' => '核销记录编号',
            'entry_kind' => '账目类型', 'tokens' => '令牌数量', 'confidence' => '证据可信度',
            'revision' => '修订版本', 'provider_id' => '供应商编号', 'external_charge_id' => '供应商计费凭证号',
            'usage' => '实际令牌用量', 'amounts' => '分类金额', 'input_hash' => '输入摘要',
            'input_tokens' => '输入令牌上限', 'output_tokens' => '输出令牌上限', 'reasoning_tokens' => '推理令牌上限',
            'method' => '估算方法', 'warning_scopes' => '预警范围', 'day_utc' => '世界协调时日期',
            'novel_id' => '小说编号', 'run_id' => '运行编号', 'model_profile_version_id' => '模型能力版本',
            'request_status' => '请求状态',
        ][$key] ?? '未命名字段';
    }
}
