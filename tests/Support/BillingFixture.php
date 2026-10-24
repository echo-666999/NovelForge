<?php

namespace Tests\Support;

use App\Models\User;
use App\Services\BillingConfigurationService;

/** 明确虚构的离线价格与预算；绝不当成真实供应商报价。 */
final class BillingFixture
{
    public static function price(User $actor, string $model, string $key = 'price'): array
    {
        return app(BillingConfigurationService::class)->execute($actor, 'price', ['model_version_id' => $model, 'expected_version' => 0, 'currency' => 'USD', 'unit_prices' => ['input' => '1', 'output' => '2', 'cache_read' => '0.1', 'cache_write' => '1.5', 'reasoning' => '3'], 'cache_mode' => 'none', 'reasoning_mode' => 'none', 'effective_at' => '2020-01-01T00:00:00+00:00', 'evidence_ref' => '离线测试虚构报价，不代表真实费用'], $key);
    }

    public static function create(User $actor, string $model): array
    {
        $price = self::price($actor, $model);
        $policy = app(BillingConfigurationService::class)->execute($actor, 'policy', ['key' => 'fixture', 'expected_version' => 0, 'currency' => 'USD', 'limits' => array_fill_keys(['request', 'chapter', 'run', 'book', 'day'], '100'), 'warning_percent' => 80, 'stop_percent' => 100, 'timezone' => 'UTC', 'reason' => '仅用于离线预算测试'], 'policy');

        return ['policy_id' => $policy['id'], 'price_version_ids' => [$price['id']], 'category' => 'generation', 'max_output_tokens' => 200, 'max_reasoning_tokens' => 0];
    }
}
