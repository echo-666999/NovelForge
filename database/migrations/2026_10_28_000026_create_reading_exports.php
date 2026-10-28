<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /** 导出身份及阅读证据只追加，DDL同时写入每一表和字段的中文备注。 */
    public function up(): void
    {
        DB::unprepared(<<<'SQL'

CREATE TABLE export_jobs (
 id bigserial PRIMARY KEY, novel_id bigint NOT NULL REFERENCES novels(id), revision_id bigint,
 scope varchar(10) NOT NULL CHECK(scope IN ('formal','draft')), format varchar(10) NOT NULL CHECK(format IN ('txt','md')),
 manifest jsonb NOT NULL, manifest_hash char(64) NOT NULL, parent_job_id bigint,
 actor_id bigint NOT NULL REFERENCES users(id), command_key varchar(128) NOT NULL, request_hash char(64) NOT NULL,
 status varchar(15) NOT NULL DEFAULT 'queued' CHECK(status IN ('queued','building','ready','failed')),
 error_code varchar(40), lease_token uuid, lease_until timestamptz, created_at timestamptz NOT NULL DEFAULT clock_timestamp(), updated_at timestamptz NOT NULL DEFAULT clock_timestamp(),
 CHECK((scope='formal')=(revision_id IS NOT NULL)), CHECK((status='building' AND lease_token IS NOT NULL AND lease_until IS NOT NULL) OR (status<>'building' AND lease_token IS NULL AND lease_until IS NULL)), UNIQUE(novel_id,id), UNIQUE(actor_id,novel_id,command_key),
 FOREIGN KEY(novel_id,revision_id) REFERENCES canonical_revisions(novel_id,id),
 FOREIGN KEY(novel_id,parent_job_id) REFERENCES export_jobs(novel_id,id)
);
CREATE INDEX e08_jobs_queue ON export_jobs(status,created_at);
CREATE INDEX e08_jobs_lease ON export_jobs(status,lease_until);
CREATE INDEX e08_jobs_book ON export_jobs(novel_id,id DESC);
CREATE TABLE export_items (
 id bigserial PRIMARY KEY, novel_id bigint NOT NULL, export_job_id bigint NOT NULL, revision_id bigint,
 chapter_id bigint NOT NULL, chapter_version_id bigint NOT NULL, commit_id bigint, sequence int NOT NULL CHECK(sequence>0),
 title text NOT NULL, volume_id bigint NOT NULL, volume_title text NOT NULL, body_hash char(64) NOT NULL, character_count int NOT NULL CHECK(character_count>0),
 UNIQUE(export_job_id,sequence), UNIQUE(export_job_id,chapter_id),
 FOREIGN KEY(novel_id,export_job_id) REFERENCES export_jobs(novel_id,id),
 FOREIGN KEY(novel_id,chapter_id,chapter_version_id) REFERENCES chapter_versions(novel_id,node_id,id),
 FOREIGN KEY(novel_id,revision_id,chapter_version_id,commit_id) REFERENCES canonical_commits(novel_id,revision_id,chapter_version_id,id),
 FOREIGN KEY(novel_id,volume_id) REFERENCES volumes(novel_id,id), CHECK((revision_id IS NULL)=(commit_id IS NULL))
);
CREATE TABLE export_files (
 id bigserial PRIMARY KEY, novel_id bigint NOT NULL, export_job_id bigint NOT NULL UNIQUE,
 path text NOT NULL UNIQUE, filename text NOT NULL, checksum char(64) NOT NULL, byte_size bigint NOT NULL CHECK(byte_size>0),
 expires_at timestamptz NOT NULL, deleted_at timestamptz, created_at timestamptz NOT NULL DEFAULT clock_timestamp(),
 FOREIGN KEY(novel_id,export_job_id) REFERENCES export_jobs(novel_id,id)
);
CREATE TABLE reading_observations (
 id bigserial PRIMARY KEY, novel_id bigint NOT NULL, revision_id bigint, chapter_id bigint NOT NULL, chapter_version_id bigint NOT NULL,
 actor_id bigint NOT NULL REFERENCES users(id), kind varchar(10) NOT NULL CHECK(kind IN ('read','issue')),
 body_hash char(64) NOT NULL, quote text, start_offset int, end_offset int, note text NOT NULL,
 command_key varchar(128) NOT NULL, request_hash char(64) NOT NULL, created_at timestamptz NOT NULL DEFAULT clock_timestamp(),
 UNIQUE(actor_id,novel_id,command_key), FOREIGN KEY(novel_id,chapter_id,chapter_version_id) REFERENCES chapter_versions(novel_id,node_id,id),
 FOREIGN KEY(novel_id,revision_id) REFERENCES canonical_revisions(novel_id,id),
 CHECK((kind='read' AND quote IS NULL AND start_offset IS NULL AND end_offset IS NULL) OR (kind='issue' AND length(quote)>0 AND start_offset>=0 AND end_offset>start_offset))
);
CREATE INDEX e08_reading_source ON reading_observations(novel_id,chapter_version_id,revision_id,kind);
CREATE OR REPLACE FUNCTION e08_guard() RETURNS trigger LANGUAGE plpgsql AS $$
DECLARE j export_jobs;
BEGIN
 IF TG_TABLE_NAME='export_jobs' THEN
  IF TG_OP='DELETE' OR (to_jsonb(NEW)-ARRAY['status','error_code','updated_at','lease_token','lease_until']) IS DISTINCT FROM (to_jsonb(OLD)-ARRAY['status','error_code','updated_at','lease_token','lease_until']) THEN RAISE EXCEPTION 'immutable export manifest'; END IF;
 ELSIF TG_TABLE_NAME='export_items' THEN
  SELECT * INTO j FROM export_jobs WHERE id=NEW.export_job_id;
  IF j.revision_id IS DISTINCT FROM NEW.revision_id OR NOT EXISTS(SELECT 1 FROM jsonb_array_elements(j.manifest->'items') v WHERE (v->>'sequence')::int=NEW.sequence AND v->>'chapter_version_id'=NEW.chapter_version_id::text AND v->>'body_hash'=NEW.body_hash) THEN RAISE EXCEPTION 'export item outside frozen manifest'; END IF;
 ELSIF TG_TABLE_NAME='export_files' THEN
  IF TG_OP='DELETE' OR OLD.deleted_at IS NOT NULL OR NEW.deleted_at IS NULL OR (to_jsonb(NEW)-'deleted_at') IS DISTINCT FROM (to_jsonb(OLD)-'deleted_at') THEN RAISE EXCEPTION 'immutable export file evidence'; END IF;
 END IF;
 RETURN NEW;
END $$;
CREATE TRIGGER e08_jobs_guard BEFORE UPDATE OR DELETE ON export_jobs FOR EACH ROW EXECUTE FUNCTION e08_guard();
CREATE TRIGGER e08_items_insert BEFORE INSERT ON export_items FOR EACH ROW EXECUTE FUNCTION e08_guard();
CREATE TRIGGER e08_items_immutable BEFORE UPDATE OR DELETE ON export_items FOR EACH ROW EXECUTE FUNCTION c01_immutable();
CREATE TRIGGER e08_files_guard BEFORE UPDATE OR DELETE ON export_files FOR EACH ROW EXECUTE FUNCTION e08_guard();
CREATE TRIGGER e08_reading_immutable BEFORE UPDATE OR DELETE ON reading_observations FOR EACH ROW EXECUTE FUNCTION c01_immutable();
-- 只记录身份、状态及校验和，正文、引文、文件路径不进入通用审计。
CREATE OR REPLACE FUNCTION e08_audit() RETURNS trigger LANGUAGE plpgsql AS $$
DECLARE actor bigint; nid bigint; j export_jobs; op uuid; a jsonb;
BEGIN
 IF TG_TABLE_NAME='export_files' THEN
  SELECT * INTO j FROM export_jobs WHERE id=NEW.export_job_id;
  actor:=j.actor_id; nid:=j.novel_id;
  a:=jsonb_build_object('id',NEW.id,'export_job_id',NEW.export_job_id,'checksum',NEW.checksum,'byte_size',NEW.byte_size,'deleted_at',NEW.deleted_at);
 ELSIF TG_TABLE_NAME='export_jobs' THEN
  actor:=NEW.actor_id; nid:=NEW.novel_id;
  a:=jsonb_build_object('id',NEW.id,'status',NEW.status,'manifest_hash',NEW.manifest_hash,'error_code',NEW.error_code);
 ELSE
  actor:=NEW.actor_id; nid:=NEW.novel_id;
  a:=jsonb_build_object('id',NEW.id,'chapter_version_id',NEW.chapter_version_id,'kind',NEW.kind,'body_hash',NEW.body_hash);
 END IF;
 op:=coalesce(nullif(current_setting('novelforge.trace_id',true),'')::uuid,gen_random_uuid());
 INSERT INTO audit_events(trace_id,operation_trace_id,novel_id,actor_id,object_type,object_id,action,outcome,reason,before_state,after_state)
 VALUES(op,op,nid,actor,TG_TABLE_NAME,NEW.id::text,lower(TG_OP),'recorded','阅读与导出来源追踪','{}',a);
 RETURN NEW;
END $$;
CREATE TRIGGER e08_audit_capture AFTER INSERT OR UPDATE ON export_jobs FOR EACH ROW EXECUTE FUNCTION e08_audit();
CREATE TRIGGER e08_audit_capture AFTER INSERT OR UPDATE ON export_files FOR EACH ROW EXECUTE FUNCTION e08_audit();
CREATE TRIGGER e08_audit_capture AFTER INSERT ON reading_observations FOR EACH ROW EXECUTE FUNCTION e08_audit();
COMMENT ON TABLE export_jobs IS '冻结版本清单的持久导出任务';
COMMENT ON COLUMN export_jobs.id IS '导出任务标识';
COMMENT ON COLUMN export_jobs.novel_id IS '所属小说';
COMMENT ON COLUMN export_jobs.revision_id IS '正式基线；显式草稿为空';
COMMENT ON COLUMN export_jobs.scope IS '正式或显式草稿范围';
COMMENT ON COLUMN export_jobs.format IS 'TXT或Markdown格式';
COMMENT ON COLUMN export_jobs.manifest IS '不可变正文版本与排版快照';
COMMENT ON COLUMN export_jobs.manifest_hash IS '清单规范JSON的SHA256';
COMMENT ON COLUMN export_jobs.parent_job_id IS '按原清单重建的来源任务';
COMMENT ON COLUMN export_jobs.actor_id IS '创建作者';
COMMENT ON COLUMN export_jobs.command_key IS '同书同作者幂等键';
COMMENT ON COLUMN export_jobs.request_hash IS '原请求规范哈希';
COMMENT ON COLUMN export_jobs.status IS '持久导出执行状态';
COMMENT ON COLUMN export_jobs.lease_token IS '本次消费者租约隔离令牌';
COMMENT ON COLUMN export_jobs.lease_until IS '执行租约到期时间；过期可重投';
COMMENT ON COLUMN export_jobs.error_code IS '固定脱敏失败码';
COMMENT ON COLUMN export_jobs.created_at IS '冻结创建时间';
COMMENT ON COLUMN export_jobs.updated_at IS '任务状态更新时间';
COMMENT ON TABLE export_items IS '导出清单的不可变章节来源映射';
COMMENT ON COLUMN export_items.id IS '映射标识';
COMMENT ON COLUMN export_items.novel_id IS '所属小说';
COMMENT ON COLUMN export_items.export_job_id IS '所属冻结导出任务';
COMMENT ON COLUMN export_items.revision_id IS '正式来源基线；草稿为空';
COMMENT ON COLUMN export_items.chapter_id IS '章节节点';
COMMENT ON COLUMN export_items.chapter_version_id IS '精确正文版本';
COMMENT ON COLUMN export_items.commit_id IS '精确正式提交；草稿为空';
COMMENT ON COLUMN export_items.sequence IS '全书章序';
COMMENT ON COLUMN export_items.title IS '冻结具体章名';
COMMENT ON COLUMN export_items.volume_id IS '冻结所属卷';
COMMENT ON COLUMN export_items.volume_title IS '冻结卷标题';
COMMENT ON COLUMN export_items.body_hash IS '正文UTF8字节SHA256';
COMMENT ON COLUMN export_items.character_count IS '可见非空白码点字数';
COMMENT ON TABLE export_files IS '私有派生文件及不可变校验证据';
COMMENT ON COLUMN export_files.id IS '文件记录标识';
COMMENT ON COLUMN export_files.novel_id IS '所属小说';
COMMENT ON COLUMN export_files.export_job_id IS '来源冻结任务';
COMMENT ON COLUMN export_files.path IS '服务端生成的私有相对路径';
COMMENT ON COLUMN export_files.filename IS '清理危险字符后的附件文件名';
COMMENT ON COLUMN export_files.checksum IS '文件UTF8字节SHA256';
COMMENT ON COLUMN export_files.byte_size IS '文件字节数';
COMMENT ON COLUMN export_files.expires_at IS '鉴权下载到期时间';
COMMENT ON COLUMN export_files.deleted_at IS '派生文件删除时间；保留文件证据';
COMMENT ON COLUMN export_files.created_at IS '生成时间';
COMMENT ON TABLE reading_observations IS '精确正文版本的人工阅读记录与问题；不代表正式认可';
COMMENT ON COLUMN reading_observations.id IS '阅读记录标识';
COMMENT ON COLUMN reading_observations.novel_id IS '所属小说';
COMMENT ON COLUMN reading_observations.revision_id IS '阅读基线；显式草稿为空';
COMMENT ON COLUMN reading_observations.chapter_id IS '章节节点';
COMMENT ON COLUMN reading_observations.chapter_version_id IS '阅读正文版本';
COMMENT ON COLUMN reading_observations.actor_id IS '阅读作者';
COMMENT ON COLUMN reading_observations.kind IS '已读标记或人工阅读问题';
COMMENT ON COLUMN reading_observations.body_hash IS '引文所属正文哈希';
COMMENT ON COLUMN reading_observations.quote IS '问题精确引文';
COMMENT ON COLUMN reading_observations.start_offset IS 'Unicode码点左闭起点';
COMMENT ON COLUMN reading_observations.end_offset IS 'Unicode码点右开终点';
COMMENT ON COLUMN reading_observations.note IS '作者问题说明或已读备注';
COMMENT ON COLUMN reading_observations.command_key IS '同书同作者幂等键';
COMMENT ON COLUMN reading_observations.request_hash IS '阅读请求规范哈希';
COMMENT ON COLUMN reading_observations.created_at IS '记录创建时间';
SQL);
    }

    /** 仅回退本阶段派生表，不修改任何正式正文或提交。 */
    public function down(): void
    {
        DB::unprepared('DROP TABLE reading_observations, export_files, export_items, export_jobs; DROP FUNCTION e08_guard(), e08_audit();');
    }
};
