<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // 合并场景只退出当前草稿，不删除旧版本引用的稳定标识。
        Schema::table('scenes', fn (Blueprint $t) => $t->boolean('retired')->default(false));
        Schema::table('plan_nodes', fn (Blueprint $t) => $t->unique(['novel_id', 'id'], 'b05_plan_node_scope_unique'));
        Schema::create('rolling_plans', function (Blueprint $t) {
            $t->id();
            $t->unsignedBigInteger('novel_id');
            $t->integer('version');
            $t->unsignedBigInteger('outline_version_id');
            $t->unsignedBigInteger('blueprint_version_id');
            $t->integer('start_chapter');
            $t->integer('end_chapter');
            $t->string('status')->default('building');
            $t->jsonb('content');
            $t->string('content_hash', 64);
            $t->text('reason');
            $t->foreignId('actor_id')->constrained('users')->restrictOnDelete();
            $t->timestampTz('created_at');
            $t->unique(['novel_id', 'id']);
            $t->unique(['novel_id', 'version']);
            $t->unique('outline_version_id');
            foreach (['outline_version_id' => 'outline_versions', 'blueprint_version_id' => 'blueprint_versions'] as $c => $table) {
                $t->foreign(['novel_id', $c])->references(['novel_id', 'id'])->on($table)->restrictOnDelete();
            }
        });
        DB::statement("ALTER TABLE rolling_plans ADD CHECK (version>0 AND start_chapter>0 AND end_chapter>=start_chapter AND end_chapter-start_chapter<10 AND status IN ('building','sealed'))");
        foreach (['requirement_coverages', 'plan_validations', 'plan_freezes'] as $table) {
            Schema::create($table, function (Blueprint $t) use ($table) {
                $t->id();
                $t->unsignedBigInteger('novel_id');
                $t->unsignedBigInteger('rolling_plan_id');
                $t->foreign(['novel_id', 'rolling_plan_id'])->references(['novel_id', 'id'])->on('rolling_plans')->restrictOnDelete();
                $t->unique(['novel_id', 'id']);
                if ($table === 'requirement_coverages') {
                    $t->unsignedBigInteger('requirement_version_id');
                    $t->unsignedBigInteger('plan_node_id');
                    $t->string('field');
                    $t->text('quote');
                    $t->integer('start_offset');
                    $t->integer('end_offset');
                    $t->string('source_hash', 64);
                    foreach (['requirement_version_id' => 'requirement_versions', 'plan_node_id' => 'plan_nodes'] as $c => $ref) {
                        $t->foreign(['novel_id', $c])->references(['novel_id', 'id'])->on($ref)->restrictOnDelete();
                    }
                    $t->unique(['rolling_plan_id', 'requirement_version_id', 'plan_node_id', 'field'], 'plan_coverage_unique');
                    $t->index(['novel_id', 'requirement_version_id']);
                } else {
                    $t->text('reason');
                    $t->foreignId('actor_id')->constrained('users')->restrictOnDelete();
                    $t->timestampTz('created_at');
                    $t->string('report_hash', 64);
                    if ($table === 'plan_validations') {
                        $t->jsonb('report');
                        $t->index(['rolling_plan_id', 'id']);
                    } else {
                        $t->unsignedBigInteger('validation_id');
                        $t->foreign(['novel_id', 'validation_id'])->references(['novel_id', 'id'])->on('plan_validations')->restrictOnDelete();
                        $t->unique('rolling_plan_id');
                    }
                }
            });
        }
        // 封存、覆盖证据和冻结审计均由数据库守护，避免绕过应用服务改写历史。
        DB::unprepared(<<<'SQL'
CREATE OR REPLACE FUNCTION nf_plan_seal() RETURNS trigger LANGUAGE plpgsql AS $$ BEGIN
 IF TG_OP='INSERT' THEN
  IF NEW.status IS DISTINCT FROM 'building' OR NOT EXISTS(SELECT 1 FROM blueprint_approvals WHERE novel_id=NEW.novel_id AND blueprint_version_id=NEW.blueprint_version_id) OR NOT EXISTS(SELECT 1 FROM outline_versions WHERE novel_id=NEW.novel_id AND id=NEW.outline_version_id AND status='sealed') THEN RAISE EXCEPTION 'Plan must start building with approved blueprint and sealed outline' USING ERRCODE='23514'; END IF;
  RETURN NEW;
 END IF;
 IF TG_OP='UPDATE' AND OLD.status='building' AND NEW.status='sealed' AND (to_jsonb(OLD)-'status')=(to_jsonb(NEW)-'status') THEN
  IF (SELECT count(*) FROM requirement_coverages WHERE rolling_plan_id=NEW.id)<>jsonb_array_length(NEW.content->'coverages')
    OR EXISTS(SELECT 1 FROM jsonb_array_elements(NEW.content->'coverages') x WHERE NOT EXISTS(SELECT 1 FROM requirement_coverages c WHERE c.rolling_plan_id=NEW.id AND c.requirement_version_id::text=x->>'requirement_version_id' AND c.plan_node_id::text=x->>'plan_node_id' AND c.field=x->>'field' AND c.quote=x->>'quote' AND c.start_offset=(x->>'start_offset')::int AND c.end_offset=(x->>'end_offset')::int AND c.source_hash=x->>'source_hash')) THEN RAISE EXCEPTION 'Plan coverage mismatch' USING ERRCODE='23514'; END IF;
  RETURN NEW;
 END IF;
 RAISE EXCEPTION 'Plan history immutable' USING ERRCODE='23514';
END $$;
CREATE TRIGGER plan_seal BEFORE INSERT OR UPDATE OR DELETE ON rolling_plans FOR EACH ROW EXECUTE FUNCTION nf_plan_seal();
CREATE OR REPLACE FUNCTION nf_plan_child() RETURNS trigger LANGUAGE plpgsql AS $$ DECLARE p rolling_plans; n plan_nodes; evidence text; BEGIN
 IF TG_OP<>'INSERT' THEN RAISE EXCEPTION 'Plan evidence immutable' USING ERRCODE='23514'; END IF;
 SELECT * INTO p FROM rolling_plans WHERE id=NEW.rolling_plan_id AND novel_id=NEW.novel_id FOR UPDATE;
 IF TG_TABLE_NAME='requirement_coverages' THEN
  SELECT * INTO n FROM plan_nodes WHERE id=NEW.plan_node_id AND novel_id=NEW.novel_id;
  evidence := n.details #>> string_to_array(NEW.field,'.');
  IF p.status IS DISTINCT FROM 'building' OR n.outline_version_id IS DISTINCT FROM p.outline_version_id OR evidence IS NULL OR NEW.start_offset<0 OR NEW.end_offset<=NEW.start_offset OR NEW.end_offset>char_length(evidence) OR encode(sha256(convert_to(evidence,'UTF8')),'hex') IS DISTINCT FROM NEW.source_hash OR substring(evidence FROM NEW.start_offset+1 FOR NEW.end_offset-NEW.start_offset) IS DISTINCT FROM NEW.quote THEN RAISE EXCEPTION 'Invalid plan evidence' USING ERRCODE='23514'; END IF;
 ELSE
  IF p.status IS DISTINCT FROM 'sealed' THEN RAISE EXCEPTION 'Plan not sealed' USING ERRCODE='23514'; END IF;
  IF TG_TABLE_NAME='plan_freezes' THEN
  IF NOT EXISTS(SELECT 1 FROM plan_validations WHERE id=NEW.validation_id AND rolling_plan_id=p.id AND report_hash=NEW.report_hash AND report->>'passed'='true') THEN RAISE EXCEPTION 'Freeze requires passed matching check' USING ERRCODE='23514'; END IF; END IF;
 END IF;
 RETURN NEW;
END $$;
SQL);
        foreach (['requirement_coverages', 'plan_validations', 'plan_freezes'] as $table) {
            DB::statement("CREATE TRIGGER plan_child BEFORE INSERT OR UPDATE OR DELETE ON {$table} FOR EACH ROW EXECUTE FUNCTION nf_plan_child()");
        }
        // 已冻结窗口的章、场景及祖先不可原地调整；同书锁与冻结事务使用同一锁顺序。
        DB::unprepared(<<<'SQL'
CREATE OR REPLACE FUNCTION nf_frozen_structure() RETURNS trigger LANGUAGE plpgsql AS $$ DECLARE book bigint; ident bigint; seq int; parent bigint; BEGIN
 IF TG_OP='DELETE' THEN book:=OLD.novel_id; ident:=OLD.id; ELSE book:=NEW.novel_id; ident:=NEW.id; END IF;
 PERFORM 1 FROM novels WHERE id=book FOR UPDATE;
 IF EXISTS(SELECT 1 FROM novels WHERE id=book AND state_version>0) THEN RAISE EXCEPTION 'Formal history requires change workflow' USING ERRCODE='23514'; END IF;
 IF EXISTS(SELECT 1 FROM rolling_plans p JOIN plan_freezes f ON f.rolling_plan_id=p.id JOIN plan_nodes n ON n.outline_version_id=p.outline_version_id
 WHERE p.novel_id=book AND ((TG_TABLE_NAME='chapters' AND n.chapter_id=ident AND n.sequence BETWEEN p.start_chapter AND p.end_chapter)
 OR (TG_TABLE_NAME='scenes' AND n.scene_id=ident AND EXISTS(SELECT 1 FROM plan_nodes c WHERE c.id=n.parent_plan_node_id AND c.sequence BETWEEN p.start_chapter AND p.end_chapter))
 OR (TG_TABLE_NAME='volumes' AND n.volume_id=ident AND (n.details->>'start_chapter')::int<=p.end_chapter AND (n.details->>'end_chapter')::int>=p.start_chapter)
 OR (TG_TABLE_NAME='story_arcs' AND n.story_arc_id=ident AND (n.details->>'start_chapter')::int<=p.end_chapter AND (n.details->>'end_chapter')::int>=p.start_chapter))) THEN RAISE EXCEPTION 'Frozen structure requires history change' USING ERRCODE='23514'; END IF;
 IF TG_OP<>'DELETE' AND TG_TABLE_NAME='scenes' THEN
  SELECT sequence INTO seq FROM chapters WHERE id=NEW.parent_id AND novel_id=book;
  IF EXISTS(SELECT 1 FROM rolling_plans p JOIN plan_freezes f ON f.rolling_plan_id=p.id WHERE p.novel_id=book AND seq BETWEEN p.start_chapter AND p.end_chapter) THEN RAISE EXCEPTION 'Frozen chapter cannot add or move scene' USING ERRCODE='23514'; END IF;
 END IF;
 IF TG_OP='DELETE' THEN RETURN OLD; END IF; RETURN NEW;
END $$;
SQL);
        foreach (['volumes', 'story_arcs', 'chapters', 'scenes'] as $table) {
            DB::statement("CREATE TRIGGER frozen_structure BEFORE INSERT OR UPDATE OR DELETE ON {$table} FOR EACH ROW EXECUTE FUNCTION nf_frozen_structure()");
        }
    }

    public function down(): void
    {
        foreach (['volumes', 'story_arcs', 'chapters', 'scenes'] as $table) {
            DB::statement("DROP TRIGGER IF EXISTS frozen_structure ON {$table}");
        }
        DB::statement('DROP FUNCTION IF EXISTS nf_frozen_structure()');
        foreach (['plan_freezes', 'plan_validations', 'requirement_coverages', 'rolling_plans'] as $table) {
            Schema::dropIfExists($table);
        }
        DB::statement('DROP FUNCTION IF EXISTS nf_plan_child()');
        DB::statement('DROP FUNCTION IF EXISTS nf_plan_seal()');
        Schema::table('scenes', fn (Blueprint $t) => $t->dropColumn('retired'));
        Schema::table('plan_nodes', fn (Blueprint $t) => $t->dropUnique('b05_plan_node_scope_unique'));
    }
};
