<?php

namespace Tests\Feature;

use App\Exceptions\NovelProblem;
use App\Generation\StageResult;
use App\Services\GenerationService;
use App\Services\OutboxService;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Tests\Support\FakeStageExecutor;
use Tests\Support\QueuedRunFixture;
use Tests\TestCase;

/** 使用 PostgreSQL 约束、真实 Redis 与显式故障注入验证队列语义。 */
class OutboxTest extends TestCase
{
    use RefreshDatabase;

    private function fixture(): array
    {
        Http::preventStrayRequests();

        return QueuedRunFixture::create();
    }

    private function event(array $f, string $key = 'first'): string
    {
        return (string) DB::table('outbox_events')->where('run_id', $f['runId'])->where('scope_key', $key)->value('id');
    }

    private function claim(array $f): ?array
    {
        return app(GenerationService::class)->claim($f['actor'], $f['novelId'], $f['runId'], 'first');
    }

    private function complete(array $f, array $claim): bool
    {
        return app(GenerationService::class)->complete($f['actor'], $f['novelId'], $f['runId'], $claim, new StageResult('succeeded', ['text' => '有效结果']), $this->event($f));
    }

    public function test_run_and_intent_share_transaction_and_rollback(): void
    {
        $f = $this->fixture();
        $this->assertDatabaseCount('generation_runs', 1);
        $this->assertDatabaseCount('outbox_events', 2);
        $this->assertSame(0, DB::table('outbox_events')->whereNotNull('published_at')->count());
        app(OutboxService::class)->startQueued($f['actor'], $f['novelId'], $f['payload'], 'queued');
        $this->assertDatabaseCount('outbox_events', 2);
        try {
            DB::transaction(function () use ($f) {
                app(OutboxService::class)->startQueued($f['actor'], $f['novelId'], $f['payload'], 'rollback');
                throw new \RuntimeException('模拟提交前中断');
            });
        } catch (\RuntimeException) {
            $this->assertDatabaseCount('generation_runs', 1);
            $this->assertDatabaseCount('outbox_events', 2);
        }
    }

    public function test_duplicate_delivery_and_result_receipt_are_atomic(): void
    {
        $f = $this->fixture();
        $claim = $this->claim($f);
        try {
            DB::transaction(function () use ($f, $claim) {
                $this->assertTrue($this->complete($f, $claim));
                throw new \RuntimeException('模拟落盘事务回滚');
            });
        } catch (\RuntimeException) {
            $this->assertDatabaseCount('generation_artifacts', 0);
            $this->assertDatabaseCount('consumer_receipts', 0);
        }
        $this->assertTrue($this->complete($f, $claim));
        $fake = new FakeStageExecutor;
        app(OutboxService::class)->consume($this->event($f), $fake);
        $this->assertSame(0, $fake->calls);
        $this->assertDatabaseCount('consumer_receipts', 1);
        $this->assertDatabaseCount('generation_artifacts', 1);
        $this->assertFalse($this->complete($f, $claim));
    }

    public function test_expired_heartbeat_and_stale_worker_cannot_write(): void
    {
        $f = $this->fixture();
        $g = app(GenerationService::class);
        $old = $this->claim($f);
        $this->assertTrue($g->heartbeat($old['id'], $old['token'], $old['owner']));
        $this->assertFalse($g->heartbeat($old['id'], '99', $old['owner']));
        DB::table('generation_steps')->where('id', $old['id'])->update(['lease_until' => DB::raw("clock_timestamp() - interval '1 second'")]);
        $this->assertFalse($g->heartbeat($old['id'], $old['token'], $old['owner']));
        $this->assertFalse($this->complete($f, $old));
        $new = $this->claim($f);
        $this->assertSame('2', $new['token']);
        $this->assertSame($old['id'], $new['id']);
        $this->assertFalse($this->complete($f, $old));
        $this->assertTrue($this->complete($f, $new));
        $this->assertDatabaseCount('generation_artifacts', 1);
        $this->assertDatabaseCount('generation_steps', 1);
        $this->assertFalse($g->heartbeat($new['id'], $new['token'], $new['owner']));
    }

    public function test_reclaim_is_bounded_and_scanner_detects_expired_lease(): void
    {
        $f = $this->fixture();
        for ($i = 1; $i <= 3; $i++) {
            $claim = $this->claim($f);
            $this->assertSame((string) $i, $claim['token']);
            DB::table('generation_steps')->where('id', $claim['id'])->update(['lease_until' => DB::raw("clock_timestamp() - interval '1 minute'")]);
        }
        DB::table('outbox_events')->update(['next_delivery_at' => DB::raw("clock_timestamp() + interval '1 hour'")]);
        $t = microtime(true);
        $scan = app(OutboxService::class)->recover();
        $this->assertSame(1, $scan['expired_steps']);
        $this->assertLessThan(300, microtime(true) - $t);
        $this->assertSame(1, DB::table('outbox_events')->whereRaw('next_delivery_at <= clock_timestamp()')->count());
        $this->assertNull($this->claim($f));
        $this->assertSame('LEASE_EXHAUSTED', DB::table('generation_steps')->value('error_code'));
        app(OutboxService::class)->consume($this->event($f));
        $this->assertDatabaseCount('consumer_receipts', 1);
        $this->assertDatabaseCount('generation_artifacts', 0);
    }

    public function test_same_book_queued_runs_are_serial_until_run_finishes(): void
    {
        $f = $this->fixture();
        $other = $f;
        $other['runId'] = app(OutboxService::class)->startQueued($f['actor'], $f['novelId'], $f['payload'], 'other')['run']['id'];
        $first = $this->claim($f);
        $this->assertNull($this->claim($other));
        $this->assertTrue($this->complete($f, $first));
        // 仅第一阶段完成不能释放整书串行槽。
        $this->assertNull($this->claim($other));
        app(OutboxService::class)->consume($this->event($f, 'second'));
        $this->assertNotNull($this->claim($other));
    }

    public function test_pause_and_cancel_are_rechecked_before_commit(): void
    {
        $f = $this->fixture();
        $claim = $this->claim($f);
        DB::table('generation_runs')->where('id', $f['runId'])->update(['status' => 'canceled']);
        $this->assertTrue($this->complete($f, $claim));
        $this->assertSame('quarantined', DB::table('generation_steps')->value('status'));
        $this->assertSame('canceled', DB::table('generation_runs')->value('status'));
        $this->assertDatabaseCount('generation_book_slots', 0);
        $this->expectException(NovelProblem::class);
        $this->claim($f);
    }

    public function test_real_queue_publish_duplicate_and_republish_without_receipt(): void
    {
        $f = $this->fixture();
        $q = Queue::connection('redis');
        $q->clear('generation');
        try {
            $o = app(OutboxService::class);
            $this->assertSame(['published' => 2, 'failed' => 0], $o->publish());
            $this->assertSame(2, $q->size('generation'));
            $this->assertSame(['published' => 0, 'failed' => 0], $o->publish());
            // 模拟发布成功但确认前崩溃：再次投递相同意图。
            DB::table('outbox_events')->update(['next_delivery_at' => DB::raw('clock_timestamp()')]);
            $this->assertSame(2, $o->publish()['published']);
            for ($i = 0; $i < 4; $i++) {
                $this->assertSame(0, Artisan::call('queue:work', ['connection' => 'redis', '--queue' => 'generation', '--once' => true, '--tries' => 1, '--timeout' => 60]));
            }
            $this->assertDatabaseCount('generation_artifacts', 2);
            $this->assertDatabaseCount('consumer_receipts', 2);
            $this->assertSame('succeeded', DB::table('generation_runs')->value('status'));
            Http::assertNothingSent();
        } finally {
            $q->clear('generation');
        }
    }

    public function test_delivery_failure_preserves_intent_and_can_retry(): void
    {
        $this->fixture();
        Queue::shouldReceive('connection')->with('redis')->andThrow(new \RuntimeException('secret-connection-detail'));
        $this->assertSame(['published' => 0, 'failed' => 2], app(OutboxService::class)->publish());
        $this->assertSame('DELIVERY_FAILED', DB::table('outbox_events')->value('last_error'));
        $this->assertDatabaseCount('outbox_events', 2);
        $this->assertDatabaseCount('consumer_receipts', 0);
    }

    public function test_retry_notice_cannot_create_first_attempt_and_stale_notice_cannot_reclaim_retry(): void
    {
        $f = $this->fixture();
        $o = app(OutboxService::class);
        $retry = $o->enqueue($f['actor'], $f['novelId'], $f['runId'], 'first', 1);
        $o->consume($retry);
        $this->assertDatabaseCount('generation_steps', 0);
        $o->consume($this->event($f), new FakeStageExecutor('deferred'));
        $claim = app(GenerationService::class)->claim($f['actor'], $f['novelId'], $f['runId'], 'first', 1);
        DB::table('generation_steps')->where('id', $claim['id'])->update(['lease_until' => DB::raw("clock_timestamp() - interval '1 second'")]);
        $this->assertNull($this->claim($f));
        $o->consume($retry);
        $this->assertDatabaseCount('generation_steps', 2);
        $this->assertDatabaseCount('consumer_receipts', 2);
        $this->assertSame(2, DB::table('generation_steps')->where('attempt_no', 2)->value('fencing_token'));
    }

    public function test_failed_run_replay_cannot_reacquire_released_book_slot(): void
    {
        $f = $this->fixture();
        $o = app(OutboxService::class);
        $o->consume($this->event($f), new FakeStageExecutor('failed'));
        $this->assertDatabaseCount('generation_book_slots', 0);
        // 下游尚未执行，失败依赖不能阻塞其他运行的书槽。
        $o->consume($this->event($f, 'second'));
        $this->assertDatabaseCount('generation_book_slots', 0);
        $other = $f;
        $other['runId'] = $o->startQueued($f['actor'], $f['novelId'], $f['payload'], 'next')['run']['id'];
        $this->assertNotNull($this->claim($other));
    }

    public function test_unsafe_timing_and_immutable_intent_are_rejected(): void
    {
        $f = $this->fixture();
        try {
            DB::transaction(fn () => DB::table('outbox_events')->where('id', $this->event($f))->update(['scope_key' => 'tampered']));
            $this->fail('持久意图不能改写');
        } catch (QueryException) {
            $this->assertTrue(true);
        }
        config(['queue.connections.redis.retry_after' => 60]);
        $this->expectException(\RuntimeException::class);
        app(OutboxService::class)->publish();
    }
}
