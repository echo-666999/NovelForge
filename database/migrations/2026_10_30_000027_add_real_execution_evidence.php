<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        // 历史离线行保持原值；真实来源只能追加并绑定已有网关证据。
        DB::unprepared(<<<'SQL'
ALTER TABLE ai_requests ADD COLUMN evidence_kind varchar(30) NOT NULL DEFAULT 'offline_simulation'
 CHECK(evidence_kind IN ('offline_simulation','real_provider'));
ALTER TABLE ai_requests DROP CONSTRAINT ai_requests_cost_status_check;
ALTER TABLE ai_requests ADD CONSTRAINT ai_requests_cost_status_check CHECK(
 (evidence_kind='offline_simulation' AND cost_status='not_billable_fake') OR
 (evidence_kind='real_provider' AND cost_status='real_billable'));
ALTER TABLE reviews DROP CONSTRAINT reviews_reviewer_type_check;
ALTER TABLE reviews ADD CONSTRAINT reviews_reviewer_type_check CHECK(reviewer_type IN ('offline','human','model'));
ALTER TABLE reviews ADD COLUMN source_request_id bigint REFERENCES ai_requests(id);
ALTER TABLE reviews ADD CONSTRAINT f01_review_request CHECK((reviewer_type='model')=(source_request_id IS NOT NULL));
ALTER TABLE revision_tasks DROP CONSTRAINT revision_tasks_mode_check;
ALTER TABLE revision_tasks ADD CONSTRAINT revision_tasks_mode_check CHECK(mode IN ('offline','manual','production'));
ALTER TABLE revision_proposals ADD COLUMN source_request_id bigint REFERENCES ai_requests(id);
CREATE TABLE model_capability_evidence (
 id bigserial PRIMARY KEY,
 model_profile_version_id bigint NOT NULL REFERENCES model_profile_versions(id),
 provider_version_id bigint NOT NULL REFERENCES provider_versions(id),
 request_id bigint NOT NULL UNIQUE REFERENCES ai_requests(id),
 response_evidence_id bigint NOT NULL REFERENCES ai_request_evidence(id),
 response_hash char(64) NOT NULL,
 observed_model varchar(200) NOT NULL,
 observations jsonb NOT NULL CHECK(jsonb_typeof(observations)='object'),
 actor_id bigint NOT NULL REFERENCES users(id),
 created_at timestamptz NOT NULL DEFAULT clock_timestamp()
);
CREATE INDEX f01_capability_lookup ON model_capability_evidence(model_profile_version_id,provider_version_id,id DESC);
CREATE TRIGGER f01_capability_immutable BEFORE UPDATE OR DELETE ON model_capability_evidence FOR EACH ROW EXECUTE FUNCTION c01_immutable();

CREATE OR REPLACE FUNCTION f01_real_evidence_scope() RETURNS trigger LANGUAGE plpgsql AS $$
BEGIN
 IF TG_TABLE_NAME='ai_requests' THEN
  IF NEW.evidence_kind='real_provider' AND NOT EXISTS(
   SELECT 1 FROM provider_versions p JOIN generation_runs r ON r.id=NEW.run_id
   WHERE p.id=NEW.provider_version_id AND p.interface_type='json_http'
   AND r.frozen_config->>'execution_mode'='production'
  ) THEN RAISE EXCEPTION 'F01 real request origin mismatch'; END IF;
 ELSIF TG_TABLE_NAME='model_capability_evidence' THEN
  IF NOT EXISTS(
   SELECT 1 FROM ai_requests q JOIN ai_request_evidence e ON e.request_id=q.id
   WHERE q.id=NEW.request_id AND q.model_profile_version_id=NEW.model_profile_version_id
   AND q.provider_version_id=NEW.provider_version_id AND q.status='succeeded'
   AND q.evidence_kind='real_provider' AND e.id=NEW.response_evidence_id
   AND e.kind='response' AND e.content_hash=NEW.response_hash
  ) THEN RAISE EXCEPTION 'F01 capability requires real response'; END IF;
 ELSE
  IF NEW.source_request_id IS NOT NULL AND NOT EXISTS(
   SELECT 1 FROM ai_requests q JOIN generation_artifacts a ON a.step_id=q.step_id
   JOIN generation_runs r ON r.id=q.run_id
   WHERE q.id=NEW.source_request_id AND q.novel_id=NEW.novel_id AND q.run_id=NEW.run_id
   AND a.id=NEW.artifact_id AND a.run_id=NEW.run_id
   AND q.status='succeeded' AND q.evidence_kind='real_provider'
   AND r.frozen_config->>'execution_mode'='production'
   AND EXISTS(SELECT 1 FROM ai_request_evidence e WHERE e.request_id=q.id AND e.kind='response')
  ) THEN RAISE EXCEPTION 'F01 result request mismatch'; END IF;
  IF TG_TABLE_NAME='revision_proposals' THEN
   IF EXISTS(
   SELECT 1 FROM revision_tasks t WHERE t.id=NEW.task_id
   AND ((t.mode='production') IS DISTINCT FROM (NEW.source_request_id IS NOT NULL))
  ) THEN RAISE EXCEPTION 'F01 revision mode mismatch'; END IF;
  END IF;
 END IF;
 RETURN NEW;
END $$;
CREATE TRIGGER f01_request_scope BEFORE INSERT ON ai_requests FOR EACH ROW EXECUTE FUNCTION f01_real_evidence_scope();
CREATE TRIGGER f01_capability_scope BEFORE INSERT ON model_capability_evidence FOR EACH ROW EXECUTE FUNCTION f01_real_evidence_scope();
CREATE TRIGGER f01_review_scope BEFORE INSERT ON reviews FOR EACH ROW EXECUTE FUNCTION f01_real_evidence_scope();
CREATE TRIGGER f01_revision_scope BEFORE INSERT ON revision_proposals FOR EACH ROW EXECUTE FUNCTION f01_real_evidence_scope();
SQL);
        // 保留原有正文、产物和上下文校验，仅扩展来源类型与冻结模式的对应关系。
        $definition = DB::selectOne("SELECT pg_get_functiondef('e04_scope()'::regprocedure) AS definition")->definition;
        $old = "NEW.reviewer_type='offline'";
        if (substr_count($definition, $old) !== 1) {
            throw new RuntimeException('评审来源函数与预期不符，停止迁移。');
        }
        DB::unprepared(str_replace($old, "(NEW.reviewer_type=CASE WHEN r.frozen_config->>'execution_mode'='production' THEN 'model' ELSE 'offline' END)", $definition));
        DB::statement("COMMENT ON COLUMN ai_requests.evidence_kind IS '请求证据来源：历史离线模拟或真实供应商'");
        DB::statement("COMMENT ON COLUMN ai_requests.cost_status IS '费用来源：离线不计真实费或真实可计费；结算状态见预算预留及费用账本'");
        DB::statement("COMMENT ON COLUMN reviews.source_request_id IS '真实评审所依据的同运行成功网关请求；离线及人工评分为空'");
        DB::statement("COMMENT ON COLUMN revision_proposals.source_request_id IS '真实修订提案所依据的同运行成功网关请求；离线提案为空'");
        DB::statement("COMMENT ON COLUMN reviews.reviewer_type IS '评审来源：离线回放、人工评分或真实模型'");
        DB::statement("COMMENT ON COLUMN revision_tasks.mode IS '修订模式：离线、人工或真实模型；自动轮次共享上限'");
        DB::statement("COMMENT ON TABLE model_capability_evidence IS '模型实际请求的不可变能力观察证据，不覆盖历史声明或证明未实测的容量'");
        foreach (['id' => '能力证据标识', 'model_profile_version_id' => '被验证的模型声明版本', 'provider_version_id' => '实际供应商配置版本', 'request_id' => '产生证据的真实成功请求', 'response_evidence_id' => '原始响应证据标识', 'response_hash' => '原始脱敏响应摘要', 'observed_model' => '响应实际报告的模型标识', 'observations' => '此次请求实际观察到的能力及测试范围', 'actor_id' => '记录验证的管理员', 'created_at' => '证据记录时间'] as $column => $comment) {
            DB::statement("COMMENT ON COLUMN model_capability_evidence.{$column} IS '{$comment}'");
        }
    }

    public function down(): void
    {
        // 不允许回滚迁移删除已经产生的真实证据或把真实来源改称离线。
        if (DB::table('model_capability_evidence')->exists() || DB::table('ai_requests')->where('evidence_kind', 'real_provider')->exists() || DB::table('revision_tasks')->where('mode', 'production')->exists()) {
            throw new RuntimeException('已有真实证据或真实修订任务，禁止回退来源契约。');
        }
        $definition = DB::selectOne("SELECT pg_get_functiondef('e04_scope()'::regprocedure) AS definition")->definition;
        DB::unprepared(str_replace("(NEW.reviewer_type=CASE WHEN r.frozen_config->>'execution_mode'='production' THEN 'model' ELSE 'offline' END)", "NEW.reviewer_type='offline'", $definition));
        DB::unprepared(<<<'SQL'
DROP TRIGGER f01_request_scope ON ai_requests;
DROP TRIGGER f01_review_scope ON reviews;
DROP TRIGGER f01_revision_scope ON revision_proposals;
DROP TABLE model_capability_evidence;
DROP FUNCTION f01_real_evidence_scope();
ALTER TABLE reviews DROP CONSTRAINT f01_review_request;
ALTER TABLE reviews DROP COLUMN source_request_id;
ALTER TABLE revision_proposals DROP COLUMN source_request_id;
ALTER TABLE reviews DROP CONSTRAINT reviews_reviewer_type_check;
ALTER TABLE reviews ADD CONSTRAINT reviews_reviewer_type_check CHECK(reviewer_type IN ('offline','human'));
ALTER TABLE revision_tasks DROP CONSTRAINT revision_tasks_mode_check;
ALTER TABLE revision_tasks ADD CONSTRAINT revision_tasks_mode_check CHECK(mode IN ('offline','manual'));
ALTER TABLE ai_requests DROP CONSTRAINT ai_requests_cost_status_check;
ALTER TABLE ai_requests DROP COLUMN evidence_kind;
ALTER TABLE ai_requests ADD CONSTRAINT ai_requests_cost_status_check CHECK(cost_status='not_billable_fake');
SQL);
    }
};
