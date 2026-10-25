<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        // 场景和章节正文均为不可变候选版本；本迁移不添加正式指针。
        foreach (['scene' => 'scenes', 'chapter' => 'chapters'] as $kind => $nodes) {
            $table = $kind.'_versions';
            DB::unprepared("CREATE TABLE {$table} (
 id bigserial PRIMARY KEY, novel_id bigint NOT NULL, node_id bigint NOT NULL, plan_id bigint NOT NULL,
 version integer NOT NULL CHECK(version>0), parent_version_id bigint,
 title text NOT NULL, body text NOT NULL, raw_body text NOT NULL,
 body_hash varchar(64) NOT NULL CHECK(body_hash ~ '^[0-9a-f]{64}$'), character_count integer NOT NULL CHECK(character_count>=0),
 source_artifact_id bigint, run_id bigint, status varchar(30) NOT NULL CHECK(status IN ('drafting','revision_required')),
 report jsonb NOT NULL, sources jsonb NOT NULL, actor_id bigint NOT NULL REFERENCES users(id), reason text NOT NULL,
 created_at timestamptz NOT NULL DEFAULT clock_timestamp(), UNIQUE(novel_id,id), UNIQUE(novel_id,node_id,id), UNIQUE(novel_id,node_id,version), UNIQUE(source_artifact_id),
 FOREIGN KEY(novel_id,node_id) REFERENCES {$nodes}(novel_id,id),
 FOREIGN KEY(novel_id,plan_id) REFERENCES rolling_plans(novel_id,id),
 FOREIGN KEY(novel_id,node_id,parent_version_id) REFERENCES {$table}(novel_id,node_id,id),
 FOREIGN KEY(novel_id,run_id,source_artifact_id) REFERENCES generation_artifacts(novel_id,run_id,id),
 CHECK((source_artifact_id IS NULL)=(run_id IS NULL)), CHECK(body_hash=encode(sha256(convert_to(body,'UTF8')),'hex'))
 );
 CREATE TRIGGER e02_capture AFTER INSERT ON {$table} FOR EACH ROW EXECUTE FUNCTION c07_audit_capture();
 CREATE TRIGGER e02_immutable BEFORE UPDATE OR DELETE ON {$table} FOR EACH ROW EXECUTE FUNCTION c01_immutable();");
            $label = $kind === 'scene' ? '场景' : '章节';
            DB::statement("COMMENT ON TABLE {$table} IS '不可变{$label}候选正文版本；不代表正式提交'");
            $comments = ['id' => '正文版本主键', 'novel_id' => '所属小说', 'node_id' => '同书结构节点', 'plan_id' => '正文使用的冻结滚动计划', 'version' => '节点内递增版本号', 'parent_version_id' => '同节点编辑来源版本', 'title' => '具体标题快照', 'body' => '仅换行规范化后的正文', 'raw_body' => '原始输入正文', 'body_hash' => '实际存储正文UTF8字节SHA256', 'character_count' => '排除空白和控制格式字符后的可见码点数', 'source_artifact_id' => '生成来源的成功产物；手工编辑为空', 'run_id' => '来源产物所属运行', 'status' => '候选草稿或需要修订；永非正式', 'report' => '字数、完整性与协议检查报告', 'sources' => '精确场景版本、哈希与前文衔接来源', 'actor_id' => '创建管理员', 'reason' => '生成、编辑或自动保存说明', 'created_at' => '创建时间'];
            foreach ($comments as $column => $comment) {
                DB::statement("COMMENT ON COLUMN {$table}.{$column} IS '{$comment}'");
            }
        }
        // 章节的来源关系按场景顺序存储，复合外键阻止跨书拼接。
        DB::unprepared(<<<'SQL'
CREATE TABLE chapter_scene_versions (
 novel_id bigint NOT NULL, chapter_version_id bigint NOT NULL, scene_version_id bigint NOT NULL, position integer NOT NULL CHECK(position>0),
 PRIMARY KEY(chapter_version_id,position), UNIQUE(chapter_version_id,scene_version_id),
 FOREIGN KEY(novel_id,chapter_version_id) REFERENCES chapter_versions(novel_id,id),
 FOREIGN KEY(novel_id,scene_version_id) REFERENCES scene_versions(novel_id,id)
);
CREATE OR REPLACE FUNCTION e02_link_scope() RETURNS trigger LANGUAGE plpgsql AS $$
BEGIN
 IF NOT EXISTS (
  SELECT 1 FROM chapter_versions c JOIN scene_versions v ON v.id=NEW.scene_version_id AND v.novel_id=c.novel_id AND v.plan_id=c.plan_id
  JOIN scenes s ON s.id=v.node_id AND s.novel_id=c.novel_id AND s.parent_id=c.node_id
  JOIN rolling_plans p ON p.id=c.plan_id
  JOIN plan_nodes ch ON ch.outline_version_id=p.outline_version_id AND ch.chapter_id=c.node_id
  JOIN plan_nodes sc ON sc.parent_plan_node_id=ch.id AND sc.scene_id=v.node_id
  WHERE c.id=NEW.chapter_version_id AND c.novel_id=NEW.novel_id AND NEW.position=(SELECT count(*)+1 FROM plan_nodes prior WHERE prior.parent_plan_node_id=ch.id AND prior.node_type='scene' AND prior.sequence<sc.sequence)
 ) THEN RAISE EXCEPTION 'E02 scene assembly scope or position mismatch'; END IF;
 RETURN NEW;
END $$;
CREATE TRIGGER e02_links_scope BEFORE INSERT ON chapter_scene_versions FOR EACH ROW EXECUTE FUNCTION e02_link_scope();
CREATE TRIGGER e02_links_immutable BEFORE UPDATE OR DELETE ON chapter_scene_versions FOR EACH ROW EXECUTE FUNCTION c01_immutable();
COMMENT ON TABLE chapter_scene_versions IS '章节候选组装的有序场景版本来源';
COMMENT ON COLUMN chapter_scene_versions.novel_id IS '所属小说';
COMMENT ON COLUMN chapter_scene_versions.chapter_version_id IS '组装章节候选版本';
COMMENT ON COLUMN chapter_scene_versions.scene_version_id IS '精确场景候选版本';
COMMENT ON COLUMN chapter_scene_versions.position IS '正文拼接顺序，从一开始';
SQL);
    }

    public function down(): void
    {
        // 子表先移除；不对有业务数据的开发库执行回滚。
        DB::unprepared('DROP TABLE chapter_scene_versions, chapter_versions, scene_versions; DROP FUNCTION e02_link_scope();');
    }
};
