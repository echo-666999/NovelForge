<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        // 价格、政策与结算历史不可覆盖；预算余额由持久桶锁串行保护。
        DB::unprepared(<<<'SQL'
CREATE TABLE price_versions (
 id bigserial PRIMARY KEY, model_version_id bigint NOT NULL REFERENCES model_profile_versions(id) ON DELETE RESTRICT,
 version integer NOT NULL CHECK(version>0), currency char(3) NOT NULL,
 unit_prices jsonb NOT NULL, cache_mode varchar(20) NOT NULL CHECK(cache_mode IN ('none','included','separate')),
 reasoning_mode varchar(20) NOT NULL CHECK(reasoning_mode IN ('none','included','separate')),
 effective_at timestamptz NOT NULL, evidence_ref text NOT NULL, actor_id bigint NOT NULL REFERENCES users(id),
 created_at timestamptz NOT NULL DEFAULT clock_timestamp(), UNIQUE(model_version_id,version)
);
CREATE TABLE budget_policies (
 id bigserial PRIMARY KEY, key varchar(100) NOT NULL, version integer NOT NULL CHECK(version>0), currency char(3) NOT NULL,
 limits jsonb NOT NULL, warning_percent integer NOT NULL CHECK(warning_percent BETWEEN 1 AND 99),
 stop_percent integer NOT NULL CHECK(stop_percent BETWEEN warning_percent+1 AND 100),
 timezone varchar(100) NOT NULL, reason text NOT NULL, actor_id bigint NOT NULL REFERENCES users(id),
 created_at timestamptz NOT NULL DEFAULT clock_timestamp(), UNIQUE(key,version)
);
CREATE TABLE budget_buckets (
 id bigserial PRIMARY KEY, scope_type varchar(20) NOT NULL CHECK(scope_type IN ('chapter','run','book','day')),
 scope_key varchar(200) NOT NULL, currency char(3) NOT NULL,
 created_at timestamptz NOT NULL DEFAULT clock_timestamp(), UNIQUE(scope_type,scope_key,currency)
);
ALTER TABLE ai_requests ADD price_version_id bigint REFERENCES price_versions(id) ON DELETE RESTRICT;
CREATE TABLE budget_reservations (
 id bigserial PRIMARY KEY, request_id bigint NOT NULL UNIQUE REFERENCES ai_requests(id) ON DELETE RESTRICT,
 policy_id bigint NOT NULL REFERENCES budget_policies(id), price_version_id bigint NOT NULL REFERENCES price_versions(id),
 category varchar(30) NOT NULL CHECK(category IN ('generation','review','revision','summary','embedding','planning')),
 currency char(3) NOT NULL, amount numeric(24,10) NOT NULL CHECK(amount>=0),
 actual_cost numeric(24,10) CHECK(actual_cost>=0), status varchar(20) NOT NULL CHECK(status IN ('reserved','unknown','settled')),
 issue_code varchar(80), frozen_bounds jsonb NOT NULL, created_at timestamptz NOT NULL DEFAULT clock_timestamp(),
 CHECK((status='settled')=(actual_cost IS NOT NULL))
);
CREATE INDEX c05_reservations_status ON budget_reservations(status,created_at);
CREATE TABLE budget_allocations (
 reservation_id bigint NOT NULL REFERENCES budget_reservations(id), bucket_id bigint NOT NULL REFERENCES budget_buckets(id),
 PRIMARY KEY(reservation_id,bucket_id)
);
CREATE INDEX c05_allocations_bucket ON budget_allocations(bucket_id,reservation_id);
CREATE TABLE usage_settlements (
 id bigserial PRIMARY KEY, request_id bigint NOT NULL REFERENCES ai_requests(id), revision integer NOT NULL CHECK(revision>0),
 provider_id bigint NOT NULL REFERENCES providers(id), external_charge_id varchar(200) NOT NULL,
 usage jsonb NOT NULL, amounts jsonb NOT NULL, actual_cost numeric(24,10) NOT NULL CHECK(actual_cost>=0),
 input_hash char(64) NOT NULL, evidence_ref text NOT NULL, actor_id bigint REFERENCES users(id),
 created_at timestamptz NOT NULL DEFAULT clock_timestamp(), UNIQUE(request_id,revision)
);
CREATE TABLE billing_identities (
 provider_id bigint NOT NULL REFERENCES providers(id), external_charge_id varchar(200) NOT NULL,
 request_id bigint NOT NULL REFERENCES ai_requests(id), PRIMARY KEY(provider_id,external_charge_id)
);
CREATE TABLE cost_ledger (
 id bigserial PRIMARY KEY, request_id bigint NOT NULL REFERENCES ai_requests(id), settlement_id bigint NOT NULL REFERENCES usage_settlements(id),
 price_version_id bigint NOT NULL REFERENCES price_versions(id), category varchar(30) NOT NULL CHECK(category IN ('input','output','cache_read','cache_write','reasoning')),
 entry_kind varchar(20) NOT NULL CHECK(entry_kind IN ('charge','reversal')), tokens bigint CHECK(tokens>=0),
 amount numeric(24,10) NOT NULL, currency char(3) NOT NULL, confidence varchar(30) NOT NULL DEFAULT 'simulated',
 created_at timestamptz NOT NULL DEFAULT clock_timestamp(), UNIQUE(settlement_id,category,entry_kind),
 CHECK((entry_kind='charge' AND amount>=0) OR (entry_kind='reversal' AND amount<=0))
);
CREATE INDEX c05_ledger_request ON cost_ledger(request_id,id);
CREATE TABLE billing_commands (
 id bigserial PRIMARY KEY, actor_id bigint NOT NULL REFERENCES users(id), command_key varchar(128) NOT NULL,
 request_hash char(64) NOT NULL, response jsonb NOT NULL, created_at timestamptz NOT NULL DEFAULT clock_timestamp(), UNIQUE(actor_id,command_key)
);
CREATE OR REPLACE FUNCTION c05_reservation_guard() RETURNS trigger LANGUAGE plpgsql AS $$
BEGIN
 IF TG_OP='DELETE' THEN RAISE EXCEPTION 'C05 reservation history'; END IF;
 IF (to_jsonb(NEW)-ARRAY['actual_cost','status','issue_code']) IS DISTINCT FROM (to_jsonb(OLD)-ARRAY['actual_cost','status','issue_code']) THEN RAISE EXCEPTION 'C05 frozen reservation'; END IF;
 RETURN NEW;
END $$;
CREATE TRIGGER c05_reservation_frozen BEFORE UPDATE OR DELETE ON budget_reservations FOR EACH ROW EXECUTE FUNCTION c05_reservation_guard();
SQL);
        foreach (['price_versions', 'budget_policies', 'budget_buckets', 'budget_allocations', 'usage_settlements', 'billing_identities', 'cost_ledger', 'billing_commands'] as $table) {
            DB::statement("CREATE TRIGGER c05_immutable BEFORE UPDATE OR DELETE ON {$table} FOR EACH ROW EXECUTE FUNCTION c01_immutable()");
        }
        // 中文数据库备注覆盖表、关联键、计量规则及金额，不仅存在于源码。
        $tables = ['price_versions' => '模型不可变价格版本（每百万Token原币单价）', 'budget_policies' => '不可变多层预算政策版本', 'budget_buckets' => '跨政策共享的预算作用域身份', 'budget_reservations' => '单请求最坏费用预留及当前结算投影', 'budget_allocations' => '预留关联的多层预算桶', 'usage_settlements' => '追加式Usage核销及对账历史', 'billing_identities' => '供应商计费身份去重归属', 'cost_ledger' => '独立计费类别的追加流水及冲正', 'billing_commands' => '预算与价格命令幂等记录'];
        $cols = ['id' => '记录主键', 'model_version_id' => '冻结模型能力版本', 'version' => '递增版本号', 'currency' => '原币ISO代码，不自动换汇', 'unit_prices' => '五种独立类别每百万Token的十进制单价', 'cache_mode' => '缓存计量：无缓存、包含于输入或独立于输入', 'reasoning_mode' => '推理计量：无推理、包含于输出或独立于输出', 'effective_at' => '价格生效时间', 'evidence_ref' => '价格或对账凭证说明', 'actor_id' => '操作管理员，自动核销时可空', 'created_at' => '创建时间', 'key' => '政策稳定键', 'limits' => '请求、章、运行、书、日十进制上限', 'warning_percent' => '预警百分比', 'stop_percent' => '停止预留百分比', 'timezone' => '日桶使用的明确时区', 'reason' => '配置变更原因', 'scope_type' => '预算作用域类型', 'scope_key' => '共享作用域标识', 'request_id' => '所属网关请求', 'policy_id' => '冻结预算政策版本', 'price_version_id' => '冻结计费价格版本', 'category' => '阶段用途或独立计费类别', 'amount' => '最坏预留金额或有符号流水金额', 'actual_cost' => '当前实际金额，未知时为空', 'status' => '预留、待对账或已结算', 'issue_code' => '计量问题机器码', 'frozen_bounds' => '冻结的输入输出推理容量上界', 'reservation_id' => '所属最坏费用预留', 'bucket_id' => '被占用的预算桶', 'revision' => '追加结算修订号', 'provider_id' => '计费身份所属供应商', 'external_charge_id' => '供应商请求或账单计费标识', 'usage' => '归一化且互不重叠的计费Token数量', 'amounts' => '各独立类别核算金额', 'input_hash' => '幂等核销输入摘要', 'settlement_id' => '所属核销修订', 'entry_kind' => '新增费用或历史冲正', 'tokens' => '非负整数计费Token数量，账单未提供时为空', 'confidence' => '计费证据性质，当前仅离线模拟', 'command_key' => '管理员命令幂等键', 'request_hash' => '命令正文摘要', 'response' => '首次操作响应'];
        foreach ($tables as $table => $comment) {
            DB::statement("COMMENT ON TABLE {$table} IS '{$comment}'");
            foreach (DB::select('SELECT column_name FROM information_schema.columns WHERE table_schema=current_schema() AND table_name=?', [$table]) as $c) {
                DB::statement("COMMENT ON COLUMN {$table}.{$c->column_name} IS '".$cols[$c->column_name]."'");
            }
        }
        DB::statement("COMMENT ON COLUMN ai_requests.price_version_id IS '发出请求前冻结的价格版本，历史无预算请求可为空'");
    }

    public function down(): void
    {
        // 回滚仅供隔离测试；开发库实施只做备份后的增量迁移。
        DB::unprepared('ALTER TABLE ai_requests DROP COLUMN price_version_id; DROP TABLE billing_commands, cost_ledger, billing_identities, usage_settlements, budget_allocations, budget_reservations, budget_buckets, budget_policies, price_versions; DROP FUNCTION c05_reservation_guard();');
    }
};
