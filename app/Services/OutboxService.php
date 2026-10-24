<?php

namespace App\Services;

use App\Exceptions\NovelProblem;
use App\Generation\StageExecutor;
use App\Generation\TestEchoExecutor;
use App\Jobs\ConsumeGenerationIntent;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Throwable;

/** 至少一次投递：消息可重复/丢失，任务事实与完成回执不能丢失。 */
final class OutboxService
{
    public function startQueued(User $actor, string $novelId, array $payload, string $key): array
    {
        return DB::transaction(function () use ($actor, $novelId, $payload, $key) {
            $result = app(GenerationService::class)->start($actor, $novelId, $payload, $key);
            foreach ($payload['nodes'] as $node) {
                $this->enqueue($actor, $novelId, $result['run']['id'], $node['key']);
            }

            return $result;
        });
    }

    /** 与调用者业务事务共同提交；本方法绝不直接向 Redis 发送。 */
    public function enqueue(User $actor, string $novelId, string $runId, string $scopeKey, int $retryOf = 0): string
    {
        return DB::transaction(function () use ($actor, $novelId, $runId, $scopeKey, $retryOf) {
            app(NovelIntentService::class)->novel($actor, $novelId, true);
            $data = app(GenerationService::class)->read($actor, $novelId, $runId);
            $node = collect($data['run']['frozen_config']['nodes'])->firstWhere('key', $scopeKey);
            $queue = $node ? (config('orchestration.stage_queues')[$node['stage']] ?? null) : null;
            if (! $node || ! in_array($queue, config('orchestration.queues'), true) || $retryOf < 0 || $retryOf > 2) {
                throw new NovelProblem('INVALID_STAGE', '未注册的阶段或重试身份。', 422);
            }
            $identity = ['novel_id' => $novelId, 'run_id' => $runId, 'scope_key' => $scopeKey, 'retry_of' => $retryOf];
            DB::table('outbox_events')->insertOrIgnore($identity + ['queue' => $queue]);

            return (string) DB::table('outbox_events')->where($identity)->value('id');
        });
    }

    /** 可配置的队列必须保留 timeout < lease < retry_after 的安全间距。 */
    public function assertTiming(): void
    {
        $retry = config('queue.connections.redis.retry_after');
        if (! is_numeric($retry) || (int) $retry <= 90 || config('orchestration.worker_timeout') !== 60 || config('orchestration.lease_seconds') !== 90) {
            throw new \RuntimeException('队列超时配置不安全：需要 timeout=60、lease=90、retry_after>90。');
        }
    }

    public function publish(int $limit = 100): array
    {
        $this->assertTiming();
        // 短事务抢占投递批次；即使发布者随后崩溃，一分钟后也能重新认领。
        $events = DB::transaction(function () use ($limit) {
            $rows = DB::table('outbox_events')->whereNotIn('id', DB::table('consumer_receipts')->select('event_id'))
                ->whereRaw('next_delivery_at <= clock_timestamp()')->orderBy('id')->limit(max(1, min(1000, $limit)))->lock('FOR UPDATE SKIP LOCKED')->get();
            foreach ($rows as $row) {
                DB::table('outbox_events')->where('id', $row->id)->update(['delivery_count' => DB::raw('delivery_count + 1'), 'next_delivery_at' => DB::raw("clock_timestamp() + interval '60 seconds'")]);
            }

            return $rows;
        });
        $published = 0;
        $failed = 0;
        foreach ($events as $event) {
            try {
                Queue::connection('redis')->push(new ConsumeGenerationIntent((string) $event->id), '', $event->queue);
                DB::table('outbox_events')->where('id', $event->id)->where('delivery_count', $event->delivery_count + 1)->update(['published_at' => DB::raw('clock_timestamp()'), 'last_error' => null]);
                $published++;
            } catch (Throwable) {
                // 不持久化可能包含凭据的 Redis 异常；未确认的发布也允许重复。
                DB::table('outbox_events')->where('id', $event->id)->where('delivery_count', $event->delivery_count + 1)->update(['last_error' => 'DELIVERY_FAILED']);
                $failed++;
            }
        }

        return compact('published', 'failed');
    }

    public function consume(string $eventId, ?StageExecutor $executor = null): void
    {
        $event = DB::table('outbox_events')->find($eventId);
        if (! $event || DB::table('consumer_receipts')->where('event_id', $eventId)->exists()) {
            return;
        }
        // 现阶段没有生产阶段注册；不能根据队列消息动态实例化任意类。
        if (! app()->environment('testing')) {
            throw new NovelProblem('STAGE_NOT_AVAILABLE', '生产阶段尚未注册。', 403);
        }
        $actor = User::findOrFail(DB::table('generation_runs')->where('id', $event->run_id)->value('actor_id'));
        try {
            app(GenerationService::class)->advance($actor, (string) $event->novel_id, (string) $event->run_id, $event->scope_key, $executor ?? new TestEchoExecutor, $event->retry_of ?: null, $eventId);
            // 覆盖“阶段已落盘、旧投递尚未确认”的重复消费路径。
            $this->acknowledge($eventId);
        } catch (NovelProblem $e) {
            DB::table('outbox_events')->where('id', $eventId)->update(['last_error' => $e->errorCode]);
            throw $e;
        }
    }

    /** 回执只在对应尝试终态后插入；正常完成时与产物共用同一事务。 */
    public function acknowledge(string $eventId): void
    {
        $event = DB::table('outbox_events')->find($eventId);
        if (! $event) {
            return;
        }
        $terminal = DB::table('generation_steps')->where('run_id', $event->run_id)->where('scope_key', $event->scope_key)
            ->where('attempt_no', $event->retry_of + 1)->whereIn('status', ['succeeded', 'failed', 'deferred', 'blocked', 'canceled', 'quarantined'])->exists();
        if ($terminal) {
            DB::table('consumer_receipts')->insertOrIgnore(['event_id' => $eventId]);
        }
    }

    /** 每分钟扫描：失效步骤立即使其持久意图可再投，运行时仍须重新认领令牌。 */
    public function recover(): array
    {
        $expired = DB::table('generation_steps')->where('status', 'running')->whereRaw('(lease_until <= clock_timestamp() OR lease_until IS NULL)')->get();
        foreach ($expired as $step) {
            DB::table('outbox_events')->where('run_id', $step->run_id)->where('scope_key', $step->scope_key)->where('retry_of', $step->attempt_no - 1)
                ->whereNotIn('id', DB::table('consumer_receipts')->select('event_id'))->update(['next_delivery_at' => DB::raw('clock_timestamp()'), 'last_error' => 'LEASE_EXPIRED']);
        }

        return ['expired_steps' => $expired->count(), 'unacknowledged_events' => DB::table('outbox_events')->whereNotIn('id', DB::table('consumer_receipts')->select('event_id'))->count()];
    }
}
