<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Symfony\Component\Process\Process;
use Tests\Support\ProviderFixture;
use Tests\TestCase;

/** 独立进程争抢同一请求与最后并发额度，不能用内存计数替代验证。 */
class ProviderConcurrencyTest extends TestCase
{
    public function test_two_processes_observe_persistent_idempotency_and_concurrency_limit(): void
    {
        $this->assertSame('novel_forge_test', DB::selectOne('SELECT current_database() AS name')->name);
        Artisan::call('migrate:fresh', ['--force' => true]);
        $children = [];
        try {
            $f = ProviderFixture::create();
            $parallel = function (array $keys) use ($f, &$children): array {
                $children = [];
                foreach ($keys as $key) {
                    $p = new Process([PHP_BINARY, base_path('tests/Support/provider-worker.php'), (string) $f['actor']->id, $f['novelId'], $f['claim']['id'], $f['model']['version_id'], json_encode($f['input']), $key], base_path(), ['APP_ENV' => 'testing']);
                    $p->start();
                    $children[] = $p;
                }
                $results = [];
                foreach ($children as $p) {
                    $p->wait();
                    $this->assertSame(0, $p->getExitCode(), $p->getErrorOutput());
                    $results[] = json_decode($p->getOutput(), true, flags: JSON_THROW_ON_ERROR);
                }

                return $results;
            };
            $r = $parallel(['same', 'same']);
            $this->assertSame($r[0]['result']['id'], $r[1]['result']['id']);
            $this->assertSame(1, array_sum(array_column($r, 'calls')));
            $this->assertDatabaseCount('ai_requests', 1);
            // 已有一个unknown占用，两个进程再争抢剩余一个额度。
            $r = $parallel(['last-a', 'last-b']);
            $this->assertCount(1, array_filter($r, fn ($x) => ($x['error'] ?? '') === 'RATE_LIMITED'));
            $this->assertSame(1, array_sum(array_column($r, 'calls')));
            $this->assertDatabaseCount('ai_requests', 2);
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
