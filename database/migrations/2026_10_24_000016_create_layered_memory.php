<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    // 草稿记忆绑定持久运行分支，正式来源在E07接入前保持关闭。
    public function up(): void
    {
        DB::unprepared(<<<'SQL'

ALTER TABLE source_documents ADD CONSTRAINT d04_source_branch UNIQUE(novel_id,run_id,id);
ALTER TABLE evidence_spans ADD CONSTRAINT d04_evidence_source UNIQUE(novel_id,source_version_id,id);
CREATE TABLE memory_collections (
 id bigserial PRIMARY KEY, novel_id bigint NOT NULL, branch_id bigint NOT NULL,
 kind varchar(20) NOT NULL CHECK(kind IN ('working','episodic','semantic','event')), name varchar(200) NOT NULL,
 actor_id bigint NOT NULL REFERENCES users(id), created_at timestamptz NOT NULL DEFAULT clock_timestamp(),
 UNIQUE(novel_id,branch_id,id), FOREIGN KEY(novel_id,branch_id) REFERENCES generation_runs(novel_id,id)
);
CREATE TABLE memory_facts (
 id bigserial PRIMARY KEY, novel_id bigint NOT NULL, branch_id bigint NOT NULL, source_version_id bigint NOT NULL,
 evidence_id bigint NOT NULL, category varchar(20) NOT NULL CHECK(category IN ('irreversible','causal','knowledge','open_issue','other')),
 statement text NOT NULL, statement_hash char(64) NOT NULL, introduced_chapter integer NOT NULL CHECK(introduced_chapter>0),
 actor_id bigint NOT NULL REFERENCES users(id), created_at timestamptz NOT NULL DEFAULT clock_timestamp(),
 UNIQUE(novel_id,branch_id,id), UNIQUE(branch_id,evidence_id,category,statement_hash),
 FOREIGN KEY(novel_id,branch_id,source_version_id) REFERENCES source_documents(novel_id,run_id,id),
 FOREIGN KEY(novel_id,source_version_id,evidence_id) REFERENCES evidence_spans(novel_id,source_version_id,id)
);
CREATE TABLE memory_items (
 id bigserial PRIMARY KEY, novel_id bigint NOT NULL, branch_id bigint NOT NULL, collection_id bigint NOT NULL,
 version integer NOT NULL CHECK(version>0), scope varchar(20) NOT NULL CHECK(scope='candidate'), revision_id bigint CHECK(revision_id IS NULL),
 status varchar(20) NOT NULL CHECK(status IN ('building','sealed')), summary text NOT NULL,
 introduced_chapter integer NOT NULL CHECK(introduced_chapter>0), visibility jsonb NOT NULL, importance integer NOT NULL CHECK(importance BETWEEN 1 AND 5),
 payload jsonb NOT NULL, content_hash char(64) NOT NULL, report jsonb NOT NULL,
 index_status varchar(30) NOT NULL DEFAULT 'not_requested' CHECK(index_status='not_requested'),
 retry_of_id bigint, reason text NOT NULL, actor_id bigint NOT NULL REFERENCES users(id), created_at timestamptz NOT NULL DEFAULT clock_timestamp(),
 UNIQUE(novel_id,branch_id,id), UNIQUE(novel_id,id), UNIQUE(collection_id,version),
 FOREIGN KEY(novel_id,branch_id,collection_id) REFERENCES memory_collections(novel_id,branch_id,id),
 FOREIGN KEY(novel_id,branch_id,retry_of_id) REFERENCES memory_items(novel_id,branch_id,id)
);
CREATE INDEX d04_memory_chapter ON memory_items(novel_id,branch_id,introduced_chapter,id);
CREATE TABLE memory_sources (
 id bigserial PRIMARY KEY, novel_id bigint NOT NULL, branch_id bigint NOT NULL, memory_id bigint NOT NULL,
 source_version_id bigint NOT NULL, source_hash char(64) NOT NULL, UNIQUE(memory_id,source_version_id),
 FOREIGN KEY(novel_id,branch_id,memory_id) REFERENCES memory_items(novel_id,branch_id,id),
 FOREIGN KEY(novel_id,branch_id,source_version_id) REFERENCES source_documents(novel_id,run_id,id)
);
CREATE INDEX d04_source_dependents ON memory_sources(source_version_id,memory_id);
CREATE TABLE memory_item_facts (
 id bigserial PRIMARY KEY, novel_id bigint NOT NULL, branch_id bigint NOT NULL, memory_id bigint NOT NULL, fact_id bigint NOT NULL,
 UNIQUE(memory_id,fact_id), FOREIGN KEY(novel_id,branch_id,memory_id) REFERENCES memory_items(novel_id,branch_id,id),
 FOREIGN KEY(novel_id,branch_id,fact_id) REFERENCES memory_facts(novel_id,branch_id,id)
);
CREATE TABLE memory_parents (
 id bigserial PRIMARY KEY, novel_id bigint NOT NULL, branch_id bigint NOT NULL, memory_id bigint NOT NULL, parent_memory_id bigint NOT NULL,
 parent_hash char(64) NOT NULL, CHECK(parent_memory_id<memory_id), UNIQUE(memory_id,parent_memory_id),
 FOREIGN KEY(novel_id,branch_id,memory_id) REFERENCES memory_items(novel_id,branch_id,id),
 FOREIGN KEY(novel_id,branch_id,parent_memory_id) REFERENCES memory_items(novel_id,branch_id,id)
);
CREATE INDEX d04_parent_dependents ON memory_parents(parent_memory_id,memory_id);
CREATE TABLE memory_chunks (
 id bigserial PRIMARY KEY, novel_id bigint NOT NULL, memory_id bigint NOT NULL, chunk_no integer NOT NULL CHECK(chunk_no>0),
 start_offset integer NOT NULL CHECK(start_offset>=0), end_offset integer NOT NULL CHECK(end_offset>start_offset),
 text text NOT NULL, content_hash char(64) NOT NULL, UNIQUE(memory_id,chunk_no),
 FOREIGN KEY(novel_id,memory_id) REFERENCES memory_items(novel_id,id)
);
CREATE TABLE memory_activations (
 id bigserial PRIMARY KEY, novel_id bigint NOT NULL, branch_id bigint NOT NULL, memory_id bigint NOT NULL UNIQUE,
 reason text NOT NULL, actor_id bigint NOT NULL REFERENCES users(id), created_at timestamptz NOT NULL DEFAULT clock_timestamp(),
 FOREIGN KEY(novel_id,branch_id,memory_id) REFERENCES memory_items(novel_id,branch_id,id)
);
CREATE TABLE memory_invalidations (
 id bigserial PRIMARY KEY, novel_id bigint NOT NULL, branch_id bigint NOT NULL, source_version_id bigint, memory_id bigint,
 reason text NOT NULL, actor_id bigint NOT NULL REFERENCES users(id), created_at timestamptz NOT NULL DEFAULT clock_timestamp(),
 CHECK((source_version_id IS NULL)<>(memory_id IS NULL)),
 UNIQUE(source_version_id), UNIQUE(memory_id),
 FOREIGN KEY(novel_id,branch_id,source_version_id) REFERENCES source_documents(novel_id,run_id,id),
 FOREIGN KEY(novel_id,branch_id,memory_id) REFERENCES memory_items(novel_id,branch_id,id)
);
CREATE OR REPLACE FUNCTION d04_seal_guard() RETURNS trigger LANGUAGE plpgsql AS $$
BEGIN
 IF TG_OP='DELETE' OR OLD.status='sealed' OR NEW.status<>'sealed' OR OLD.id<>NEW.id OR OLD.novel_id<>NEW.novel_id THEN RAISE EXCEPTION 'immutable memory version'; END IF;
 RETURN NEW;
END $$;
CREATE TRIGGER d04_seal BEFORE UPDATE OR DELETE ON memory_items FOR EACH ROW EXECUTE FUNCTION d04_seal_guard();
CREATE OR REPLACE FUNCTION d04_child_guard() RETURNS trigger LANGUAGE plpgsql AS $$
BEGIN
 IF NOT EXISTS(SELECT 1 FROM memory_items WHERE id=NEW.memory_id AND novel_id=NEW.novel_id AND status='building' FOR UPDATE) THEN RAISE EXCEPTION 'sealed memory children'; END IF;
 RETURN NEW;
END $$;
CREATE OR REPLACE FUNCTION d04_fact_guard() RETURNS trigger LANGUAGE plpgsql AS $$
BEGIN
 IF NOT EXISTS(SELECT 1 FROM evidence_spans WHERE id=NEW.evidence_id AND novel_id=NEW.novel_id AND source_version_id=NEW.source_version_id AND length(trim(NEW.statement))>0 AND position(NEW.statement IN quote)>0 AND encode(sha256(convert_to(NEW.statement,'UTF8')),'hex')=NEW.statement_hash) THEN RAISE EXCEPTION 'fact must retain exact source evidence'; END IF;
 RETURN NEW;
END $$;
CREATE TRIGGER d04_fact_check BEFORE INSERT ON memory_facts FOR EACH ROW EXECUTE FUNCTION d04_fact_guard();
CREATE OR REPLACE FUNCTION d04_activation_guard() RETURNS trigger LANGUAGE plpgsql AS $$
BEGIN
 IF NOT EXISTS(SELECT 1 FROM memory_items WHERE id=NEW.memory_id AND novel_id=NEW.novel_id AND branch_id=NEW.branch_id AND status='sealed' AND scope='candidate' AND report->>'passed'='true') THEN RAISE EXCEPTION 'memory validation required'; END IF;
 RETURN NEW;
END $$;
CREATE TRIGGER d04_activation_check BEFORE INSERT ON memory_activations FOR EACH ROW EXECUTE FUNCTION d04_activation_guard();
CREATE OR REPLACE FUNCTION d04_chunk_guard() RETURNS trigger LANGUAGE plpgsql AS $$
DECLARE body text;
BEGIN
 SELECT summary INTO body FROM memory_items WHERE id=NEW.memory_id AND novel_id=NEW.novel_id;
 IF NEW.end_offset>length(body) OR substring(body FROM NEW.start_offset+1 FOR NEW.end_offset-NEW.start_offset)<>NEW.text OR encode(sha256(convert_to(NEW.text,'UTF8')),'hex')<>NEW.content_hash THEN RAISE EXCEPTION 'invalid memory chunk'; END IF;
 RETURN NEW;
END $$;
CREATE TRIGGER d04_chunk_check BEFORE INSERT ON memory_chunks FOR EACH ROW EXECUTE FUNCTION d04_chunk_guard();
COMMENT ON TABLE memory_collections IS '分支内分层记忆稳定身份';
COMMENT ON COLUMN memory_collections.id IS '记录标识';
COMMENT ON COLUMN memory_collections.novel_id IS '所属小说';
COMMENT ON COLUMN memory_collections.branch_id IS '候选分支，绑定同书持久运行';
COMMENT ON COLUMN memory_collections.kind IS '工作、情节、语义或事件记忆';
COMMENT ON COLUMN memory_collections.name IS '记忆主题名称';
COMMENT ON COLUMN memory_collections.actor_id IS '操作作者';
COMMENT ON COLUMN memory_collections.created_at IS '数据库记录时间';
CREATE TRIGGER d04_immutable BEFORE UPDATE OR DELETE ON memory_collections FOR EACH ROW EXECUTE FUNCTION c01_immutable();
COMMENT ON TABLE memory_facts IS '证据支持的不可变事实清单';
COMMENT ON COLUMN memory_facts.id IS '记录标识';
COMMENT ON COLUMN memory_facts.novel_id IS '所属小说';
COMMENT ON COLUMN memory_facts.branch_id IS '候选分支，绑定同书持久运行';
COMMENT ON COLUMN memory_facts.source_version_id IS '强类型来源注册版本';
COMMENT ON COLUMN memory_facts.evidence_id IS '精确原文证据';
COMMENT ON COLUMN memory_facts.category IS '不可逆、因果、知情、未解或一般事实';
COMMENT ON COLUMN memory_facts.statement_hash IS '事实原文片段哈希，用于长文本去重';
COMMENT ON COLUMN memory_facts.statement IS '必须保留的精确原文事实片段';
COMMENT ON COLUMN memory_facts.introduced_chapter IS '最早可见章位，不是正式提交证明';
COMMENT ON COLUMN memory_facts.actor_id IS '操作作者';
COMMENT ON COLUMN memory_facts.created_at IS '数据库记录时间';
CREATE TRIGGER d04_immutable BEFORE UPDATE OR DELETE ON memory_facts FOR EACH ROW EXECUTE FUNCTION c01_immutable();
CREATE TRIGGER d04_audit AFTER INSERT ON memory_facts FOR EACH ROW EXECUTE FUNCTION c07_audit_capture();
COMMENT ON TABLE memory_items IS '候选分支的不可变记忆摘要版本';
COMMENT ON COLUMN memory_items.id IS '记录标识';
COMMENT ON COLUMN memory_items.novel_id IS '所属小说';
COMMENT ON COLUMN memory_items.branch_id IS '候选分支，绑定同书持久运行';
COMMENT ON COLUMN memory_items.collection_id IS '分层记忆稳定身份';
COMMENT ON COLUMN memory_items.version IS '主题内摘要版本号';
COMMENT ON COLUMN memory_items.scope IS '当前只允许候选分支';
COMMENT ON COLUMN memory_items.revision_id IS '正式基线尚未接入，必须为空';
COMMENT ON COLUMN memory_items.status IS '构建或封存状态';
COMMENT ON COLUMN memory_items.summary IS '摘要完整正文';
COMMENT ON COLUMN memory_items.introduced_chapter IS '最早可见章位，不是正式提交证明';
COMMENT ON COLUMN memory_items.visibility IS '作者声明的角色范围，不代表已验证知识';
COMMENT ON COLUMN memory_items.importance IS '重要度一至五';
COMMENT ON COLUMN memory_items.payload IS '完整冻结输入';
COMMENT ON COLUMN memory_items.content_hash IS '规范内容哈希';
COMMENT ON COLUMN memory_items.report IS '保留事实与来源复核报告';
COMMENT ON COLUMN memory_items.index_status IS '独立索引状态，D05前未请求';
COMMENT ON COLUMN memory_items.retry_of_id IS '失败重试来源版本';
COMMENT ON COLUMN memory_items.reason IS '操作原因';
COMMENT ON COLUMN memory_items.actor_id IS '操作作者';
COMMENT ON COLUMN memory_items.created_at IS '数据库记录时间';
CREATE TRIGGER d04_audit AFTER INSERT ON memory_items FOR EACH ROW EXECUTE FUNCTION c07_audit_capture();
COMMENT ON TABLE memory_sources IS '摘要展开后的完整来源链';
COMMENT ON COLUMN memory_sources.id IS '记录标识';
COMMENT ON COLUMN memory_sources.novel_id IS '所属小说';
COMMENT ON COLUMN memory_sources.branch_id IS '候选分支，绑定同书持久运行';
COMMENT ON COLUMN memory_sources.memory_id IS '不可变记忆版本';
COMMENT ON COLUMN memory_sources.source_version_id IS '强类型来源注册版本';
COMMENT ON COLUMN memory_sources.source_hash IS '来源规范正文哈希';
CREATE TRIGGER d04_immutable BEFORE UPDATE OR DELETE ON memory_sources FOR EACH ROW EXECUTE FUNCTION c01_immutable();
CREATE TRIGGER d04_child_check BEFORE INSERT ON memory_sources FOR EACH ROW EXECUTE FUNCTION d04_child_guard();
COMMENT ON TABLE memory_item_facts IS '摘要版本与事实清单关联';
COMMENT ON COLUMN memory_item_facts.id IS '记录标识';
COMMENT ON COLUMN memory_item_facts.novel_id IS '所属小说';
COMMENT ON COLUMN memory_item_facts.branch_id IS '候选分支，绑定同书持久运行';
COMMENT ON COLUMN memory_item_facts.memory_id IS '不可变记忆版本';
COMMENT ON COLUMN memory_item_facts.fact_id IS '必须保留的证据事实';
CREATE TRIGGER d04_immutable BEFORE UPDATE OR DELETE ON memory_item_facts FOR EACH ROW EXECUTE FUNCTION c01_immutable();
CREATE TRIGGER d04_child_check BEFORE INSERT ON memory_item_facts FOR EACH ROW EXECUTE FUNCTION d04_child_guard();
COMMENT ON TABLE memory_parents IS '摘要压缩父版本与哈希链';
COMMENT ON COLUMN memory_parents.id IS '记录标识';
COMMENT ON COLUMN memory_parents.novel_id IS '所属小说';
COMMENT ON COLUMN memory_parents.branch_id IS '候选分支，绑定同书持久运行';
COMMENT ON COLUMN memory_parents.memory_id IS '不可变记忆版本';
COMMENT ON COLUMN memory_parents.parent_memory_id IS '被压缩或修订的父记忆';
COMMENT ON COLUMN memory_parents.parent_hash IS '父版本规范内容哈希';
CREATE TRIGGER d04_immutable BEFORE UPDATE OR DELETE ON memory_parents FOR EACH ROW EXECUTE FUNCTION c01_immutable();
CREATE TRIGGER d04_child_check BEFORE INSERT ON memory_parents FOR EACH ROW EXECUTE FUNCTION d04_child_guard();
COMMENT ON TABLE memory_chunks IS '按Unicode码点定位的记忆分块';
COMMENT ON COLUMN memory_chunks.id IS '记录标识';
COMMENT ON COLUMN memory_chunks.novel_id IS '所属小说';
COMMENT ON COLUMN memory_chunks.memory_id IS '不可变记忆版本';
COMMENT ON COLUMN memory_chunks.chunk_no IS '块序号，从一开始';
COMMENT ON COLUMN memory_chunks.start_offset IS '起始码点，包含';
COMMENT ON COLUMN memory_chunks.end_offset IS '结束码点，不包含';
COMMENT ON COLUMN memory_chunks.text IS '分块完整文本';
COMMENT ON COLUMN memory_chunks.content_hash IS '规范内容哈希';
CREATE TRIGGER d04_immutable BEFORE UPDATE OR DELETE ON memory_chunks FOR EACH ROW EXECUTE FUNCTION c01_immutable();
CREATE TRIGGER d04_child_check BEFORE INSERT ON memory_chunks FOR EACH ROW EXECUTE FUNCTION d04_child_guard();
COMMENT ON TABLE memory_activations IS '候选分支内验证通过版本的追加激活记录';
COMMENT ON COLUMN memory_activations.id IS '记录标识';
COMMENT ON COLUMN memory_activations.novel_id IS '所属小说';
COMMENT ON COLUMN memory_activations.branch_id IS '候选分支，绑定同书持久运行';
COMMENT ON COLUMN memory_activations.memory_id IS '不可变记忆版本';
COMMENT ON COLUMN memory_activations.reason IS '操作原因';
COMMENT ON COLUMN memory_activations.actor_id IS '操作作者';
COMMENT ON COLUMN memory_activations.created_at IS '数据库记录时间';
CREATE TRIGGER d04_immutable BEFORE UPDATE OR DELETE ON memory_activations FOR EACH ROW EXECUTE FUNCTION c01_immutable();
CREATE TRIGGER d04_audit AFTER INSERT ON memory_activations FOR EACH ROW EXECUTE FUNCTION c07_audit_capture();
COMMENT ON TABLE memory_invalidations IS '来源或错误派生记忆的不可逆停用依据';
COMMENT ON COLUMN memory_invalidations.id IS '记录标识';
COMMENT ON COLUMN memory_invalidations.novel_id IS '所属小说';
COMMENT ON COLUMN memory_invalidations.branch_id IS '候选分支，绑定同书持久运行';
COMMENT ON COLUMN memory_invalidations.source_version_id IS '强类型来源注册版本';
COMMENT ON COLUMN memory_invalidations.memory_id IS '不可变记忆版本';
COMMENT ON COLUMN memory_invalidations.reason IS '操作原因';
COMMENT ON COLUMN memory_invalidations.actor_id IS '操作作者';
COMMENT ON COLUMN memory_invalidations.created_at IS '数据库记录时间';
CREATE TRIGGER d04_immutable BEFORE UPDATE OR DELETE ON memory_invalidations FOR EACH ROW EXECUTE FUNCTION c01_immutable();
CREATE TRIGGER d04_audit AFTER INSERT ON memory_invalidations FOR EACH ROW EXECUTE FUNCTION c07_audit_capture();
SQL);
    }

    public function down(): void
    {
        // 按来源依赖逆序回滚，不修改既有正文证据。
        DB::unprepared('DROP TABLE memory_invalidations,memory_activations,memory_chunks,memory_parents,memory_item_facts,memory_sources,memory_items,memory_facts,memory_collections; ALTER TABLE evidence_spans DROP CONSTRAINT d04_evidence_source; ALTER TABLE source_documents DROP CONSTRAINT d04_source_branch; DROP FUNCTION d04_chunk_guard(),d04_activation_guard(),d04_fact_guard(),d04_child_guard(),d04_seal_guard();');
    }
};
