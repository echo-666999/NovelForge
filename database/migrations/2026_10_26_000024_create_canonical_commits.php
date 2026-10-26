<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    // 正式图谱只在同一受约束事务内开放，延迟约束拒绝半提交。
    public function up(): void
    {
        DB::unprepared(<<<'SQL'

CREATE TABLE canonical_revisions (
 id bigserial PRIMARY KEY, novel_id bigint NOT NULL REFERENCES novels(id), version int NOT NULL CHECK(version=1),
 status varchar(20) NOT NULL CHECK(status='active'), cursor int NOT NULL DEFAULT 0 CHECK(cursor>=0),
 local_state_version bigint NOT NULL DEFAULT 0 CHECK(local_state_version>=0),
 actor_id bigint NOT NULL REFERENCES users(id), reason text NOT NULL, created_at timestamptz NOT NULL DEFAULT clock_timestamp(),
 UNIQUE(novel_id,id), UNIQUE(novel_id,version)
);
CREATE UNIQUE INDEX e06_active_revision ON canonical_revisions(novel_id) WHERE status='active';
ALTER TABLE novels ADD COLUMN active_canonical_revision_id bigint,
 ADD FOREIGN KEY(id,active_canonical_revision_id) REFERENCES canonical_revisions(novel_id,id);
CREATE TABLE canonical_preflights (
 id bigserial PRIMARY KEY, novel_id bigint NOT NULL, chapter_id bigint NOT NULL, command_key varchar(128) NOT NULL,
 request jsonb NOT NULL, request_hash char(64) NOT NULL, report jsonb NOT NULL, content_hash char(64) NOT NULL,
 actor_id bigint NOT NULL REFERENCES users(id), created_at timestamptz NOT NULL DEFAULT clock_timestamp(),
 UNIQUE(novel_id,id), UNIQUE(novel_id,chapter_id,id), UNIQUE(novel_id,command_key),
 FOREIGN KEY(novel_id,chapter_id) REFERENCES chapters(novel_id,id)
);
CREATE TABLE canonical_commits (
 id bigserial PRIMARY KEY, novel_id bigint NOT NULL, revision_id bigint NOT NULL, chapter_id bigint NOT NULL,
 chapter_version_id bigint NOT NULL, chapter_sequence int NOT NULL CHECK(chapter_sequence>0), review_id bigint NOT NULL,
 event_artifact_id bigint NOT NULL, validation_artifact_id bigint NOT NULL, plan_node_version_id bigint NOT NULL,
 previous_state_version bigint NOT NULL CHECK(previous_state_version>=0), new_state_version bigint NOT NULL,
 idempotency_key varchar(128) NOT NULL, request jsonb NOT NULL, request_hash char(64) NOT NULL,
 status varchar(20) NOT NULL CHECK(status IN ('building','committed')), snapshot_id bigint, response jsonb, response_hash char(64),
 actor_id bigint NOT NULL REFERENCES users(id), reason text NOT NULL, created_at timestamptz NOT NULL DEFAULT clock_timestamp(),
 CHECK(new_state_version=previous_state_version+1), UNIQUE(novel_id,id), UNIQUE(novel_id,revision_id,id),
 UNIQUE(novel_id,revision_id,chapter_version_id,id), UNIQUE(novel_id,idempotency_key),
 UNIQUE(revision_id,chapter_sequence), UNIQUE(revision_id,chapter_id),
 FOREIGN KEY(novel_id,revision_id) REFERENCES canonical_revisions(novel_id,id),
 FOREIGN KEY(novel_id,chapter_id,chapter_version_id) REFERENCES chapter_versions(novel_id,node_id,id),
 FOREIGN KEY(novel_id,review_id) REFERENCES reviews(novel_id,id),
 FOREIGN KEY(novel_id,event_artifact_id) REFERENCES extraction_attempts(novel_id,id),
 FOREIGN KEY(novel_id,chapter_id,validation_artifact_id) REFERENCES canonical_preflights(novel_id,chapter_id,id),
 FOREIGN KEY(plan_node_version_id) REFERENCES plan_nodes(id)
);
CREATE TABLE canonical_chapters (
 id bigserial PRIMARY KEY, novel_id bigint NOT NULL, revision_id bigint NOT NULL, chapter_id bigint NOT NULL,
 chapter_version_id bigint NOT NULL, commit_id bigint NOT NULL, sequence int NOT NULL CHECK(sequence>0), body_hash char(64) NOT NULL,
 UNIQUE(revision_id,sequence), UNIQUE(revision_id,chapter_id), UNIQUE(commit_id), UNIQUE(novel_id,chapter_id,chapter_version_id,commit_id),
 FOREIGN KEY(novel_id,revision_id,chapter_version_id,commit_id) REFERENCES canonical_commits(novel_id,revision_id,chapter_version_id,id),
 FOREIGN KEY(novel_id,chapter_id,chapter_version_id) REFERENCES chapter_versions(novel_id,node_id,id)
);
ALTER TABLE chapters ADD COLUMN current_canonical_version_id bigint, ADD COLUMN canonical_commit_id bigint,
 ADD CHECK((current_canonical_version_id IS NULL)=(canonical_commit_id IS NULL)),
 ADD FOREIGN KEY(novel_id,id,current_canonical_version_id,canonical_commit_id) REFERENCES canonical_chapters(novel_id,chapter_id,chapter_version_id,commit_id);
ALTER TABLE story_events DROP CONSTRAINT d01_formal_entry_closed,
 ADD UNIQUE(novel_id,revision_id,id), ADD UNIQUE(revision_id,proposed_event_id), ADD UNIQUE(commit_id,narrative_order),
 ADD CONSTRAINT e06_formal_commit FOREIGN KEY(novel_id,revision_id,chapter_version_id,commit_id) REFERENCES canonical_commits(novel_id,revision_id,chapter_version_id,id),
 ADD CONSTRAINT e06_formal_supersedes FOREIGN KEY(novel_id,revision_id,supersedes_id) REFERENCES story_events(novel_id,revision_id,id);
ALTER TABLE state_snapshots DROP CONSTRAINT state_snapshots_scope_check,
 ADD COLUMN revision_id bigint, ADD COLUMN commit_id bigint, ADD COLUMN formal_state_version bigint,
 ADD CHECK((scope='candidate' AND revision_id IS NULL AND commit_id IS NULL AND formal_state_version IS NULL) OR
 (scope='formal' AND revision_id IS NOT NULL AND commit_id IS NOT NULL AND formal_state_version IS NOT NULL)),
 ADD UNIQUE(novel_id,revision_id,id),
 ADD FOREIGN KEY(novel_id,revision_id,commit_id) REFERENCES canonical_commits(novel_id,revision_id,id);
CREATE UNIQUE INDEX e06_formal_snapshot ON state_snapshots(revision_id,chapter_sequence) WHERE scope='formal';
ALTER TABLE canonical_commits ADD CONSTRAINT e06_commit_snapshot FOREIGN KEY(novel_id,revision_id,snapshot_id) REFERENCES state_snapshots(novel_id,revision_id,id);
ALTER TABLE foreshadow_occurrences DROP CONSTRAINT d03_formal_entry_closed,
 ADD UNIQUE(novel_id,revision_id,id),
 ADD CONSTRAINT e06_formal_commit FOREIGN KEY(novel_id,revision_id,chapter_version_id,commit_id) REFERENCES canonical_commits(novel_id,revision_id,chapter_version_id,id),
 ADD CONSTRAINT e06_formal_event FOREIGN KEY(novel_id,revision_id,event_id) REFERENCES story_events(novel_id,revision_id,id);
CREATE TABLE canonical_knowledge_facts (
 id bigserial PRIMARY KEY, novel_id bigint NOT NULL, revision_id bigint NOT NULL, commit_id bigint NOT NULL,
 knower_id bigint NOT NULL, fact_event_id bigint NOT NULL, acquired_event_id bigint NOT NULL,
 method varchar(20) NOT NULL CHECK(method IN ('witness','told','inference')), knowledge_kind varchar(30) NOT NULL CHECK(knowledge_kind IN ('fact','belief','rumor','lie','inference','hidden_truth')),
 source_knower_id bigint, CHECK((method='told' AND source_knower_id IS NOT NULL AND source_knower_id<>knower_id) OR (method<>'told' AND source_knower_id IS NULL)), introduced_chapter int NOT NULL CHECK(introduced_chapter>0),
 UNIQUE(revision_id,knower_id,fact_event_id,acquired_event_id),
 FOREIGN KEY(novel_id,revision_id,commit_id) REFERENCES canonical_commits(novel_id,revision_id,id),
 FOREIGN KEY(novel_id,knower_id) REFERENCES entities(novel_id,id), FOREIGN KEY(novel_id,source_knower_id) REFERENCES entities(novel_id,id),
 FOREIGN KEY(novel_id,revision_id,fact_event_id) REFERENCES story_events(novel_id,revision_id,id),
 FOREIGN KEY(novel_id,revision_id,acquired_event_id) REFERENCES story_events(novel_id,revision_id,id)
);
CREATE INDEX e06_knowledge_pov ON canonical_knowledge_facts(novel_id,revision_id,knower_id,introduced_chapter);
CREATE TABLE canonical_relationships (
 id bigserial PRIMARY KEY, novel_id bigint NOT NULL, revision_id bigint NOT NULL, commit_id bigint NOT NULL,
 event_id bigint NOT NULL, from_entity_id bigint NOT NULL, to_entity_id bigint NOT NULL, relation_type varchar(80) NOT NULL, state text NOT NULL,
 UNIQUE(revision_id,event_id,from_entity_id,to_entity_id,relation_type),
 FOREIGN KEY(novel_id,revision_id,commit_id) REFERENCES canonical_commits(novel_id,revision_id,id),
 FOREIGN KEY(novel_id,revision_id,event_id) REFERENCES story_events(novel_id,revision_id,id),
 FOREIGN KEY(novel_id,from_entity_id) REFERENCES entities(novel_id,id), FOREIGN KEY(novel_id,to_entity_id) REFERENCES entities(novel_id,id)
);
CREATE TABLE canonical_foreshadow_states (
 id bigserial PRIMARY KEY, novel_id bigint NOT NULL, revision_id bigint NOT NULL, commit_id bigint NOT NULL,
 plan_id bigint NOT NULL, occurrence_id bigint NOT NULL UNIQUE, status varchar(20) NOT NULL CHECK(status IN ('planted','reinforced','paid_off')),
 FOREIGN KEY(novel_id,revision_id,commit_id) REFERENCES canonical_commits(novel_id,revision_id,id),
 FOREIGN KEY(novel_id,plan_id) REFERENCES foreshadow_plans(novel_id,id),
 FOREIGN KEY(novel_id,revision_id,occurrence_id) REFERENCES foreshadow_occurrences(novel_id,revision_id,id)
);
CREATE TABLE canonical_outbox (
 id bigserial PRIMARY KEY, novel_id bigint NOT NULL, revision_id bigint NOT NULL, commit_id bigint NOT NULL UNIQUE,
 topic varchar(50) NOT NULL CHECK(topic='canonical.committed'), payload jsonb NOT NULL, payload_hash char(64) NOT NULL,
 created_at timestamptz NOT NULL DEFAULT clock_timestamp(),
 FOREIGN KEY(novel_id,revision_id,commit_id) REFERENCES canonical_commits(novel_id,revision_id,id)
);
CREATE INDEX e06_commit_history ON canonical_commits(novel_id,revision_id,chapter_sequence,id);
CREATE OR REPLACE FUNCTION e06_guard() RETURNS trigger LANGUAGE plpgsql AS $$
DECLARE c canonical_commits; e proposed_events; p canonical_preflights; original_id bigint;
BEGIN
 IF TG_TABLE_NAME='canonical_commits' THEN
  IF TG_OP='DELETE' OR (TG_OP='UPDATE' AND (OLD.status<>'building' OR NEW.status<>'committed' OR
   (to_jsonb(NEW)-ARRAY['status','snapshot_id','response','response_hash'])<>(to_jsonb(OLD)-ARRAY['status','snapshot_id','response','response_hash']))) THEN RAISE EXCEPTION 'immutable canonical commit'; END IF;
  IF TG_OP='INSERT' THEN
   SELECT * INTO p FROM canonical_preflights WHERE novel_id=NEW.novel_id AND chapter_id=NEW.chapter_id AND id=NEW.validation_artifact_id;
   IF NEW.status<>'building' OR p.report->>'status'<>'ready' OR p.request<>(NEW.request-'validation_artifact_id') OR p.request->>'review_id'<>NEW.review_id::text OR p.request->>'event_artifact_id'<>NEW.event_artifact_id::text THEN RAISE EXCEPTION 'invalid canonical preflight'; END IF;
   IF NOT EXISTS(SELECT 1 FROM reviews r JOIN extraction_attempts x ON x.id=r.extraction_id AND x.novel_id=r.novel_id WHERE r.id=NEW.review_id AND r.novel_id=NEW.novel_id AND r.chapter_version_id=NEW.chapter_version_id AND x.id=NEW.event_artifact_id AND r.report->>'verdict'='pass' AND r.report->>'hard_pass'='true' AND x.report->>'eligible'='true') THEN RAISE EXCEPTION 'canonical review proof required'; END IF;
  END IF;
  RETURN NEW;
 END IF;
 IF TG_TABLE_NAME='state_snapshots' THEN IF NEW.scope='candidate' THEN RETURN NEW; END IF; END IF;
 IF TG_TABLE_NAME='entity_state_versions' THEN
  SELECT cc.* INTO c FROM canonical_commits cc JOIN state_snapshots s ON s.commit_id=cc.id WHERE s.id=NEW.snapshot_id AND s.scope='formal';
  IF c.id IS NULL THEN RETURN NEW; END IF;
 ELSE
  SELECT * INTO c FROM canonical_commits WHERE id=NEW.commit_id AND novel_id=NEW.novel_id;
 END IF;
 IF c.id IS NULL OR c.status<>'building' OR current_setting('novelforge.canonical_commit',true) IS DISTINCT FROM c.id::text THEN RAISE EXCEPTION 'canonical commit write proof required'; END IF;
 IF TG_TABLE_NAME='story_events' THEN
  SELECT pe.* INTO e FROM proposed_events pe JOIN extraction_events x ON x.event_id=pe.id AND x.novel_id=pe.novel_id JOIN evidence_spans s ON s.id=pe.evidence_id AND s.novel_id=pe.novel_id JOIN extraction_attempts a ON a.id=x.attempt_id AND a.novel_id=pe.novel_id WHERE pe.id=NEW.proposed_event_id AND pe.novel_id=c.novel_id AND x.attempt_id=c.event_artifact_id AND s.source_version_id=a.source_id AND pe.evidence_id=NEW.evidence_id;
  IF e.id IS NULL OR NEW.revision_id<>c.revision_id OR NEW.chapter_version_id<>c.chapter_version_id OR NEW.narrative_order<>e.narrative_order OR e.chapter_sequence<>c.chapter_sequence OR e.confidence<>'confirmed' OR NOT EXISTS(SELECT 1 FROM entities WHERE id=e.entity_id AND novel_id=c.novel_id AND approved_version_id=e.definition_version_id AND NOT retired) THEN RAISE EXCEPTION 'canonical event source mismatch'; END IF;
  SELECT proposed_event_id INTO original_id FROM story_events WHERE id=NEW.supersedes_id AND novel_id=c.novel_id AND revision_id=c.revision_id;
  IF original_id IS DISTINCT FROM e.supersedes_id OR NEW.payload IS DISTINCT FROM to_jsonb(e) THEN RAISE EXCEPTION 'canonical event payload mismatch'; END IF;
 ELSIF TG_TABLE_NAME='canonical_chapters' THEN
  IF NEW.revision_id<>c.revision_id OR NEW.chapter_id<>c.chapter_id OR NEW.chapter_version_id<>c.chapter_version_id OR NEW.sequence<>c.chapter_sequence OR NOT EXISTS(SELECT 1 FROM chapter_versions WHERE id=c.chapter_version_id AND body_hash=NEW.body_hash) THEN RAISE EXCEPTION 'canonical chapter mapping mismatch'; END IF;
 ELSIF TG_TABLE_NAME='state_snapshots' THEN
  IF NEW.revision_id<>c.revision_id OR NEW.formal_state_version<>c.new_state_version OR NEW.chapter_sequence<>c.chapter_sequence OR NEW.payload->>'schema' IS DISTINCT FROM 'formal-state-v1' THEN RAISE EXCEPTION 'canonical snapshot scope mismatch'; END IF;
 ELSIF TG_TABLE_NAME='foreshadow_occurrences' THEN
  IF NEW.revision_id<>c.revision_id OR NEW.chapter_version_id<>c.chapter_version_id OR NOT EXISTS(SELECT 1 FROM story_events s JOIN extraction_foreshadows x ON x.event_id=s.proposed_event_id AND x.novel_id=s.novel_id WHERE s.id=NEW.event_id AND s.commit_id=c.id AND s.evidence_id=NEW.evidence_id AND x.attempt_id=c.event_artifact_id AND x.plan_id=NEW.plan_id AND x.kind=NEW.kind) THEN RAISE EXCEPTION 'canonical foreshadow proof mismatch'; END IF;
 ELSIF TG_TABLE_NAME='canonical_foreshadow_states' THEN
  IF NOT EXISTS(SELECT 1 FROM foreshadow_occurrences o WHERE o.id=NEW.occurrence_id AND o.commit_id=c.id AND o.plan_id=NEW.plan_id AND NEW.status=CASE o.kind WHEN 'plant' THEN 'planted' WHEN 'reinforce' THEN 'reinforced' WHEN 'payoff' THEN 'paid_off' END) THEN RAISE EXCEPTION 'canonical foreshadow state mismatch'; END IF;
 ELSIF TG_TABLE_NAME='canonical_outbox' THEN
  IF NEW.payload->>'id'<>c.id::text OR NEW.payload->>'novel_id'<>c.novel_id::text OR NEW.payload->>'revision_id'<>c.revision_id::text THEN RAISE EXCEPTION 'canonical outbox payload mismatch'; END IF;
 ELSIF TG_TABLE_NAME='canonical_knowledge_facts' THEN
  IF NOT EXISTS(SELECT 1 FROM extraction_attempts x JOIN knowledge_facts k ON k.context_id=x.context_id AND k.novel_id=x.novel_id JOIN story_events f ON f.proposed_event_id=k.fact_event_id AND f.revision_id=c.revision_id JOIN story_events a ON a.proposed_event_id=k.acquired_event_id AND a.revision_id=c.revision_id WHERE x.id=c.event_artifact_id AND k.knower_id=NEW.knower_id AND f.id=NEW.fact_event_id AND a.id=NEW.acquired_event_id AND k.method=NEW.method AND k.source_knower_id IS NOT DISTINCT FROM NEW.source_knower_id AND (a.payload->>'chapter_sequence')::int=NEW.introduced_chapter) THEN RAISE EXCEPTION 'canonical knowledge source mismatch'; END IF;
 ELSIF TG_TABLE_NAME='canonical_relationships' THEN
  IF NOT EXISTS(SELECT 1 FROM extraction_attempts x JOIN relationships r ON r.context_id=x.context_id AND r.novel_id=x.novel_id JOIN story_events relationship_event ON relationship_event.proposed_event_id=r.event_id AND relationship_event.revision_id=c.revision_id WHERE x.id=c.event_artifact_id AND relationship_event.id=NEW.event_id AND r.from_entity_id=NEW.from_entity_id AND r.to_entity_id=NEW.to_entity_id AND r.relation_type=NEW.relation_type AND r.state=NEW.state) THEN RAISE EXCEPTION 'canonical relationship source mismatch'; END IF;
 END IF;
 RETURN NEW;
END $$;
CREATE TRIGGER e06_commit_guard BEFORE INSERT OR UPDATE OR DELETE ON canonical_commits FOR EACH ROW EXECUTE FUNCTION e06_guard();
CREATE TRIGGER e06_event_guard BEFORE INSERT ON story_events FOR EACH ROW EXECUTE FUNCTION e06_guard();
CREATE TRIGGER e06_occurrence_guard BEFORE INSERT ON foreshadow_occurrences FOR EACH ROW EXECUTE FUNCTION e06_guard();
CREATE TRIGGER e06_snapshot_guard BEFORE INSERT ON state_snapshots FOR EACH ROW EXECUTE FUNCTION e06_guard();
CREATE TRIGGER e06_entity_guard BEFORE INSERT ON entity_state_versions FOR EACH ROW EXECUTE FUNCTION e06_guard();
CREATE OR REPLACE FUNCTION e06_snapshot_immutable() RETURNS trigger LANGUAGE plpgsql AS $$
BEGIN
 IF (TG_TABLE_NAME='state_snapshots' AND to_jsonb(OLD)->>'scope'='formal') OR (TG_TABLE_NAME='entity_state_versions' AND EXISTS(SELECT 1 FROM state_snapshots WHERE id=(to_jsonb(OLD)->>'snapshot_id')::bigint AND scope='formal')) THEN RAISE EXCEPTION 'immutable formal projection'; END IF;
 RETURN CASE WHEN TG_OP='DELETE' THEN OLD ELSE NEW END;
END $$;
CREATE TRIGGER e06_snapshot_change BEFORE UPDATE OR DELETE ON state_snapshots FOR EACH ROW EXECUTE FUNCTION e06_snapshot_immutable();
CREATE TRIGGER e06_entity_change BEFORE UPDATE OR DELETE ON entity_state_versions FOR EACH ROW EXECUTE FUNCTION e06_snapshot_immutable();
CREATE OR REPLACE FUNCTION e06_revision_guard() RETURNS trigger LANGUAGE plpgsql AS $$
DECLARE c canonical_commits;
BEGIN
 IF TG_OP='INSERT' THEN
  IF NEW.cursor<>0 OR NEW.local_state_version<>0 THEN RAISE EXCEPTION 'canonical genesis must be empty'; END IF;
  RETURN NEW;
 END IF;
 IF TG_OP='DELETE' OR (to_jsonb(NEW)-ARRAY['cursor','local_state_version'])<>(to_jsonb(OLD)-ARRAY['cursor','local_state_version']) THEN RAISE EXCEPTION 'immutable canonical revision identity'; END IF;
 SELECT * INTO c FROM canonical_commits WHERE id=nullif(current_setting('novelforge.canonical_commit',true),'')::bigint AND revision_id=NEW.id AND status='building';
 IF c.id IS NULL OR NEW.cursor<>OLD.cursor+1 OR NEW.local_state_version<>OLD.local_state_version+1 OR NEW.cursor<>c.chapter_sequence OR NEW.local_state_version<>c.new_state_version THEN RAISE EXCEPTION 'canonical cursor transition mismatch'; END IF;
 RETURN NEW;
END $$;
CREATE TRIGGER e06_revision_change BEFORE INSERT OR UPDATE OR DELETE ON canonical_revisions FOR EACH ROW EXECUTE FUNCTION e06_revision_guard();
CREATE OR REPLACE FUNCTION e06_complete() RETURNS trigger LANGUAGE plpgsql AS $$
DECLARE c canonical_commits;
BEGIN
 SELECT * INTO c FROM canonical_commits WHERE id=NEW.id;
 IF c.status<>'committed' OR c.response IS NULL OR c.snapshot_id IS NULL OR c.response_hash IS NULL OR
 NOT EXISTS(SELECT 1 FROM canonical_chapters WHERE commit_id=c.id) OR NOT EXISTS(SELECT 1 FROM canonical_outbox WHERE commit_id=c.id) OR
 NOT EXISTS(SELECT 1 FROM state_snapshots WHERE id=c.snapshot_id AND commit_id=c.id AND scope='formal') OR
 NOT EXISTS(SELECT 1 FROM chapters WHERE id=c.chapter_id AND current_canonical_version_id=c.chapter_version_id AND canonical_commit_id=c.id) OR
 NOT EXISTS(SELECT 1 FROM novels n JOIN canonical_revisions r ON r.id=n.active_canonical_revision_id AND r.novel_id=n.id WHERE n.id=c.novel_id AND r.id=c.revision_id AND n.state_version>=c.new_state_version AND r.cursor>=c.chapter_sequence AND r.local_state_version=n.state_version) OR
 (SELECT count(*) FROM story_events WHERE commit_id=c.id)<>(SELECT count(*) FROM extraction_events WHERE attempt_id=c.event_artifact_id) OR
 (SELECT count(*) FROM foreshadow_occurrences WHERE commit_id=c.id)<>(SELECT count(*) FROM extraction_foreshadows WHERE attempt_id=c.event_artifact_id)
 THEN RAISE EXCEPTION 'incomplete atomic canonical commit'; END IF;
 RETURN NEW;
END $$;
CREATE CONSTRAINT TRIGGER e06_commit_complete AFTER INSERT OR UPDATE ON canonical_commits DEFERRABLE INITIALLY DEFERRED FOR EACH ROW EXECUTE FUNCTION e06_complete();

COMMENT ON TABLE canonical_revisions IS '活动正式基线与提交游标，仅空根基线和顺序追加';
COMMENT ON COLUMN canonical_revisions.id IS '基线标识';
COMMENT ON COLUMN canonical_revisions.novel_id IS '所属小说';
COMMENT ON COLUMN canonical_revisions.version IS '首期根基线版本';
COMMENT ON COLUMN canonical_revisions.status IS '活动状态，历史重基线尚未开放';
COMMENT ON COLUMN canonical_revisions.cursor IS '已连续提交章序';
COMMENT ON COLUMN canonical_revisions.local_state_version IS '本基线正式状态版本';
COMMENT ON COLUMN canonical_revisions.actor_id IS '初始化管理员';
COMMENT ON COLUMN canonical_revisions.reason IS '初始化原因';
COMMENT ON COLUMN canonical_revisions.created_at IS '创建时间';
CREATE TRIGGER e06_audit AFTER INSERT OR UPDATE ON canonical_revisions FOR EACH ROW EXECUTE FUNCTION c07_audit_capture();

COMMENT ON TABLE canonical_preflights IS '不可变正式提交预检与校验产物';
COMMENT ON COLUMN canonical_preflights.id IS '校验产物标识';
COMMENT ON COLUMN canonical_preflights.novel_id IS '所属小说';
COMMENT ON COLUMN canonical_preflights.chapter_id IS '目标章节';
COMMENT ON COLUMN canonical_preflights.command_key IS '同书预检幂等键';
COMMENT ON COLUMN canonical_preflights.request IS '不含校验产物自身标识的请求';
COMMENT ON COLUMN canonical_preflights.request_hash IS '预检请求规范哈希';
COMMENT ON COLUMN canonical_preflights.report IS '门禁、依赖哈希或阻塞原因';
COMMENT ON COLUMN canonical_preflights.content_hash IS '报告规范哈希';
COMMENT ON COLUMN canonical_preflights.actor_id IS '预检管理员';
COMMENT ON COLUMN canonical_preflights.created_at IS '记录时间';
CREATE TRIGGER e06_immutable BEFORE UPDATE OR DELETE ON canonical_preflights FOR EACH ROW EXECUTE FUNCTION c01_immutable();
CREATE TRIGGER e06_audit AFTER INSERT OR UPDATE ON canonical_preflights FOR EACH ROW EXECUTE FUNCTION c07_audit_capture();

COMMENT ON TABLE canonical_commits IS '唯一正式提交记录及长期幂等回执';
COMMENT ON COLUMN canonical_commits.id IS '正式提交标识';
COMMENT ON COLUMN canonical_commits.novel_id IS '所属小说';
COMMENT ON COLUMN canonical_commits.revision_id IS '活动正式基线';
COMMENT ON COLUMN canonical_commits.chapter_id IS '目标章节';
COMMENT ON COLUMN canonical_commits.chapter_version_id IS '精确章节正文版本';
COMMENT ON COLUMN canonical_commits.chapter_sequence IS '正式连续章序';
COMMENT ON COLUMN canonical_commits.review_id IS '正文与规则绑定的评审';
COMMENT ON COLUMN canonical_commits.event_artifact_id IS '事件产物：实际E03提取记录';
COMMENT ON COLUMN canonical_commits.validation_artifact_id IS '校验产物：不可变正式预检';
COMMENT ON COLUMN canonical_commits.plan_node_version_id IS '冻结大纲中的章节节点';
COMMENT ON COLUMN canonical_commits.previous_state_version IS '提交前正式版本';
COMMENT ON COLUMN canonical_commits.new_state_version IS '提交后正式版本';
COMMENT ON COLUMN canonical_commits.idempotency_key IS '同书永久幂等键';
COMMENT ON COLUMN canonical_commits.request IS '完整提交请求';
COMMENT ON COLUMN canonical_commits.request_hash IS '完整请求规范哈希';
COMMENT ON COLUMN canonical_commits.status IS '事务构建态或正式提交态';
COMMENT ON COLUMN canonical_commits.snapshot_id IS '正式状态快照';
COMMENT ON COLUMN canonical_commits.response IS '首次成功回执';
COMMENT ON COLUMN canonical_commits.response_hash IS '成功回执规范哈希';
COMMENT ON COLUMN canonical_commits.actor_id IS '提交管理员';
COMMENT ON COLUMN canonical_commits.reason IS '提交理由';
COMMENT ON COLUMN canonical_commits.created_at IS '提交时间';
CREATE TRIGGER e06_audit AFTER INSERT OR UPDATE ON canonical_commits FOR EACH ROW EXECUTE FUNCTION c07_audit_capture();

COMMENT ON TABLE canonical_chapters IS '正式基线与精确章版本映射';
COMMENT ON COLUMN canonical_chapters.id IS '映射标识';
COMMENT ON COLUMN canonical_chapters.novel_id IS '所属小说';
COMMENT ON COLUMN canonical_chapters.revision_id IS '正式基线';
COMMENT ON COLUMN canonical_chapters.chapter_id IS '章节节点';
COMMENT ON COLUMN canonical_chapters.chapter_version_id IS '精确正式正文版本';
COMMENT ON COLUMN canonical_chapters.commit_id IS '来源正式提交';
COMMENT ON COLUMN canonical_chapters.sequence IS '正式章序';
COMMENT ON COLUMN canonical_chapters.body_hash IS '正式正文哈希';
CREATE TRIGGER e06_immutable BEFORE UPDATE OR DELETE ON canonical_chapters FOR EACH ROW EXECUTE FUNCTION c01_immutable();
CREATE TRIGGER e06_write_guard BEFORE INSERT ON canonical_chapters FOR EACH ROW EXECUTE FUNCTION e06_guard();

COMMENT ON TABLE canonical_knowledge_facts IS '有正式事件和获知证据的知识投影';
COMMENT ON COLUMN canonical_knowledge_facts.id IS '投影标识';
COMMENT ON COLUMN canonical_knowledge_facts.novel_id IS '所属小说';
COMMENT ON COLUMN canonical_knowledge_facts.revision_id IS '正式基线';
COMMENT ON COLUMN canonical_knowledge_facts.commit_id IS '首次写入提交';
COMMENT ON COLUMN canonical_knowledge_facts.knower_id IS '知情人物';
COMMENT ON COLUMN canonical_knowledge_facts.fact_event_id IS '正式事实事件';
COMMENT ON COLUMN canonical_knowledge_facts.acquired_event_id IS '正式获知事件';
COMMENT ON COLUMN canonical_knowledge_facts.method IS '目击、被告知或推断';
COMMENT ON COLUMN canonical_knowledge_facts.knowledge_kind IS '保留事实与推断类别';
COMMENT ON COLUMN canonical_knowledge_facts.source_knower_id IS '告知者';
COMMENT ON COLUMN canonical_knowledge_facts.introduced_chapter IS '最早可知叙述章序';
CREATE TRIGGER e06_immutable BEFORE UPDATE OR DELETE ON canonical_knowledge_facts FOR EACH ROW EXECUTE FUNCTION c01_immutable();
CREATE TRIGGER e06_write_guard BEFORE INSERT ON canonical_knowledge_facts FOR EACH ROW EXECUTE FUNCTION e06_guard();

COMMENT ON TABLE canonical_relationships IS '绑定正式来源事件的关系投影';
COMMENT ON COLUMN canonical_relationships.id IS '投影标识';
COMMENT ON COLUMN canonical_relationships.novel_id IS '所属小说';
COMMENT ON COLUMN canonical_relationships.revision_id IS '正式基线';
COMMENT ON COLUMN canonical_relationships.commit_id IS '首次写入提交';
COMMENT ON COLUMN canonical_relationships.event_id IS '正式关系来源事件';
COMMENT ON COLUMN canonical_relationships.from_entity_id IS '关系起点实体';
COMMENT ON COLUMN canonical_relationships.to_entity_id IS '关系终点实体';
COMMENT ON COLUMN canonical_relationships.relation_type IS '关系类型';
COMMENT ON COLUMN canonical_relationships.state IS '关系状态';
CREATE TRIGGER e06_immutable BEFORE UPDATE OR DELETE ON canonical_relationships FOR EACH ROW EXECUTE FUNCTION c01_immutable();
CREATE TRIGGER e06_write_guard BEFORE INSERT ON canonical_relationships FOR EACH ROW EXECUTE FUNCTION e06_guard();

COMMENT ON TABLE canonical_foreshadow_states IS '由正式证据驱动的伏笔生命周期';
COMMENT ON COLUMN canonical_foreshadow_states.id IS '状态标识';
COMMENT ON COLUMN canonical_foreshadow_states.novel_id IS '所属小说';
COMMENT ON COLUMN canonical_foreshadow_states.revision_id IS '正式基线';
COMMENT ON COLUMN canonical_foreshadow_states.commit_id IS '变更提交';
COMMENT ON COLUMN canonical_foreshadow_states.plan_id IS '具体伏笔规划版本';
COMMENT ON COLUMN canonical_foreshadow_states.occurrence_id IS '正式证据出现记录';
COMMENT ON COLUMN canonical_foreshadow_states.status IS '已埋设、强化或回收';
CREATE TRIGGER e06_immutable BEFORE UPDATE OR DELETE ON canonical_foreshadow_states FOR EACH ROW EXECUTE FUNCTION c01_immutable();
CREATE TRIGGER e06_write_guard BEFORE INSERT ON canonical_foreshadow_states FOR EACH ROW EXECUTE FUNCTION e06_guard();

COMMENT ON TABLE canonical_outbox IS '正式提交的持久后续意图，消费待E07';
COMMENT ON COLUMN canonical_outbox.id IS '持久意图标识';
COMMENT ON COLUMN canonical_outbox.novel_id IS '所属小说';
COMMENT ON COLUMN canonical_outbox.revision_id IS '正式基线';
COMMENT ON COLUMN canonical_outbox.commit_id IS '唯一来源提交';
COMMENT ON COLUMN canonical_outbox.topic IS '正式提交完成主题';
COMMENT ON COLUMN canonical_outbox.payload IS '版本、哈希及来源回执';
COMMENT ON COLUMN canonical_outbox.payload_hash IS '意图规范哈希';
COMMENT ON COLUMN canonical_outbox.created_at IS '创建时间';
CREATE TRIGGER e06_immutable BEFORE UPDATE OR DELETE ON canonical_outbox FOR EACH ROW EXECUTE FUNCTION c01_immutable();
CREATE TRIGGER e06_write_guard BEFORE INSERT ON canonical_outbox FOR EACH ROW EXECUTE FUNCTION e06_guard();
COMMENT ON COLUMN novels.active_canonical_revision_id IS '当前正式根基线，空根初始化不产生剧情状态';
COMMENT ON COLUMN chapters.current_canonical_version_id IS '当前正式章版本，仅原子提交更新';
COMMENT ON COLUMN chapters.canonical_commit_id IS '当前正式版本的提交来源';
COMMENT ON COLUMN state_snapshots.revision_id IS '正式范围所属基线，候选为空';
COMMENT ON COLUMN state_snapshots.commit_id IS '正式快照来源提交，候选为空';
COMMENT ON COLUMN state_snapshots.formal_state_version IS '正式状态版本，候选为空';
COMMENT ON TABLE state_snapshots IS '候选或正式状态快照，正式快照不可变且可由事件回放';
COMMENT ON TABLE entity_state_versions IS '绑定候选或正式快照的实体状态投影';
COMMENT ON COLUMN story_events.revision_id IS '正式基线标识';
COMMENT ON COLUMN story_events.commit_id IS '正式提交来源';
COMMENT ON COLUMN story_events.chapter_version_id IS '已提交的精确正文版本';
COMMENT ON TABLE story_events IS '绑定正式提交与精确正文证据的不可变事件';
COMMENT ON COLUMN state_snapshots.scope IS '候选或正式范围，正式范围必须绑定提交';

SQL);

        // 冻结结构保持原样；仅允许当前构建提交更新两个正式指针。
        $source = file_get_contents(__DIR__.'/2026_10_23_000005_create_rolling_plans.php');
        preg_match('/CREATE OR REPLACE FUNCTION nf_frozen_structure\(\).*?END \$\$;/s', $source, $matches);
        $permission = <<<'SQL'
 IF TG_TABLE_NAME='chapters' AND TG_OP='UPDATE' THEN
  IF (to_jsonb(NEW)-ARRAY['current_canonical_version_id','canonical_commit_id'])=(to_jsonb(OLD)-ARRAY['current_canonical_version_id','canonical_commit_id']) AND
   EXISTS(SELECT 1 FROM canonical_commits c JOIN canonical_chapters m ON m.commit_id=c.id WHERE c.id=NEW.canonical_commit_id AND c.id::text=current_setting('novelforge.canonical_commit',true) AND c.status='building' AND c.novel_id=NEW.novel_id AND c.chapter_id=NEW.id AND c.chapter_version_id=NEW.current_canonical_version_id) THEN RETURN NEW; END IF;
 END IF;
SQL;
        DB::unprepared(str_replace('PERFORM 1 FROM novels WHERE id=book FOR UPDATE;', 'PERFORM 1 FROM novels WHERE id=book FOR UPDATE;'.$permission, $matches[0]));
    }

    public function down(): void
    {
        // 仅空库逆依赖回滚；不删除正式历史以强行降级。
        DB::unprepared(<<<'SQL'

DROP TRIGGER e06_commit_complete ON canonical_commits;
ALTER TABLE novels DROP COLUMN active_canonical_revision_id;
ALTER TABLE chapters DROP COLUMN current_canonical_version_id, DROP COLUMN canonical_commit_id;
DROP TABLE canonical_outbox,canonical_foreshadow_states,canonical_relationships,canonical_knowledge_facts,canonical_chapters;
ALTER TABLE canonical_commits DROP CONSTRAINT e06_commit_snapshot;
DROP TRIGGER e06_snapshot_guard ON state_snapshots;
DROP TRIGGER e06_snapshot_change ON state_snapshots;
DROP TRIGGER e06_entity_guard ON entity_state_versions;
DROP TRIGGER e06_entity_change ON entity_state_versions;
ALTER TABLE state_snapshots DROP COLUMN revision_id, DROP COLUMN commit_id, DROP COLUMN formal_state_version;
ALTER TABLE state_snapshots ADD CONSTRAINT state_snapshots_scope_check CHECK(scope='candidate');
DROP TRIGGER e06_event_guard ON story_events;
DROP TRIGGER e06_occurrence_guard ON foreshadow_occurrences;
ALTER TABLE foreshadow_occurrences DROP CONSTRAINT e06_formal_event;
ALTER TABLE story_events DROP CONSTRAINT e06_formal_commit;
ALTER TABLE story_events DROP CONSTRAINT e06_formal_supersedes;
ALTER TABLE story_events DROP CONSTRAINT story_events_revision_id_proposed_event_id_key, DROP CONSTRAINT story_events_commit_id_narrative_order_key, DROP CONSTRAINT story_events_novel_id_revision_id_id_key;
ALTER TABLE foreshadow_occurrences DROP CONSTRAINT e06_formal_commit;
ALTER TABLE foreshadow_occurrences DROP CONSTRAINT foreshadow_occurrences_novel_id_revision_id_id_key;
DROP TABLE canonical_commits,canonical_preflights,canonical_revisions;
ALTER TABLE story_events ADD CONSTRAINT d01_formal_entry_closed CHECK(false);
ALTER TABLE foreshadow_occurrences ADD CONSTRAINT d03_formal_entry_closed CHECK(false);
DROP FUNCTION e06_guard(),e06_snapshot_immutable(),e06_revision_guard(),e06_complete();

SQL);

        // 回滚恢复原有冻结结构守卫，不残留对正式指针列的引用。
        $source = file_get_contents(__DIR__.'/2026_10_23_000005_create_rolling_plans.php');
        preg_match('/CREATE OR REPLACE FUNCTION nf_frozen_structure\(\).*?END \$\$;/s', $source, $matches);
        DB::unprepared($matches[0]);
    }
};
