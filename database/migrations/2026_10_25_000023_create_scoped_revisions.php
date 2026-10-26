<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        // 所有修订历史追加保存，采纳只指向候选正文，正式状态写入口仍关闭。
        DB::unprepared(<<<'SQL'
CREATE TABLE revision_tasks (
 id bigserial PRIMARY KEY, novel_id bigint NOT NULL, chapter_node_id bigint NOT NULL, source_version_id bigint NOT NULL, review_id bigint NOT NULL, predecessor_id bigint,
 mode varchar(20) NOT NULL CHECK(mode IN ('offline','manual')), automatic_round int NOT NULL CHECK(automatic_round>=0), max_rounds int NOT NULL CHECK(max_rounds BETWEEN 0 AND 3),
 input jsonb NOT NULL, input_hash char(64) NOT NULL, initial_status varchar(30) NOT NULL CHECK(initial_status IN ('draft','manual_required')),
 actor_id bigint NOT NULL REFERENCES users(id), reason text NOT NULL, created_at timestamptz NOT NULL DEFAULT clock_timestamp(), UNIQUE(novel_id,id), UNIQUE(novel_id,id,review_id),
 FOREIGN KEY(novel_id,chapter_node_id) REFERENCES chapters(novel_id,id), FOREIGN KEY(novel_id,source_version_id) REFERENCES chapter_versions(novel_id,id), FOREIGN KEY(novel_id,review_id) REFERENCES reviews(novel_id,id), FOREIGN KEY(novel_id,predecessor_id) REFERENCES revision_tasks(novel_id,id), CHECK(predecessor_id IS NULL OR predecessor_id<id)
);
CREATE INDEX e05_rounds ON revision_tasks(novel_id,chapter_node_id,id DESC);
CREATE TABLE revision_task_issues (
 novel_id bigint NOT NULL, task_id bigint NOT NULL, review_id bigint NOT NULL, issue_id bigint NOT NULL, PRIMARY KEY(task_id,issue_id),
 FOREIGN KEY(novel_id,task_id,review_id) REFERENCES revision_tasks(novel_id,id,review_id), FOREIGN KEY(novel_id,review_id,issue_id) REFERENCES review_issues(novel_id,review_id,id)
);
CREATE TABLE revision_task_facts (
 novel_id bigint NOT NULL, task_id bigint NOT NULL, event_id bigint NOT NULL, PRIMARY KEY(task_id,event_id),
 FOREIGN KEY(novel_id,task_id) REFERENCES revision_tasks(novel_id,id), FOREIGN KEY(novel_id,event_id) REFERENCES proposed_events(novel_id,id)
);
CREATE TABLE revision_proposals (
 id bigserial PRIMARY KEY, novel_id bigint NOT NULL, task_id bigint NOT NULL, run_id bigint, artifact_id bigint UNIQUE,
 output jsonb NOT NULL, output_hash char(64) NOT NULL, report jsonb NOT NULL, created_at timestamptz NOT NULL DEFAULT clock_timestamp(), UNIQUE(novel_id,task_id,id),
 FOREIGN KEY(novel_id,task_id) REFERENCES revision_tasks(novel_id,id), FOREIGN KEY(novel_id,run_id,artifact_id) REFERENCES generation_artifacts(novel_id,run_id,id), CHECK((run_id IS NULL)=(artifact_id IS NULL))
);
CREATE TABLE revision_decisions (
 id bigserial PRIMARY KEY, novel_id bigint NOT NULL, task_id bigint NOT NULL, proposal_id bigint, kind varchar(20) NOT NULL CHECK(kind IN ('accept','manual','reject','abandon')),
 selection jsonb NOT NULL, diff jsonb NOT NULL, target_version_id bigint, actor_id bigint NOT NULL REFERENCES users(id), reason text NOT NULL, created_at timestamptz NOT NULL DEFAULT clock_timestamp(), UNIQUE(novel_id,task_id,id), UNIQUE(novel_id,id),
 FOREIGN KEY(novel_id,task_id) REFERENCES revision_tasks(novel_id,id), FOREIGN KEY(novel_id,task_id,proposal_id) REFERENCES revision_proposals(novel_id,task_id,id), FOREIGN KEY(novel_id,target_version_id) REFERENCES chapter_versions(novel_id,id), CHECK((kind IN ('accept','manual'))=(target_version_id IS NOT NULL))
);
CREATE UNIQUE INDEX e05_one_adoption ON revision_decisions(task_id) WHERE target_version_id IS NOT NULL;
CREATE UNIQUE INDEX e05_target ON revision_decisions(target_version_id) WHERE target_version_id IS NOT NULL;
CREATE TABLE revision_validations (
 id bigserial PRIMARY KEY, novel_id bigint NOT NULL, task_id bigint NOT NULL, decision_id bigint NOT NULL, extraction_id bigint NOT NULL, report jsonb NOT NULL, content_hash char(64) NOT NULL, created_at timestamptz NOT NULL DEFAULT clock_timestamp(), UNIQUE(novel_id,id),
 FOREIGN KEY(novel_id,task_id,decision_id) REFERENCES revision_decisions(novel_id,task_id,id), FOREIGN KEY(novel_id,extraction_id) REFERENCES extraction_attempts(novel_id,id)
);
CREATE TABLE revision_assessments (
 id bigserial PRIMARY KEY, novel_id bigint NOT NULL, task_id bigint NOT NULL, validation_id bigint NOT NULL, review_id bigint NOT NULL, report jsonb NOT NULL, created_at timestamptz NOT NULL DEFAULT clock_timestamp(),
 FOREIGN KEY(novel_id,task_id) REFERENCES revision_tasks(novel_id,id), FOREIGN KEY(novel_id,validation_id) REFERENCES revision_validations(novel_id,id), FOREIGN KEY(novel_id,review_id) REFERENCES reviews(novel_id,id)
);
CREATE OR REPLACE FUNCTION e05_scope() RETURNS trigger LANGUAGE plpgsql AS $$
BEGIN
 IF TG_TABLE_NAME='revision_tasks' THEN
  IF NOT EXISTS(SELECT 1 FROM reviews r JOIN chapter_versions c ON c.id=r.chapter_version_id WHERE r.novel_id=NEW.novel_id AND r.id=NEW.review_id AND r.chapter_version_id=NEW.source_version_id AND c.node_id=NEW.chapter_node_id) THEN RAISE EXCEPTION 'E05 source review mismatch'; END IF;
 ELSIF TG_TABLE_NAME='revision_proposals' THEN
  IF NEW.run_id IS NOT NULL AND NOT EXISTS(SELECT 1 FROM generation_artifacts a JOIN generation_runs r ON r.id=a.run_id WHERE a.novel_id=NEW.novel_id AND a.id=NEW.artifact_id AND a.run_id=NEW.run_id AND a.type='revision_candidate' AND r.frozen_config->>'schema_version'='revision-v1' AND r.frozen_config->>'task_id'=NEW.task_id::text AND a.content_hash=NEW.output_hash AND a.content=NEW.output) THEN RAISE EXCEPTION 'E05 proposal origin mismatch'; END IF;
 ELSIF TG_TABLE_NAME='revision_decisions' THEN
  IF NEW.target_version_id IS NOT NULL AND NOT EXISTS(SELECT 1 FROM revision_tasks t JOIN chapter_versions c ON c.novel_id=t.novel_id AND c.node_id=t.chapter_node_id WHERE t.id=NEW.task_id AND t.novel_id=NEW.novel_id AND c.id=NEW.target_version_id AND c.parent_version_id=t.source_version_id AND c.body=NEW.diff->>'after') THEN RAISE EXCEPTION 'E05 candidate adoption mismatch'; END IF;
 ELSIF TG_TABLE_NAME='revision_validations' THEN
  IF NOT EXISTS(SELECT 1 FROM revision_decisions d JOIN extraction_attempts e ON e.novel_id=d.novel_id AND e.chapter_version_id=d.target_version_id WHERE d.id=NEW.decision_id AND d.task_id=NEW.task_id AND d.novel_id=NEW.novel_id AND e.id=NEW.extraction_id) THEN RAISE EXCEPTION 'E05 validation source mismatch'; END IF;
 ELSE
  IF NOT EXISTS(SELECT 1 FROM revision_validations v JOIN reviews r ON r.novel_id=v.novel_id AND r.extraction_id=v.extraction_id WHERE v.id=NEW.validation_id AND v.task_id=NEW.task_id AND v.novel_id=NEW.novel_id AND r.id=NEW.review_id) THEN RAISE EXCEPTION 'E05 assessment source mismatch'; END IF;
 END IF;
 RETURN NEW;
END $$;
SQL);
        $tables = ['revision_task_issues' => ['冻结修订问题的同书评审强关联', ['novel_id', 'task_id', 'review_id', 'issue_id']], 'revision_task_facts' => ['必须保留候选事实的同书强关联', ['novel_id', 'task_id', 'event_id']], 'revision_tasks' => ['冻结问题、允许范围及保留事实的候选修订任务', ['id', 'novel_id', 'chapter_node_id', 'source_version_id', 'review_id', 'predecessor_id', 'mode', 'automatic_round', 'max_rounds', 'input', 'input_hash', 'initial_status', 'actor_id', 'reason', 'created_at']], 'revision_proposals' => ['不可变修订补丁与范围校验结果', ['id', 'novel_id', 'task_id', 'run_id', 'artifact_id', 'output', 'output_hash', 'report', 'created_at']], 'revision_decisions' => ['逐项采纳、手工编辑或放弃的追加决定', ['id', 'novel_id', 'task_id', 'proposal_id', 'kind', 'selection', 'diff', 'target_version_id', 'actor_id', 'reason', 'created_at']], 'revision_validations' => ['新正文重提取后的事实与原文保留门禁', ['id', 'novel_id', 'task_id', 'decision_id', 'extraction_id', 'report', 'content_hash', 'created_at']], 'revision_assessments' => ['完整章节复评与改善判断的不可变结果', ['id', 'novel_id', 'task_id', 'validation_id', 'review_id', 'report', 'created_at']]];
        $comments = ['issue_id' => '冻结来源评审中的实际问题', 'event_id' => '必须保留的同书候选事件', 'id' => '记录标识', 'novel_id' => '所属小说', 'chapter_node_id' => '稳定章节节点', 'source_version_id' => '冻结原候选正文版本', 'review_id' => '绑定的完整章节评审', 'predecessor_id' => '本章前一修订任务；不以新键重置轮次', 'mode' => '明确离线自动补丁或人工编辑', 'automatic_round' => '本章拟用自动文学轮次；获准时预留，与技术尝试独立', 'max_rounds' => '冻结自动轮次上限，最多三轮', 'input' => '原文、问题、范围和全部必须保留事实快照', 'input_hash' => '冻结输入哈希', 'initial_status' => '初始草稿或必须人工处理', 'actor_id' => '操作管理员', 'reason' => '明确操作原因', 'created_at' => '记录时间', 'task_id' => '同书修订任务', 'run_id' => '可选注册离线运行', 'artifact_id' => '精确输出产物', 'output' => '原始补丁响应', 'output_hash' => '响应规范化哈希', 'report' => '候选校验或复评报告', 'proposal_id' => '被选择的补丁提案', 'kind' => '采纳、手工编辑、拒绝或放弃', 'selection' => '逐项选择或人工补丁原始列表', 'diff' => '修改前后全文、坐标及原因', 'target_version_id' => '仅候选的新正文版本', 'decision_id' => '本次采纳决定', 'extraction_id' => '新正文的完整E03提取', 'content_hash' => '事实保留验证报告哈希', 'validation_id' => '绑定新正文与提取的保留验证'];
        foreach ($tables as $table => [$description,$columns]) {
            DB::statement("COMMENT ON TABLE {$table} IS '{$description}'");
            foreach ($columns as $column) {
                DB::statement("COMMENT ON COLUMN {$table}.{$column} IS '{$comments[$column]}'");
            }
            DB::statement("CREATE TRIGGER e05_immutable BEFORE UPDATE OR DELETE ON {$table} FOR EACH ROW EXECUTE FUNCTION c01_immutable()");
            if (! in_array($table, ['revision_task_issues', 'revision_task_facts'], true)) {
                DB::statement("CREATE TRIGGER e05_scope BEFORE INSERT ON {$table} FOR EACH ROW EXECUTE FUNCTION e05_scope()");
            }
            // 复合键关联表由父任务完整快照审计覆盖；通用审计要求单列id。
            if (! in_array($table, ['revision_task_issues', 'revision_task_facts'], true)) {
                DB::statement("CREATE TRIGGER e05_audit AFTER INSERT ON {$table} FOR EACH ROW EXECUTE FUNCTION c07_audit_capture()");
            }
        }
    }

    public function down(): void
    {
        // 只撤销候选修订记录，不修改已有正文和正式状态。
        DB::unprepared('DROP TABLE revision_assessments,revision_validations,revision_decisions,revision_proposals,revision_task_facts,revision_task_issues,revision_tasks; DROP FUNCTION e05_scope();');
    }
};
