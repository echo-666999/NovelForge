<?php

namespace App\Services;

use App\Billing\TokenMeter;
use App\Exceptions\NovelProblem;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;

/** 原子预算门禁：网络调用前提交预留，网络结果与费用可信度分别保存。 */
final class BudgetService
{
    public function freeze(User $actor, string $novelId, array $p, ?array $configuration): array
    {
        app(NovelIntentService::class)->novel($actor, $novelId);
        Validator::make($p, ['policy_id' => 'required|integer|min:1|exists:budget_policies,id', 'price_version_ids' => 'required|array|min:1|max:100', 'price_version_ids.*' => 'required|integer|min:1|distinct|exists:price_versions,id', 'chapter_id' => 'nullable|integer|min:1', 'category' => 'required|in:generation,review,revision,summary,embedding,planning', 'max_output_tokens' => 'required|integer|min:0|max:1000000', 'max_reasoning_tokens' => 'required|integer|min:0|max:1000000'])->validate();
        if (array_diff(array_keys($p), ['policy_id', 'price_version_ids', 'chapter_id', 'category', 'max_output_tokens', 'max_reasoning_tokens'])) {
            throw new NovelProblem('INVALID_BILLING', '预算输入包含未知字段。', 422);
        }
        if (isset($p['chapter_id']) && ! DB::table('chapters')->where('novel_id', $novelId)->where('id', $p['chapter_id'])->exists()) {
            throw new NovelProblem('NOT_FOUND', '预算章节不属于当前小说。', 404);
        }
        $service = app(BillingConfigurationService::class);
        $policy = $service->row('budget_policies', (string) $p['policy_id']);
        $prices = [];
        foreach ($p['price_version_ids'] as $id) {
            $price = $service->row('price_versions', (string) $id);
            if ($price['currency'] !== $policy['currency']) {
                throw new NovelProblem('CURRENCY_MISMATCH', '价格与预算币种不同，不能自动换汇。');
            }
            if (DB::table('price_versions')->where('id', $id)->whereRaw('effective_at > clock_timestamp()')->exists()) {
                throw new NovelProblem('PRICE_NOT_EFFECTIVE', '价格版本尚未生效。');
            }
            if (isset($prices[$price['model_version_id']])) {
                throw new NovelProblem('INVALID_BILLING', '同一模型不能同时冻结两个价格版本。', 422);
            }
            $prices[$price['model_version_id']] = $price;
        }
        if ($configuration) {
            foreach ($configuration['models'] as $model) {
                if (! isset($prices[$model['model_version_id']])) {
                    throw new NovelProblem('PRICE_REQUIRED', '主备模型必须都有明确价格。');
                }
            }
            if ($p['max_output_tokens'] !== $configuration['parameters']['max_output_tokens']) {
                throw new NovelProblem('CONTRACT_MISMATCH', '预算输出上界须与路由参数一致。');
            }
        }

        return ['policy' => $policy, 'prices' => $prices, 'chapter_id' => isset($p['chapter_id']) ? (string) $p['chapter_id'] : null, 'category' => $p['category'], 'max_output_tokens' => $p['max_output_tokens'], 'max_reasoning_tokens' => $p['max_reasoning_tokens'], 'evidence_kind' => ($configuration['route']['content']['mode'] ?? 'offline_fake') === 'production' ? 'real_provider' : 'offline_simulation'];
    }

    /** 仅在网关创建请求的同一事务中调用；任何桶不足都回滚请求与全部预留。 */
    public function reserve(object $request, object $run, string $modelVersionId): array
    {
        $frozen = json_decode($run->frozen_config, true);
        $billing = $frozen['billing'] ?? null;
        if (! $billing || ! isset($billing['prices'][$modelVersionId])) {
            throw new NovelProblem('BUDGET_CONFIGURATION_REQUIRED', '缺少运行冻结的价格和预算，禁止发送。');
        }
        // 章节与用途读取冻结节点，不能由发起网关请求的调用者临时改写。
        $nodeScope = json_decode(DB::table('generation_steps')->where('id', $request->step_id)->value('frozen_input'), true)['node']['billing_scope'] ?? null;
        if ($nodeScope !== null) {
            $billing['category'] = $nodeScope['category'];
            $billing['chapter_id'] = $nodeScope['chapter_id'] ?? null;
        }
        $price = (object) $billing['prices'][$modelVersionId];
        $policy = $billing['policy'];
        $model = DB::table('model_profile_versions')->find($modelVersionId);
        $cap = json_decode($model->declared_capabilities, true);
        $output = $billing['max_output_tokens'];
        $reason = $price->reasoning_mode === 'separate' ? $billing['max_reasoning_tokens'] : 0;
        if (! is_int($cap['context_tokens'] ?? null) || ! is_int($cap['max_output_tokens'] ?? null) || $output > $cap['max_output_tokens'] || $cap['context_tokens'] <= $output + $reason || ($price->reasoning_mode === 'separate' && $reason === 0)) {
            throw new NovelProblem('CAPACITY_REQUIRED', '缺少可靠容量上界或输出/推理上界无效。');
        }
        $input = $cap['context_tokens'] - $output - $reason;
        $rates = $price->unit_prices;
        $meter = app(TokenMeter::class);
        $inputRate = $rates['input'];
        if ($price->cache_mode !== 'none') {
            foreach (['cache_read', 'cache_write'] as $k) {
                if ($meter->compare($rates[$k], $inputRate) > 0) {
                    $inputRate = $rates[$k];
                }
            }
        }
        // 缓存子类按总输入上界的最高单价预留，不能假定命中缓存后便宜。
        $amount = $meter->total([$price->cache_mode === 'none' ? '0' : '0.0000000002', $meter->cost($input, $inputRate), $meter->cost($output, $rates['output']), $meter->cost($reason, $rates['reasoning'])]);
        if ($meter->compare($amount, $meter->percent($policy['limits']['request'], $policy['stop_percent'])) > 0) {
            throw new NovelProblem('DEFERRED_BUDGET', '单请求最坏费用超过冻结上限。');
        }
        $day = DB::selectOne("SELECT to_char(clock_timestamp() AT TIME ZONE 'UTC','YYYY-MM-DD') AS day")->day;
        $scopes = ['book' => (string) $request->novel_id, 'chapter' => $request->novel_id.':'.($billing['chapter_id'] ?? 'unassigned'), 'day' => $run->actor_id.':'.$day, 'run' => (string) $request->run_id];
        $buckets = [];
        $warnings = [];
        foreach ($scopes as $scope => $key) {
            DB::table('budget_buckets')->insertOrIgnore(['scope_type' => $scope, 'scope_key' => $key, 'currency' => $policy['currency']]);
            $bucket = DB::table('budget_buckets')->where(['scope_type' => $scope, 'scope_key' => $key, 'currency' => $policy['currency']])->lockForUpdate()->first();
            if (DB::table('budget_allocations as a')->join('budget_reservations as r', 'r.id', '=', 'a.reservation_id')->where('a.bucket_id', $bucket->id)->where('r.issue_code', 'ACTUAL_EXCEEDS_RESERVATION')->exists()) {
                throw new NovelProblem('BUDGET_RECONCILIATION_REQUIRED', '已有实际费用超过预留，停止新请求并等待对账。');
            }
            $spent = $this->exposure((string) $bucket->id);
            $next = $meter->total([$spent, $amount]);
            if ($meter->compare($next, $meter->percent($policy['limits'][$scope], $policy['stop_percent'])) > 0 || $meter->compare($spent, $meter->percent($policy['limits'][$scope], $policy['stop_percent'])) >= 0) {
                throw new NovelProblem('DEFERRED_BUDGET', '预算余额不足或已达到停止线，未发出请求。');
            }
            if ($meter->compare($next, $meter->percent($policy['limits'][$scope], $policy['warning_percent'])) >= 0) {
                $warnings[] = $scope;
            }
            $buckets[] = $bucket->id;
        }
        $bounds = ['input_tokens' => $input, 'output_tokens' => $output, 'reasoning_tokens' => $reason, 'method' => 'declared_context_upper_bound_v1', 'warning_scopes' => $warnings, 'day_utc' => $day];
        $id = DB::table('budget_reservations')->insertGetId(['request_id' => $request->id, 'policy_id' => $policy['id'], 'price_version_id' => $price->id, 'category' => $billing['category'], 'currency' => $price->currency, 'amount' => $amount, 'status' => 'reserved', 'frozen_bounds' => NovelIntentService::canonical($bounds)]);
        foreach ($buckets as $bucket) {
            DB::table('budget_allocations')->insert(['reservation_id' => $id, 'bucket_id' => $bucket]);
        }

        return ['price_version_id' => $price->id, 'max_output_tokens' => $output, 'max_reasoning_tokens' => $reason];
    }

    private function exposure(string $bucketId): string
    {
        return DB::table('budget_allocations as a')->join('budget_reservations as r', 'r.id', '=', 'a.reservation_id')->where('a.bucket_id', $bucketId)->selectRaw('coalesce(sum(coalesce(r.actual_cost,r.amount)),0)::numeric(24,10)::text AS total')->first()->total;
    }

    private function lockBuckets(string $reservationId): void
    {
        DB::table('budget_buckets')->whereIn('id', DB::table('budget_allocations')->where('reservation_id', $reservationId)->select('bucket_id'))->orderBy('scope_type')->orderBy('scope_key')->lockForUpdate()->get();
    }

    /** 缺Usage、冲突身份及无效Token保留原最坏预留，不根据HTTP失败推定免费。 */
    public function settle(string $requestId, ?array $raw, ?string $externalId, string $evidence, ?User $actor = null, bool $correction = false, ?array $invoiceAmounts = null): array
    {
        return DB::transaction(function () use ($requestId, $raw, $externalId, $evidence, $actor, $correction, $invoiceAmounts) {
            $request = DB::table('ai_requests')->find($requestId);
            if (! $request) {
                throw new NovelProblem('NOT_FOUND', '请求不存在。', 404);
            }
            // 与发起路径一致，先锁小说/运行，再锁预留及按固定顺序锁预算桶。
            DB::table('novels')->where('id', $request->novel_id)->lockForUpdate()->first();
            DB::table('generation_runs')->where('id', $request->run_id)->lockForUpdate()->first();
            $r = DB::table('budget_reservations')->where('request_id', $requestId)->lockForUpdate()->first();
            if (! $r) {
                throw new NovelProblem('BUDGET_CONFIGURATION_REQUIRED', '历史请求没有可核销预留。');
            }
            $this->lockBuckets((string) $r->id);
            $price = DB::table('price_versions')->find($r->price_version_id);
            $meter = app(TokenMeter::class);
            $old = DB::table('usage_settlements')->where('request_id', $requestId)->orderByDesc('revision')->first();
            try {
                // 账单可确认金额而不提供Token；未知计量保留null，不能编造零Token。
                $tokens = $raw === null && $invoiceAmounts !== null ? array_fill_keys(TokenMeter::CATEGORIES, null) : $meter->normalize($raw, $price);
                if (! $externalId || strlen($externalId) > 200) {
                    throw new NovelProblem('CHARGE_ID_MISSING', '缺少可信计费身份，等待凭证对账。');
                }
                $hash = hash('sha256', NovelIntentService::canonical([$externalId, $tokens, $invoiceAmounts]));
                if ($old && hash_equals($old->input_hash, $hash)) {
                    return $this->read($requestId);
                }
                if ($old && ! $correction) {
                    throw new NovelProblem('USAGE_CONFLICT', '已有不同核销，必须通过有凭证的对账修订。');
                }
                DB::table('billing_identities')->insertOrIgnore(['provider_id' => $request->provider_id, 'external_charge_id' => $externalId, 'request_id' => $requestId]);
                $identity = DB::table('billing_identities')->where(['provider_id' => $request->provider_id, 'external_charge_id' => $externalId])->first();
                if ((string) $identity->request_id !== $requestId) {
                    throw new NovelProblem('DUPLICATE_CHARGE_ID', '计费身份已属于其他请求，保留风险预留待对账。');
                }
                if ($invoiceAmounts !== null) {
                    if (array_diff(array_keys($invoiceAmounts), TokenMeter::CATEGORIES) || array_diff(TokenMeter::CATEGORIES, array_keys($invoiceAmounts))) {
                        throw new NovelProblem('INVALID_MONEY', '账单金额必须覆盖全部独立类别。', 422);
                    }
                    $amounts = array_map(fn ($v) => $meter->decimal($v), $invoiceAmounts);
                } else {
                    $amounts = $meter->amounts($tokens, $price);
                }
                $actual = $meter->total($amounts);
            } catch (NovelProblem $e) {
                if ($correction || $old) {
                    throw $e;
                }
                DB::table('budget_reservations')->where('id', $r->id)->update(['status' => 'unknown', 'issue_code' => $e->errorCode]);

                return $this->read($requestId);
            }
            $settlement = DB::table('usage_settlements')->insertGetId(['request_id' => $requestId, 'revision' => ($old?->revision ?? 0) + 1, 'provider_id' => $request->provider_id, 'external_charge_id' => $externalId, 'usage' => NovelIntentService::canonical($tokens), 'amounts' => NovelIntentService::canonical($amounts), 'actual_cost' => $actual, 'input_hash' => $hash, 'evidence_ref' => $evidence, 'actor_id' => $actor?->id]);
            // 对账更正追加反向流水，旧核销及其证据永久保留。
            if ($old) {
                $oldTokens = json_decode($old->usage, true);
                $oldAmounts = json_decode($old->amounts, true);
                foreach (TokenMeter::CATEGORIES as $category) {
                    DB::table('cost_ledger')->insert(['request_id' => $requestId, 'settlement_id' => $settlement, 'price_version_id' => $r->price_version_id, 'category' => $category, 'entry_kind' => 'reversal', 'tokens' => $oldTokens[$category], 'amount' => '-'.$oldAmounts[$category], 'currency' => $r->currency]);
                }
            }
            foreach (TokenMeter::CATEGORIES as $category) {
                DB::table('cost_ledger')->insert(['request_id' => $requestId, 'settlement_id' => $settlement, 'price_version_id' => $r->price_version_id, 'category' => $category, 'entry_kind' => 'charge', 'tokens' => $tokens[$category], 'amount' => $amounts[$category], 'currency' => $r->currency]);
            }
            DB::table('budget_reservations')->where('id', $r->id)->update(['status' => 'settled', 'actual_cost' => $actual, 'issue_code' => $meter->compare($actual, $r->amount) > 0 ? 'ACTUAL_EXCEEDS_RESERVATION' : null]);

            return $this->read($requestId);
        });
    }

    public function reconcile(User $actor, array $p): array
    {
        Validator::make($p, ['request_id' => 'required|integer|min:1', 'usage' => 'nullable|array', 'amounts' => 'nullable|array', 'external_charge_id' => 'required|string|max:200', 'evidence_ref' => 'required|string|min:10|max:2000'])->validate();
        if (array_diff(array_keys($p), ['request_id', 'usage', 'amounts', 'external_charge_id', 'evidence_ref'])) {
            throw new NovelProblem('INVALID_BILLING', '对账输入存在未知字段。', 422);
        }
        $request = DB::table('ai_requests')->find($p['request_id']);
        if (! $request) {
            throw new NovelProblem('NOT_FOUND', '请求不存在。', 404);
        }
        app(NovelIntentService::class)->novel($actor, (string) $request->novel_id);
        if ($request->status === 'pending') {
            throw new NovelProblem('REQUEST_IN_FLIGHT', '在途请求不能人工结算。');
        }

        return $this->settle((string) $request->id, $p['usage'] ?? null, $p['external_charge_id'], $p['evidence_ref'], $actor, true, $p['amounts'] ?? null);
    }

    /** 安全费用投影；未核销的实际金额保持null，并单列风险预留。 */
    public function read(string $requestId): ?array
    {
        $r = DB::table('budget_reservations')->where('request_id', $requestId)->first();
        if (! $r) {
            return null;
        }
        $row = (array) $r;
        foreach (['id', 'request_id', 'policy_id', 'price_version_id'] as $key) {
            $row[$key] = (string) $row[$key];
        }
        $row['frozen_bounds'] = json_decode($r->frozen_bounds, true);
        $row['risk_reserved'] = $r->status === 'settled' ? '0.0000000000' : $r->amount;
        $row['evidence_kind'] = DB::table('ai_requests')->where('id', $requestId)->value('evidence_kind');
        $policy = DB::table('budget_policies')->find($r->policy_id);
        $limits = json_decode($policy->limits, true);
        $meter = app(TokenMeter::class);
        $row['buckets'] = DB::table('budget_buckets')->whereIn('id', DB::table('budget_allocations')->where('reservation_id', $r->id)->select('bucket_id'))->orderBy('scope_type')->get()->map(function ($b) use ($policy, $limits, $meter) {
            $used = $this->exposure((string) $b->id);
            $limit = $meter->percent($limits[$b->scope_type], $policy->stop_percent);

            return ['scope' => $b->scope_type, 'key' => $b->scope_key, 'exposure' => $used, 'stop_limit' => $limit, 'warning_percent' => $policy->warning_percent, 'stop_percent' => $policy->stop_percent, 'state' => $meter->compare($used, $limit) >= 0 ? 'exhausted' : ($meter->compare($used, $meter->percent($limits[$b->scope_type], $policy->warning_percent)) >= 0 ? 'warning' : 'normal')];
        })->all();
        $row['ledger'] = DB::table('cost_ledger')->where('request_id', $requestId)->orderBy('id')->get()->map(function ($v) {
            $a = (array) $v;
            foreach (['id', 'request_id', 'settlement_id', 'price_version_id', 'tokens'] as $key) {
                $a[$key] = $a[$key] === null ? null : (string) $a[$key];
            }

            return $a;
        })->all();
        $row['settlements'] = DB::table('usage_settlements')->where('request_id', $requestId)->orderBy('revision')->get()->map(function ($v) {
            $a = (array) $v;
            foreach (['id', 'request_id', 'provider_id', 'actor_id'] as $key) {
                $a[$key] = $a[$key] === null ? null : (string) $a[$key];
            }foreach (['usage', 'amounts'] as $key) {
                $a[$key] = json_decode($a[$key], true);
            }

            return $a;
        })->all();

        return $row;
    }
}
