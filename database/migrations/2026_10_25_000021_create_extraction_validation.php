<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        // 扩展已有来源链以绑定不可变章节正文；旧产物约束和正式写入门禁保留。
        DB::unprepared(<<<'SQL'
ALTER TABLE source_documents ALTER COLUMN run_id DROP NOT NULL, ALTER COLUMN artifact_id DROP NOT NULL;
ALTER TABLE source_documents DROP CONSTRAINT source_documents_kind_check;
ALTER TABLE source_documents ADD COLUMN chapter_version_id bigint;
ALTER TABLE source_documents ADD CONSTRAINT e03_source_chapter FOREIGN KEY(novel_id,chapter_version_id) REFERENCES chapter_versions(novel_id,id);
ALTER TABLE source_documents ADD CONSTRAINT e03_source_kind CHECK((kind='artifact' AND artifact_id IS NOT NULL AND run_id IS NOT NULL AND chapter_version_id IS NULL) OR (kind='chapter' AND artifact_id IS NULL AND run_id IS NULL AND chapter_version_id IS NOT NULL));
CREATE UNIQUE INDEX e03_chapter_source ON source_documents(chapter_version_id) WHERE chapter_version_id IS NOT NULL;
COMMENT ON COLUMN source_documents.chapter_version_id IS '不可变章节候选正文版本；与旧产物来源互斥';
COMMENT ON COLUMN source_documents.run_id IS '旧产物所属运行；章节正文来源为空，不伪造运行';
COMMENT ON COLUMN source_documents.artifact_id IS '旧文本产物；章节正文来源为空';
COMMENT ON COLUMN source_documents.kind IS '来源种类：旧文本产物或章节候选正文';
CREATE OR REPLACE FUNCTION d01_validate_source() RETURNS trigger LANGUAGE plpgsql AS $$
DECLARE original text;
BEGIN
 IF NEW.kind='artifact' THEN
  SELECT content->>'text' INTO original FROM generation_artifacts WHERE novel_id=NEW.novel_id AND run_id=NEW.run_id AND id=NEW.artifact_id;
 ELSE
  SELECT body INTO original FROM chapter_versions WHERE novel_id=NEW.novel_id AND id=NEW.chapter_version_id;
 END IF;
 IF original IS NULL OR NEW.body IS DISTINCT FROM replace(replace(original,E'\r\n',E'\n'),E'\r',E'\n') OR NEW.source_hash IS DISTINCT FROM encode(sha256(convert_to(NEW.body,'UTF8')),'hex') THEN RAISE EXCEPTION 'invalid source body or hash'; END IF;
 RETURN NEW;
END $$;
CREATE TABLE extraction_attempts (
 id bigserial PRIMARY KEY, novel_id bigint NOT NULL, chapter_version_id bigint NOT NULL, source_id bigint NOT NULL,
 parent_id bigint, baseline_id bigint, context_id bigint, run_id bigint, artifact_id bigint UNIQUE, body_hash char(64) NOT NULL,
 row_version bigint NOT NULL, state_version bigint NOT NULL,
 frozen_input jsonb NOT NULL, input_hash char(64) NOT NULL, output jsonb NOT NULL, output_hash char(64) NOT NULL,
 report jsonb NOT NULL, actor_id bigint NOT NULL REFERENCES users(id), reason text NOT NULL,
 created_at timestamptz NOT NULL DEFAULT clock_timestamp(), UNIQUE(novel_id,id),
 CHECK((parent_id IS NULL OR parent_id<id) AND (baseline_id IS NULL OR baseline_id<id)),
 FOREIGN KEY(novel_id,chapter_version_id) REFERENCES chapter_versions(novel_id,id),
 FOREIGN KEY(novel_id,source_id) REFERENCES source_documents(novel_id,id),
 FOREIGN KEY(novel_id,parent_id) REFERENCES extraction_attempts(novel_id,id),
 FOREIGN KEY(novel_id,baseline_id) REFERENCES extraction_attempts(novel_id,id),
 FOREIGN KEY(novel_id,context_id) REFERENCES story_context_versions(novel_id,id),
 FOREIGN KEY(novel_id,run_id,artifact_id) REFERENCES generation_artifacts(novel_id,run_id,id),
 CHECK((run_id IS NULL)=(artifact_id IS NULL))
);
CREATE INDEX e03_history ON extraction_attempts(novel_id,chapter_version_id,id DESC);
CREATE TABLE extraction_events (
 novel_id bigint NOT NULL, attempt_id bigint NOT NULL, event_id bigint NOT NULL, event_key varchar(80) NOT NULL,
 PRIMARY KEY(attempt_id,event_key), UNIQUE(attempt_id,event_id),
 FOREIGN KEY(novel_id,attempt_id) REFERENCES extraction_attempts(novel_id,id),
 FOREIGN KEY(novel_id,event_id) REFERENCES proposed_events(novel_id,id)
);
CREATE TABLE extraction_foreshadows (
 id bigserial PRIMARY KEY, novel_id bigint NOT NULL, attempt_id bigint NOT NULL, plan_id bigint NOT NULL, event_id bigint NOT NULL,
 kind varchar(20) NOT NULL CHECK(kind IN ('plant','reinforce','payoff')), UNIQUE(attempt_id,plan_id,kind),
 FOREIGN KEY(novel_id,attempt_id) REFERENCES extraction_attempts(novel_id,id),
 FOREIGN KEY(novel_id,plan_id) REFERENCES foreshadow_plans(novel_id,id),
 FOREIGN KEY(novel_id,event_id) REFERENCES proposed_events(novel_id,id)
);
CREATE OR REPLACE FUNCTION e03_scope() RETURNS trigger LANGUAGE plpgsql AS $$
BEGIN
 IF TG_TABLE_NAME='extraction_attempts' THEN
  IF NOT EXISTS(SELECT 1 FROM source_documents s WHERE s.id=NEW.source_id AND s.novel_id=NEW.novel_id AND s.chapter_version_id=NEW.chapter_version_id AND s.source_hash=NEW.body_hash) THEN RAISE EXCEPTION 'E03 source mismatch'; END IF;
  IF NEW.run_id IS NOT NULL AND NOT EXISTS(SELECT 1 FROM generation_artifacts a JOIN generation_runs r ON r.id=a.run_id AND r.novel_id=a.novel_id WHERE a.id=NEW.artifact_id AND a.novel_id=NEW.novel_id AND a.run_id=NEW.run_id AND a.type='extraction_candidate' AND a.content_hash=NEW.output_hash AND a.content=NEW.output AND r.frozen_config->>'schema_version'='extraction-v1' AND r.frozen_config->>'chapter_version_id'=NEW.chapter_version_id::text AND r.frozen_config->>'body_hash'=NEW.body_hash) THEN RAISE EXCEPTION 'E03 generation origin mismatch'; END IF;
  IF NEW.parent_id IS NOT NULL AND NOT EXISTS(SELECT 1 FROM extraction_attempts p WHERE p.id=NEW.parent_id AND p.novel_id=NEW.novel_id AND p.chapter_version_id=NEW.chapter_version_id AND p.body_hash=NEW.body_hash) THEN RAISE EXCEPTION 'E03 reextract body mismatch'; END IF;
 ELSE
  IF TG_TABLE_NAME='extraction_foreshadows' AND NOT EXISTS(SELECT 1 FROM extraction_events e WHERE e.novel_id=NEW.novel_id AND e.attempt_id=NEW.attempt_id AND e.event_id=NEW.event_id) THEN RAISE EXCEPTION 'E03 missing local event mapping'; END IF;
  IF NOT EXISTS(SELECT 1 FROM extraction_attempts a JOIN proposed_events e ON e.novel_id=a.novel_id JOIN evidence_spans s ON s.id=e.evidence_id AND s.novel_id=a.novel_id WHERE a.id=NEW.attempt_id AND e.id=NEW.event_id AND s.source_version_id=a.source_id AND s.source_hash=a.body_hash) THEN RAISE EXCEPTION 'E03 event source mismatch'; END IF;
 END IF;
 RETURN NEW;
END $$;
SQL);
        $comments = ['extraction_attempts' => '绑定实际候选正文的不可变离线提取与硬约束验证记录', 'extraction_events' => '提取局部键与同来源候选事件的受约束映射', 'extraction_foreshadows' => '仅候选伏笔变化证据，不更新正式伏笔状态'];
        $columns = ['id' => '记录主键', 'novel_id' => '所属小说', 'chapter_version_id' => '提取的精确章节正文版本', 'source_id' => '已验证的正文证据来源', 'parent_id' => '同正文的上次误提取记录', 'baseline_id' => '前章通过的候选提取基线，不代表正式状态', 'context_id' => '候选时间和知识验证上下文', 'run_id' => '离线网关提取运行；纯验证回放为空', 'artifact_id' => '有效租约提交的提取响应产物；纯验证回放为空', 'body_hash' => '实际正文UTF8字节哈希', 'row_version' => '冻结书级输入版本', 'state_version' => '冻结正式状态版本', 'frozen_input' => '正文来源、批准实体和作者规则上下文快照', 'input_hash' => '冻结输入规范哈希', 'output' => '显式提供的离线提取响应原件', 'output_hash' => '离线响应规范哈希', 'report' => '硬约束结果及原文和冲突事实定位', 'actor_id' => '提交管理员', 'reason' => '提取或重新提取原因', 'created_at' => '记录时间', 'attempt_id' => '所属提取记录', 'event_id' => '候选事件', 'event_key' => '响应内唯一事件键', 'plan_id' => '冻结伏笔计划版本', 'kind' => '埋设、强化或回收候选变化'];
        foreach ($comments as $table => $comment) {
            DB::statement("COMMENT ON TABLE {$table} IS '{$comment}'");
            foreach (DB::select('SELECT column_name FROM information_schema.columns WHERE table_schema=current_schema() AND table_name=?', [$table]) as $c) {
                DB::statement("COMMENT ON COLUMN {$table}.{$c->column_name} IS '{$columns[$c->column_name]}'");
            }
            DB::statement("CREATE TRIGGER e03_scope_check BEFORE INSERT ON {$table} FOR EACH ROW EXECUTE FUNCTION e03_scope()");
            DB::statement("CREATE TRIGGER e03_immutable BEFORE UPDATE OR DELETE ON {$table} FOR EACH ROW EXECUTE FUNCTION c01_immutable()");
        }
        DB::statement('CREATE TRIGGER e03_capture AFTER INSERT ON extraction_attempts FOR EACH ROW EXECUTE FUNCTION c07_audit_capture()');
    }

    public function down(): void
    {
        // 仅空测试库回滚；不删除有正文来源的开发历史来强行降级。
        DB::unprepared(<<<'SQL'
DROP TABLE extraction_foreshadows,extraction_events,extraction_attempts;
DROP FUNCTION e03_scope();
CREATE OR REPLACE FUNCTION d01_validate_source() RETURNS trigger LANGUAGE plpgsql AS $$
DECLARE original text;
BEGIN
 SELECT content->>'text' INTO original FROM generation_artifacts WHERE novel_id=NEW.novel_id AND run_id=NEW.run_id AND id=NEW.artifact_id;
 IF original IS NULL OR NEW.body IS DISTINCT FROM replace(replace(original,E'\r\n',E'\n'),E'\r',E'\n') OR NEW.source_hash IS DISTINCT FROM encode(sha256(convert_to(NEW.body,'UTF8')),'hex') THEN
  RAISE EXCEPTION 'invalid source body or hash';
 END IF;
 RETURN NEW;
END $$;

ALTER TABLE source_documents DROP CONSTRAINT e03_source_kind;
DROP INDEX e03_chapter_source;
ALTER TABLE source_documents DROP COLUMN chapter_version_id;
ALTER TABLE source_documents ADD CHECK(kind='artifact');
ALTER TABLE source_documents ALTER COLUMN run_id SET NOT NULL, ALTER COLUMN artifact_id SET NOT NULL;
COMMENT ON COLUMN source_documents.run_id IS '来源产物所属运行';
COMMENT ON COLUMN source_documents.artifact_id IS '完整文本产物来源';
COMMENT ON COLUMN source_documents.kind IS '来源种类：旧文本产物';
SQL);
    }
};
