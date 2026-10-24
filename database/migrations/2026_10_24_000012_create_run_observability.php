<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    // 只采集已经实现的模块，禁止将密钥、正文或原始请求复制进审计摘要。
    private array $sources = ['generation_runs', 'generation_steps', 'generation_artifacts', 'ai_requests', 'recovery_decisions', 'recovery_outputs', 'generation_late_evidence', 'usage_settlements', 'budget_reservations', 'cost_ledger', 'provider_versions', 'model_profile_versions', 'prompt_versions', 'route_versions', 'style_versions', 'price_versions', 'budget_policies'];

    public function up(): void
    {
        DB::unprepared(<<<'SQL'
CREATE TABLE run_traces (
 run_id bigint PRIMARY KEY REFERENCES generation_runs(id), trace_id uuid NOT NULL UNIQUE,
 origin varchar(30) NOT NULL CHECK(origin IN ('live','historical_backfill')),
 created_at timestamptz NOT NULL DEFAULT clock_timestamp()
);
INSERT INTO run_traces(run_id,trace_id,origin) SELECT id,gen_random_uuid(),'historical_backfill' FROM generation_runs;
CREATE TRIGGER c07_trace_immutable BEFORE UPDATE OR DELETE ON run_traces FOR EACH ROW EXECUTE FUNCTION c01_immutable();
CREATE TABLE audit_events (
 id bigserial PRIMARY KEY, trace_id uuid NOT NULL, operation_trace_id uuid NOT NULL,
 novel_id bigint REFERENCES novels(id), run_id bigint REFERENCES generation_runs(id), actor_id bigint REFERENCES users(id),
 object_type varchar(80) NOT NULL, object_id varchar(100) NOT NULL, action varchar(100) NOT NULL,
 outcome varchar(80) NOT NULL, high_risk boolean NOT NULL DEFAULT false, reason text,
 before_state jsonb NOT NULL DEFAULT '{}', after_state jsonb NOT NULL DEFAULT '{}',
 created_at timestamptz NOT NULL DEFAULT clock_timestamp(),
 FOREIGN KEY(novel_id,run_id) REFERENCES generation_runs(novel_id,id)
);
CREATE INDEX c07_audit_trace ON audit_events(trace_id,id);
CREATE INDEX c07_audit_operation ON audit_events(operation_trace_id,id);
CREATE INDEX c07_audit_run ON audit_events(run_id,id);
CREATE INDEX c07_audit_filter ON audit_events(created_at,id);
CREATE TRIGGER c07_audit_immutable BEFORE UPDATE OR DELETE ON audit_events FOR EACH ROW EXECUTE FUNCTION c01_immutable();
CREATE OR REPLACE FUNCTION c07_audit_capture() RETURNS trigger LANGUAGE plpgsql AS $$
DECLARE n jsonb := to_jsonb(NEW); o jsonb := '{}'; b jsonb; a jsonb;
 rid bigint; nid bigint; aid bigint; root uuid; op uuid;
BEGIN
 IF TG_OP='UPDATE' THEN o := to_jsonb(OLD); END IF;
 SELECT coalesce(jsonb_object_agg(key,value),'{}') INTO a FROM jsonb_each(n) WHERE key IN ('id','status','error_code','version','row_version','expected_state_version','input_hash','content_hash','dependency_hash','fencing_token','checkpoint_artifact_id','resume_artifact_id','model_profile_version_id','price_version_id','policy_id','revision','amount','actual_cost','currency','action','source_artifact_id','enabled','parent_id','issue_code','category','provider_id','model_profile_id','route_version_id','prompt_version_id','style_version_id','configuration_hash');
 SELECT coalesce(jsonb_object_agg(key,value),'{}') INTO b FROM jsonb_each(o) WHERE key IN ('id','status','error_code','version','row_version','expected_state_version','input_hash','content_hash','dependency_hash','fencing_token','checkpoint_artifact_id','resume_artifact_id','model_profile_version_id','price_version_id','policy_id','revision','amount','actual_cost','currency','action','source_artifact_id','enabled','parent_id','issue_code','category','provider_id','model_profile_id','route_version_id','prompt_version_id','style_version_id','configuration_hash');
 IF TG_OP='UPDATE' AND a=b THEN RETURN NEW; END IF;
 op := coalesce(nullif(current_setting('novelforge.trace_id',true),'')::uuid,gen_random_uuid());
 rid := (n->>'run_id')::bigint; nid := (n->>'novel_id')::bigint; aid := (n->>'actor_id')::bigint;
 IF TG_TABLE_NAME='generation_runs' THEN
  rid := NEW.id;
  INSERT INTO run_traces(run_id,trace_id,origin) VALUES(rid,op,'live') ON CONFLICT DO NOTHING;
 END IF;
 IF rid IS NULL AND n ? 'request_id' THEN SELECT run_id,novel_id INTO rid,nid FROM ai_requests WHERE id=(n->>'request_id')::bigint; END IF;
 IF rid IS NOT NULL THEN
  SELECT t.trace_id,r.novel_id,coalesce(aid,r.actor_id) INTO root,nid,aid FROM run_traces t JOIN generation_runs r ON r.id=t.run_id WHERE t.run_id=rid;
 END IF;
 INSERT INTO audit_events(trace_id,operation_trace_id,novel_id,run_id,actor_id,object_type,object_id,action,outcome,high_risk,reason,before_state,after_state)
 VALUES(coalesce(root,op),op,nid,rid,aid,TG_TABLE_NAME,n->>'id',lower(TG_OP),coalesce(n->>'status','recorded'),TG_TABLE_NAME IN ('recovery_decisions','provider_versions','model_profile_versions','prompt_versions','route_versions','style_versions','price_versions','budget_policies','usage_settlements'),coalesce(nullif(current_setting('novelforge.reason',true),''),n->>'reason','系统记录：'||TG_TABLE_NAME),b,a);
 RETURN NEW;
END $$;
SQL);
        foreach ($this->sources as $table) {
            DB::statement("CREATE TRIGGER c07_capture AFTER INSERT OR UPDATE ON {$table} FOR EACH ROW EXECUTE FUNCTION c07_audit_capture()");
        }
        $tables = ['run_traces' => '运行根追踪标识及历史补建来源', 'audit_events' => '只追加且不含正文密钥的操作审计'];
        $columns = ['run_id' => '所属持久运行', 'trace_id' => '贯穿运行及产物的根追踪标识', 'origin' => '实时建立或历史补建', 'created_at' => '数据库记录时间', 'id' => '审计事件标识', 'operation_trace_id' => '本次HTTP或后台操作追踪标识', 'novel_id' => '所属小说，配置操作可空', 'actor_id' => '操作人或自动运行发起者', 'object_type' => '受控对象类型', 'object_id' => '对象标识', 'action' => '操作类型', 'outcome' => '结果或状态', 'high_risk' => '是否高风险操作', 'reason' => '操作原因或系统事件说明', 'before_state' => '白名单字段变更前快照', 'after_state' => '白名单字段变更后快照'];
        foreach ($tables as $table => $comment) {
            DB::statement("COMMENT ON TABLE {$table} IS '{$comment}'");
            foreach (DB::select('SELECT column_name FROM information_schema.columns WHERE table_schema=current_schema() AND table_name=?', [$table]) as $column) {
                DB::statement("COMMENT ON COLUMN {$table}.{$column->column_name} IS '{$columns[$column->column_name]}'");
            }
        }
    }

    public function down(): void
    {
        // 先撤销采集触发器，再移除审计表；仅用于隔离空库回滚验收。
        foreach ($this->sources as $table) {
            DB::statement("DROP TRIGGER c07_capture ON {$table}");
        }
        DB::unprepared('DROP FUNCTION c07_audit_capture(); DROP TABLE audit_events,run_traces;');
    }
};
