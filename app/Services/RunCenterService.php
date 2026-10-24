<?php

namespace App\Services;

use App\Exceptions\NovelProblem;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;

/** 只读运行投影；页面刷新不执行任务，正文正式进度没有实现时保持未知。 */
final class RunCenterService
{
    private function filters(array $f): array
    {
        return Validator::make($f, [
            'novel_id' => 'nullable|integer|min:1|max:9223372036854775807', 'run_id' => 'nullable|integer|min:1|max:9223372036854775807',
            'status' => 'nullable|string|max:80', 'error' => 'nullable|string|max:80', 'trace_id' => 'nullable|uuid', 'object_type' => 'nullable|string|max:80',
            'action' => 'nullable|string|max:100', 'high_risk' => 'nullable|boolean', 'date_from' => 'nullable|date_format:Y-m-d', 'date_to' => 'nullable|date_format:Y-m-d',
            'page' => 'nullable|integer|min:1|max:1000000',
        ])->validate();
    }

    public function listing(User $actor, array $filters = []): array
    {
        app(NovelIntentService::class)->authorize($actor);
        $f = $this->filters($filters);
        $q = DB::table('generation_runs as r')->join('novels as n', 'n.id', '=', 'r.novel_id')->join('run_traces as t', 't.run_id', '=', 'r.id')->where('n.owner_id', $actor->id);
        foreach (['novel_id', 'status', 'run_id'] as $key) {
            if (! empty($f[$key])) {
                $q->where($key === 'run_id' ? 'r.id' : 'r.'.$key, $f[$key]);
            }
        }
        if (! empty($f['error'])) {
            $q->where('r.error_code', $f['error']);
        }
        if (! empty($f['trace_id'])) {
            $q->where('t.trace_id', $f['trace_id']);
        }
        $this->dates($q, $f, 'r.created_at');
        $counts = (clone $q)->selectRaw('r.status,count(*)::int AS total')->groupBy('r.status')->pluck('total', 'status')->all();
        $total = (clone $q)->count();
        $page = $f['page'] ?? 1;
        $rows = $q->orderByDesc('r.id')->offset(($page - 1) * 25)->limit(25)->select('r.id', 'r.novel_id', 'n.title', 'r.status', 'r.error_code', 'r.created_at', 'r.updated_at', 'r.frozen_config', 't.trace_id', 't.origin')->get()->map(function ($r) {
            $nodes = json_decode($r->frozen_config, true)['nodes'];
            $done = DB::table('generation_steps')->where('run_id', $r->id)->orderByDesc('attempt_no')->get()->unique('scope_key')->where('status', 'succeeded')->count();

            return ['id' => (string) $r->id, 'novel_id' => (string) $r->novel_id, 'title' => $r->title, 'status' => $r->status, 'error_code' => $r->error_code,
                'trace_id' => $r->trace_id, 'trace_origin' => $r->origin, 'created_at' => $this->time($r->created_at), 'updated_at' => $this->time($r->updated_at),
                'costs' => DB::table('budget_reservations as b')->join('ai_requests as a', 'a.id', '=', 'b.request_id')->where('a.run_id', $r->id)->selectRaw("b.currency,sum(b.actual_cost)::text AS settled_cost,coalesce(sum(b.amount) FILTER(WHERE b.status<>'settled'),0)::text AS risk_reserved,count(*) FILTER(WHERE b.status<>'settled')::int AS unknown_count")->groupBy('b.currency')->get()->all(), 'steps_succeeded' => $done, 'steps_total' => count($nodes), 'canonical_chapters' => null,
                'detail_url' => '/admin/run-center?novel='.$r->novel_id.'&run='.$r->id];
        })->all();

        return ['rows' => $rows, 'total' => $total, 'page' => $page, 'per_page' => 25, 'status_counts' => $counts, 'production_start_available' => false];
    }

    public function detail(User $actor, string $novelId, string $runId, int $requestPage = 1): array
    {
        Validator::make(['request_page' => $requestPage], ['request_page' => 'integer|min:1|max:1000000'])->validate();
        $data = app(GenerationService::class)->read($actor, $novelId, $runId);
        $data['run'] = $this->normalize($data['run']);
        $trace = DB::table('run_traces')->where('run_id', $runId)->first();
        foreach ($data['steps'] as &$step) {
            $step['trace_id'] = $trace->trace_id;
            // 原始心跳时间直接呈现；过期不伪装为Worker在线或成功。
            $step['lease_expired'] = $step['status'] === 'running' && (! $step['lease_until'] || CarbonImmutable::parse($step['lease_until'])->isPast());
            $step['duration_seconds'] = max(0, CarbonImmutable::parse($step['created_at'])->diffInSeconds(CarbonImmutable::parse($step['updated_at']), false));
            $step = $this->normalize($step);
        }
        unset($step);
        $artifacts = [];
        foreach ($data['artifacts'] as $a) {
            $text = NovelIntentService::canonical($a['content']);
            unset($a['content']);
            $a += ['trace_id' => $trace->trace_id, 'preview' => mb_substr($text, 0, 500), 'truncated' => mb_strlen($text) > 500,
                'download_url' => "/api/v1/novels/{$novelId}/runs/{$runId}/artifacts/{$a['id']}"];
            $artifacts[] = $this->normalize($a);
        }
        $requests = DB::table('ai_requests')->where('run_id', $runId)->orderBy('id')->forPage($requestPage, 50)->get()->map(function ($r) use ($actor, $novelId, $runId, $trace) {
            $row = app(ProviderGateway::class)->read($actor, $novelId, (string) $r->id);
            $row['trace_id'] = $trace->trace_id;
            $row['created_at'] = $this->time($r->created_at);
            $row['completed_at'] = $this->time($r->completed_at);
            foreach ($row['evidence'] as &$e) {
                $e['download_url'] = "/api/v1/novels/{$novelId}/runs/{$runId}/requests/{$r->id}/evidence/{$e['kind']}";
            }
            unset($e);

            return $row;
        })->all();

        return ['trace_id' => $trace->trace_id, 'trace_origin' => $trace->origin, 'run' => $data['run'], 'steps' => $data['steps'], 'artifacts' => $artifacts, 'requests' => $requests, 'request_page' => $requestPage, 'requests_total' => DB::table('ai_requests')->where('run_id', $runId)->count(), 'requests_per_page' => 50,
            'outbox' => DB::table('outbox_events as o')->leftJoin('consumer_receipts as c', 'c.event_id', '=', 'o.id')->where('o.run_id', $runId)->orderBy('o.id')->get(['o.id', 'o.scope_key', 'o.retry_of', 'o.queue', 'o.next_delivery_at', 'o.delivery_count', 'o.last_error', 'c.completed_at'])->map(fn ($r) => $this->normalize((array) $r))->all(),
            'billing' => app(BillingConfigurationService::class)->listing($actor, ['novel_id' => $novelId, 'run_id' => $runId]),
            'recovery_url' => "/admin/recovery-desk?novel={$novelId}&run={$runId}", 'billing_url' => "/admin/billing?novel={$novelId}&run={$runId}",
            'audit_url' => '/admin/audit-center?trace='.$trace->trace_id];
    }

    public function audit(User $actor, array $filters = []): array
    {
        app(NovelIntentService::class)->authorize($actor);
        $f = $this->filters($filters);
        $q = DB::table('audit_events')->where(function ($q) use ($actor) {
            $q->whereIn('novel_id', DB::table('novels')->where('owner_id', $actor->id)->select('id'))->orWhere(function ($q) use ($actor) {
                $q->whereNull('novel_id')->where('actor_id', $actor->id);
            });
        });
        foreach (['novel_id', 'run_id', 'object_type', 'action', 'high_risk'] as $key) {
            if (isset($f[$key]) && $f[$key] !== '') {
                $q->where($key, $f[$key]);
            }
        }
        if (! empty($f['status'])) {
            $q->where('outcome', $f['status']);
        }
        if (! empty($f['trace_id'])) {
            $q->where(fn ($q) => $q->where('trace_id', $f['trace_id'])->orWhere('operation_trace_id', $f['trace_id']));
        }
        $this->dates($q, $f, 'created_at');
        $total = (clone $q)->count();
        $page = $f['page'] ?? 1;
        $rows = $q->orderByDesc('id')->offset(($page - 1) * 25)->limit(25)->get()->map(function ($e) {
            $a = (array) $e;
            foreach (['id', 'novel_id', 'run_id', 'actor_id'] as $key) {
                $a[$key] = $a[$key] === null ? null : (string) $a[$key];
            }
            foreach (['before_state', 'after_state'] as $key) {
                $a[$key] = $this->normalize(json_decode($a[$key], true));
            }
            $a['created_at'] = $this->time($a['created_at']);
            $a['artifact_url'] = $e->object_type === 'generation_artifacts' && $e->run_id ? '/api/v1/novels/'.$e->novel_id.'/runs/'.$e->run_id.'/artifacts/'.$e->object_id : null;

            return $a;
        })->all();

        return compact('rows', 'total', 'page') + ['per_page' => 25];
    }

    public function health(User $actor): array
    {
        app(NovelIntentService::class)->authorize($actor);
        $books = DB::table('novels')->where('owner_id', $actor->id)->select('id');
        $runs = DB::table('generation_runs')->whereIn('novel_id', $books)->select('id');
        $expired = DB::table('generation_steps')->whereIn('run_id', $runs)->where('status', 'running')->whereRaw('(lease_until IS NULL OR lease_until <= clock_timestamp())')->count();
        $blocked = DB::table('generation_runs')->whereIn('id', $runs)->whereIn('status', ['failed', 'blocked', 'deferred', 'needs_attention'])->count();
        $unknown = DB::table('budget_reservations')->whereIn('request_id', DB::table('ai_requests')->whereIn('run_id', $runs)->select('id'))->where('status', 'unknown')->count();
        $outbox = DB::table('outbox_events')->whereIn('run_id', $runs)->whereNotIn('id', DB::table('consumer_receipts')->select('event_id'))->whereNotIn('run_id', DB::table('generation_runs')->whereIn('status', ['paused', 'canceled', 'needs_attention'])->select('id'))->whereRaw('next_delivery_at < clock_timestamp()')->count();

        $requestErrors = DB::table('ai_requests')->whereIn('run_id', DB::table('generation_runs')->whereIn('id', $runs)->whereNotIn('status', ['succeeded', 'canceled'])->select('id'))->whereIn('status', ['failed', 'unknown'])->count();

        return ['state' => $expired ? 'incident' : (($blocked || $unknown || $outbox || $requestErrors) ? 'degraded' : 'healthy'), 'scope' => '仅数据库观测，不代表外部服务可用性', 'unresolved_request_errors' => $requestErrors, 'expired_leases' => $expired, 'blocked_runs' => $blocked, 'unknown_cost_requests' => $unknown, 'due_outbox' => $outbox, 'redis_connectivity' => 'unknown', 'provider_availability' => 'unverified', 'worker_processes' => 'unknown', 'canonical_progress' => null];
    }

    public function download(User $actor, string $novelId, string $runId, string $kind, string $id, ?string $evidenceKind = null): string
    {
        app(GenerationService::class)->read($actor, $novelId, $runId);
        if ($kind === 'artifact') {
            $row = DB::table('generation_artifacts')->where('run_id', $runId)->where('id', $id)->first();
            if (! $row) {
                throw new NovelProblem('NOT_FOUND', '产物不存在。', 404);
            }
            $text = NovelIntentService::canonical(json_decode($row->content, true));
            if (! hash_equals($row->content_hash, hash('sha256', $text))) {
                throw new NovelProblem('EVIDENCE_CORRUPT', '产物哈希不符。');
            }
        } else {
            if (! in_array($evidenceKind, ['request', 'response'], true) || ! DB::table('ai_requests')->where('run_id', $runId)->where('id', $id)->exists()) {
                throw new NovelProblem('NOT_FOUND', '证据不存在。', 404);
            }
            $text = app(ProviderGateway::class)->evidenceText($actor, $novelId, $id, $evidenceKind);
            $hash = DB::table('ai_request_evidence')->where('request_id', $id)->where('kind', $evidenceKind)->value('content_hash');
            if ($text === null) {
                throw new NovelProblem('NOT_FOUND', '证据尚未产生。', 404);
            }
            if (! hash_equals($hash, hash('sha256', $text))) {
                throw new NovelProblem('EVIDENCE_CORRUPT', '证据哈希不符。');
            }
        }
        $trace = DB::table('run_traces')->where('run_id', $runId)->value('trace_id');
        DB::table('audit_events')->insert(['trace_id' => $trace, 'operation_trace_id' => request()->attributes->get('trace_id') ?? $trace, 'novel_id' => $novelId, 'run_id' => $runId, 'actor_id' => $actor->id, 'object_type' => $kind, 'object_id' => $id, 'action' => 'download', 'outcome' => 'served', 'reason' => '管理员读取完整受控证据']);

        return $text;
    }

    private function dates($q, array $f, string $column): void
    {
        if (! empty($f['date_from'])) {
            $q->whereRaw("{$column} >= ?::date AT TIME ZONE 'UTC'", [$f['date_from']]);
        }
        if (! empty($f['date_to'])) {
            $q->whereRaw("{$column} < (?::date + interval '1 day') AT TIME ZONE 'UTC'", [$f['date_to']]);
        }
    }

    /** 对外标识用字符串，实际时间使用带时区ISO格式，避免浏览器精度及本地时区歧义。 */
    private function normalize(array $row): array
    {
        foreach ($row as $key => $value) {
            if ($value === null) {
                continue;
            }
            if ($key === 'id' || str_ends_with($key, '_id')) {
                $row[$key] = (string) $value;
            }
            if (str_ends_with($key, '_at') || $key === 'lease_until') {
                $row[$key] = $this->time($value);
            }
        }

        return $row;
    }

    private function time(?string $value): ?string
    {
        return $value === null ? null : CarbonImmutable::parse($value)->utc()->toIso8601String();
    }
}
