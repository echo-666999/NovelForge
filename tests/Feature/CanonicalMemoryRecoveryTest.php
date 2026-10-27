<?php

namespace Tests\Feature;

use App\Services\CanonicalMemoryService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Symfony\Component\Process\Process;
use Tests\Support\CanonicalFixture as F;
use Tests\Support\RecoveryCleanup;
use Tests\Support\RecoveryEnvironment;
use Tests\TestCase;

/** 单独真实进程验证租约崩溃恢复与至少一次投递，不复用父事务模拟。 */
class CanonicalMemoryRecoveryTest extends TestCase
{
    /** 前置检查没有迁移/夹具副作用，私有Redis未就绪直接阻断。 */
    protected function setUp(): void
    {
        parent::setUp();
        RecoveryEnvironment::assertReady();
    }

    private function worker(string $id): Process
    {
        return new Process([PHP_BINARY, base_path('tests/Support/canonical-memory-worker.php'), $id], base_path(), ['APP_ENV' => 'testing']);
    }

    public function test_new_worker_recovers_expired_lease_and_replayed_delivery_does_not_duplicate_outputs(): void
    {
        $this->assertSame('novel_forge_test', DB::selectOne('SELECT current_database() AS db')->db);
        RecoveryEnvironment::resetDatabase();
        $children = [];
        (new RecoveryCleanup)->run(function () use (&$children) {
            $f = F::create();
            F::commit($f);
            $s = app(CanonicalMemoryService::class);
            $s->discover();
            $id = (string) DB::table('canonical_memory_tasks')->value('id');
            $lease = $s->claim($id);
            $s->prepare($lease);
            $this->assertDatabaseCount('canonical_memories', 0);
            DB::table('canonical_memory_tasks')->where('id', $id)->update(['lease_until' => DB::raw("clock_timestamp()-interval '1 second'")]);
            foreach ([true, false] as $expected) {
                $p = $this->worker($id);
                $children[] = $p;
                $p->setTimeout(60)->run();
                $this->assertSame(0, $p->getExitCode(), $p->getErrorOutput());
                $this->assertSame($expected, json_decode($p->getOutput(), true)['processed']);
            }
            $this->assertDatabaseCount('canonical_memories', 4);
            $this->assertDatabaseCount('canonical_memory_documents', 1);
            $this->assertDatabaseCount('canonical_memory_embeddings', 1);
            $this->assertSame(2, DB::table('canonical_memory_tasks')->value('token'));
            $this->assertDatabaseCount('story_events', 1);
        }, [
            'workers' => function () use (&$children) {
                RecoveryCleanup::stopProcesses($children);
            },
            'database_reset' => fn () => RecoveryEnvironment::resetDatabase(),
        ]);
    }

    public function test_two_consumers_of_the_same_task_only_materialize_once(): void
    {
        RecoveryEnvironment::resetDatabase();
        $children = [];
        (new RecoveryCleanup)->run(function () use (&$children) {
            $f = F::create();
            F::commit($f);
            app(CanonicalMemoryService::class)->discover();
            $id = (string) DB::table('canonical_memory_tasks')->value('id');
            foreach ([1, 2] as $i) {
                $p = $this->worker($id);
                $p->setTimeout(60)->start();
                $children[] = $p;
            }$results = [];
            foreach ($children as $p) {
                $p->wait();
                $this->assertSame(0, $p->getExitCode(), $p->getErrorOutput());
                $results[] = json_decode($p->getOutput(), true)['processed'];
            }$this->assertSame(1, count(array_filter($results)));
            $this->assertDatabaseCount('canonical_memories', 4);
            $this->assertDatabaseCount('canonical_memory_documents', 1);
            $this->assertDatabaseCount('story_events', 1);
        }, [
            'workers' => function () use (&$children) {
                RecoveryCleanup::stopProcesses($children);
            },
            'database_reset' => fn () => RecoveryEnvironment::resetDatabase(),
        ]);
    }

    /** 真实Redis发布到memory队列，再由新的artisan Worker执行并确认。 */
    public function test_scheduler_publication_is_consumed_by_real_memory_queue_worker(): void
    {
        RecoveryEnvironment::resetDatabase();
        $children = [];
        (new RecoveryCleanup)->run(function () use (&$children) {
            $queue = Queue::connection('redis');
            $queue->clear('memory');
            $f = F::create();
            F::commit($f);
            $s = app(CanonicalMemoryService::class);
            $this->assertSame(1, $s->publish()['published']);
            $this->assertSame(0, $s->publish()['published']);
            $this->assertSame(0, DB::table('canonical_memory_tasks')->value('attempts'));
            $worker = new Process([PHP_BINARY, base_path('artisan'), 'queue:work', 'redis', '--queue=memory', '--once', '--sleep=0', '--tries=1', '--timeout=60'], base_path(), ['APP_ENV' => 'testing']);
            $children[] = $worker;
            $worker->setTimeout(90)->run();
            $this->assertSame(0, $worker->getExitCode(), $worker->getOutput().$worker->getErrorOutput());
            $this->assertSame('complete', DB::table('canonical_memory_tasks')->value('status'));
            $this->assertDatabaseCount('canonical_memories', 4);
            $this->assertSame(0, $queue->size('memory'));
        }, [
            'workers' => function () use (&$children) {
                RecoveryCleanup::stopProcesses($children);
            },
            'memory_queue' => fn () => Queue::connection('redis')->clear('memory'),
            'database_reset' => fn () => RecoveryEnvironment::resetDatabase(),
        ]);
    }
}
