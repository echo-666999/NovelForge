<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    // 首期使用精确向量搜索，维度由档案冻结，不创建假定维度的ANN索引。
    public function up(): void
    {
        DB::unprepared(<<<'SQL'

CREATE TABLE embedding_profiles (
 id bigserial PRIMARY KEY, name varchar(150) NOT NULL, version integer NOT NULL CHECK(version>0),
 model_profile_version_id bigint REFERENCES model_profile_versions(id), encoder varchar(30) NOT NULL CHECK(encoder IN ('offline_hash_v1','external_disabled')),
 dimensions integer NOT NULL CHECK(dimensions BETWEEN 2 AND 4096), distance_metric varchar(20) NOT NULL CHECK(distance_metric IN ('cosine','l2')),
 content_hash char(64) NOT NULL, actor_id bigint NOT NULL REFERENCES users(id), reason text NOT NULL,
 created_at timestamptz NOT NULL DEFAULT clock_timestamp(), UNIQUE(name,version),
 CHECK((encoder='offline_hash_v1' AND model_profile_version_id IS NULL) OR (encoder='external_disabled' AND model_profile_version_id IS NOT NULL))
);
CREATE TABLE index_builds (
 id bigserial PRIMARY KEY, novel_id bigint NOT NULL, branch_id bigint NOT NULL, generation integer NOT NULL CHECK(generation>0),
 profile_id bigint NOT NULL REFERENCES embedding_profiles(id), scope varchar(20) NOT NULL CHECK(scope='candidate'), revision_id bigint CHECK(revision_id IS NULL),
 status varchar(20) NOT NULL CHECK(status IN ('building','validated','active','retired')), manifest_hash char(64), metrics jsonb NOT NULL DEFAULT '{}',
 actor_id bigint NOT NULL REFERENCES users(id), reason text NOT NULL, created_at timestamptz NOT NULL DEFAULT clock_timestamp(),
 CHECK(status='building' OR manifest_hash IS NOT NULL),
 UNIQUE(novel_id,id), UNIQUE(novel_id,branch_id,id), UNIQUE(id,profile_id), UNIQUE(novel_id,branch_id,generation),
 FOREIGN KEY(novel_id,branch_id) REFERENCES generation_runs(novel_id,id)
);
CREATE UNIQUE INDEX d05_one_active ON index_builds(novel_id,branch_id) WHERE status='active';
CREATE TABLE search_documents (
 id bigserial PRIMARY KEY, novel_id bigint NOT NULL, branch_id bigint NOT NULL, index_build_id bigint NOT NULL,
 source_version_id bigint NOT NULL, evidence_id bigint, event_id bigint, memory_id bigint, chunk_id bigint,
 source_hash char(64) NOT NULL, body text NOT NULL, title text NOT NULL, aliases jsonb NOT NULL,
 normalized_text text NOT NULL, introduced_chapter integer NOT NULL CHECK(introduced_chapter>0), content_hash char(64) NOT NULL,
 created_at timestamptz NOT NULL DEFAULT clock_timestamp(), UNIQUE(novel_id,id), UNIQUE(index_build_id,id), UNIQUE(index_build_id,content_hash),
 FOREIGN KEY(novel_id,branch_id,index_build_id) REFERENCES index_builds(novel_id,branch_id,id),
 FOREIGN KEY(novel_id,branch_id,source_version_id) REFERENCES source_documents(novel_id,run_id,id),
 FOREIGN KEY(novel_id,source_version_id,evidence_id) REFERENCES evidence_spans(novel_id,source_version_id,id),
 FOREIGN KEY(novel_id,event_id) REFERENCES proposed_events(novel_id,id),
 FOREIGN KEY(novel_id,branch_id,memory_id) REFERENCES memory_items(novel_id,branch_id,id),
 FOREIGN KEY(chunk_id) REFERENCES memory_chunks(id),
 CHECK((memory_id IS NULL)=(chunk_id IS NULL)), CHECK((memory_id IS NULL AND evidence_id IS NOT NULL) OR (memory_id IS NOT NULL AND event_id IS NULL))
);
CREATE INDEX d05_search_filter ON search_documents(novel_id,branch_id,index_build_id,introduced_chapter,id);
CREATE INDEX d05_search_trgm ON search_documents USING gin(normalized_text gin_trgm_ops);
CREATE INDEX d05_source_lookup ON search_documents(source_version_id,index_build_id);
CREATE TABLE memory_embeddings (
 id bigserial PRIMARY KEY, index_build_id bigint NOT NULL, profile_id bigint NOT NULL, document_id bigint NOT NULL,
 input_hash char(64) NOT NULL, embedding vector NOT NULL, origin varchar(30) NOT NULL CHECK(origin='offline_hash_v1'),
 created_at timestamptz NOT NULL DEFAULT clock_timestamp(), UNIQUE(index_build_id,document_id),
 FOREIGN KEY(index_build_id,profile_id) REFERENCES index_builds(id,profile_id),
 FOREIGN KEY(index_build_id,document_id) REFERENCES search_documents(index_build_id,id)
);
CREATE INDEX d05_vector_scope ON memory_embeddings(index_build_id,profile_id,document_id);
CREATE TABLE search_evaluations (
 id bigserial PRIMARY KEY, novel_id bigint NOT NULL, index_build_id bigint NOT NULL, manifest_hash char(64) NOT NULL,
 corpus_hash char(64) NOT NULL, queries_hash char(64) NOT NULL, report jsonb NOT NULL,
 actor_id bigint NOT NULL REFERENCES users(id), created_at timestamptz NOT NULL DEFAULT clock_timestamp(),
 FOREIGN KEY(novel_id,index_build_id) REFERENCES index_builds(novel_id,id)
);
CREATE OR REPLACE FUNCTION d05_build_guard() RETURNS trigger LANGUAGE plpgsql AS $$
BEGIN
 IF TG_OP='DELETE' OR (to_jsonb(NEW)-'status'-'manifest_hash'-'metrics')<>(to_jsonb(OLD)-'status'-'manifest_hash'-'metrics') THEN RAISE EXCEPTION 'immutable index identity'; END IF;
 IF NOT ((OLD.status='building' AND NEW.status='validated') OR (OLD.status='validated' AND NEW.status='active') OR (OLD.status='active' AND NEW.status='retired') OR (OLD.status='retired' AND NEW.status='active')) THEN RAISE EXCEPTION 'invalid index transition'; END IF;
 IF OLD.status<>'building' AND (NEW.manifest_hash IS DISTINCT FROM OLD.manifest_hash OR NEW.metrics<>OLD.metrics) THEN RAISE EXCEPTION 'immutable validated manifest'; END IF;
 RETURN NEW;
END $$;
CREATE TRIGGER d05_build_change BEFORE UPDATE OR DELETE ON index_builds FOR EACH ROW EXECUTE FUNCTION d05_build_guard();
CREATE OR REPLACE FUNCTION d05_document_guard() RETURNS trigger LANGUAGE plpgsql AS $$
BEGIN
 IF NOT EXISTS(SELECT 1 FROM index_builds WHERE id=NEW.index_build_id AND status='building' FOR UPDATE) THEN RAISE EXCEPTION 'sealed search index'; END IF;
 IF NOT EXISTS(SELECT 1 FROM source_documents WHERE id=NEW.source_version_id AND novel_id=NEW.novel_id AND run_id=NEW.branch_id AND source_hash=NEW.source_hash) THEN RAISE EXCEPTION 'invalid search source'; END IF;
 IF NEW.memory_id IS NULL AND NOT EXISTS(SELECT 1 FROM evidence_spans WHERE id=NEW.evidence_id AND source_version_id=NEW.source_version_id AND novel_id=NEW.novel_id AND quote=NEW.body) THEN RAISE EXCEPTION 'search body must equal evidence'; END IF;
 IF NEW.memory_id IS NOT NULL AND NOT EXISTS(SELECT 1 FROM memory_chunks WHERE id=NEW.chunk_id AND memory_id=NEW.memory_id AND novel_id=NEW.novel_id AND text=NEW.body) THEN RAISE EXCEPTION 'invalid memory chunk'; END IF;
 IF NEW.event_id IS NOT NULL AND NOT EXISTS(SELECT 1 FROM proposed_events WHERE id=NEW.event_id AND novel_id=NEW.novel_id AND evidence_id=NEW.evidence_id AND jsonb_typeof(value)='string' AND value#>>'{}'=NEW.body AND chapter_sequence=NEW.introduced_chapter) THEN RAISE EXCEPTION 'event disclosure scope mismatch'; END IF;
 IF jsonb_typeof(NEW.aliases)<>'array' THEN RAISE EXCEPTION 'aliases must be array'; END IF;
 IF NEW.event_id IS NOT NULL AND (position(NEW.title IN NEW.body)=0 OR EXISTS(SELECT 1 FROM jsonb_array_elements_text(NEW.aliases) a WHERE position(a IN NEW.body)=0)) THEN RAISE EXCEPTION 'event labels disclose extra text'; END IF;
 RETURN NEW;
END $$;
CREATE TRIGGER d05_document_check BEFORE INSERT ON search_documents FOR EACH ROW EXECUTE FUNCTION d05_document_guard();
CREATE OR REPLACE FUNCTION d05_embedding_guard() RETURNS trigger LANGUAGE plpgsql AS $$
DECLARE dims integer;
BEGIN
 SELECT p.dimensions INTO dims FROM embedding_profiles p JOIN index_builds b ON b.profile_id=p.id WHERE b.id=NEW.index_build_id AND p.id=NEW.profile_id AND b.status='building' AND p.encoder='offline_hash_v1' FOR UPDATE OF b;
 IF dims IS NULL OR vector_dims(NEW.embedding)<>dims OR vector_norm(NEW.embedding)=0 THEN RAISE EXCEPTION 'embedding profile or dimensions mismatch'; END IF;
 IF NOT EXISTS(SELECT 1 FROM search_documents WHERE id=NEW.document_id AND index_build_id=NEW.index_build_id AND encode(sha256(convert_to(normalized_text,'UTF8')),'hex')=NEW.input_hash) THEN RAISE EXCEPTION 'embedding input hash mismatch'; END IF;
 RETURN NEW;
END $$;
CREATE TRIGGER d05_embedding_check BEFORE INSERT ON memory_embeddings FOR EACH ROW EXECUTE FUNCTION d05_embedding_guard();
COMMENT ON TABLE embedding_profiles IS '不可变模型维度距离档案';
COMMENT ON COLUMN embedding_profiles.id IS '记录标识';
COMMENT ON COLUMN embedding_profiles.name IS '档案名称';
COMMENT ON COLUMN embedding_profiles.version IS '同名版本号';
COMMENT ON COLUMN embedding_profiles.model_profile_version_id IS '外部模型具体版本，离线哈希为空';
COMMENT ON COLUMN embedding_profiles.encoder IS '离线哈希或未开放外部执行器';
COMMENT ON COLUMN embedding_profiles.dimensions IS '向量维度';
COMMENT ON COLUMN embedding_profiles.distance_metric IS '余弦或欧氏距离';
COMMENT ON COLUMN embedding_profiles.content_hash IS '规范内容哈希';
COMMENT ON COLUMN embedding_profiles.actor_id IS '操作作者';
COMMENT ON COLUMN embedding_profiles.reason IS '操作原因';
COMMENT ON COLUMN embedding_profiles.created_at IS '记录创建时间';
CREATE TRIGGER d05_immutable BEFORE UPDATE OR DELETE ON embedding_profiles FOR EACH ROW EXECUTE FUNCTION c01_immutable();
CREATE TRIGGER d05_audit AFTER INSERT ON embedding_profiles FOR EACH ROW EXECUTE FUNCTION c07_audit_capture();
COMMENT ON TABLE index_builds IS '按小说与候选分支隔离的索引代次';
COMMENT ON COLUMN index_builds.id IS '记录标识';
COMMENT ON COLUMN index_builds.novel_id IS '所属小说';
COMMENT ON COLUMN index_builds.branch_id IS '候选持久运行分支';
COMMENT ON COLUMN index_builds.generation IS '分支内代次数';
COMMENT ON COLUMN index_builds.profile_id IS '不可变嵌入档案';
COMMENT ON COLUMN index_builds.scope IS '当前只允许候选范围';
COMMENT ON COLUMN index_builds.revision_id IS '正式基线未接入，必须为空';
COMMENT ON COLUMN index_builds.status IS '构建、验证、活动或退役';
COMMENT ON COLUMN index_builds.manifest_hash IS '冻结索引清单哈希';
COMMENT ON COLUMN index_builds.metrics IS '结构校验指标，不冒充模型质量评测';
COMMENT ON COLUMN index_builds.actor_id IS '操作作者';
COMMENT ON COLUMN index_builds.reason IS '操作原因';
COMMENT ON COLUMN index_builds.created_at IS '记录创建时间';
CREATE TRIGGER d05_audit AFTER INSERT OR UPDATE ON index_builds FOR EACH ROW EXECUTE FUNCTION c07_audit_capture();
COMMENT ON TABLE search_documents IS '绑定精确证据或记忆块的检索文档';
COMMENT ON COLUMN search_documents.id IS '记录标识';
COMMENT ON COLUMN search_documents.novel_id IS '所属小说';
COMMENT ON COLUMN search_documents.branch_id IS '候选持久运行分支';
COMMENT ON COLUMN search_documents.index_build_id IS '索引代次标识';
COMMENT ON COLUMN search_documents.source_version_id IS '强类型正文来源';
COMMENT ON COLUMN search_documents.evidence_id IS '精确原文证据';
COMMENT ON COLUMN search_documents.event_id IS '可用于候选知识校验的事件';
COMMENT ON COLUMN search_documents.memory_id IS '候选记忆版本';
COMMENT ON COLUMN search_documents.chunk_id IS '候选记忆分块';
COMMENT ON COLUMN search_documents.source_hash IS '来源正文哈希';
COMMENT ON COLUMN search_documents.body IS '精确证据引文或记忆块';
COMMENT ON COLUMN search_documents.title IS '文档标题';
COMMENT ON COLUMN search_documents.aliases IS '受控别名集合';
COMMENT ON COLUMN search_documents.normalized_text IS '无分词的字符规范化检索文本';
COMMENT ON COLUMN search_documents.introduced_chapter IS '最早可见章位';
COMMENT ON COLUMN search_documents.content_hash IS '规范内容哈希';
COMMENT ON COLUMN search_documents.created_at IS '记录创建时间';
CREATE TRIGGER d05_immutable BEFORE UPDATE OR DELETE ON search_documents FOR EACH ROW EXECUTE FUNCTION c01_immutable();
COMMENT ON TABLE memory_embeddings IS '隔离档案与代次的离线检索向量';
COMMENT ON COLUMN memory_embeddings.id IS '记录标识';
COMMENT ON COLUMN memory_embeddings.index_build_id IS '索引代次标识';
COMMENT ON COLUMN memory_embeddings.profile_id IS '不可变嵌入档案';
COMMENT ON COLUMN memory_embeddings.document_id IS '来源检索文档';
COMMENT ON COLUMN memory_embeddings.input_hash IS '编码输入文本哈希';
COMMENT ON COLUMN memory_embeddings.embedding IS '维度由档案校验的pgvector向量';
COMMENT ON COLUMN memory_embeddings.origin IS '明确离线测试来源，不冒充真实语义模型';
COMMENT ON COLUMN memory_embeddings.created_at IS '记录创建时间';
CREATE TRIGGER d05_immutable BEFORE UPDATE OR DELETE ON memory_embeddings FOR EACH ROW EXECUTE FUNCTION c01_immutable();
COMMENT ON TABLE search_evaluations IS '固定语料与标注查询的不可变评测报告';
COMMENT ON COLUMN search_evaluations.id IS '记录标识';
COMMENT ON COLUMN search_evaluations.novel_id IS '所属小说';
COMMENT ON COLUMN search_evaluations.index_build_id IS '索引代次标识';
COMMENT ON COLUMN search_evaluations.manifest_hash IS '冻结索引清单哈希';
COMMENT ON COLUMN search_evaluations.corpus_hash IS '评测语料文件哈希';
COMMENT ON COLUMN search_evaluations.queries_hash IS '查询标注文件哈希';
COMMENT ON COLUMN search_evaluations.report IS '逐查询召回、泄露和通道贡献';
COMMENT ON COLUMN search_evaluations.actor_id IS '操作作者';
COMMENT ON COLUMN search_evaluations.created_at IS '记录创建时间';
CREATE TRIGGER d05_immutable BEFORE UPDATE OR DELETE ON search_evaluations FOR EACH ROW EXECUTE FUNCTION c01_immutable();
CREATE TRIGGER d05_audit AFTER INSERT ON search_evaluations FOR EACH ROW EXECUTE FUNCTION c07_audit_capture();
SQL);
    }

    public function down(): void
    {
        // 逆依赖回滚，不删除正文来源和记忆。
        DB::unprepared('DROP TABLE search_evaluations,memory_embeddings,search_documents,index_builds,embedding_profiles; DROP FUNCTION d05_embedding_guard(),d05_document_guard(),d05_build_guard();');
    }
};
