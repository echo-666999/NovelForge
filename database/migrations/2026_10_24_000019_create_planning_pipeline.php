<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        // 输入提炼发生在大纲之前；仅有明确规划协议的运行可没有旧大纲。
        DB::unprepared(<<<'SQL'
ALTER TABLE generation_runs ALTER COLUMN outline_version_id DROP NOT NULL;
ALTER TABLE generation_runs ADD CONSTRAINT e01_outline_or_planning CHECK(outline_version_id IS NOT NULL OR coalesce(frozen_config->>'schema_version','')='planning-v1');
COMMENT ON COLUMN generation_runs.outline_version_id IS '冻结大纲版本；E01前置规划允许为空，其他运行必须有大纲';
CREATE TABLE planning_stages (
 id bigserial PRIMARY KEY, novel_id bigint NOT NULL, run_id bigint NOT NULL UNIQUE,
 phase varchar(30) NOT NULL CHECK(phase IN ('intake','blueprint','outline','chapter_plan','scene_plan')),
 parent_id bigint, parent_receipt_id bigint, created_at timestamptz NOT NULL DEFAULT clock_timestamp(),
 UNIQUE(novel_id,id), FOREIGN KEY(novel_id,run_id) REFERENCES generation_runs(novel_id,id),
 FOREIGN KEY(novel_id,parent_id) REFERENCES planning_stages(novel_id,id),
 CHECK((phase='intake')=(parent_id IS NULL)), CHECK((parent_id IS NULL)=(parent_receipt_id IS NULL))
);
CREATE INDEX e01_stage_history ON planning_stages(novel_id,id DESC);
CREATE TABLE planning_adoptions (
 id bigserial PRIMARY KEY, novel_id bigint NOT NULL, stage_id bigint NOT NULL UNIQUE, run_id bigint NOT NULL,
 artifact_id bigint NOT NULL, actor_id bigint NOT NULL REFERENCES users(id),
 before_version bigint NOT NULL, after_version bigint NOT NULL, CHECK(before_version>0 AND after_version>=before_version), reason text NOT NULL, result jsonb NOT NULL,
 created_at timestamptz NOT NULL DEFAULT clock_timestamp(), UNIQUE(novel_id,id),
 FOREIGN KEY(novel_id,stage_id) REFERENCES planning_stages(novel_id,id),
 FOREIGN KEY(novel_id,run_id,artifact_id) REFERENCES generation_artifacts(novel_id,run_id,id)
);
ALTER TABLE planning_stages ADD CONSTRAINT e01_parent_receipt_scope FOREIGN KEY(novel_id,parent_receipt_id) REFERENCES planning_adoptions(novel_id,id);
CREATE OR REPLACE FUNCTION e01_planning_parent() RETURNS trigger LANGUAGE plpgsql AS $$
DECLARE parent_phase text; adopted_stage bigint; run_stage bigint;
BEGIN
 IF TG_TABLE_NAME='planning_stages' THEN
 IF NEW.parent_id IS NOT NULL THEN
  SELECT phase INTO parent_phase FROM planning_stages WHERE id=NEW.parent_id;
  SELECT stage_id INTO adopted_stage FROM planning_adoptions WHERE id=NEW.parent_receipt_id;
  IF adopted_stage IS DISTINCT FROM NEW.parent_id OR array_position(ARRAY['intake','blueprint','outline','chapter_plan','scene_plan'],NEW.phase) IS DISTINCT FROM array_position(ARRAY['intake','blueprint','outline','chapter_plan','scene_plan'],parent_phase)+1 THEN RAISE EXCEPTION 'E01 invalid planning dependency'; END IF;
 END IF;
 ELSIF TG_TABLE_NAME='planning_adoptions' THEN
  SELECT run_id INTO run_stage FROM planning_stages WHERE id=NEW.stage_id AND novel_id=NEW.novel_id;
  IF run_stage IS DISTINCT FROM NEW.run_id OR NOT EXISTS(SELECT 1 FROM generation_artifacts a JOIN generation_steps s ON s.id=a.step_id WHERE a.id=NEW.artifact_id AND a.run_id=NEW.run_id AND a.type='planning_candidate' AND s.status='succeeded') THEN RAISE EXCEPTION 'E01 adoption run mismatch'; END IF;
 END IF;
 RETURN NEW;
END $$;
CREATE TRIGGER e01_parent BEFORE INSERT ON planning_stages FOR EACH ROW EXECUTE FUNCTION e01_planning_parent();
CREATE TRIGGER e01_adoption_scope BEFORE INSERT ON planning_adoptions FOR EACH ROW EXECUTE FUNCTION e01_planning_parent();
CREATE TRIGGER e01_stage_immutable BEFORE UPDATE OR DELETE ON planning_stages FOR EACH ROW EXECUTE FUNCTION c01_immutable();
CREATE TRIGGER e01_adoption_immutable BEFORE UPDATE OR DELETE ON planning_adoptions FOR EACH ROW EXECUTE FUNCTION c01_immutable();
SQL);
        $tables = ['planning_stages' => '离线规划五阶段有向无环依赖与运行引用', 'planning_adoptions' => '作者采纳规划候选的不可变来源和结果'];
        $comments = ['id' => '记录主键', 'novel_id' => '所属小说', 'run_id' => '已有编排运行', 'phase' => '提炼、蓝图、骨架、章计划或场景计划', 'parent_id' => '同书前一阶段身份', 'parent_receipt_id' => '前一阶段作者采纳回执', 'created_at' => '创建时间', 'stage_id' => '本次采纳的规划阶段', 'artifact_id' => '精确绑定的成功候选产物', 'actor_id' => '采纳管理员', 'before_version' => '采纳前书级版本', 'after_version' => '采纳后书级版本', 'reason' => '作者采纳说明', 'result' => '创建的要求、结构、蓝图和覆盖建议映射'];
        foreach ($tables as $table => $comment) {
            DB::statement("COMMENT ON TABLE {$table} IS '{$comment}'");
            foreach (DB::select('SELECT column_name FROM information_schema.columns WHERE table_schema=current_schema() AND table_name=?', [$table]) as $column) {
                DB::statement("COMMENT ON COLUMN {$table}.{$column->column_name} IS '".$comments[$column->column_name]."'");
            }
        }
    }

    public function down(): void
    {
        // 回滚前先解除依赖环；仅测试空库执行，含规划运行的开发库不强制降级。
        DB::unprepared('ALTER TABLE planning_stages DROP CONSTRAINT e01_parent_receipt_scope; DROP TABLE planning_adoptions, planning_stages; DROP FUNCTION e01_planning_parent(); ALTER TABLE generation_runs DROP CONSTRAINT e01_outline_or_planning; ALTER TABLE generation_runs ALTER COLUMN outline_version_id SET NOT NULL;');
    }
};
