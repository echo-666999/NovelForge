<?php

namespace App\Services;

use App\Billing\TokenMeter;
use App\Exceptions\NovelProblem;
use App\Models\User;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;

/** 价格与预算只创建有凭证的新版本，不猜测真实单价或替用户批准预算。 */
final class BillingConfigurationService
{
    public function execute(User $actor, string $action, array $p, string $key): array
    {
        app(NovelIntentService::class)->authorize($actor);
        Validator::make(['key' => $key, 'action' => $action], ['key' => ['required', 'string', 'max:128', 'regex:/^[\x20-\x7E]+$/D'], 'action' => 'required|in:price,policy,reconcile'])->validate();
        if (strlen(NovelIntentService::canonical($p)) > 50000) {
            throw new NovelProblem('INVALID_BILLING', '输入过大。', 422);
        }

        return DB::transaction(function () use ($actor, $action, $p, $key) {
            if ($action !== 'reconcile') {
                DB::select("SELECT pg_advisory_xact_lock(hashtext('c05-configuration'))");
            } else {
                DB::select('SELECT pg_advisory_xact_lock(hashtextextended(?,0))', ['c05-command:'.$actor->id.':'.$key]);
            }
            $hash = hash('sha256', NovelIntentService::canonical([$action, $p]));
            $old = DB::table('billing_commands')->where(['actor_id' => $actor->id, 'command_key' => $key])->first();
            if ($old) {
                if (! hash_equals($hash, $old->request_hash)) {
                    throw new NovelProblem('IDEMPOTENCY_CONFLICT', '幂等键用于不同计费输入。');
                }

                return json_decode($old->response, true);
            }
            $response = match ($action) {
                'price' => $this->price($actor, $p), 'policy' => $this->policy($actor, $p),
                'reconcile' => app(BudgetService::class)->reconcile($actor, $p),
            };
            DB::table('billing_commands')->insert(['actor_id' => $actor->id, 'command_key' => $key, 'request_hash' => $hash, 'response' => NovelIntentService::canonical($response)]);

            return $response;
        });
    }

    private function price(User $actor, array $p): array
    {
        $rules = ['model_version_id' => 'required|integer|min:1|exists:model_profile_versions,id', 'expected_version' => 'required|integer|min:0', 'currency' => 'required|string|regex:/^[A-Z]{3}$/D', 'unit_prices' => 'required|array:input,output,cache_read,cache_write,reasoning', 'cache_mode' => 'required|in:none,included,separate', 'reasoning_mode' => 'required|in:none,included,separate', 'effective_at' => 'required|date_format:Y-m-d\TH:i:sP', 'evidence_ref' => 'required|string|min:10|max:2000'];
        Validator::make($p, $rules)->validate();
        if (array_diff(array_keys($p), array_keys($rules))) {
            throw new NovelProblem('INVALID_BILLING', '价格存在未知字段。', 422);
        }
        $meter = app(TokenMeter::class);
        $rates = [];
        foreach (TokenMeter::CATEGORIES as $category) {
            $rates[$category] = $meter->decimal($p['unit_prices'][$category] ?? null);
            if ($meter->compare($rates[$category], '1000000') > 0) {
                throw new NovelProblem('INVALID_MONEY', '单价超出本版支持范围。', 422);
            }
        }
        if ((int) DB::table('price_versions')->where('model_version_id', $p['model_version_id'])->max('version') !== (int) $p['expected_version']) {
            throw new NovelProblem('STALE_VERSION', '价格版本已变化。');
        }
        $id = DB::table('price_versions')->insertGetId(['model_version_id' => $p['model_version_id'], 'version' => $p['expected_version'] + 1, 'currency' => $p['currency'], 'unit_prices' => NovelIntentService::canonical($rates), 'cache_mode' => $p['cache_mode'], 'reasoning_mode' => $p['reasoning_mode'], 'effective_at' => $p['effective_at'], 'evidence_ref' => $p['evidence_ref'], 'actor_id' => $actor->id]);

        return $this->row('price_versions', (string) $id);
    }

    private function policy(User $actor, array $p): array
    {
        $rules = ['key' => 'required|string|regex:/^[a-z][a-z0-9_.-]{0,99}$/D', 'expected_version' => 'required|integer|min:0', 'currency' => 'required|string|regex:/^[A-Z]{3}$/D', 'limits' => 'required|array:request,chapter,run,book,day', 'warning_percent' => 'required|integer|min:1|max:99', 'stop_percent' => 'required|integer|min:2|max:100|gt:warning_percent', 'timezone' => 'required|in:UTC', 'reason' => 'required|string|min:5|max:2000'];
        Validator::make($p, $rules)->validate();
        if (array_diff(array_keys($p), array_keys($rules))) {
            throw new NovelProblem('INVALID_BILLING', '预算存在未知字段。', 422);
        }
        $limits = [];
        foreach (['request', 'chapter', 'run', 'book', 'day'] as $scope) {
            $limits[$scope] = app(TokenMeter::class)->decimal($p['limits'][$scope] ?? null);
        }
        // 同一政策键固定币种与时区，不能通过改时区获得同一天的第二份余额。
        $old = DB::table('budget_policies')->where('key', $p['key'])->orderByDesc('version')->first();
        if (($old?->version ?? 0) !== (int) $p['expected_version']) {
            throw new NovelProblem('STALE_VERSION', '预算版本已变化。');
        }
        if ($old && ($old->currency !== $p['currency'] || $old->timezone !== $p['timezone'])) {
            throw new NovelProblem('IMMUTABLE_IDENTITY', '政策币种和时区不可原地修改。');
        }
        $id = DB::table('budget_policies')->insertGetId(['key' => $p['key'], 'version' => $p['expected_version'] + 1, 'currency' => $p['currency'], 'limits' => NovelIntentService::canonical($limits), 'warning_percent' => $p['warning_percent'], 'stop_percent' => $p['stop_percent'], 'timezone' => $p['timezone'], 'reason' => $p['reason'], 'actor_id' => $actor->id]);

        return $this->row('budget_policies', (string) $id);
    }

    public function row(string $table, string $id): array
    {
        $v = DB::table($table)->find($id);
        if (! $v) {
            throw new NovelProblem('NOT_FOUND', '计费版本不存在。', 404);
        }
        $data = (array) $v;
        foreach ($data as $key => $value) {
            if ($key === 'id' || str_ends_with($key, '_id')) {
                $data[$key] = $value === null ? null : (string) $value;
            }
            if (in_array($key, ['unit_prices', 'limits'], true)) {
                $data[$key] = json_decode($value, true);
            }
        }

        return $data;
    }

    public function listing(User $actor, array $filters = []): array
    {
        app(NovelIntentService::class)->authorize($actor);
        Validator::make($filters, ['novel_id' => 'nullable|integer|min:1', 'run_id' => 'nullable|integer|min:1', 'model_profile_version_id' => 'nullable|integer|min:1', 'request_status' => 'nullable|in:pending,succeeded,failed,unknown,quarantined', 'date_from' => 'nullable|date_format:Y-m-d', 'date_to' => 'nullable|date_format:Y-m-d', 'status' => 'nullable|in:reserved,unknown,settled', 'currency' => 'nullable|string|size:3', 'category' => 'nullable|in:generation,review,revision,summary,embedding,planning', 'page' => 'nullable|integer|min:1|max:100000'])->validate();
        $q = $this->query($actor, $filters);
        $totals = (clone $q)->selectRaw("b.currency, count(*) AS requests, count(*) FILTER(WHERE b.status<>'settled') AS unknown_count, coalesce(sum(b.actual_cost),0)::text AS settled_cost, coalesce(sum(b.amount) FILTER(WHERE b.status<>'settled'),0)::text AS risk_reserved")->groupBy('b.currency')->get()->map(fn ($v) => (array) $v)->all();
        $total = (clone $q)->count();
        $page = (int) ($filters['page'] ?? 1);
        $requests = $q->orderByDesc('b.id')->forPage($page, 25)->pluck('b.request_id')->map(fn ($id) => app(BudgetService::class)->read((string) $id))->all();

        return ['requests' => $requests, 'total' => $total, 'page' => $page, 'totals' => $totals, 'prices' => DB::table('price_versions')->orderByDesc('id')->limit(100)->pluck('id')->map(fn ($id) => $this->row('price_versions', (string) $id))->all(), 'policies' => DB::table('budget_policies')->orderByDesc('id')->limit(100)->pluck('id')->map(fn ($id) => $this->row('budget_policies', (string) $id))->all(), 'catalog_limit' => 100, 'evidence_kind' => 'offline_simulation'];
    }

    /** 导出当前筛选的请求费用摘要；行级未知金额为空，不混成零费用。 */
    public function export(User $actor, array $filters = []): string
    {
        $initial = $this->listing($actor, $filters);
        if ($initial['total'] > 10000) {
            throw new NovelProblem('EXPORT_TOO_LARGE', '请缩小筛选范围，单次最多导出一万笔请求。', 422);
        }
        // 先固定本次导出的请求ID，后续新增请求不会混入结果。
        $q = $this->query($actor, $filters);
        $rows = $q->orderBy('b.id')->limit(10001)->select('b.request_id', 'b.category', 'b.currency', 'b.price_version_id', 'b.policy_id', 'b.status', 'b.amount', 'b.actual_cost', 'b.issue_code')->get();
        if ($rows->count() > 10000) {
            throw new NovelProblem('EXPORT_TOO_LARGE', '导出范围变化，请缩小筛选范围。', 422);
        }
        $stream = fopen('php://temp', 'w+');
        fwrite($stream, "\xEF\xBB\xBF");
        fputcsv($stream, ['请求', '用途', '币种', '价格版本', '政策版本', '状态', '最坏预留', '实际费用（空为未知）', '问题码'], ',', '"', '');
        foreach ($rows as $row) {
            fputcsv($stream, array_values((array) $row), ',', '"', '');
        }
        rewind($stream);
        $csv = stream_get_contents($stream);
        fclose($stream);

        return $csv;
    }

    /** 列表与导出共享筛选规则，日期按UTC自然日包含末日。 */
    private function query(User $actor, array $filters): Builder
    {
        $q = DB::table('budget_reservations as b')->join('ai_requests as r', 'r.id', '=', 'b.request_id')->join('novels as n', 'n.id', '=', 'r.novel_id')->where('n.owner_id', $actor->id);
        foreach (['status', 'currency', 'category'] as $key) {
            if (! empty($filters[$key])) {
                $q->where('b.'.$key, $filters[$key]);
            }
        }
        foreach (['novel_id', 'run_id', 'model_profile_version_id'] as $key) {
            if (! empty($filters[$key])) {
                $q->where('r.'.$key, $filters[$key]);
            }
        }
        if (! empty($filters['request_status'])) {
            $q->where('r.status', $filters['request_status']);
        }
        if (! empty($filters['date_from'])) {
            $q->whereRaw("r.created_at >= ?::date AT TIME ZONE 'UTC'", [$filters['date_from']]);
        }
        if (! empty($filters['date_to'])) {
            $q->whereRaw("r.created_at < (?::date + interval '1 day') AT TIME ZONE 'UTC'", [$filters['date_to']]);
        }

        return $q;
    }
}
