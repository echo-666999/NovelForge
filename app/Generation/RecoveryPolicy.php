<?php

namespace App\Generation;

/** 保守分类：未知故障交人工，绝不把可能已计费的超时当成可自动重发。 */
final class RecoveryPolicy
{
    public static function classify(?string $code): array
    {
        return match ($code) {
            'RATE_LIMITED', 'PROVIDER_RATE_LIMITED', 'PROVIDER_HTTP_429', 'PROVIDER_HTTP_500', 'PROVIDER_HTTP_502', 'PROVIDER_HTTP_503', 'PROVIDER_HTTP_504', 'NETWORK_NOT_SENT' => ['kind' => 'retryable', 'status' => 'failed', 'advice' => '已知未成功的技术故障，最多三次尝试，退避后重试。'],
            'DEFERRED_BUDGET' => ['kind' => 'deferred_budget', 'status' => 'deferred', 'advice' => '预算不足，保留产物；核对余额或为新运行选择新政策。'],
            'CAPACITY_EXCEEDED', 'CONTEXT_CAPACITY_EXCEEDED', 'OUTPUT_TRUNCATED' => ['kind' => 'deferred_capacity', 'status' => 'deferred', 'advice' => '保留完整检查点，调整上下文或拆分任务，不盲目提高输出额度。'],
            'DEPENDENCY_MISMATCH', 'STALE_STATE' => ['kind' => 'stale', 'status' => 'blocked', 'advice' => '来源发生变化，先预览失效范围并创建新运行。'],
            'PROVIDER_OUTCOME_UNKNOWN', 'UNKNOWN_REQUEST_OUTCOME', 'BUDGET_RECONCILIATION_REQUIRED' => ['kind' => 'unknown', 'status' => 'blocked', 'advice' => '请求结果或费用不明，保留风险预留，人工核实，禁止自动重发。'],
            'EVIDENCE_NOT_FOUND', 'EVIDENCE_UNLOCATABLE' => ['kind' => 'evidence', 'status' => 'blocked', 'advice' => '优先基于原产物重新提取；不得改写原文制造证据。'],
            'LITERARY_REVIEW_BLOCKED' => ['kind' => 'literary', 'status' => 'blocked', 'advice' => '保留评分和问题，人工处理或后续受限修订；不得技术重试凑分。'],
            'HARD_CONSTRAINT_BLOCKED' => ['kind' => 'hard_constraint', 'status' => 'blocked', 'advice' => '核对原文与冲突事实；若只是误提取，保持正文版本并提供修正响应重新提取，不走技术重试。'],
            'LITERARY_REVIEW_FAILED' => ['kind' => 'literary', 'status' => 'blocked', 'advice' => '文学修订与技术重试分别计数；修订执行器尚未接入。'],
            default => ['kind' => 'blocked', 'status' => 'blocked', 'advice' => '检查错误证据和配置后人工决定；没有强制通过入口。'],
        };
    }

    public static function delay(int $attempt, int $retryAfter = 0): int
    {
        // 指数退避加抖动，供应商提示只能延长等待，不得缩短。
        return max($retryAfter, 5 * (2 ** max(0, min(2, $attempt - 1))) + random_int(0, 5));
    }
}
