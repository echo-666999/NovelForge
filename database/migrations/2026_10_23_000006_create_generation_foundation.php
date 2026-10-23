<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        // C01 只保存编排事实；租约、投递与供应商请求由后续任务实现。
        DB::unprepared(<<<'SQL'
CREATE TABLE generation_runs (
 id bigserial PRIMARY KEY, novel_id bigint NOT NULL REFERENCES novels(id) ON DELETE RESTRICT,
 actor_id bigint NOT NULL REFERENCES users(id) ON DELETE RESTRICT,
 outline_version_id bigint NOT NULL, expected_state_version bigint NOT NULL,
 command_key varchar(128) NOT NULL, request_hash char(64) NOT NULL,
 frozen_config jsonb NOT NULL, input_hash char(64) NOT NULL,
 status varchar(32) NOT NULL DEFAULT 'queued', error_code varchar(80),
 created_at timestamptz NOT NULL, updated_at timestamptz NOT NULL,
 UNIQUE(novel_id,id), UNIQUE(actor_id,novel_id,command_key),
 FOREIGN KEY(novel_id,outline_version_id) REFERENCES outline_versions(novel_id,id) ON DELETE RESTRICT,
 CHECK(status IN ('queued','running','waiting_dependency','deferred','blocked','paused','needs_attention','succeeded','failed','canceled'))
);
CREATE INDEX generation_runs_status_updated ON generation_runs(status,updated_at);
CREATE TABLE generation_steps (
 id bigserial PRIMARY KEY, novel_id bigint NOT NULL, run_id bigint NOT NULL,
 stage varchar(80) NOT NULL, scope_key varchar(80) NOT NULL, attempt_no integer NOT NULL,
 frozen_input jsonb NOT NULL, dependency_hash char(64) NOT NULL,
 status varchar(32) NOT NULL DEFAULT 'queued', checkpoint_artifact_id bigint, resume_artifact_id bigint,
 error_code varchar(80), created_at timestamptz NOT NULL, updated_at timestamptz NOT NULL,
 UNIQUE(novel_id,run_id,id), UNIQUE(run_id,stage,scope_key,attempt_no),
 FOREIGN KEY(novel_id,run_id) REFERENCES generation_runs(novel_id,id) ON DELETE RESTRICT,
 CHECK(attempt_no BETWEEN 1 AND 3),
 CHECK(status IN ('queued','running','succeeded','failed','deferred','blocked','canceled','quarantined'))
);
CREATE INDEX generation_steps_run_status ON generation_steps(run_id,status);
CREATE UNIQUE INDEX generation_steps_one_active ON generation_steps(run_id,stage,scope_key) WHERE status IN ('queued','running');
CREATE TABLE generation_artifacts (
 id bigserial PRIMARY KEY, novel_id bigint NOT NULL, run_id bigint NOT NULL, step_id bigint NOT NULL,
 type varchar(80) NOT NULL, schema_version varchar(80) NOT NULL,
 content jsonb NOT NULL, content_hash char(64) NOT NULL, source_hash char(64) NOT NULL,
 storage_ref text NOT NULL UNIQUE, created_at timestamptz NOT NULL,
 UNIQUE(novel_id,run_id,id), UNIQUE(novel_id,run_id,step_id,id), UNIQUE(step_id),
 FOREIGN KEY(novel_id,run_id,step_id) REFERENCES generation_steps(novel_id,run_id,id) ON DELETE RESTRICT
);
ALTER TABLE generation_steps ADD CONSTRAINT generation_resume_scope FOREIGN KEY(novel_id,run_id,resume_artifact_id) REFERENCES generation_artifacts(novel_id,run_id,id) ON DELETE RESTRICT;
ALTER TABLE generation_steps ADD CONSTRAINT generation_checkpoint_owner FOREIGN KEY(novel_id,run_id,id,checkpoint_artifact_id) REFERENCES generation_artifacts(novel_id,run_id,step_id,id) ON DELETE RESTRICT;
CREATE TABLE generation_dependencies (
 novel_id bigint NOT NULL, run_id bigint NOT NULL, step_id bigint NOT NULL,
 source_step_id bigint NOT NULL, source_artifact_id bigint NOT NULL,
 PRIMARY KEY(step_id,source_step_id), CHECK(step_id <> source_step_id),
 FOREIGN KEY(novel_id,run_id,step_id) REFERENCES generation_steps(novel_id,run_id,id) ON DELETE RESTRICT,
 FOREIGN KEY(novel_id,run_id,source_step_id,source_artifact_id) REFERENCES generation_artifacts(novel_id,run_id,step_id,id) ON DELETE RESTRICT
);
CREATE OR REPLACE FUNCTION c01_immutable() RETURNS trigger LANGUAGE plpgsql AS $$
BEGIN RAISE EXCEPTION 'C01 immutable history'; END $$;
CREATE TRIGGER c01_artifact_immutable BEFORE UPDATE OR DELETE ON generation_artifacts FOR EACH ROW EXECUTE FUNCTION c01_immutable();
CREATE TRIGGER c01_dependency_immutable BEFORE UPDATE OR DELETE ON generation_dependencies FOR EACH ROW EXECUTE FUNCTION c01_immutable();
CREATE OR REPLACE FUNCTION c01_frozen_identity() RETURNS trigger LANGUAGE plpgsql AS $$
BEGIN
 IF TG_OP='DELETE' THEN RAISE EXCEPTION 'C01 immutable history'; END IF;
 IF (to_jsonb(NEW) - ARRAY['status','error_code','updated_at','checkpoint_artifact_id']) IS DISTINCT FROM (to_jsonb(OLD) - ARRAY['status','error_code','updated_at','checkpoint_artifact_id']) THEN
  RAISE EXCEPTION 'C01 frozen identity';
 END IF;
 IF TG_TABLE_NAME='generation_steps' AND OLD.status IN ('succeeded','failed','deferred','blocked','canceled','quarantined') AND NEW IS DISTINCT FROM OLD THEN
  RAISE EXCEPTION 'C01 terminal attempt';
 END IF;
 RETURN NEW;
END $$;
CREATE TRIGGER c01_run_frozen BEFORE UPDATE OR DELETE ON generation_runs FOR EACH ROW EXECUTE FUNCTION c01_frozen_identity();
CREATE TRIGGER c01_step_frozen BEFORE UPDATE OR DELETE ON generation_steps FOR EACH ROW EXECUTE FUNCTION c01_frozen_identity();
SQL);
    }

    public function down(): void
    {
        // 先解除检查点环，再按引用顺序回滚；仅测试空库使用回滚验收。
        DB::unprepared('ALTER TABLE generation_steps DROP CONSTRAINT generation_resume_scope; ALTER TABLE generation_steps DROP CONSTRAINT generation_checkpoint_owner; DROP TABLE generation_dependencies, generation_artifacts, generation_steps, generation_runs; DROP FUNCTION c01_frozen_identity(); DROP FUNCTION c01_immutable();');
    }
};
