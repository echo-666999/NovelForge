<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/** E07正式派生来源与候选表分离，全部新表及字段中文备注。 */
return new class extends Migration
{
    public function up(): void
    {
        DB::unprepared(<<<'SQL'
CREATE TABLE canonical_memory_tasks (
 id bigserial PRIMARY KEY, novel_id bigint NOT NULL, revision_id bigint NOT NULL, commit_id bigint NOT NULL UNIQUE, outbox_id bigint NOT NULL UNIQUE REFERENCES canonical_outbox(id),
 status varchar(20) NOT NULL DEFAULT 'queued' CHECK(status IN ('queued','running','complete','failed','stale')), attempts int NOT NULL DEFAULT 0, token bigint NOT NULL DEFAULT 0,
 lease_owner uuid, lease_until timestamptz, available_at timestamptz NOT NULL DEFAULT clock_timestamp(), error_code varchar(100), delivery_count int NOT NULL DEFAULT 0, next_delivery_at timestamptz NOT NULL DEFAULT clock_timestamp(), last_publish_error varchar(100), vector_status varchar(30) NOT NULL DEFAULT 'pending',
 profile jsonb NOT NULL, profile_hash char(64) NOT NULL, created_at timestamptz NOT NULL DEFAULT clock_timestamp(), updated_at timestamptz NOT NULL DEFAULT clock_timestamp(),
 UNIQUE(novel_id,id), FOREIGN KEY(novel_id,revision_id,commit_id) REFERENCES canonical_commits(novel_id,revision_id,id)
);
CREATE INDEX e07_due ON canonical_memory_tasks(status,available_at,lease_until,id);
CREATE TABLE canonical_memory_attempts (
 id bigserial PRIMARY KEY, novel_id bigint NOT NULL, task_id bigint NOT NULL, token bigint NOT NULL, kind varchar(20) NOT NULL CHECK(kind IN ('claimed','completed','failed')), details jsonb NOT NULL,
 created_at timestamptz NOT NULL DEFAULT clock_timestamp(), UNIQUE(task_id,token,kind), FOREIGN KEY(novel_id,task_id) REFERENCES canonical_memory_tasks(novel_id,id)
);
CREATE TABLE canonical_memories (
 id bigserial PRIMARY KEY, novel_id bigint NOT NULL, revision_id bigint NOT NULL, commit_id bigint NOT NULL, kind varchar(20) NOT NULL CHECK(kind IN ('working','episodic','semantic','event')),
 chapter_sequence int NOT NULL, summary text NOT NULL, payload jsonb NOT NULL, content_hash char(64) NOT NULL, source_hash char(64) NOT NULL, created_at timestamptz NOT NULL DEFAULT clock_timestamp(),
 UNIQUE(commit_id,kind), UNIQUE(novel_id,id), FOREIGN KEY(novel_id,revision_id,commit_id) REFERENCES canonical_commits(novel_id,revision_id,id)
);
CREATE INDEX e07_memory_scope ON canonical_memories(novel_id,revision_id,chapter_sequence,kind);
CREATE TABLE canonical_memory_documents (
 id bigserial PRIMARY KEY, novel_id bigint NOT NULL, revision_id bigint NOT NULL, commit_id bigint NOT NULL, event_id bigint NOT NULL, chapter_sequence int NOT NULL,
 attribute varchar(80) NOT NULL, body text NOT NULL, fact_kind varchar(30) NOT NULL, content_hash char(64) NOT NULL, created_at timestamptz NOT NULL DEFAULT clock_timestamp(),
 UNIQUE(revision_id,event_id), UNIQUE(novel_id,id), FOREIGN KEY(novel_id,revision_id,commit_id) REFERENCES canonical_commits(novel_id,revision_id,id),
 FOREIGN KEY(novel_id,revision_id,event_id) REFERENCES story_events(novel_id,revision_id,id)
);
CREATE INDEX e07_document_scope ON canonical_memory_documents(novel_id,revision_id,chapter_sequence,event_id);
CREATE INDEX e07_document_trgm ON canonical_memory_documents USING gin(body gin_trgm_ops);
CREATE TABLE canonical_memory_embeddings (
 id bigserial PRIMARY KEY, novel_id bigint NOT NULL, document_id bigint NOT NULL, profile_hash char(64) NOT NULL, input_hash char(64) NOT NULL, embedding vector(64) NOT NULL,
 origin varchar(30) NOT NULL CHECK(origin='offline_hash_v1'), created_at timestamptz NOT NULL DEFAULT clock_timestamp(), UNIQUE(document_id,profile_hash),
 FOREIGN KEY(novel_id,document_id) REFERENCES canonical_memory_documents(novel_id,id)
);
CREATE OR REPLACE FUNCTION e07_source_guard() RETURNS trigger LANGUAGE plpgsql AS $$
DECLARE c canonical_commits; t canonical_memory_tasks; e story_events;
BEGIN
 IF TG_TABLE_NAME='canonical_memory_tasks' THEN
  IF TG_OP='UPDATE' THEN
   IF (to_jsonb(NEW)-ARRAY['status','attempts','token','lease_owner','lease_until','available_at','error_code','delivery_count','next_delivery_at','last_publish_error','vector_status','updated_at']) IS DISTINCT FROM (to_jsonb(OLD)-ARRAY['status','attempts','token','lease_owner','lease_until','available_at','error_code','delivery_count','next_delivery_at','last_publish_error','vector_status','updated_at']) THEN RAISE EXCEPTION 'immutable formal task identity'; END IF;
   RETURN NEW;
  END IF;
  IF NOT EXISTS(SELECT 1 FROM canonical_outbox o WHERE o.id=NEW.outbox_id AND o.novel_id=NEW.novel_id AND o.revision_id=NEW.revision_id AND o.commit_id=NEW.commit_id) THEN RAISE EXCEPTION 'formal outbox mismatch'; END IF;
 END IF;
 IF TG_TABLE_NAME IN ('canonical_memory_tasks','canonical_memories','canonical_memory_documents') THEN
  SELECT * INTO c FROM canonical_commits WHERE id=NEW.commit_id AND novel_id=NEW.novel_id AND revision_id=NEW.revision_id AND status='committed';
  IF c.id IS NULL THEN RAISE EXCEPTION 'formal commit required'; END IF;
  IF TG_TABLE_NAME<>'canonical_memory_tasks' THEN
   SELECT * INTO t FROM canonical_memory_tasks WHERE commit_id=c.id AND status='running' AND lease_until>clock_timestamp() AND token=nullif(current_setting('novelforge.memory_token',true),'')::bigint;
   IF t.id IS NULL THEN RAISE EXCEPTION 'formal memory lease required'; END IF;
   IF NEW.chapter_sequence<>c.chapter_sequence THEN RAISE EXCEPTION 'formal chapter mismatch'; END IF;
  END IF;
 END IF;
 IF TG_TABLE_NAME='canonical_memories' THEN
  IF NEW.source_hash IS DISTINCT FROM c.response->>'body_hash' OR NEW.payload->>'commit_id' IS DISTINCT FROM c.id::text OR NEW.payload->'state' IS DISTINCT FROM (SELECT payload FROM state_snapshots WHERE id=c.snapshot_id) OR
   (SELECT count(DISTINCT f->>'event_id') FROM jsonb_array_elements(NEW.payload->'facts') f)<>(SELECT count(*) FROM story_events WHERE commit_id=c.id) OR
   EXISTS(SELECT 1 FROM jsonb_array_elements(NEW.payload->'facts') f LEFT JOIN story_events se ON se.id=(f->>'event_id')::bigint AND se.commit_id=c.id WHERE se.id IS NULL OR f->'value' IS DISTINCT FROM se.payload->'value' OR f->>'kind' IS DISTINCT FROM se.payload->>'fact_kind' OR f->>'entity_id' IS DISTINCT FROM se.payload->>'entity_id') THEN RAISE EXCEPTION 'formal memory fact retention mismatch'; END IF;
  IF NEW.summary::jsonb IS DISTINCT FROM (CASE NEW.kind WHEN 'working' THEN NEW.payload->'state' WHEN 'episodic' THEN jsonb_build_object('chapter_sequence',c.chapter_sequence,'events',NEW.payload->'facts') WHEN 'semantic' THEN NEW.payload->'state'->'state' ELSE jsonb_build_object('events',NEW.payload->'facts') END) THEN RAISE EXCEPTION 'formal conservative summary mismatch'; END IF;
 END IF;
 IF TG_TABLE_NAME='canonical_memory_documents' THEN
  SELECT * INTO e FROM story_events WHERE id=NEW.event_id AND commit_id=c.id;
  IF e.id IS NULL OR NEW.attribute IS DISTINCT FROM e.payload->>'attribute' OR NEW.fact_kind IS DISTINCT FROM e.payload->>'fact_kind' OR (e.payload->'value' IS DISTINCT FROM CASE WHEN jsonb_typeof(e.payload->'value')='string' THEN to_jsonb(NEW.body) ELSE NEW.body::jsonb END) THEN RAISE EXCEPTION 'formal event content mismatch'; END IF;
 ELSIF TG_TABLE_NAME='canonical_memory_embeddings' THEN
  IF NOT EXISTS(SELECT 1 FROM canonical_memory_documents d JOIN canonical_memory_tasks mt ON mt.commit_id=d.commit_id WHERE d.id=NEW.document_id AND d.novel_id=NEW.novel_id AND mt.profile_hash=NEW.profile_hash AND mt.status='running' AND mt.lease_until>clock_timestamp() AND mt.token=nullif(current_setting('novelforge.memory_token',true),'')::bigint) THEN RAISE EXCEPTION 'formal embedding lease mismatch'; END IF;
 END IF;
 RETURN NEW;
END $$;
CREATE TRIGGER e07_source BEFORE INSERT OR UPDATE ON canonical_memory_tasks FOR EACH ROW EXECUTE FUNCTION e07_source_guard();
CREATE TRIGGER e07_immutable BEFORE DELETE ON canonical_memory_tasks FOR EACH ROW EXECUTE FUNCTION c01_immutable();
COMMENT ON TABLE canonical_memory_tasks IS '正式提交后派生任务及租约游标';
COMMENT ON COLUMN canonical_memory_tasks.id IS '记录标识';
COMMENT ON COLUMN canonical_memory_tasks.novel_id IS '所属小说';
COMMENT ON COLUMN canonical_memory_tasks.revision_id IS '正式基线标识';
COMMENT ON COLUMN canonical_memory_tasks.commit_id IS '来源正式提交';
COMMENT ON COLUMN canonical_memory_tasks.outbox_id IS '事务提交意图';
COMMENT ON COLUMN canonical_memory_tasks.status IS '任务处理状态';
COMMENT ON COLUMN canonical_memory_tasks.attempts IS '本轮技术尝试次数';
COMMENT ON COLUMN canonical_memory_tasks.token IS '单调递增隔离令牌';
COMMENT ON COLUMN canonical_memory_tasks.lease_owner IS '租约执行者';
COMMENT ON COLUMN canonical_memory_tasks.lease_until IS '租约截止时间';
COMMENT ON COLUMN canonical_memory_tasks.available_at IS '下次可执行时间';
COMMENT ON COLUMN canonical_memory_tasks.error_code IS '保留的最近错误码';
COMMENT ON COLUMN canonical_memory_tasks.delivery_count IS '至少一次队列通知投递次数';
COMMENT ON COLUMN canonical_memory_tasks.next_delivery_at IS '下次允许补投的时间';
COMMENT ON COLUMN canonical_memory_tasks.last_publish_error IS '最近通知发布错误';
COMMENT ON COLUMN canonical_memory_tasks.vector_status IS '向量处理状态';
COMMENT ON COLUMN canonical_memory_tasks.profile IS '冻结的离线嵌入档案';
COMMENT ON COLUMN canonical_memory_tasks.profile_hash IS '离线档案规范哈希';
COMMENT ON COLUMN canonical_memory_tasks.created_at IS '数据库创建时间';
COMMENT ON COLUMN canonical_memory_tasks.updated_at IS '最近状态更新时间';
CREATE TRIGGER e07_immutable BEFORE UPDATE OR DELETE ON canonical_memory_attempts FOR EACH ROW EXECUTE FUNCTION c01_immutable();
COMMENT ON TABLE canonical_memory_attempts IS '不可变记忆任务尝试与恢复日志';
COMMENT ON COLUMN canonical_memory_attempts.id IS '记录标识';
COMMENT ON COLUMN canonical_memory_attempts.novel_id IS '所属小说';
COMMENT ON COLUMN canonical_memory_attempts.task_id IS '所属派生任务';
COMMENT ON COLUMN canonical_memory_attempts.token IS '单调递增隔离令牌';
COMMENT ON COLUMN canonical_memory_attempts.kind IS '记忆层级或尝试类型';
COMMENT ON COLUMN canonical_memory_attempts.details IS '不可变尝试详情';
COMMENT ON COLUMN canonical_memory_attempts.created_at IS '数据库创建时间';
CREATE TRIGGER e07_source BEFORE INSERT ON canonical_memories FOR EACH ROW EXECUTE FUNCTION e07_source_guard();
CREATE TRIGGER e07_immutable BEFORE UPDATE OR DELETE ON canonical_memories FOR EACH ROW EXECUTE FUNCTION c01_immutable();
COMMENT ON TABLE canonical_memories IS '正式来源的四层保守摘要版本';
COMMENT ON COLUMN canonical_memories.id IS '记录标识';
COMMENT ON COLUMN canonical_memories.novel_id IS '所属小说';
COMMENT ON COLUMN canonical_memories.revision_id IS '正式基线标识';
COMMENT ON COLUMN canonical_memories.commit_id IS '来源正式提交';
COMMENT ON COLUMN canonical_memories.kind IS '记忆层级或尝试类型';
COMMENT ON COLUMN canonical_memories.chapter_sequence IS '来源正式章序';
COMMENT ON COLUMN canonical_memories.summary IS '保守摘要文本，不生成新事实';
COMMENT ON COLUMN canonical_memories.payload IS '完整来源事实与快照清单';
COMMENT ON COLUMN canonical_memories.content_hash IS '规范内容哈希';
COMMENT ON COLUMN canonical_memories.source_hash IS '正式来源正文哈希';
COMMENT ON COLUMN canonical_memories.created_at IS '数据库创建时间';
CREATE TRIGGER e07_source BEFORE INSERT ON canonical_memory_documents FOR EACH ROW EXECUTE FUNCTION e07_source_guard();
CREATE TRIGGER e07_immutable BEFORE UPDATE OR DELETE ON canonical_memory_documents FOR EACH ROW EXECUTE FUNCTION c01_immutable();
COMMENT ON TABLE canonical_memory_documents IS '绑定正式事件事实值的检索文档';
COMMENT ON COLUMN canonical_memory_documents.id IS '记录标识';
COMMENT ON COLUMN canonical_memory_documents.novel_id IS '所属小说';
COMMENT ON COLUMN canonical_memory_documents.revision_id IS '正式基线标识';
COMMENT ON COLUMN canonical_memory_documents.commit_id IS '来源正式提交';
COMMENT ON COLUMN canonical_memory_documents.event_id IS '精确正式事件标识';
COMMENT ON COLUMN canonical_memory_documents.chapter_sequence IS '来源正式章序';
COMMENT ON COLUMN canonical_memory_documents.attribute IS '事实属性';
COMMENT ON COLUMN canonical_memory_documents.body IS '精确事实值，不扩大原文引文';
COMMENT ON COLUMN canonical_memory_documents.fact_kind IS '事实信念等原始类型';
COMMENT ON COLUMN canonical_memory_documents.content_hash IS '规范内容哈希';
COMMENT ON COLUMN canonical_memory_documents.created_at IS '数据库创建时间';
CREATE TRIGGER e07_source BEFORE INSERT ON canonical_memory_embeddings FOR EACH ROW EXECUTE FUNCTION e07_source_guard();
CREATE TRIGGER e07_immutable BEFORE UPDATE OR DELETE ON canonical_memory_embeddings FOR EACH ROW EXECUTE FUNCTION c01_immutable();
COMMENT ON TABLE canonical_memory_embeddings IS '离线字符编码正式向量，不代表语义模型';
COMMENT ON COLUMN canonical_memory_embeddings.id IS '记录标识';
COMMENT ON COLUMN canonical_memory_embeddings.novel_id IS '所属小说';
COMMENT ON COLUMN canonical_memory_embeddings.document_id IS '来源正式检索文档';
COMMENT ON COLUMN canonical_memory_embeddings.profile_hash IS '离线档案规范哈希';
COMMENT ON COLUMN canonical_memory_embeddings.input_hash IS '实际嵌入文本哈希';
COMMENT ON COLUMN canonical_memory_embeddings.embedding IS '六十四维离线字符向量';
COMMENT ON COLUMN canonical_memory_embeddings.origin IS '明确离线编码来源';
COMMENT ON COLUMN canonical_memory_embeddings.created_at IS '数据库创建时间';
CREATE TRIGGER e07_task_audit AFTER INSERT OR UPDATE ON canonical_memory_tasks FOR EACH ROW EXECUTE FUNCTION c07_audit_capture();

SQL);
    }

    public function down(): void
    {
        DB::unprepared(<<<'SQL'
DROP TABLE canonical_memory_embeddings;
DROP TABLE canonical_memory_documents;
DROP TABLE canonical_memories;
DROP TABLE canonical_memory_attempts;
DROP TABLE canonical_memory_tasks;
DROP FUNCTION e07_source_guard();
SQL);
    }
};
