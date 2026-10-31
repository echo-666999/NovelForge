<?php

namespace Tests\Feature;

use App\Exceptions\NovelProblem;
use App\Models\Novel;
use App\Models\User;
use App\ProvidersGateway\FakeProvider;
use App\Services\GenerationService;
use App\Services\ProviderGateway;
use App\Services\RequestIsolationService;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Support\ProviderFixture;
use Tests\TestCase;

/** 使用离线请求验证隔离只释放新运行并发槽，绝不释放历史风险或重发旧请求。 */
class RequestIsolationTest extends TestCase
{
    use RefreshDatabase;

    private function rejected(string $code, callable $call): void
    {
        try {
            $call();
            $this->fail('应被门禁拒绝');
        } catch (NovelProblem $e) {
            $this->assertSame($code, $e->errorCode);
        }
    }

    /** 成功响应与其他用户的请求都不能被当作未知结果隔离。 */
    public function test_isolation_rejects_success_and_cross_owner_access(): void
    {
        $f = ProviderFixture::create();
        $q = app(ProviderGateway::class)->execute($f['actor'], $f['novelId'], $f['claim']['id'], $f['model']['version_id'], $f['input'], 'success', new FakeProvider);
        $service = app(RequestIsolationService::class);
        $reason = '验证隔离边界不允许篡改成功请求或跨用户操作';
        $other = User::factory()->create();
        $this->rejected('FORBIDDEN', fn () => $service->isolate($other, $f['novelId'], $q['id'], $reason));
        $this->rejected('REQUEST_NOT_ISOLATABLE', fn () => $service->isolate($f['actor'], $f['novelId'], $q['id'], $reason));
        $this->assertDatabaseCount('ai_request_isolations', 0);
    }

    public function test_isolation_allows_new_run_but_preserves_unknown_evidence_budget_and_retry_gate(): void
    {
        $f = ProviderFixture::create();
        $gateway = app(ProviderGateway::class);
        $old = $gateway->execute($f['actor'], $f['novelId'], $f['claim']['id'], $f['model']['version_id'], $f['input'], 'old', new FakeProvider(timeout: true));
        $service = app(RequestIsolationService::class);
        $reason = '作者要求隔离历史未知请求并保留预留，不重发原运行';
        $this->rejected('REQUEST_NOT_ISOLATABLE', fn () => $service->isolate($f['actor'], $f['novelId'], $old['id'], $reason));
        // 模拟原工作进程已经停止，不更改请求结果或预算记录。
        DB::table('generation_steps')->where('id', $f['claim']['id'])->update(['status' => 'blocked']);
        DB::table('generation_runs')->where('id', $f['runId'])->update(['status' => 'blocked']);
        DB::table('generation_book_slots')->insert(['novel_id' => $f['novelId'], 'run_id' => $f['runId']]);
        $isolation = $service->isolate($f['actor'], $f['novelId'], $old['id'], $reason);
        $this->assertDatabaseMissing('generation_book_slots', ['novel_id' => $f['novelId'], 'run_id' => $f['runId']]);
        $this->assertEquals($isolation, $service->isolate($f['actor'], $f['novelId'], $old['id'], $reason));
        $this->assertSame($old, $gateway->read($f['actor'], $f['novelId'], $old['id']));
        $spy = new FakeProvider;
        $this->assertSame($old, $gateway->execute($f['actor'], $f['novelId'], $f['claim']['id'], $f['model']['version_id'], $f['input'], 'old', $spy));
        $this->rejected('REQUEST_ISOLATED', fn () => $gateway->execute($f['actor'], $f['novelId'], $f['claim']['id'], $f['model']['version_id'], $f['input'], 'changed-key', $spy));
        $this->assertSame(0, $spy->calls);
        $this->assertTrue(app(GenerationService::class)->hasUncertainRequest($f['claim']['id']));
        $this->rejected('UNKNOWN_REQUEST_OUTCOME', fn () => app(GenerationService::class)->claim($f['actor'], $f['novelId'], $f['runId'], 'first'));
        // 新运行仍通过正常预留；把离线夹具并发槽填满来证明隔离例外有效。
        $f['payload']['expected_version'] = Novel::find($f['novelId'])->row_version;
        $newRun = app(GenerationService::class)->start($f['actor'], $f['novelId'], $f['payload'], 'new-independent')['run']['id'];
        $claim = app(GenerationService::class)->claim($f['actor'], $f['novelId'], $newRun, 'first');
        $gateway->execute($f['actor'], $f['novelId'], $claim['id'], $f['model']['version_id'], $f['input'], 'new-a', new FakeProvider(timeout: true));
        $last = $gateway->execute($f['actor'], $f['novelId'], $claim['id'], $f['model']['version_id'], $f['input'], 'new-b', new FakeProvider(timeout: true));
        $this->assertSame('unknown', $last['status']);
        $this->rejected('RATE_LIMITED', fn () => $gateway->execute($f['actor'], $f['novelId'], $claim['id'], $f['model']['version_id'], $f['input'], 'new-c', $spy));
        $savedBilling = $gateway->read($f['actor'], $f['novelId'], $old['id'])['billing'];
        // 原请求预留不变；全书暴露随两个独立新请求增加，不能假装释放旧预留。
        foreach (['risk_reserved', 'actual_cost', 'status'] as $field) {
            $this->assertSame($old['billing'][$field], $savedBilling[$field]);
        }
        $this->assertNotSame($old['billing']['buckets'], $savedBilling['buckets']);
        $this->assertDatabaseCount('budget_reservations', 3);
        $this->assertDatabaseCount('usage_settlements', 0);
        $this->assertDatabaseCount('ai_request_isolations', 1);
        $this->assertDatabaseCount('ai_requests', 3);
        $this->expectException(QueryException::class);
        DB::table('ai_request_isolations')->where('id', $isolation['id'])->update(['reason' => '禁止覆盖隔离授权历史']);
    }
}
