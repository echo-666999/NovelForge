<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    // 规划可冻结，正式证据必须等待 Canonical Commit 完整来源链，不能用候选冒充。
    public function up(): void
    {
        DB::unprepared(<<<'SQL'

CREATE TABLE foreshadow_lineages (
 id bigserial PRIMARY KEY, novel_id bigint NOT NULL REFERENCES novels(id),
 actor_id bigint NOT NULL REFERENCES users(id), created_at timestamptz NOT NULL DEFAULT clock_timestamp(), UNIQUE(novel_id,id)
);
CREATE TABLE foreshadow_plans (
 id bigserial PRIMARY KEY, novel_id bigint NOT NULL, lineage_id bigint NOT NULL, version integer NOT NULL CHECK(version>0),
 blueprint_version_id bigint NOT NULL, thread_id bigint, status varchar(20) NOT NULL CHECK(status IN ('building','sealed')),
 lifecycle varchar(20) NOT NULL CHECK(lifecycle IN ('planned','abandoned')), name varchar(200) NOT NULL,
 plant_start integer NOT NULL CHECK(plant_start>0), plant_end integer NOT NULL CHECK(plant_end>=plant_start),
 payoff_target integer NOT NULL, payoff_start integer NOT NULL CHECK(payoff_start>plant_end), payoff_end integer NOT NULL CHECK(payoff_end>=payoff_start),
 required boolean NOT NULL, payload jsonb NOT NULL, content_hash char(64) NOT NULL,
 reason text NOT NULL, actor_id bigint NOT NULL REFERENCES users(id), created_at timestamptz NOT NULL DEFAULT clock_timestamp(),
 CHECK(payoff_target BETWEEN payoff_start AND payoff_end), UNIQUE(novel_id,id), UNIQUE(lineage_id,version),
 FOREIGN KEY(novel_id,lineage_id) REFERENCES foreshadow_lineages(novel_id,id),
 FOREIGN KEY(novel_id,blueprint_version_id) REFERENCES blueprint_versions(novel_id,id),
 FOREIGN KEY(blueprint_version_id,thread_id) REFERENCES blueprint_thread_versions(blueprint_version_id,thread_id)
);
CREATE INDEX d03_payoff ON foreshadow_plans(novel_id,payoff_target);
CREATE TABLE foreshadow_entity_refs (
 id bigserial PRIMARY KEY, novel_id bigint NOT NULL, plan_id bigint NOT NULL, entity_id bigint NOT NULL, definition_version_id bigint NOT NULL,
 role varchar(20) NOT NULL CHECK(role IN ('related','knower')), UNIQUE(plan_id,definition_version_id,role),
 FOREIGN KEY(novel_id,plan_id) REFERENCES foreshadow_plans(novel_id,id),
 FOREIGN KEY(novel_id,entity_id,definition_version_id) REFERENCES entity_definition_versions(novel_id,entity_id,id)
);
CREATE TABLE foreshadow_prerequisites (
 id bigserial PRIMARY KEY, novel_id bigint NOT NULL, plan_id bigint NOT NULL, event_id bigint NOT NULL,
 UNIQUE(plan_id,event_id), FOREIGN KEY(novel_id,plan_id) REFERENCES foreshadow_plans(novel_id,id),
 FOREIGN KEY(novel_id,event_id) REFERENCES proposed_events(novel_id,id)
);
CREATE TABLE foreshadow_proposals (
 id bigserial PRIMARY KEY, novel_id bigint NOT NULL, plan_id bigint NOT NULL, kind varchar(20) NOT NULL CHECK(kind IN ('reschedule','abandon')),
 payload jsonb NOT NULL, reason text NOT NULL, alternative text NOT NULL,
 actor_id bigint NOT NULL REFERENCES users(id), created_at timestamptz NOT NULL DEFAULT clock_timestamp(), UNIQUE(novel_id,id),
 FOREIGN KEY(novel_id,plan_id) REFERENCES foreshadow_plans(novel_id,id), CHECK(kind<>'abandon' OR length(trim(alternative))>0)
);
CREATE TABLE foreshadow_decisions (
 id bigserial PRIMARY KEY, novel_id bigint NOT NULL, proposal_id bigint NOT NULL UNIQUE, plan_id bigint,
 decision varchar(20) NOT NULL CHECK(decision IN ('approve','reject')), reason text NOT NULL,
 actor_id bigint NOT NULL REFERENCES users(id), created_at timestamptz NOT NULL DEFAULT clock_timestamp(),
 FOREIGN KEY(novel_id,proposal_id) REFERENCES foreshadow_proposals(novel_id,id), FOREIGN KEY(novel_id,plan_id) REFERENCES foreshadow_plans(novel_id,id),
 CHECK((decision='approve')=(plan_id IS NOT NULL))
);
CREATE TABLE foreshadow_freezes (
 id bigserial PRIMARY KEY, novel_id bigint NOT NULL, plan_id bigint NOT NULL UNIQUE, content_hash char(64) NOT NULL,
 reason text NOT NULL, actor_id bigint NOT NULL REFERENCES users(id), created_at timestamptz NOT NULL DEFAULT clock_timestamp(),
 FOREIGN KEY(novel_id,plan_id) REFERENCES foreshadow_plans(novel_id,id)
);
CREATE TABLE foreshadow_occurrences (
 id bigserial PRIMARY KEY, novel_id bigint NOT NULL, plan_id bigint NOT NULL, event_id bigint NOT NULL, evidence_id bigint NOT NULL,
 commit_id bigint NOT NULL, chapter_version_id bigint NOT NULL, revision_id bigint NOT NULL,
 kind varchar(20) NOT NULL CHECK(kind IN ('mention','plant','reinforce','explain','payoff')), causal_role text NOT NULL, new_information text NOT NULL,
 created_at timestamptz NOT NULL DEFAULT clock_timestamp(), UNIQUE(plan_id,event_id,kind),
 FOREIGN KEY(novel_id,plan_id) REFERENCES foreshadow_plans(novel_id,id), FOREIGN KEY(novel_id,event_id) REFERENCES story_events(novel_id,id),
 FOREIGN KEY(novel_id,evidence_id) REFERENCES evidence_spans(novel_id,id), CONSTRAINT d03_formal_entry_closed CHECK(false)
);
CREATE INDEX d03_occurrence_evidence ON foreshadow_occurrences(novel_id,plan_id,event_id);
CREATE OR REPLACE FUNCTION d03_seal_guard() RETURNS trigger LANGUAGE plpgsql AS $$
BEGIN
 IF OLD.status='sealed' OR TG_OP='DELETE' OR NEW.status<>'sealed' OR NEW.id<>OLD.id OR NEW.novel_id<>OLD.novel_id THEN RAISE EXCEPTION 'immutable foreshadow plan'; END IF;
 RETURN NEW;
END $$;
CREATE TRIGGER d03_sealed BEFORE UPDATE OR DELETE ON foreshadow_plans FOR EACH ROW EXECUTE FUNCTION d03_seal_guard();
CREATE OR REPLACE FUNCTION d03_child_guard() RETURNS trigger LANGUAGE plpgsql AS $$
BEGIN
 IF NOT EXISTS(SELECT 1 FROM foreshadow_plans WHERE id=NEW.plan_id AND novel_id=NEW.novel_id AND status='building' FOR UPDATE) THEN RAISE EXCEPTION 'sealed foreshadow references'; END IF;
 RETURN NEW;
END $$;
CREATE OR REPLACE FUNCTION d03_freeze_guard() RETURNS trigger LANGUAGE plpgsql AS $$
BEGIN
 IF NOT EXISTS(SELECT 1 FROM foreshadow_plans p JOIN blueprint_approvals a ON a.blueprint_version_id=p.blueprint_version_id AND a.novel_id=p.novel_id WHERE p.id=NEW.plan_id AND p.novel_id=NEW.novel_id AND p.status='sealed' AND p.lifecycle='planned' AND p.content_hash=NEW.content_hash) THEN RAISE EXCEPTION 'invalid foreshadow freeze'; END IF;
 RETURN NEW;
END $$;
CREATE TRIGGER d03_freeze_check BEFORE INSERT ON foreshadow_freezes FOR EACH ROW EXECUTE FUNCTION d03_freeze_guard();
COMMENT ON TABLE foreshadow_lineages IS '伏笔稳定谱系';
COMMENT ON COLUMN foreshadow_lineages.id IS '记录标识';
COMMENT ON COLUMN foreshadow_lineages.novel_id IS '所属小说';
COMMENT ON COLUMN foreshadow_lineages.actor_id IS '操作作者';
COMMENT ON COLUMN foreshadow_lineages.created_at IS '记录创建时间';
CREATE TRIGGER d03_immutable BEFORE UPDATE OR DELETE ON foreshadow_lineages FOR EACH ROW EXECUTE FUNCTION c01_immutable();
COMMENT ON TABLE foreshadow_plans IS '不可变伏笔规划版本';
COMMENT ON COLUMN foreshadow_plans.id IS '记录标识';
COMMENT ON COLUMN foreshadow_plans.novel_id IS '所属小说';
COMMENT ON COLUMN foreshadow_plans.lineage_id IS '伏笔稳定谱系标识';
COMMENT ON COLUMN foreshadow_plans.version IS '谱系内递增版本号';
COMMENT ON COLUMN foreshadow_plans.blueprint_version_id IS '绑定蓝图版本';
COMMENT ON COLUMN foreshadow_plans.thread_id IS '所属蓝图主支线标识';
COMMENT ON COLUMN foreshadow_plans.status IS '构建或封存状态';
COMMENT ON COLUMN foreshadow_plans.lifecycle IS '作者规划生命周期，不能代表正式埋设或回收';
COMMENT ON COLUMN foreshadow_plans.name IS '伏笔名称';
COMMENT ON COLUMN foreshadow_plans.plant_start IS '首次埋设计划窗口起章';
COMMENT ON COLUMN foreshadow_plans.plant_end IS '首次埋设计划窗口止章';
COMMENT ON COLUMN foreshadow_plans.payoff_target IS '计划回收目标章';
COMMENT ON COLUMN foreshadow_plans.payoff_start IS '允许回收窗口起章';
COMMENT ON COLUMN foreshadow_plans.payoff_end IS '允许回收窗口止章';
COMMENT ON COLUMN foreshadow_plans.required IS '是否必须回收';
COMMENT ON COLUMN foreshadow_plans.payload IS '不可变完整规划内容';
COMMENT ON COLUMN foreshadow_plans.content_hash IS '规范内容哈希';
COMMENT ON COLUMN foreshadow_plans.reason IS '作者操作理由';
COMMENT ON COLUMN foreshadow_plans.actor_id IS '操作作者';
COMMENT ON COLUMN foreshadow_plans.created_at IS '记录创建时间';
CREATE TRIGGER d03_audit AFTER INSERT ON foreshadow_plans FOR EACH ROW EXECUTE FUNCTION c07_audit_capture();
COMMENT ON TABLE foreshadow_entity_refs IS '计划关联实体及预期知情者，非已知事实';
COMMENT ON COLUMN foreshadow_entity_refs.id IS '记录标识';
COMMENT ON COLUMN foreshadow_entity_refs.novel_id IS '所属小说';
COMMENT ON COLUMN foreshadow_entity_refs.plan_id IS '不可变伏笔计划版本';
COMMENT ON COLUMN foreshadow_entity_refs.entity_id IS '稳定世界实体';
COMMENT ON COLUMN foreshadow_entity_refs.definition_version_id IS '批准的具体定义版本';
COMMENT ON COLUMN foreshadow_entity_refs.role IS '关联实体或计划知情角色';
CREATE TRIGGER d03_immutable BEFORE UPDATE OR DELETE ON foreshadow_entity_refs FOR EACH ROW EXECUTE FUNCTION c01_immutable();
CREATE TRIGGER d03_refs BEFORE INSERT ON foreshadow_entity_refs FOR EACH ROW EXECUTE FUNCTION d03_child_guard();
COMMENT ON TABLE foreshadow_prerequisites IS '计划前置候选事件，不等同正式满足';
COMMENT ON COLUMN foreshadow_prerequisites.id IS '记录标识';
COMMENT ON COLUMN foreshadow_prerequisites.novel_id IS '所属小说';
COMMENT ON COLUMN foreshadow_prerequisites.plan_id IS '不可变伏笔计划版本';
COMMENT ON COLUMN foreshadow_prerequisites.event_id IS '前置候选事件标识';
CREATE TRIGGER d03_immutable BEFORE UPDATE OR DELETE ON foreshadow_prerequisites FOR EACH ROW EXECUTE FUNCTION c01_immutable();
CREATE TRIGGER d03_refs BEFORE INSERT ON foreshadow_prerequisites FOR EACH ROW EXECUTE FUNCTION d03_child_guard();
COMMENT ON TABLE foreshadow_proposals IS '改期或放弃候选，不直接改变有效计划';
COMMENT ON COLUMN foreshadow_proposals.id IS '记录标识';
COMMENT ON COLUMN foreshadow_proposals.novel_id IS '所属小说';
COMMENT ON COLUMN foreshadow_proposals.plan_id IS '不可变伏笔计划版本';
COMMENT ON COLUMN foreshadow_proposals.kind IS '改期或放弃类型';
COMMENT ON COLUMN foreshadow_proposals.payload IS '不可变完整规划内容';
COMMENT ON COLUMN foreshadow_proposals.reason IS '作者操作理由';
COMMENT ON COLUMN foreshadow_proposals.alternative IS '放弃后的替代安排';
COMMENT ON COLUMN foreshadow_proposals.actor_id IS '操作作者';
COMMENT ON COLUMN foreshadow_proposals.created_at IS '记录创建时间';
CREATE TRIGGER d03_immutable BEFORE UPDATE OR DELETE ON foreshadow_proposals FOR EACH ROW EXECUTE FUNCTION c01_immutable();
CREATE TRIGGER d03_audit AFTER INSERT ON foreshadow_proposals FOR EACH ROW EXECUTE FUNCTION c07_audit_capture();
COMMENT ON TABLE foreshadow_decisions IS '作者对变更候选的不可变决定';
COMMENT ON COLUMN foreshadow_decisions.id IS '记录标识';
COMMENT ON COLUMN foreshadow_decisions.novel_id IS '所属小说';
COMMENT ON COLUMN foreshadow_decisions.proposal_id IS '被决定的变更候选';
COMMENT ON COLUMN foreshadow_decisions.plan_id IS '批准生成的新计划，拒绝时为空';
COMMENT ON COLUMN foreshadow_decisions.decision IS '批准或拒绝';
COMMENT ON COLUMN foreshadow_decisions.reason IS '作者操作理由';
COMMENT ON COLUMN foreshadow_decisions.actor_id IS '操作作者';
COMMENT ON COLUMN foreshadow_decisions.created_at IS '记录创建时间';
CREATE TRIGGER d03_immutable BEFORE UPDATE OR DELETE ON foreshadow_decisions FOR EACH ROW EXECUTE FUNCTION c01_immutable();
CREATE TRIGGER d03_audit AFTER INSERT ON foreshadow_decisions FOR EACH ROW EXECUTE FUNCTION c07_audit_capture();
COMMENT ON TABLE foreshadow_freezes IS '冻结原始伏笔承诺，不因改期追认旧验收';
COMMENT ON COLUMN foreshadow_freezes.id IS '记录标识';
COMMENT ON COLUMN foreshadow_freezes.novel_id IS '所属小说';
COMMENT ON COLUMN foreshadow_freezes.plan_id IS '不可变伏笔计划版本';
COMMENT ON COLUMN foreshadow_freezes.content_hash IS '规范内容哈希';
COMMENT ON COLUMN foreshadow_freezes.reason IS '作者操作理由';
COMMENT ON COLUMN foreshadow_freezes.actor_id IS '操作作者';
COMMENT ON COLUMN foreshadow_freezes.created_at IS '记录创建时间';
CREATE TRIGGER d03_immutable BEFORE UPDATE OR DELETE ON foreshadow_freezes FOR EACH ROW EXECUTE FUNCTION c01_immutable();
CREATE TRIGGER d03_audit AFTER INSERT ON foreshadow_freezes FOR EACH ROW EXECUTE FUNCTION c07_audit_capture();
COMMENT ON TABLE foreshadow_occurrences IS '正式伏笔正文证据预备结构，E06前禁止写入';
COMMENT ON COLUMN foreshadow_occurrences.id IS '记录标识';
COMMENT ON COLUMN foreshadow_occurrences.novel_id IS '所属小说';
COMMENT ON COLUMN foreshadow_occurrences.plan_id IS '不可变伏笔计划版本';
COMMENT ON COLUMN foreshadow_occurrences.event_id IS '正式故事事件';
COMMENT ON COLUMN foreshadow_occurrences.evidence_id IS '精确正文证据';
COMMENT ON COLUMN foreshadow_occurrences.commit_id IS '正式提交标识，E06补外键后开放';
COMMENT ON COLUMN foreshadow_occurrences.chapter_version_id IS '正式正文版本，E06补外键后开放';
COMMENT ON COLUMN foreshadow_occurrences.revision_id IS '正式基线，E06补外键后开放';
COMMENT ON COLUMN foreshadow_occurrences.kind IS '提及、埋设、强化、解释或因果回收';
COMMENT ON COLUMN foreshadow_occurrences.causal_role IS '对人物选择及终局因果的作用';
COMMENT ON COLUMN foreshadow_occurrences.new_information IS '相较旧线索新增的信息或选择影响';
COMMENT ON COLUMN foreshadow_occurrences.created_at IS '记录创建时间';
CREATE TRIGGER d03_immutable BEFORE UPDATE OR DELETE ON foreshadow_occurrences FOR EACH ROW EXECUTE FUNCTION c01_immutable();
SQL);
    }

    public function down(): void
    {
        // 逆依赖回滚，保留既有故事状态和蓝图结构。
        DB::unprepared('DROP TABLE foreshadow_occurrences,foreshadow_freezes,foreshadow_decisions,foreshadow_proposals,foreshadow_prerequisites,foreshadow_entity_refs,foreshadow_plans,foreshadow_lineages; DROP FUNCTION d03_freeze_guard(),d03_child_guard(),d03_seal_guard();');
    }
};
