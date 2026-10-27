<?php

namespace Tests\Feature;

use App\Services\RecoveryService;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Symfony\Component\Process\Process;
use Tests\Support\QueuedRunFixture;
use Tests\TestCase;

/** 两个独立进程竞争同一恢复预览，确保只接受一个互斥决定。 */
class RecoveryConcurrencyTest extends TestCase
{
    public function test_conflicting_decisions_are_serialized_and_replay_is_stable(): void
    {
        $this->assertSame('novel_forge_test', DB::selectOne('SELECT current_database() AS name')->name);
        Artisan::call('migrate:fresh', ['--force' => true]);
        $children = [];
        try {
            $f = QueuedRunFixture::create();
            $payload = ['reason' => '并发控制验收', 'preview_hash' => app(RecoveryService::class)->preview($f['actor'], $f['novelId'], $f['runId'])['preview_hash']];
            foreach (['pause', 'cancel'] as $action) {
                $p = new Process([PHP_BINARY, base_path('tests/Support/recovery-worker.php'), (string) $f['actor']->id, $f['novelId'], $f['runId'], $action, json_encode($payload), $action], base_path(), ['APP_ENV' => 'testing']);
                $p->start();
                $children[] = $p;
            }
            $results = [];
            foreach ($children as $p) {
                $p->wait();
                $this->assertSame(0, $p->getExitCode(), $p->getErrorOutput());
                $results[] = json_decode($p->getOutput(), true, flags: JSON_THROW_ON_ERROR);
            }
            $this->assertCount(1, array_filter($results, fn ($r) => $r['ok']));
            $this->assertSame('STALE_RECOVERY_PREVIEW', array_values(array_filter($results, fn ($r) => ! $r['ok']))[0]['code']);
            $this->assertDatabaseCount('recovery_decisions', 1);
            $winner = array_values(array_filter($results, fn ($r) => $r['ok']))[0]['result'];
            $this->assertEquals($winner, app(RecoveryService::class)->execute($f['actor'], $f['novelId'], $f['runId'], $winner['action'], $payload, $winner['action']));
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
