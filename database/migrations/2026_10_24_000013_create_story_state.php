<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    // 先建立候选来源链；正式提交表尚不存在，使用数据库硬门禁阻止孤立正式事实。
    public function up(): void
    {
        DB::unprepared(<<<'SQL'
CREATE TABLE source_documents (
 id bigserial PRIMARY KEY, novel_id bigint NOT NULL REFERENCES novels(id), run_id bigint NOT NULL,
 artifact_id bigint NOT NULL, kind varchar(30) NOT NULL CHECK(kind='artifact'),
 body text NOT NULL, source_hash char(64) NOT NULL, normalization varchar(40) NOT NULL CHECK(normalization='body-lf-utf8-sha256-v1'),
 created_at timestamptz NOT NULL DEFAULT clock_timestamp(), UNIQUE(novel_id,id), UNIQUE(artifact_id),
 FOREIGN KEY(novel_id,run_id,artifact_id) REFERENCES generation_artifacts(novel_id,run_id,id)
);
CREATE TABLE evidence_spans (
 id bigserial PRIMARY KEY, novel_id bigint NOT NULL, source_version_id bigint NOT NULL,
 source_hash char(64) NOT NULL, start_offset integer NOT NULL, end_offset integer NOT NULL,
 quote text NOT NULL, offset_unit varchar(30) NOT NULL CHECK(offset_unit='unicode_codepoint'),
 created_at timestamptz NOT NULL DEFAULT clock_timestamp(), UNIQUE(novel_id,id),
 CHECK(start_offset>=0 AND end_offset>start_offset),
 FOREIGN KEY(novel_id,source_version_id) REFERENCES source_documents(novel_id,id)
);
CREATE TABLE proposed_events (
 id bigserial PRIMARY KEY, novel_id bigint NOT NULL REFERENCES novels(id), evidence_id bigint NOT NULL,
 entity_id bigint NOT NULL, definition_version_id bigint NOT NULL, holder_id bigint,
 fact_kind varchar(30) NOT NULL CHECK(fact_kind IN ('fact','belief','rumor','lie','inference','hidden_truth')),
 action varchar(20) NOT NULL CHECK(action IN ('set','revoke','replace')), supersedes_id bigint,
 chapter_sequence integer NOT NULL CHECK(chapter_sequence>0), narrative_order integer NOT NULL CHECK(narrative_order>0),
 attribute varchar(80) NOT NULL, value jsonb NOT NULL, story_time jsonb NOT NULL DEFAULT '{}',
 confidence varchar(20) NOT NULL CHECK(confidence IN ('confirmed','uncertain')),
 content_hash char(64) NOT NULL, reason text NOT NULL, actor_id bigint NOT NULL REFERENCES users(id),
 created_at timestamptz NOT NULL DEFAULT clock_timestamp(), UNIQUE(novel_id,id),
 FOREIGN KEY(novel_id,evidence_id) REFERENCES evidence_spans(novel_id,id),
 FOREIGN KEY(novel_id,entity_id,definition_version_id) REFERENCES entity_definition_versions(novel_id,entity_id,id),
 FOREIGN KEY(novel_id,holder_id) REFERENCES entities(novel_id,id),
 FOREIGN KEY(novel_id,supersedes_id) REFERENCES proposed_events(novel_id,id),
 CHECK((action='set' AND supersedes_id IS NULL) OR (action IN ('revoke','replace') AND supersedes_id IS NOT NULL)),
 CHECK((fact_kind IN ('fact','hidden_truth') AND holder_id IS NULL) OR (fact_kind IN ('belief','rumor','lie','inference') AND holder_id IS NOT NULL))
);
CREATE INDEX d01_event_chapter ON proposed_events(novel_id,chapter_sequence,narrative_order,id);
CREATE TABLE proposed_state_patches (
 id bigserial PRIMARY KEY, novel_id bigint NOT NULL, event_id bigint NOT NULL UNIQUE,
 patch jsonb NOT NULL, patch_hash char(64) NOT NULL,
 FOREIGN KEY(novel_id,event_id) REFERENCES proposed_events(novel_id,id), UNIQUE(novel_id,id)
);
CREATE TABLE story_events (
 id bigserial PRIMARY KEY, novel_id bigint NOT NULL REFERENCES novels(id), proposed_event_id bigint NOT NULL,
 revision_id bigint NOT NULL, commit_id bigint NOT NULL, chapter_version_id bigint NOT NULL,
 evidence_id bigint NOT NULL, narrative_order integer NOT NULL, payload jsonb NOT NULL, supersedes_id bigint,
 created_at timestamptz NOT NULL DEFAULT clock_timestamp(), UNIQUE(novel_id,id),
 FOREIGN KEY(novel_id,proposed_event_id) REFERENCES proposed_events(novel_id,id),
 FOREIGN KEY(novel_id,evidence_id) REFERENCES evidence_spans(novel_id,id),
 FOREIGN KEY(novel_id,supersedes_id) REFERENCES story_events(novel_id,id),
 CONSTRAINT d01_formal_entry_closed CHECK(false)
);
CREATE INDEX d01_formal_order ON story_events(novel_id,revision_id,narrative_order);
CREATE TABLE state_snapshots (
 id bigserial PRIMARY KEY, novel_id bigint NOT NULL REFERENCES novels(id), stream_hash char(64) NOT NULL,
 chapter_sequence integer NOT NULL CHECK(chapter_sequence>0), local_state_version integer NOT NULL,
 scope varchar(20) NOT NULL CHECK(scope='candidate'), payload jsonb NOT NULL, payload_hash char(64) NOT NULL,
 event_ids jsonb NOT NULL, created_at timestamptz NOT NULL DEFAULT clock_timestamp(),
 UNIQUE(novel_id,stream_hash,chapter_sequence), UNIQUE(novel_id,id)
);
CREATE TABLE entity_state_versions (
 id bigserial PRIMARY KEY, novel_id bigint NOT NULL, snapshot_id bigint NOT NULL, entity_id bigint NOT NULL,
 attributes jsonb NOT NULL, UNIQUE(snapshot_id,entity_id),
 FOREIGN KEY(novel_id,snapshot_id) REFERENCES state_snapshots(novel_id,id) ON DELETE CASCADE,
 FOREIGN KEY(novel_id,entity_id) REFERENCES entities(novel_id,id)
);
CREATE TRIGGER d01_immutable BEFORE UPDATE OR DELETE ON source_documents FOR EACH ROW EXECUTE FUNCTION c01_immutable();
CREATE TRIGGER d01_immutable BEFORE UPDATE OR DELETE ON evidence_spans FOR EACH ROW EXECUTE FUNCTION c01_immutable();
CREATE TRIGGER d01_immutable BEFORE UPDATE OR DELETE ON proposed_events FOR EACH ROW EXECUTE FUNCTION c01_immutable();
CREATE TRIGGER d01_immutable BEFORE UPDATE OR DELETE ON proposed_state_patches FOR EACH ROW EXECUTE FUNCTION c01_immutable();
CREATE TRIGGER d01_immutable BEFORE UPDATE OR DELETE ON story_events FOR EACH ROW EXECUTE FUNCTION c01_immutable();
-- 数据库也核验正文和证据，防止绕过应用服务插入伪造引文。
CREATE OR REPLACE FUNCTION d01_validate_source() RETURNS trigger LANGUAGE plpgsql AS $$
DECLARE original text;
BEGIN
 SELECT content->>'text' INTO original FROM generation_artifacts WHERE novel_id=NEW.novel_id AND run_id=NEW.run_id AND id=NEW.artifact_id;
 IF original IS NULL OR NEW.body IS DISTINCT FROM replace(replace(original,E'\r\n',E'\n'),E'\r',E'\n') OR NEW.source_hash IS DISTINCT FROM encode(sha256(convert_to(NEW.body,'UTF8')),'hex') THEN
  RAISE EXCEPTION 'invalid source body or hash';
 END IF;
 RETURN NEW;
END $$;
CREATE TRIGGER d01_source_check BEFORE INSERT ON source_documents FOR EACH ROW EXECUTE FUNCTION d01_validate_source();
CREATE OR REPLACE FUNCTION d01_validate_evidence() RETURNS trigger LANGUAGE plpgsql AS $$
DECLARE source source_documents;
BEGIN
 SELECT * INTO source FROM source_documents WHERE novel_id=NEW.novel_id AND id=NEW.source_version_id;
 IF source.id IS NULL OR NEW.source_hash IS DISTINCT FROM source.source_hash OR NEW.start_offset<0 OR NEW.end_offset<=NEW.start_offset OR NEW.end_offset>char_length(source.body) THEN
  RAISE EXCEPTION 'invalid evidence range or hash';
 END IF;
 IF NEW.quote IS DISTINCT FROM substring(source.body FROM NEW.start_offset+1 FOR NEW.end_offset-NEW.start_offset) THEN
  RAISE EXCEPTION 'invalid evidence quote';
 END IF;
 RETURN NEW;
END $$;
CREATE TRIGGER d01_evidence_check BEFORE INSERT ON evidence_spans FOR EACH ROW EXECUTE FUNCTION d01_validate_evidence();
COMMENT ON TABLE source_documents IS '不可变正文来源注册表，当前仅接入运行产物';
COMMENT ON COLUMN source_documents.id IS '记录标识';
COMMENT ON COLUMN source_documents.novel_id IS '所属小说';
COMMENT ON COLUMN source_documents.run_id IS '来源运行标识';
COMMENT ON COLUMN source_documents.artifact_id IS '不可变来源产物标识';
COMMENT ON COLUMN source_documents.kind IS '来源类型';
COMMENT ON COLUMN source_documents.body IS '规范化后的完整正文';
COMMENT ON COLUMN source_documents.source_hash IS '规范化正文哈希';
COMMENT ON COLUMN source_documents.normalization IS '正文规范化版本';
COMMENT ON COLUMN source_documents.created_at IS '数据库记录时间';
COMMENT ON TABLE evidence_spans IS '按 Unicode 码点定位的不可变原文证据';
COMMENT ON COLUMN evidence_spans.id IS '记录标识';
COMMENT ON COLUMN evidence_spans.novel_id IS '所属小说';
COMMENT ON COLUMN evidence_spans.source_version_id IS '来源注册表版本标识';
COMMENT ON COLUMN evidence_spans.source_hash IS '规范化正文哈希';
COMMENT ON COLUMN evidence_spans.start_offset IS '起始码点偏移，包含';
COMMENT ON COLUMN evidence_spans.end_offset IS '结束码点偏移，不包含';
COMMENT ON COLUMN evidence_spans.quote IS '原文精确引文';
COMMENT ON COLUMN evidence_spans.offset_unit IS '偏移计量单位';
COMMENT ON COLUMN evidence_spans.created_at IS '数据库记录时间';
COMMENT ON TABLE proposed_events IS '不可变候选事件，不能视为正式事实';
COMMENT ON COLUMN proposed_events.id IS '记录标识';
COMMENT ON COLUMN proposed_events.novel_id IS '所属小说';
COMMENT ON COLUMN proposed_events.evidence_id IS '正文证据标识';
COMMENT ON COLUMN proposed_events.entity_id IS '被描述实体标识';
COMMENT ON COLUMN proposed_events.definition_version_id IS '批准的实体设定版本';
COMMENT ON COLUMN proposed_events.holder_id IS '持有信念或说法的角色';
COMMENT ON COLUMN proposed_events.fact_kind IS '事实类别，严禁隐式提升';
COMMENT ON COLUMN proposed_events.action IS '设置、撤销或替代动作';
COMMENT ON COLUMN proposed_events.supersedes_id IS '被撤销或替代的旧事件';
COMMENT ON COLUMN proposed_events.chapter_sequence IS '叙述章序';
COMMENT ON COLUMN proposed_events.narrative_order IS '章内叙述顺序';
COMMENT ON COLUMN proposed_events.attribute IS '状态属性名称';
COMMENT ON COLUMN proposed_events.value IS '状态值';
COMMENT ON COLUMN proposed_events.story_time IS '发生时间原始信息，语义校验待 D02';
COMMENT ON COLUMN proposed_events.confidence IS '明确或待确认，不作为事实升级依据';
COMMENT ON COLUMN proposed_events.content_hash IS '规范化事件内容哈希';
COMMENT ON COLUMN proposed_events.reason IS '记录或修正原因';
COMMENT ON COLUMN proposed_events.actor_id IS '操作用户';
COMMENT ON COLUMN proposed_events.created_at IS '数据库记录时间';
COMMENT ON TABLE proposed_state_patches IS '候选事件对应的不可变状态补丁';
COMMENT ON COLUMN proposed_state_patches.id IS '记录标识';
COMMENT ON COLUMN proposed_state_patches.novel_id IS '所属小说';
COMMENT ON COLUMN proposed_state_patches.event_id IS '对应候选事件';
COMMENT ON COLUMN proposed_state_patches.patch IS '确定性状态补丁';
COMMENT ON COLUMN proposed_state_patches.patch_hash IS '补丁内容哈希';
COMMENT ON TABLE story_events IS '正式事件预备结构，E06 接入前禁止插入';
COMMENT ON COLUMN story_events.id IS '记录标识';
COMMENT ON COLUMN story_events.novel_id IS '所属小说';
COMMENT ON COLUMN story_events.proposed_event_id IS '来源候选事件';
COMMENT ON COLUMN story_events.revision_id IS '未来正式基线标识，E06 补外键前不可写';
COMMENT ON COLUMN story_events.commit_id IS '未来正式提交标识，E06 补外键前不可写';
COMMENT ON COLUMN story_events.chapter_version_id IS '未来正文版本标识，E06 补外键前不可写';
COMMENT ON COLUMN story_events.evidence_id IS '正文证据标识';
COMMENT ON COLUMN story_events.narrative_order IS '章内叙述顺序';
COMMENT ON COLUMN story_events.payload IS '完整状态或正式事件载荷';
COMMENT ON COLUMN story_events.supersedes_id IS '被撤销或替代的旧事件';
COMMENT ON COLUMN story_events.created_at IS '数据库记录时间';
COMMENT ON TABLE state_snapshots IS '候选回放快照，可从事件重建';
COMMENT ON COLUMN state_snapshots.id IS '记录标识';
COMMENT ON COLUMN state_snapshots.novel_id IS '所属小说';
COMMENT ON COLUMN state_snapshots.stream_hash IS '有序来源事件流哈希';
COMMENT ON COLUMN state_snapshots.chapter_sequence IS '叙述章序';
COMMENT ON COLUMN state_snapshots.local_state_version IS '候选局部游标，不推进正式状态';
COMMENT ON COLUMN state_snapshots.scope IS '状态所属候选范围';
COMMENT ON COLUMN state_snapshots.payload IS '完整状态或正式事件载荷';
COMMENT ON COLUMN state_snapshots.payload_hash IS '规范化状态哈希';
COMMENT ON COLUMN state_snapshots.event_ids IS '完整回放事件标识清单';
COMMENT ON COLUMN state_snapshots.created_at IS '数据库记录时间';
COMMENT ON TABLE entity_state_versions IS '候选实体状态投影，可从事件重建';
COMMENT ON COLUMN entity_state_versions.id IS '记录标识';
COMMENT ON COLUMN entity_state_versions.novel_id IS '所属小说';
COMMENT ON COLUMN entity_state_versions.snapshot_id IS '所属候选快照';
COMMENT ON COLUMN entity_state_versions.entity_id IS '被描述实体标识';
COMMENT ON COLUMN entity_state_versions.attributes IS '实体各类别状态和来源事件';
SQL);
    }

    public function down(): void
    {
        // 派生数据先移除，来源与不可变事件最后移除，仅用于迁移回滚。
        DB::unprepared('DROP TABLE entity_state_versions,state_snapshots,story_events,proposed_state_patches,proposed_events,evidence_spans,source_documents; DROP FUNCTION d01_validate_evidence(),d01_validate_source();');
    }
};
