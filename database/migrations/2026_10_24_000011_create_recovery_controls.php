<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        // 决策与迟到证据独立追加，绝不覆盖已封存的步骤和正文。
        DB::unprepared(<<<'SQL'
ALTER TABLE ai_requests ADD COLUMN retry_after_seconds integer CHECK(retry_after_seconds>=0);
COMMENT ON COLUMN ai_requests.retry_after_seconds IS '供应商要求的最短重试等待秒数';
CREATE OR REPLACE FUNCTION c03_request_identity() RETURNS trigger LANGUAGE plpgsql AS $$
BEGIN
 IF TG_OP='DELETE' THEN RAISE EXCEPTION 'C03 immutable request'; END IF;
 IF OLD.status <> 'pending' OR (to_jsonb(NEW)-ARRAY['status','error_code','issues','provider_request_id','http_status','usage','completed_at','retry_after_seconds']) IS DISTINCT FROM (to_jsonb(OLD)-ARRAY['status','error_code','issues','provider_request_id','http_status','usage','completed_at','retry_after_seconds']) THEN RAISE EXCEPTION 'C03 immutable request'; END IF;
 RETURN NEW;
END $$;
CREATE TABLE recovery_decisions (
 id bigserial PRIMARY KEY, novel_id bigint NOT NULL, run_id bigint NOT NULL,
 actor_id bigint NOT NULL REFERENCES users(id), command_key varchar(128) NOT NULL,
 action varchar(32) NOT NULL CHECK(action IN ('pause','resume','cancel','manual','retry','reextract','change_plan')),
 reason text NOT NULL, request_hash char(64) NOT NULL, payload jsonb NOT NULL,
 response jsonb NOT NULL, created_at timestamptz NOT NULL DEFAULT clock_timestamp(),
 UNIQUE(actor_id,run_id,command_key), UNIQUE(novel_id,run_id,id),
 FOREIGN KEY(novel_id,run_id) REFERENCES generation_runs(novel_id,id)
);
CREATE INDEX c06_decisions_run ON recovery_decisions(run_id,id);
CREATE TRIGGER c06_decision_immutable BEFORE UPDATE OR DELETE ON recovery_decisions FOR EACH ROW EXECUTE FUNCTION c01_immutable();
CREATE TABLE recovery_outputs (
 id bigserial PRIMARY KEY, novel_id bigint NOT NULL, run_id bigint NOT NULL,
 decision_id bigint NOT NULL UNIQUE, source_artifact_id bigint NOT NULL,
 source_hash char(64) NOT NULL, content jsonb NOT NULL, content_hash char(64) NOT NULL,
 schema_version varchar(80) NOT NULL, created_at timestamptz NOT NULL DEFAULT clock_timestamp(),
 FOREIGN KEY(novel_id,run_id,decision_id) REFERENCES recovery_decisions(novel_id,run_id,id),
 FOREIGN KEY(novel_id,run_id,source_artifact_id) REFERENCES generation_artifacts(novel_id,run_id,id)
);
CREATE INDEX c06_outputs_run ON recovery_outputs(run_id,id);
CREATE TRIGGER c06_output_immutable BEFORE UPDATE OR DELETE ON recovery_outputs FOR EACH ROW EXECUTE FUNCTION c01_immutable();
CREATE TABLE generation_late_evidence (
 id bigserial PRIMARY KEY, novel_id bigint NOT NULL, run_id bigint NOT NULL, step_id bigint NOT NULL,
 fencing_token bigint NOT NULL CHECK(fencing_token>0), lease_owner uuid NOT NULL,
 content jsonb NOT NULL, content_hash char(64) NOT NULL, reason varchar(80) NOT NULL,
 created_at timestamptz NOT NULL DEFAULT clock_timestamp(),
 UNIQUE(step_id,fencing_token,lease_owner,content_hash),
 FOREIGN KEY(novel_id,run_id,step_id) REFERENCES generation_steps(novel_id,run_id,id)
);
CREATE INDEX c06_late_run ON generation_late_evidence(run_id,id);
CREATE TRIGGER c06_late_immutable BEFORE UPDATE OR DELETE ON generation_late_evidence FOR EACH ROW EXECUTE FUNCTION c01_immutable();
SQL);
        $comments = [
            'recovery_outputs' => ['基于原证据重提取的隔离结果', ['id' => '结果标识', 'novel_id' => '所属小说', 'run_id' => '所属运行', 'decision_id' => '人工重新提取决定', 'source_artifact_id' => '不可变原证据标识', 'source_hash' => '原证据内容哈希', 'content' => '重新提取结果不覆盖原文', 'content_hash' => '结果规范化哈希', 'schema_version' => '重提取输出契约版本', 'created_at' => '结果生成时间']],
            'recovery_decisions' => ['恢复操作及人工决策不可变记录', ['id' => '决策标识', 'novel_id' => '所属小说', 'run_id' => '所属运行', 'actor_id' => '操作管理员', 'command_key' => '幂等操作键', 'action' => '恢复动作', 'reason' => '人工操作原因', 'request_hash' => '请求规范化哈希', 'payload' => '冻结决策输入及来源', 'response' => '首次操作响应', 'created_at' => '决定时间']],
            'generation_late_evidence' => ['过期或撤销租约的隔离响应证据', ['id' => '证据标识', 'novel_id' => '所属小说', 'run_id' => '所属运行', 'step_id' => '原始步骤', 'fencing_token' => '返回结果的隔离令牌', 'lease_owner' => '返回结果的租约持有者', 'content' => '隔离输出仅供审查', 'content_hash' => '内容规范化哈希', 'reason' => '不能提交原因', 'created_at' => '留证时间']],
        ];
        foreach ($comments as $table => [$description, $columns]) {
            DB::statement("COMMENT ON TABLE {$table} IS '{$description}'");
            foreach ($columns as $column => $comment) {
                DB::statement("COMMENT ON COLUMN {$table}.{$column} IS '{$comment}'");
            }
        }
    }

    public function down(): void
    {
        // 仅在隔离空库验证回滚；生产历史不得清除。
        DB::unprepared('DROP TABLE recovery_outputs, generation_late_evidence, recovery_decisions; ALTER TABLE ai_requests DROP COLUMN retry_after_seconds');
        $migration = file_get_contents(__DIR__.'/2026_10_24_000008_create_provider_gateway.php');
        preg_match('/CREATE OR REPLACE FUNCTION c03_request_identity\(\).*?END \$\$;/s', $migration, $matches);
        DB::unprepared($matches[0]);
    }
};
