<?php

namespace Tests\Feature;

use App\Filament\Pages\AuditCenter;
use App\Filament\Pages\Billing;
use App\Models\User;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Livewire\Livewire;
use Tests\TestCase;

/** UI24只验证剩余配置展示与既有金额/幂等契约，不请求模型。 */
class RemainingConfigurationModalUiTest extends TestCase
{
    use RefreshDatabase;

    private function actor(): User
    {
        Http::preventStrayRequests();
        $actor = User::factory()->create(['is_admin' => true]);
        $this->actingAs($actor);
        Filament::setCurrentPanel(Filament::getPanel('admin'));

        return $actor;
    }

    private function policy(): array
    {
        return ['key' => 'ui24.budget', 'expected_version' => 0, 'currency' => 'USD', 'limits' => array_fill_keys(['request', 'chapter', 'run', 'book', 'day'], '123.4500000001'), 'warning_percent' => 80, 'stop_percent' => 100, 'timezone' => 'UTC', 'reason' => '弹窗预算精度测试'];
    }

    public function test_billing_lists_are_default_and_each_editor_is_lazy_with_no_cancel_writes(): void
    {
        $this->actor();
        $page = Livewire::test(Billing::class)->assertSee('价格版本列表')->assertSee('预算政策列表')
            ->assertSet('billingEditor', '')->assertSet('trees', [])->assertDontSeeHtml('id="nf-billing-editor-form"');
        foreach (['price', 'policy', 'reconcile'] as $action) {
            $page->call('beginBillingEditor', $action)->assertSet('billingEditor', $action)
                ->assertSeeHtml('id="nf-billing-editor-form"')->assertDispatched('billing-editor-opened')
                ->call('closeBillingEditor')->assertSet('billingEditor', '')->assertDontSeeHtml('id="nf-billing-editor-form"');
        }
        foreach (['price_versions', 'budget_policies', 'billing_commands', 'ai_requests'] as $table) {
            $this->assertDatabaseCount($table, 0);
        }
        Http::assertNothingSent();
    }

    public function test_success_closes_and_same_input_reopen_retries_exact_decimal_without_duplicate(): void
    {
        $this->actor();
        $page = Livewire::test(Billing::class)->set('policyPayload', json_encode($this->policy()))
            ->call('beginBillingEditor', 'policy')->call('saveBilling', 'policy')->assertHasNoErrors()
            ->assertSet('billingEditor', '')->assertDispatched('close-modal', id: 'nf-billing-editor-modal');
        $keys = $page->get('billingCommandKeys');
        $page->call('beginBillingEditor', 'policy')->call('closeBillingEditor')->assertSet('billingCommandKeys', $keys)
            ->call('beginBillingEditor', 'policy')->call('saveBilling', 'policy')->assertHasNoErrors()->assertSet('billingCommandKeys', $keys);
        $this->assertDatabaseCount('budget_policies', 1);
        $this->assertDatabaseCount('billing_commands', 1);
        $this->assertSame('123.4500000001', json_decode(DB::table('budget_policies')->value('limits'), true)['request']);
        Http::assertNothingSent();
    }

    public function test_failed_save_keeps_editor_tree_and_retry_key(): void
    {
        $this->actor();
        $page = Livewire::test(Billing::class)->call('beginBillingEditor', 'policy');
        $tree = $page->get('trees.policy_payload');
        $page->call('saveBilling', 'policy')->assertHasErrors()->assertSet('billingEditor', 'policy')
            ->assertSet('billingSaveFailed', true)->assertSet('trees.policy_payload', $tree);
        $keys = $page->get('billingCommandKeys');
        $page->call('saveBilling', 'policy')->assertSet('billingCommandKeys', $keys)->assertSet('billingEditor', 'policy');
        $this->assertDatabaseCount('budget_policies', 0);
        $this->assertDatabaseCount('billing_commands', 0);
    }

    public function test_version_detail_is_read_only_and_limited_to_current_catalog(): void
    {
        $this->actor();
        $page = Livewire::test(Billing::class)->set('policyPayload', json_encode($this->policy()))->call('saveBilling', 'policy');
        $id = (string) DB::table('budget_policies')->value('id');
        $before = DB::table('audit_events')->count();
        $page->call('inspectBilling', 'policy', $id)->assertSet('billingDetail.record.id', $id)
            ->assertDispatched('open-modal', id: 'nf-billing-detail-modal');
        $this->assertDatabaseCount('budget_policies', 1);
        $this->assertSame($before, DB::table('audit_events')->count());
        $page->call('inspectBilling', 'policy', '999999999')->assertStatus(404);
    }

    public function test_audit_is_read_only_and_detail_cannot_read_foreign_actor_record(): void
    {
        $actor = $this->actor();
        // 外部操作人使用普通用户，保留系统既有单管理员约束。
        $other = User::factory()->create(['is_admin' => false]);
        $record = fn ($id) => ['trace_id' => (string) Str::uuid(), 'operation_trace_id' => (string) Str::uuid(), 'actor_id' => $id, 'object_type' => 'ui_fixture', 'object_id' => '1', 'action' => 'view_fixture', 'outcome' => 'recorded', 'reason' => '只读审计夹具'];
        $own = (string) DB::table('audit_events')->insertGetId($record($actor->id));
        $foreign = (string) DB::table('audit_events')->insertGetId($record($other->id));
        $page = Livewire::test(AuditCenter::class)->assertSee('审计记录列表')->assertSet('auditDetail', null)
            ->assertDontSee('新建审计')->call('inspectAudit', $own)->assertSet('auditDetail.id', $own)
            ->assertDispatched('open-modal', id: 'nf-audit-detail-modal');
        $this->assertDatabaseCount('audit_events', 2);
        $page->call('inspectAudit', $foreign)->assertStatus(404);
        Http::assertNothingSent();
    }
}
