<?php

namespace Tests\Feature;

use App\Billing\TokenMeter;
use App\Exceptions\NovelProblem;
use App\Filament\Pages\Billing;
use App\Generation\StageExecutor;
use App\Generation\StageResult;
use App\Models\User;
use App\ProvidersGateway\FakeProvider;
use App\ProvidersGateway\ProviderReply;
use App\Services\BillingConfigurationService;
use App\Services\BudgetService;
use App\Services\GenerationService;
use App\Services\ProviderGateway;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;
use Tests\Support\ProviderFixture;
use Tests\TestCase;

/** 所有金额均为离线报价，验证真实PostgreSQL预留和核销，不产生外部费用。 */
class BillingTest extends TestCase
{
    use RefreshDatabase;

    private function send(array $f, ?ProviderReply $reply = null, string $key = 'billing'): array
    {
        return app(ProviderGateway::class)->execute($f['actor'], $f['novelId'], $f['claim']['id'], $f['model']['version_id'], $f['input'], $key, new FakeProvider($reply));
    }

    private function problem(string $code, callable $call): void
    {
        try {
            $call();
            $this->fail('应拒绝：'.$code);
        } catch (NovelProblem $e) {
            $this->assertSame($code, $e->errorCode);
        }
    }

    private function freshRun(array $f, array $changes): array
    {
        $f['payload']['billing'] = array_replace($f['payload']['billing'], $changes);
        $f['runId'] = app(GenerationService::class)->start($f['actor'], $f['novelId'], $f['payload'], (string) Str::uuid())['run']['id'];
        $f['claim'] = app(GenerationService::class)->claim($f['actor'], $f['novelId'], $f['runId'], 'first');

        return $f;
    }

    private function policy(array $f, string $limit, array $overrides = []): array
    {
        return app(BillingConfigurationService::class)->execute($f['actor'], 'policy', array_replace(['key' => 'budget.'.Str::lower(Str::random(6)), 'expected_version' => 0, 'currency' => 'USD', 'limits' => array_fill_keys(['request', 'chapter', 'run', 'book', 'day'], $limit), 'warning_percent' => 80, 'stop_percent' => 100, 'timezone' => 'UTC', 'reason' => '离线预算边界测试'], $overrides), (string) Str::uuid());
    }

    public function test_success_reserves_all_scopes_and_settles_exact_decimal_once(): void
    {
        $f = ProviderFixture::create();
        $r = $this->send($f);
        $b = $r['billing'];
        $this->assertSame('settled', $b['status']);
        $this->assertSame('0.0000110000', $b['actual_cost']);
        $this->assertSame('0.0083920000', $b['amount']);
        $this->assertSame('0.0000000000', $b['risk_reserved']);
        $this->assertCount(4, $b['buckets']);
        $this->assertCount(5, $b['ledger']);
        $raw = ['input_tokens' => 3, 'output_tokens' => 4];
        app(BudgetService::class)->settle($r['id'], $raw, $r['provider_request_id'], '重复Usage');
        $this->assertDatabaseCount('usage_settlements', 1);
        $this->assertDatabaseCount('cost_ledger', 5);
        $this->assertEquals($r, $this->send($f));
        Http::assertNothingSent();
    }

    public function test_meter_handles_inclusive_cache_and_reasoning_without_double_billing(): void
    {
        $meter = app(TokenMeter::class);
        $p = (object) ['cache_mode' => 'included', 'reasoning_mode' => 'included', 'unit_prices' => json_encode(['input' => '1', 'output' => '2', 'cache_read' => '0.1', 'cache_write' => '1.5', 'reasoning' => '3'])];
        $tokens = $meter->normalize(['input_tokens' => 100, 'output_tokens' => 50, 'cache_read_tokens' => 20, 'cache_write_tokens' => 10, 'reasoning_tokens' => 30], $p);
        $this->assertSame(['input' => 70, 'output' => 50, 'cache_read' => 20, 'cache_write' => 10, 'reasoning' => 0], $tokens);
        $this->assertSame('0.0001870000', $meter->total($meter->amounts($tokens, $p)));
        $p->cache_mode = 'separate';
        $p->reasoning_mode = 'separate';
        $tokens = $meter->normalize(['input_tokens' => 100, 'output_tokens' => 50, 'cache_read_tokens' => 20, 'cache_write_tokens' => 10, 'reasoning_tokens' => 30], $p);
        $this->assertSame('0.0003070000', $meter->total($meter->amounts($tokens, $p)));
        $this->problem('USAGE_MISSING', fn () => $meter->normalize(['input_tokens' => 1, 'output_tokens' => 1], $p));
        $p->cache_mode = 'included';
        $this->problem('INVALID_USAGE', fn () => $meter->normalize(['input_tokens' => 1, 'output_tokens' => 1, 'cache_read_tokens' => 2, 'cache_write_tokens' => 0, 'reasoning_tokens' => 0], $p));
        $this->problem('INVALID_USAGE', fn () => $meter->normalize(['input_tokens' => 1.1, 'output_tokens' => 1], $p));
        $this->problem('INVALID_MONEY', fn () => $meter->decimal(0.1));
        $this->assertSame('0.0000000001', $meter->cost(1, '0.0000000001'));
        $this->assertSame('0.0000000000', $meter->percent('0.0000000001', 90));
    }

    public function test_unknown_and_failed_usage_keep_risk_then_reconcile_with_immutable_revisions(): void
    {
        $f = ProviderFixture::create();
        $gateway = app(ProviderGateway::class);
        $r = $gateway->execute($f['actor'], $f['novelId'], $f['claim']['id'], $f['model']['version_id'], $f['input'], 'timeout', new FakeProvider(timeout: true));
        $this->assertSame('unknown', $r['billing']['status']);
        $this->assertNull($r['billing']['actual_cost']);
        $this->assertSame($r['billing']['amount'], $r['billing']['risk_reserved']);
        $service = app(BillingConfigurationService::class);
        $p = ['request_id' => $r['id'], 'external_charge_id' => 'invoice-A', 'usage' => ['input_tokens' => 10, 'output_tokens' => 20], 'evidence_ref' => '离线账单凭证A，确认供应商Usage'];
        $settled = $service->execute($f['actor'], 'reconcile', $p, 'reconcile');
        $this->assertSame('0.0000500000', $settled['actual_cost']);
        $this->assertEquals($settled, $service->execute($f['actor'], 'reconcile', $p, 'reconcile'));
        $p['usage']['output_tokens'] = 30;
        $p['evidence_ref'] = '离线账单更正凭证B，供应商修订Usage';
        $second = $service->execute($f['actor'], 'reconcile', $p, 'correction');
        $this->assertSame('0.0000700000', $second['actual_cost']);
        $this->assertCount(15, $second['ledger']);
        $this->assertCount(2, $second['settlements']);
        $this->assertSame('0.0000700000', DB::table('cost_ledger')->selectRaw('sum(amount)::numeric(24,10)::text AS v')->first()->v);
        $this->assertSame('unknown', DB::table('ai_requests')->where('id', $r['id'])->value('status'));
        $this->expectException(QueryException::class);
        DB::table('usage_settlements')->update(['evidence_ref' => '覆盖历史']);
    }

    public function test_http_and_schema_failures_with_usage_are_still_charged_and_missing_usage_is_not_zero(): void
    {
        $f = ProviderFixture::create();
        foreach ([500, 200] as $status) {
            $r = $this->send($f, new ProviderReply($status, json_encode(['request_id' => 'failed-'.$status, 'output' => ['text' => 123], 'usage' => ['input_tokens' => 10, 'output_tokens' => 5]])), 'failed-'.$status);
            $this->assertSame('failed', $r['status']);
            $this->assertSame('settled', $r['billing']['status']);
            $this->assertSame('0.0000200000', $r['billing']['actual_cost']);
        }
        $r = $this->send($f, new ProviderReply(429, '{"error":"limited"}'), 'missing');
        $this->assertNull($r['billing']['actual_cost']);
        $this->assertSame('unknown', $r['billing']['status']);
    }

    public function test_duplicate_supplier_identity_does_not_charge_twice_and_retains_second_risk(): void
    {
        $f = ProviderFixture::create();
        $reply = new ProviderReply(200, '{"request_id":"same-bill","output":{"text":"ok"},"usage":{"input_tokens":3,"output_tokens":4}}');
        $a = $this->send($f, $reply, 'a');
        $b = $this->send($f, $reply, 'b');
        $this->assertSame('settled', $a['billing']['status']);
        $this->assertSame('unknown', $b['billing']['status']);
        $this->assertSame('DUPLICATE_CHARGE_ID', $b['billing']['issue_code']);
        $this->assertDatabaseCount('cost_ledger', 5);
        $this->assertNotSame('0.0000000000', $b['billing']['risk_reserved']);
    }

    public function test_budget_rejection_rolls_back_request_and_all_allocations_before_send(): void
    {
        $f = ProviderFixture::create();
        $policy = $this->policy($f, '0.0083919999');
        $f = $this->freshRun($f, ['policy_id' => $policy['id']]);
        $fake = new FakeProvider;
        $this->problem('DEFERRED_BUDGET', fn () => app(ProviderGateway::class)->execute($f['actor'], $f['novelId'], $f['claim']['id'], $f['model']['version_id'], $f['input'], 'insufficient', $fake));
        $this->assertSame(0, $fake->calls);
        $this->assertDatabaseCount('ai_requests', 0);
        $this->assertDatabaseCount('budget_allocations', 0);
        $this->assertDatabaseCount('budget_reservations', 0);
    }

    public function test_each_scope_and_warning_stop_thresholds_are_enforced(): void
    {
        $f = ProviderFixture::create();
        foreach (['request', 'chapter', 'run', 'book', 'day'] as $scope) {
            $limits = array_fill_keys(['request', 'chapter', 'run', 'book', 'day'], '100');
            $limits[$scope] = '0.001';
            $policy = $this->policy($f, '100', ['limits' => $limits]);
            $scoped = $this->freshRun($f, ['policy_id' => $policy['id']]);
            $this->problem('DEFERRED_BUDGET', fn () => $this->send($scoped, key: $scope));
        }
        $policy = $this->policy($f, '0.0100000000');
        $f = $this->freshRun($f, ['policy_id' => $policy['id']]);
        $r = app(ProviderGateway::class)->execute($f['actor'], $f['novelId'], $f['claim']['id'], $f['model']['version_id'], $f['input'], 'warning', new FakeProvider(timeout: true));
        $this->assertCount(4, $r['billing']['frozen_bounds']['warning_scopes']);
        $this->assertSame('warning', $r['billing']['buckets'][0]['state']);
        $this->problem('DEFERRED_BUDGET', fn () => $this->send($f, key: 'next'));
    }

    public function test_new_policy_cannot_reset_book_or_day_spending(): void
    {
        $f = ProviderFixture::create();
        $this->send($f);
        $policy = $this->policy($f, '0.0083920000');
        $f = $this->freshRun($f, ['policy_id' => $policy['id']]);
        $this->problem('DEFERRED_BUDGET', fn () => $this->send($f, key: 'new-policy'));
        $this->assertDatabaseCount('ai_requests', 1);
    }

    public function test_missing_budget_and_cross_book_chapter_are_blocked(): void
    {
        $f = ProviderFixture::create();
        $p = $f['payload'];
        unset($p['billing']);
        $run = app(GenerationService::class)->start($f['actor'], $f['novelId'], $p, 'no-budget')['run'];
        $claim = app(GenerationService::class)->claim($f['actor'], $f['novelId'], $run['id'], 'first');
        $this->problem('BUDGET_CONFIGURATION_REQUIRED', fn () => app(ProviderGateway::class)->execute($f['actor'], $f['novelId'], $claim['id'], $f['model']['version_id'], $f['input'], 'no-budget'));
        $this->problem('NOT_FOUND', fn () => $this->freshRun($f, ['chapter_id' => '999999']));
    }

    public function test_actual_over_reservation_is_recorded_and_stops_new_requests(): void
    {
        $f = ProviderFixture::create();
        $r = $this->send($f, new ProviderReply(200, '{"request_id":"over","output":{"text":"ok"},"usage":{"input_tokens":1000000,"output_tokens":0}}'));
        $this->assertSame('1.0000000000', $r['billing']['actual_cost']);
        $this->assertSame('ACTUAL_EXCEEDS_RESERVATION', $r['billing']['issue_code']);
        $this->problem('BUDGET_RECONCILIATION_REQUIRED', fn () => $this->send($f, key: 'after-overrun'));
    }

    public function test_all_request_categories_use_same_gate_and_prices_are_frozen(): void
    {
        $f = ProviderFixture::create();
        $svc = app(BillingConfigurationService::class);
        $price = $svc->row('price_versions', $f['payload']['billing']['price_version_ids'][0]);
        $p = array_intersect_key($price, array_flip(['model_version_id', 'currency', 'unit_prices', 'cache_mode', 'reasoning_mode', 'evidence_ref']));
        $p += ['expected_version' => 1, 'effective_at' => '2020-01-01T00:00:00+00:00'];
        $p['unit_prices']['input'] = '100';
        $new = $svc->execute($f['actor'], 'price', $p, 'higher-price');
        foreach (['generation', 'review', 'revision', 'summary', 'embedding', 'planning'] as $category) {
            $scoped = $this->freshRun($f, ['category' => $category]);
            $r = $this->send($scoped, key: $category);
            $this->assertSame('0.0000110000', $r['billing']['actual_cost']);
            $this->assertSame($price['id'], $r['price_version_id']);
            $this->assertSame($category, $r['billing']['category']);
        }
        $this->expectException(QueryException::class);
        DB::table('price_versions')->where('id', $new['id'])->update(['currency' => 'EUR']);
    }

    public function test_api_page_filters_and_all_database_comments(): void
    {
        $this->getJson('/api/v1/billing')->assertUnauthorized();
        $this->actingAs(User::factory()->create())->getJson('/api/v1/billing')->assertForbidden();
        $f = ProviderFixture::create();
        $this->send($f);
        $this->actingAs($f['actor']);
        $this->get('/admin/billing')->assertOk()->assertSee('风险预留');
        $this->getJson('/api/v1/billing?status=settled')->assertJsonPath('data.total', 1);
        Livewire::test(Billing::class)->set('status', 'unknown')->assertSee('暂无费用记录');
        $this->getJson('/api/v1/billing?date_from=2020-01-01&date_to=2099-01-01&run_id='.$f['runId'])->assertOk()->assertJsonPath('data.total', 1);
        $this->getJson('/api/v1/billing?request_status=failed')->assertJsonPath('data.total', 0);
        $this->post('/api/v1/billing/policy', ['command_key' => 'invalid', 'payload_json' => 'bad'])->assertRedirect('/admin/billing')->assertSessionHas('billing_error');
        foreach (['price_versions', 'budget_policies', 'budget_buckets', 'budget_reservations', 'budget_allocations', 'usage_settlements', 'billing_identities', 'cost_ledger', 'billing_commands'] as $table) {
            $this->assertMatchesRegularExpression('/[\x{4e00}-\x{9fff}]/u', DB::selectOne("SELECT obj_description(?::regclass,'pg_class') AS c", [$table])->c);
            foreach (DB::select('SELECT col_description(a.attrelid,a.attnum) AS c FROM pg_attribute a WHERE a.attrelid=?::regclass AND a.attnum>0 AND NOT a.attisdropped', [$table]) as $c) {
                $this->assertMatchesRegularExpression('/[\x{4e00}-\x{9fff}]/u', $c->c ?? '');
            }
        }
    }

    public function test_invoice_amount_can_resolve_unknown_cost_without_inventing_tokens(): void
    {
        $f = ProviderFixture::create();
        $r = $this->send($f, new ProviderReply(500, 'unknown'), 'invoice-only');
        $p = ['request_id' => $r['id'], 'usage' => null, 'external_charge_id' => 'invoice-only', 'amounts' => ['input' => '0.001', 'output' => '0', 'cache_read' => '0', 'cache_write' => '0', 'reasoning' => '0'], 'evidence_ref' => '离线账单只提供分类金额，未提供Token'];
        $result = app(BillingConfigurationService::class)->execute($f['actor'], 'reconcile', $p, 'invoice-only');
        $this->assertSame('0.0010000000', $result['actual_cost']);
        $this->assertNull($result['ledger'][0]['tokens']);
        $this->actingAs($f['actor'])->get('/api/v1/billing/export')->assertOk()->assertHeader('Content-Type', 'text/csv; charset=UTF-8')->assertSee('0.0010000000');
    }

    public function test_currency_and_future_price_versions_fail_closed(): void
    {
        $f = ProviderFixture::create();
        $svc = app(BillingConfigurationService::class);
        $price = $svc->row('price_versions', $f['payload']['billing']['price_version_ids'][0]);
        $p = array_intersect_key($price, array_flip(['model_version_id', 'currency', 'unit_prices', 'cache_mode', 'reasoning_mode', 'evidence_ref']));
        $p += ['expected_version' => 1, 'effective_at' => '2099-01-01T00:00:00+00:00'];
        $new = $svc->execute($f['actor'], 'price', $p, 'future');
        $this->problem('PRICE_NOT_EFFECTIVE', fn () => $this->freshRun($f, ['price_version_ids' => [$new['id']]]));
        $policy = $this->policy($f, '100', ['currency' => 'EUR']);
        $this->problem('CURRENCY_MISMATCH', fn () => $this->freshRun($f, ['policy_id' => $policy['id']]));
    }

    public function test_node_chapter_and_category_override_are_frozen_inside_multi_scope_run(): void
    {
        $f = ProviderFixture::create();
        $chapter = (string) DB::table('chapters')->where('novel_id', $f['novelId'])->value('id');
        $f['payload']['nodes'][0]['billing_scope'] = ['chapter_id' => $chapter, 'category' => 'review'];
        $f = $this->freshRun($f, []);
        $r = $this->send($f);
        $this->assertSame('review', $r['billing']['category']);
        $chapterBucket = collect($r['billing']['buckets'])->firstWhere('scope', 'chapter');
        $this->assertSame($f['novelId'].':'.$chapter, $chapterBucket['key']);
        $f['payload']['nodes'][0]['billing_scope']['chapter_id'] = '999999';
        $this->problem('NOT_FOUND', fn () => $this->freshRun($f, []));
        $f['payload']['nodes'][0]['billing_scope'] = [];
        $this->expectException(ValidationException::class);
        $this->freshRun($f, []);
    }

    public function test_budget_exception_is_persisted_as_deferred_without_transport(): void
    {
        $f = ProviderFixture::create();
        $policy = $this->policy($f, '0.001');
        $f['payload']['billing']['policy_id'] = $policy['id'];
        $run = app(GenerationService::class)->start($f['actor'], $f['novelId'], $f['payload'], 'defer-budget')['run'];
        $executor = new class($f) implements StageExecutor
        {
            public function __construct(private array $fixture) {}

            public function execute(array $input): StageResult
            {
                // 阶段内调用统一网关，预算不足在网络调用前抛出。
                $f = $this->fixture;
                app(ProviderGateway::class)->execute($f['actor'], $f['novelId'], $input['_lease']['id'], $f['model']['version_id'], $f['input'], 'budget-defer');

                return new StageResult('succeeded', ['text' => '不应执行到这里']);
            }
        };
        $result = app(GenerationService::class)->advance($f['actor'], $f['novelId'], $run['id'], 'first', $executor);
        $this->assertSame('deferred', $result['run']['status']);
        $this->assertSame('DEFERRED_BUDGET', $result['run']['error_code']);
        $this->assertDatabaseCount('ai_requests', 0);
        $this->assertDatabaseCount('budget_reservations', 0);
        Http::assertNothingSent();
    }
}
