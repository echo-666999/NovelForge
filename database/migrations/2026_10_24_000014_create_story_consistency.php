<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    // 候选版本封存后只读；知识和关系不越过正式提交边界。
    public function up(): void
    {
        DB::unprepared(<<<'SQL'
CREATE TABLE story_context_versions (
 id bigserial PRIMARY KEY, novel_id bigint NOT NULL REFERENCES novels(id), version integer NOT NULL CHECK(version>0),
 status varchar(20) NOT NULL CHECK(status IN ('building','sealed')), command_key varchar(128) NOT NULL,
 request_hash char(64) NOT NULL, payload jsonb NOT NULL, content_hash char(64) NOT NULL, report jsonb NOT NULL,
 reason text NOT NULL, actor_id bigint NOT NULL REFERENCES users(id), created_at timestamptz NOT NULL DEFAULT clock_timestamp(),
 UNIQUE(novel_id,id), UNIQUE(novel_id,version), UNIQUE(novel_id,command_key)
);
CREATE TABLE story_time_entries (
 id bigserial PRIMARY KEY, novel_id bigint NOT NULL, context_id bigint NOT NULL, event_id bigint NOT NULL,
 time_kind varchar(20) NOT NULL CHECK(time_kind IN ('unknown','exact','range')),
 clock varchar(30) CHECK(clock IN ('utc','relative_seconds')), start_time bigint, end_time bigint,
 activity boolean NOT NULL, location_id bigint, ability_id bigint, ability_units integer CHECK(ability_units>0),
 previous_owner_id bigint, revival_rule_id bigint,
 UNIQUE(novel_id,context_id,event_id),
 FOREIGN KEY(novel_id,context_id) REFERENCES story_context_versions(novel_id,id),
 FOREIGN KEY(novel_id,event_id) REFERENCES proposed_events(novel_id,id),
 FOREIGN KEY(novel_id,location_id) REFERENCES entities(novel_id,id),
 FOREIGN KEY(novel_id,ability_id) REFERENCES entities(novel_id,id),
 FOREIGN KEY(novel_id,previous_owner_id) REFERENCES entities(novel_id,id),
 FOREIGN KEY(novel_id,revival_rule_id) REFERENCES entity_definition_versions(novel_id,id),
 CHECK((time_kind='unknown' AND clock IS NULL AND start_time IS NULL AND end_time IS NULL) OR
 (time_kind<>'unknown' AND clock IS NOT NULL AND start_time IS NOT NULL AND end_time IS NOT NULL AND start_time<=end_time AND (time_kind<>'exact' OR start_time=end_time))),
 CHECK((ability_id IS NULL)=(ability_units IS NULL))
);
CREATE INDEX d02_time_order ON story_time_entries(novel_id,context_id,clock,start_time,end_time);
CREATE TABLE event_dependencies (
 id bigserial PRIMARY KEY, novel_id bigint NOT NULL, context_id bigint NOT NULL,
 predecessor_id bigint NOT NULL, successor_id bigint NOT NULL,
 relation_type varchar(20) NOT NULL CHECK(relation_type IN ('before','depends_on','contains','simultaneous')),
 CHECK(predecessor_id<>successor_id), UNIQUE(context_id,predecessor_id,successor_id,relation_type),
 FOREIGN KEY(novel_id,context_id,predecessor_id) REFERENCES story_time_entries(novel_id,context_id,event_id),
 FOREIGN KEY(novel_id,context_id,successor_id) REFERENCES story_time_entries(novel_id,context_id,event_id)
);
CREATE TABLE knowledge_facts (
 id bigserial PRIMARY KEY, novel_id bigint NOT NULL, context_id bigint NOT NULL, knower_id bigint NOT NULL,
 fact_event_id bigint NOT NULL, acquired_event_id bigint NOT NULL,
 method varchar(20) NOT NULL CHECK(method IN ('witness','told','inference')), source_knower_id bigint,
 UNIQUE(context_id,knower_id,fact_event_id,acquired_event_id),
 FOREIGN KEY(novel_id,context_id,fact_event_id) REFERENCES story_time_entries(novel_id,context_id,event_id),
 FOREIGN KEY(novel_id,context_id,acquired_event_id) REFERENCES story_time_entries(novel_id,context_id,event_id),
 FOREIGN KEY(novel_id,knower_id) REFERENCES entities(novel_id,id),
 FOREIGN KEY(novel_id,source_knower_id) REFERENCES entities(novel_id,id),
 CHECK((method='told' AND source_knower_id IS NOT NULL AND source_knower_id<>knower_id) OR (method<>'told' AND source_knower_id IS NULL))
);
CREATE INDEX d02_knowledge_lookup ON knowledge_facts(novel_id,context_id,knower_id,acquired_event_id);
CREATE TABLE relationships (
 id bigserial PRIMARY KEY, novel_id bigint NOT NULL, context_id bigint NOT NULL, event_id bigint NOT NULL,
 from_entity_id bigint NOT NULL, to_entity_id bigint NOT NULL, relation_type varchar(80) NOT NULL, state text NOT NULL,
 UNIQUE(context_id,event_id,from_entity_id,to_entity_id,relation_type), CHECK(from_entity_id<>to_entity_id),
 FOREIGN KEY(novel_id,context_id,event_id) REFERENCES story_time_entries(novel_id,context_id,event_id),
 FOREIGN KEY(novel_id,from_entity_id) REFERENCES entities(novel_id,id),
 FOREIGN KEY(novel_id,to_entity_id) REFERENCES entities(novel_id,id)
);
CREATE TABLE consistency_rules (
 id bigserial PRIMARY KEY, novel_id bigint NOT NULL, context_id bigint NOT NULL, rule_version_id bigint NOT NULL,
 kind varchar(20) NOT NULL CHECK(kind IN ('travel','ability','revival')), subject_id bigint NOT NULL, target_id bigint,
 limit_value bigint NOT NULL CHECK(limit_value>=0),
 FOREIGN KEY(novel_id,context_id) REFERENCES story_context_versions(novel_id,id),
 FOREIGN KEY(novel_id,rule_version_id) REFERENCES entity_definition_versions(novel_id,id),
 FOREIGN KEY(novel_id,subject_id) REFERENCES entities(novel_id,id),
 FOREIGN KEY(novel_id,target_id) REFERENCES entities(novel_id,id),
 UNIQUE NULLS NOT DISTINCT(context_id,kind,subject_id,target_id)
);
CREATE OR REPLACE FUNCTION d02_sealed_guard() RETURNS trigger LANGUAGE plpgsql AS $$
BEGIN
 IF OLD.status='sealed' OR TG_OP='DELETE' THEN RAISE EXCEPTION 'immutable story context'; END IF;
 IF NEW.status<>'sealed' OR NEW.id<>OLD.id OR NEW.novel_id<>OLD.novel_id THEN RAISE EXCEPTION 'invalid context seal'; END IF;
 RETURN NEW;
END $$;
CREATE TRIGGER d02_context_immutable BEFORE UPDATE OR DELETE ON story_context_versions FOR EACH ROW EXECUTE FUNCTION d02_sealed_guard();
CREATE OR REPLACE FUNCTION d02_child_guard() RETURNS trigger LANGUAGE plpgsql AS $$
BEGIN
 IF NOT EXISTS(SELECT 1 FROM story_context_versions WHERE novel_id=NEW.novel_id AND id=NEW.context_id AND status='building' FOR UPDATE) THEN RAISE EXCEPTION 'immutable sealed context'; END IF;
 RETURN NEW;
END $$;
CREATE TRIGGER d02_audit AFTER INSERT OR UPDATE ON story_context_versions FOR EACH ROW EXECUTE FUNCTION c07_audit_capture();
COMMENT ON COLUMN story_context_versions.request_hash IS '原始命令幂等校验哈希';
COMMENT ON TABLE story_context_versions IS '不可变候选时间线与一致性验证版本';
COMMENT ON COLUMN story_context_versions.id IS '记录标识';
COMMENT ON COLUMN story_context_versions.novel_id IS '所属小说';
COMMENT ON COLUMN story_context_versions.version IS '书内时间线版本号';
COMMENT ON COLUMN story_context_versions.status IS '构建或封存状态';
COMMENT ON COLUMN story_context_versions.command_key IS '幂等命令标识';
COMMENT ON COLUMN story_context_versions.payload IS '完整不可变候选配置';
COMMENT ON COLUMN story_context_versions.content_hash IS '规范配置哈希';
COMMENT ON COLUMN story_context_versions.report IS '确定性验证结果';
COMMENT ON COLUMN story_context_versions.reason IS '作者保存或修正原因';
COMMENT ON COLUMN story_context_versions.actor_id IS '操作用户';
COMMENT ON COLUMN story_context_versions.created_at IS '数据库记录时间';
CREATE TRIGGER d02_no_change BEFORE UPDATE OR DELETE ON story_time_entries FOR EACH ROW EXECUTE FUNCTION c01_immutable();
CREATE TRIGGER d02_insert_guard BEFORE INSERT ON story_time_entries FOR EACH ROW EXECUTE FUNCTION d02_child_guard();
COMMENT ON TABLE story_time_entries IS '事件实际时间与叙述来源关联';
COMMENT ON COLUMN story_time_entries.id IS '记录标识';
COMMENT ON COLUMN story_time_entries.novel_id IS '所属小说';
COMMENT ON COLUMN story_time_entries.context_id IS '候选时间线版本标识';
COMMENT ON COLUMN story_time_entries.event_id IS '来源候选事件';
COMMENT ON COLUMN story_time_entries.time_kind IS '未知、精确或有界区间';
COMMENT ON COLUMN story_time_entries.clock IS '独立故事时钟，UTC或相对秒序';
COMMENT ON COLUMN story_time_entries.start_time IS '实际时间下界，秒';
COMMENT ON COLUMN story_time_entries.end_time IS '实际时间上界，秒';
COMMENT ON COLUMN story_time_entries.activity IS '是否为角色行动';
COMMENT ON COLUMN story_time_entries.location_id IS '行动所在地点';
COMMENT ON COLUMN story_time_entries.ability_id IS '使用的能力';
COMMENT ON COLUMN story_time_entries.ability_units IS '使用能力所需整数单位';
COMMENT ON COLUMN story_time_entries.previous_owner_id IS '转移前所有者';
COMMENT ON COLUMN story_time_entries.revival_rule_id IS '明确解释复活的批准规则版本';
CREATE TRIGGER d02_no_change BEFORE UPDATE OR DELETE ON event_dependencies FOR EACH ROW EXECUTE FUNCTION c01_immutable();
CREATE TRIGGER d02_insert_guard BEFORE INSERT ON event_dependencies FOR EACH ROW EXECUTE FUNCTION d02_child_guard();
COMMENT ON TABLE event_dependencies IS '候选事件有向时间及因果约束';
COMMENT ON COLUMN event_dependencies.id IS '记录标识';
COMMENT ON COLUMN event_dependencies.novel_id IS '所属小说';
COMMENT ON COLUMN event_dependencies.context_id IS '候选时间线版本标识';
COMMENT ON COLUMN event_dependencies.predecessor_id IS '前置或容器事件';
COMMENT ON COLUMN event_dependencies.successor_id IS '后续或被包含事件';
COMMENT ON COLUMN event_dependencies.relation_type IS '时间关系或人物关系类型';
CREATE TRIGGER d02_no_change BEFORE UPDATE OR DELETE ON knowledge_facts FOR EACH ROW EXECUTE FUNCTION c01_immutable();
CREATE TRIGGER d02_insert_guard BEFORE INSERT ON knowledge_facts FOR EACH ROW EXECUTE FUNCTION d02_child_guard();
COMMENT ON TABLE knowledge_facts IS '带获得事件与知情途径的候选知识';
COMMENT ON COLUMN knowledge_facts.id IS '记录标识';
COMMENT ON COLUMN knowledge_facts.novel_id IS '所属小说';
COMMENT ON COLUMN knowledge_facts.context_id IS '候选时间线版本标识';
COMMENT ON COLUMN knowledge_facts.knower_id IS '知情角色';
COMMENT ON COLUMN knowledge_facts.fact_event_id IS '所知内容来源事件';
COMMENT ON COLUMN knowledge_facts.acquired_event_id IS '实际获得信息的事件';
COMMENT ON COLUMN knowledge_facts.method IS '目击、转述或推测途径';
COMMENT ON COLUMN knowledge_facts.source_knower_id IS '转述信息的已知角色';
CREATE TRIGGER d02_no_change BEFORE UPDATE OR DELETE ON relationships FOR EACH ROW EXECUTE FUNCTION c01_immutable();
CREATE TRIGGER d02_insert_guard BEFORE INSERT ON relationships FOR EACH ROW EXECUTE FUNCTION d02_child_guard();
COMMENT ON TABLE relationships IS '事件驱动的候选动态关系';
COMMENT ON COLUMN relationships.id IS '记录标识';
COMMENT ON COLUMN relationships.novel_id IS '所属小说';
COMMENT ON COLUMN relationships.context_id IS '候选时间线版本标识';
COMMENT ON COLUMN relationships.event_id IS '来源候选事件';
COMMENT ON COLUMN relationships.from_entity_id IS '关系主体';
COMMENT ON COLUMN relationships.to_entity_id IS '关系对象';
COMMENT ON COLUMN relationships.relation_type IS '时间关系或人物关系类型';
COMMENT ON COLUMN relationships.state IS '事件推动后的关系状态';
CREATE TRIGGER d02_no_change BEFORE UPDATE OR DELETE ON consistency_rules FOR EACH ROW EXECUTE FUNCTION c01_immutable();
CREATE TRIGGER d02_insert_guard BEFORE INSERT ON consistency_rules FOR EACH ROW EXECUTE FUNCTION d02_child_guard();
COMMENT ON TABLE consistency_rules IS '作者确认并绑定批准规则版本的结构化限制';
COMMENT ON COLUMN consistency_rules.id IS '记录标识';
COMMENT ON COLUMN consistency_rules.novel_id IS '所属小说';
COMMENT ON COLUMN consistency_rules.context_id IS '候选时间线版本标识';
COMMENT ON COLUMN consistency_rules.rule_version_id IS '批准的世界规则版本';
COMMENT ON COLUMN consistency_rules.kind IS '结构化限制类型';
COMMENT ON COLUMN consistency_rules.subject_id IS '规则主体，角色或起点';
COMMENT ON COLUMN consistency_rules.target_id IS '能力或目的地';
COMMENT ON COLUMN consistency_rules.limit_value IS '最少旅行秒数、最大能力单位或复活标记';
SQL);
    }

    public function down(): void
    {
        // 按来源依赖逆序回滚，仅供隔离库与受控迁移恢复。
        DB::unprepared('DROP TABLE consistency_rules,relationships,knowledge_facts,event_dependencies,story_time_entries,story_context_versions; DROP FUNCTION d02_child_guard(),d02_sealed_guard();');
    }
};
