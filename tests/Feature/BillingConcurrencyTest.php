<?php

namespace Tests\Feature;

use App\Models\Novel;
use App\Services\BillingConfigurationService;
use App\Services\GenerationService;
use App\Services\ProviderConfigurationService;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Symfony\Component\Process\Process;
use Tests\Support\BillingFixture;
use Tests\Support\BlueprintFixture;
use Tests\Support\ProviderFixture;
use Tests\TestCase;

/** 两个真实PHP进程竞争最后预算，断言只有一个进入传输且unknown保留余额。 */
class BillingConcurrencyTest extends TestCase
{
    public function test_two_workers_cannot_overspend_last_balance(): void
    {
        $this->assertSame('novel_forge_test', DB::selectOne('SELECT current_database() AS name')->name);
        Artisan::call('migrate:fresh', ['--force' => true]);
        $children = [];
        try {
            $f = ProviderFixture::create();
            $policy = app(BillingConfigurationService::class)->execute($f['actor'], 'policy', ['key' => 'last.balance', 'expected_version' => 0, 'currency' => 'USD', 'limits' => array_fill_keys(['request', 'chapter', 'run', 'book', 'day'], '0.0083920000'), 'warning_percent' => 80, 'stop_percent' => 100, 'timezone' => 'UTC', 'reason' => '两个进程竞争最后一笔预算'], 'last-policy');
            $f['payload']['billing']['policy_id'] = $policy['id'];
            $run = app(GenerationService::class)->start($f['actor'], $f['novelId'], $f['payload'], 'last-run')['run'];
            $claim = app(GenerationService::class)->claim($f['actor'], $f['novelId'], $run['id'], 'first');
            // 第二进程使用另一书和另一供应商，避免书锁/供应商锁掩盖日预算竞争。
            $second = BlueprintFixture::create($f['actor'], chapterCount: 1);
            $bookId = $second['book']['novel']['id'];
            $providerPayload = $f['provider_payload'];
            $providerPayload['name'] = '第二离线供应商';
            $provider = app(ProviderConfigurationService::class)->execute($f['actor'], 'provider', $providerPayload, 'provider-two');
            $modelPayload = $f['model_payload'];
            $modelPayload['provider_id'] = $provider['id'];
            $model = app(ProviderConfigurationService::class)->execute($f['actor'], 'model', $modelPayload, 'model-two');
            $price = BillingFixture::price($f['actor'], $model['version_id'], 'price-two');
            $payload = $f['payload'];
            $payload['outline_version_id'] = $second['outline']['id'];
            $payload['expected_version'] = Novel::find($bookId)->row_version;
            $payload['billing']['price_version_ids'] = [$price['id']];
            $other = app(GenerationService::class)->start($f['actor'], $bookId, $payload, 'last-run-two')['run'];
            $otherClaim = app(GenerationService::class)->claim($f['actor'], $bookId, $other['id'], 'first');
            foreach ([[$f['novelId'], $claim['id'], $f['model']['version_id'], 'last-a'], [$bookId, $otherClaim['id'], $model['version_id'], 'last-b']] as [$novel,$step,$modelId,$key]) {
                $p = new Process([PHP_BINARY, base_path('tests/Support/provider-worker.php'), (string) $f['actor']->id, $novel, $step, $modelId, json_encode($f['input']), $key], base_path(), ['APP_ENV' => 'testing']);
                $p->start();
                $children[] = $p;
            }
            $results = [];
            foreach ($children as $p) {
                $p->wait();
                $this->assertSame(0, $p->getExitCode(), $p->getErrorOutput());
                $results[] = json_decode($p->getOutput(), true, flags: JSON_THROW_ON_ERROR);
            }
            $this->assertSame(1, array_sum(array_column($results, 'calls')));
            $this->assertCount(1, array_filter($results, fn ($r) => ($r['error'] ?? '') === 'DEFERRED_BUDGET'));
            $this->assertDatabaseCount('ai_requests', 1);
            $this->assertDatabaseCount('budget_reservations', 1);
            $this->assertDatabaseCount('budget_allocations', 4);
            $this->assertNull(DB::table('budget_reservations')->value('actual_cost'));
            $this->assertSame('unknown', DB::table('budget_reservations')->value('status'));
        } finally {
            foreach ($children as $p) {
                if ($p->isRunning()) {
                    $p->stop();
                }
            }
            Artisan::call('migrate:fresh', ['--force' => true]);
        }
    }
}
