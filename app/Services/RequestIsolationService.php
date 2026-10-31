<?php

namespace App\Services;

use App\Exceptions\NovelProblem;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;

/** 人工隔离历史未知请求：不解释供应商结果，不冲销预算，不解除原运行恢复门禁。 */
final class RequestIsolationService
{
    public function isolate(User $actor, string $novel, string $request, string $reason): array
    {
        Validator::make(['reason' => $reason], ['reason' => 'required|string|min:10|max:2000'])->validate();
        app(ProviderGateway::class)->read($actor, $novel, $request);

        return DB::transaction(function () use ($actor, $novel, $request, $reason) {
            // 与网关一致的锁顺序，避免隔离和新请求并发统计交错。
            app(NovelIntentService::class)->novel($actor, $novel, true);
            $q = DB::table('ai_requests')->where('novel_id', $novel)->find($request);
            $run = DB::table('generation_runs')->where('id', $q->run_id)->lockForUpdate()->first();
            DB::table('providers')->where('id', $q->provider_id)->lockForUpdate()->first();
            $q = DB::table('ai_requests')->where('id', $request)->lockForUpdate()->first();
            if ($q->status !== 'unknown' || ! in_array($run->status, ['blocked', 'failed', 'needs_attention', 'canceled'], true)
                || DB::table('ai_requests')->where('run_id', $q->run_id)->where('status', 'pending')->exists()
                || DB::table('generation_steps')->where('run_id', $q->run_id)->where('status', 'running')->whereRaw('lease_until > clock_timestamp()')->exists()) {
                throw new NovelProblem('REQUEST_NOT_ISOLATABLE', '只允许隔离已停止且无有效执行租约的未知请求。');
            }
            // 已停止的旧运行不再占用整书执行槽；只删除精确归属该运行的槽，不触及新运行。
            DB::table('generation_book_slots')->where('novel_id', $novel)->where('run_id', $q->run_id)->delete();
            if ($old = DB::table('ai_request_isolations')->where('request_id', $request)->first()) {
                return (array) $old;
            }
            $id = DB::table('ai_request_isolations')->insertGetId(['request_id' => $request, 'actor_id' => $actor->id, 'reason' => $reason]);

            return (array) DB::table('ai_request_isolations')->find($id);
        });
    }
}
