<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        // 三类配置共用生命周期约束，正文连草稿也不可覆盖；编辑总是派生新版本。
        foreach (['prompt' => 'prompt_templates', 'route' => 'model_routes', 'style' => 'style_profiles'] as $kind => $table) {
            $versions = $kind.'_versions';
            DB::unprepared("CREATE TABLE {$table} (id bigserial PRIMARY KEY, key varchar(100) NOT NULL UNIQUE, stage varchar(80) NOT NULL, language varchar(30) NOT NULL, default_version_id bigint, created_at timestamptz NOT NULL DEFAULT clock_timestamp());
CREATE TABLE {$versions} (id bigserial PRIMARY KEY, parent_id bigint NOT NULL REFERENCES {$table}(id) ON DELETE RESTRICT, version integer NOT NULL CHECK(version>0), content jsonb NOT NULL, content_hash char(64) NOT NULL, status varchar(20) NOT NULL DEFAULT 'draft' CHECK(status IN ('draft','tested','published','retired')), actor_id bigint NOT NULL REFERENCES users(id) ON DELETE RESTRICT, reason text NOT NULL, created_at timestamptz NOT NULL DEFAULT clock_timestamp(), UNIQUE(parent_id,version), UNIQUE(parent_id,id));
ALTER TABLE {$table} ADD FOREIGN KEY(id,default_version_id) REFERENCES {$versions}(parent_id,id) ON DELETE RESTRICT;
CREATE INDEX {$versions}_state ON {$versions}(status,parent_id);");
        }
        DB::unprepared(<<<'SQL'
ALTER TABLE route_versions ADD prompt_version_id bigint NOT NULL REFERENCES prompt_versions(id) ON DELETE RESTRICT, ADD style_version_id bigint NOT NULL REFERENCES style_versions(id) ON DELETE RESTRICT;
ALTER TABLE route_versions ADD CONSTRAINT c04_route_references CHECK ((content->>'prompt_version_id')::bigint = prompt_version_id AND (content->>'style_version_id')::bigint = style_version_id);
CREATE TABLE configuration_regressions (
 id bigserial PRIMARY KEY, kind varchar(20) NOT NULL CHECK(kind IN ('prompt','route','style')),
 version_id bigint NOT NULL, content_hash char(64) NOT NULL, passed boolean NOT NULL,
 report jsonb NOT NULL, actor_id bigint NOT NULL REFERENCES users(id) ON DELETE RESTRICT,
 created_at timestamptz NOT NULL DEFAULT clock_timestamp()
);
CREATE INDEX c04_regression_version ON configuration_regressions(kind,version_id,id DESC);
CREATE TABLE configuration_commands (
 id bigserial PRIMARY KEY, actor_id bigint NOT NULL REFERENCES users(id) ON DELETE RESTRICT,
 command_key varchar(128) NOT NULL, request_hash char(64) NOT NULL, response jsonb NOT NULL,
 created_at timestamptz NOT NULL DEFAULT clock_timestamp(), UNIQUE(actor_id,command_key)
);
ALTER TABLE generation_runs ADD configuration_route_version_id bigint REFERENCES route_versions(id) ON DELETE RESTRICT;
ALTER TABLE ai_requests ADD route_version_id bigint REFERENCES route_versions(id) ON DELETE RESTRICT, ADD prompt_version_id bigint REFERENCES prompt_versions(id) ON DELETE RESTRICT, ADD style_version_id bigint REFERENCES style_versions(id) ON DELETE RESTRICT, ADD configuration_hash char(64);
CREATE OR REPLACE FUNCTION c04_version_guard() RETURNS trigger LANGUAGE plpgsql AS $$
BEGIN
 IF TG_OP='DELETE' THEN RAISE EXCEPTION 'C04 immutable version'; END IF;
 IF (to_jsonb(NEW)-'status') IS DISTINCT FROM (to_jsonb(OLD)-'status') THEN RAISE EXCEPTION 'C04 immutable content'; END IF;
 IF NOT ((OLD.status='draft' AND NEW.status='tested') OR (OLD.status='tested' AND NEW.status='published') OR (OLD.status='published' AND NEW.status='retired')) THEN RAISE EXCEPTION 'C04 invalid transition'; END IF;
 IF NEW.status IN ('tested','published') AND NOT EXISTS(SELECT 1 FROM configuration_regressions WHERE kind=TG_ARGV[0] AND version_id=OLD.id AND content_hash=OLD.content_hash AND passed=true) THEN RAISE EXCEPTION 'C04 regression required'; END IF;
 RETURN NEW;
END $$;
CREATE OR REPLACE FUNCTION c04_parent_guard() RETURNS trigger LANGUAGE plpgsql AS $$
DECLARE chosen record;
BEGIN
 IF TG_OP='DELETE' THEN RAISE EXCEPTION 'C04 immutable identity'; END IF;
 IF (to_jsonb(NEW)-'default_version_id') IS DISTINCT FROM (to_jsonb(OLD)-'default_version_id') THEN RAISE EXCEPTION 'C04 immutable identity'; END IF;
 IF NEW.default_version_id IS NOT NULL THEN
 EXECUTE format('SELECT status FROM %I WHERE id=$1 AND parent_id=$2',TG_ARGV[0]) INTO chosen USING NEW.default_version_id,NEW.id;
 IF chosen.status IS DISTINCT FROM 'published' THEN RAISE EXCEPTION 'C04 default must be published'; END IF;
 END IF;
 RETURN NEW;
END $$;
CREATE OR REPLACE FUNCTION c04_regression_guard() RETURNS trigger LANGUAGE plpgsql AS $$
DECLARE found_hash text;
BEGIN
 EXECUTE format('SELECT content_hash FROM %I WHERE id=$1',NEW.kind || '_versions') INTO found_hash USING NEW.version_id;
 IF found_hash IS DISTINCT FROM NEW.content_hash THEN RAISE EXCEPTION 'C04 regression source mismatch'; END IF;
 RETURN NEW;
END $$;
CREATE TRIGGER c04_regression_source BEFORE INSERT ON configuration_regressions FOR EACH ROW EXECUTE FUNCTION c04_regression_guard();
SQL);
        foreach (['prompt' => 'prompt_templates', 'route' => 'model_routes', 'style' => 'style_profiles'] as $kind => $table) {
            DB::statement("CREATE TRIGGER c04_version BEFORE UPDATE OR DELETE ON {$kind}_versions FOR EACH ROW EXECUTE FUNCTION c04_version_guard('{$kind}')");
            DB::statement("CREATE TRIGGER c04_parent BEFORE UPDATE OR DELETE ON {$table} FOR EACH ROW EXECUTE FUNCTION c04_parent_guard('{$kind}_versions')");
        }
        foreach (['configuration_commands', 'configuration_regressions'] as $table) {
            DB::statement("CREATE TRIGGER c04_immutable BEFORE UPDATE OR DELETE ON {$table} FOR EACH ROW EXECUTE FUNCTION c01_immutable()");
        }
        // 数据库中文备注覆盖所有新增表和字段；运行与请求中的引用也写入备注。
        $tables = ['prompt_templates' => '提示词模板身份', 'prompt_versions' => '不可变提示词版本', 'model_routes' => '模型路由身份', 'route_versions' => '不可变主备路由版本', 'style_profiles' => '文风与评审策略身份', 'style_versions' => '不可变文风评分策略版本', 'configuration_regressions' => '离线配置回归证据（非模型实测）', 'configuration_commands' => '配置命令幂等结果'];
        $comments = ['id' => '记录主键', 'key' => '稳定配置键', 'stage' => '使用阶段', 'language' => '使用语言', 'default_version_id' => '仅用于新运行的默认发布版本', 'created_at' => '创建时间', 'parent_id' => '配置稳定身份', 'version' => '递增版本号', 'content' => '不可变配置正文', 'content_hash' => '规范化正文摘要', 'status' => '草稿、已测试、已发布或已退役', 'actor_id' => '操作管理员', 'reason' => '版本变更或发布说明', 'kind' => '配置类型', 'version_id' => '回归所属配置版本', 'passed' => '离线回归是否通过', 'report' => '校验明细及离线限制', 'command_key' => '命令幂等键', 'request_hash' => '命令输入摘要', 'response' => '首次响应快照', 'prompt_version_id' => '冻结提示词版本', 'style_version_id' => '冻结文风评审策略版本'];
        foreach ($tables as $table => $comment) {
            DB::statement("COMMENT ON TABLE {$table} IS '{$comment}'");
            foreach (DB::select('SELECT column_name FROM information_schema.columns WHERE table_schema=current_schema() AND table_name=?', [$table]) as $col) {
                DB::statement("COMMENT ON COLUMN {$table}.{$col->column_name} IS '".$comments[$col->column_name]."'");
            }
        }
        foreach (['generation_runs' => ['configuration_route_version_id' => '运行冻结路由版本，旧支架运行可为空'], 'ai_requests' => ['route_version_id' => '实际请求冻结路由版本', 'prompt_version_id' => '实际请求冻结提示词版本', 'style_version_id' => '实际请求冻结文风评审策略版本', 'configuration_hash' => '运行配置及渲染输入快照摘要']] as $table => $cols) {
            foreach ($cols as $col => $comment) {
                DB::statement("COMMENT ON COLUMN {$table}.{$col} IS '{$comment}'");
            }
        }
    }

    public function down(): void
    {
        // 先移除外部引用，再解除默认版本的环形外键。
        DB::unprepared('ALTER TABLE ai_requests DROP COLUMN route_version_id, DROP COLUMN prompt_version_id, DROP COLUMN style_version_id, DROP COLUMN configuration_hash; ALTER TABLE generation_runs DROP COLUMN configuration_route_version_id; DROP TABLE configuration_commands, configuration_regressions; DROP TABLE model_routes, route_versions, prompt_templates, prompt_versions, style_profiles, style_versions; DROP FUNCTION c04_version_guard(), c04_parent_guard(), c04_regression_guard();');
    }
};
