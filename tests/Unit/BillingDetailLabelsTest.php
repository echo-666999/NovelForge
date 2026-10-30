<?php

namespace Tests\Unit;

use App\Filament\Support\BillingDetailLabels;
use App\Filament\Support\JsonFields;
use PHPUnit\Framework\TestCase;

/** 同名字段按账本上下文翻译，不能改变其他页面的输出含义。 */
class BillingDetailLabelsTest extends TestCase
{
    public function test_billing_labels_distinguish_prices_usage_and_limits(): void
    {
        $this->assertSame('币种', BillingDetailLabels::label('$/currency'));
        $this->assertSame('输出单价', BillingDetailLabels::label('$/unit_prices/output'));
        $this->assertSame('缓存写入单价', BillingDetailLabels::label('$/unit_prices/cache_write'));
        $this->assertSame('输出令牌用量', BillingDetailLabels::label('$/settlements/0/usage/output'));
        $this->assertSame('输出金额', BillingDetailLabels::label('$/settlements/0/amounts/output'));
        $this->assertSame('每日上限', BillingDetailLabels::label('$/limits/day'));
        $this->assertSame('第1项', BillingDetailLabels::label('$/ledger/0'));
        $this->assertSame('未命名字段', BillingDetailLabels::label('$/unknown_field'));
        $this->assertSame('预期输出', JsonFields::labelPath('$/examples/0/output'));
    }
}
