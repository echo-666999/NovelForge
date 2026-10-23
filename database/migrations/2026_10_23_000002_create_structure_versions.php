<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        foreach (['volumes' => null, 'story_arcs' => 'volumes', 'chapters' => 'story_arcs', 'scenes' => 'chapters'] as $table => $parent) {
            Schema::create($table, function (Blueprint $t) use ($table, $parent) {
                $t->id();
                $t->foreignId('novel_id')->constrained()->restrictOnDelete();
                if ($parent) {
                    $t->unsignedBigInteger('parent_id');
                    $t->foreign(['novel_id', 'parent_id'])->references(['novel_id', 'id'])->on($parent)->restrictOnDelete();
                }
                $t->unsignedBigInteger('source_version_id');
                $t->foreign(['novel_id', 'source_version_id'])->references(['novel_id', 'id'])->on('premise_versions')->restrictOnDelete();
                $t->integer('sequence');
                $t->string('title');
                $t->text('objective');
                $t->text('summary')->default('');
                $t->jsonb('details');
                $t->string('status')->default('skeleton');
                $t->unsignedBigInteger('row_version')->default(1);
                $t->text('reason');
                if (in_array($table, ['volumes', 'story_arcs'])) {
                    $t->integer('start_chapter');
                    $t->integer('end_chapter');
                }
                $t->timestampsTz();
                $t->unique(['novel_id', 'id']);
                $t->unique(in_array($table, ['volumes', 'chapters']) ? ['novel_id', 'sequence'] : ['parent_id', 'sequence']);
            });
            DB::statement("ALTER TABLE {$table} ADD CONSTRAINT {$table}_valid CHECK (sequence > 0 AND row_version > 0 AND status IN ('skeleton','detailed') AND length(btrim(title)) > 0)");
            if (in_array($table, ['volumes', 'story_arcs'])) {
                DB::statement("ALTER TABLE {$table} ADD CONSTRAINT {$table}_range CHECK (start_chapter > 0 AND end_chapter >= start_chapter)");
            }
        }
        Schema::create('threads', function (Blueprint $t) {
            $t->id();
            $t->foreignId('novel_id')->constrained()->restrictOnDelete();
            $t->string('title');
            $t->text('objective');
            $t->unsignedBigInteger('row_version')->default(1);
            $t->text('reason');
            $t->timestampsTz();
            $t->unique(['novel_id', 'id']);
        });
        Schema::create('arc_thread', function (Blueprint $t) {
            $t->unsignedBigInteger('novel_id');
            $t->unsignedBigInteger('arc_id');
            $t->unsignedBigInteger('thread_id');
            $t->foreign(['novel_id', 'arc_id'])->references(['novel_id', 'id'])->on('story_arcs')->restrictOnDelete();
            $t->foreign(['novel_id', 'thread_id'])->references(['novel_id', 'id'])->on('threads')->restrictOnDelete();
            $t->primary(['arc_id', 'thread_id']);
        });
        Schema::create('outline_versions', function (Blueprint $t) {
            $t->id();
            $t->foreignId('novel_id')->constrained()->restrictOnDelete();
            $t->integer('version');
            $t->unsignedBigInteger('source_version_id');
            $t->foreign(['novel_id', 'source_version_id'])->references(['novel_id', 'id'])->on('premise_versions')->restrictOnDelete();
            $t->unsignedBigInteger('base_version_id')->nullable();
            $t->unsignedBigInteger('input_row_version');
            $t->string('status')->default('building');
            $t->jsonb('content');
            $t->string('content_hash', 64);
            $t->string('schema_version')->default('structure-v1');
            $t->text('reason');
            $t->foreignId('actor_id')->constrained('users')->restrictOnDelete();
            $t->timestampTz('created_at');
            $t->unique(['novel_id', 'id']);
            $t->unique(['novel_id', 'version']);
            $t->foreign(['novel_id', 'base_version_id'])->references(['novel_id', 'id'])->on('outline_versions')->restrictOnDelete();
        });
        DB::statement("ALTER TABLE outline_versions ADD CONSTRAINT outline_status CHECK (version > 0 AND status IN ('building','sealed'))");
        Schema::create('plan_nodes', function (Blueprint $t) {
            $t->id();
            $t->unsignedBigInteger('novel_id');
            $t->unsignedBigInteger('outline_version_id');
            $t->string('node_type');
            $t->unsignedBigInteger('parent_plan_node_id')->nullable();
            foreach (['volume' => 'volumes', 'story_arc' => 'story_arcs', 'chapter' => 'chapters', 'scene' => 'scenes'] as $type => $table) {
                $t->unsignedBigInteger($type.'_id')->nullable();
                $t->foreign(['novel_id', $type.'_id'])->references(['novel_id', 'id'])->on($table)->restrictOnDelete();
            }
            $t->integer('sequence');
            $t->jsonb('details');
            $t->string('content_hash', 64);
            $t->foreign(['novel_id', 'outline_version_id'])->references(['novel_id', 'id'])->on('outline_versions')->restrictOnDelete();
            $t->unique(['novel_id', 'outline_version_id', 'id']);
            $t->foreign(['novel_id', 'outline_version_id', 'parent_plan_node_id'])->references(['novel_id', 'outline_version_id', 'id'])->on('plan_nodes')->restrictOnDelete();
            foreach (['volume', 'story_arc', 'chapter', 'scene'] as $type) {
                $t->unique(['outline_version_id', $type.'_id']);
            }
        });
        DB::statement("ALTER TABLE plan_nodes ADD CONSTRAINT plan_node_type CHECK (sequence > 0 AND num_nonnulls(volume_id,story_arc_id,chapter_id,scene_id)=1 AND ((node_type='volume' AND volume_id IS NOT NULL AND parent_plan_node_id IS NULL) OR (node_type='story_arc' AND story_arc_id IS NOT NULL AND parent_plan_node_id IS NOT NULL) OR (node_type='chapter' AND chapter_id IS NOT NULL AND parent_plan_node_id IS NOT NULL) OR (node_type='scene' AND scene_id IS NOT NULL AND parent_plan_node_id IS NOT NULL)))");
        DB::statement("CREATE UNIQUE INDEX plan_global_sequence ON plan_nodes(outline_version_id,node_type,sequence) WHERE node_type IN ('volume','chapter')");
        DB::statement("CREATE UNIQUE INDEX plan_parent_sequence ON plan_nodes(outline_version_id,parent_plan_node_id,sequence) WHERE node_type IN ('story_arc','scene')");
        DB::unprepared(<<<'SQL'
        CREATE OR REPLACE FUNCTION nf_structure_range_guard() RETURNS trigger LANGUAGE plpgsql AS $$
        DECLARE pstart integer; pend integer;
        BEGIN
          PERFORM 1 FROM novels WHERE id=NEW.novel_id FOR UPDATE;
          IF TG_OP='UPDATE' AND NEW.novel_id<>OLD.novel_id THEN RAISE EXCEPTION 'Stable node cannot change novels' USING ERRCODE='23514'; END IF;
          IF TG_TABLE_NAME='volumes' THEN
            IF NEW.end_chapter > (SELECT target_chapters FROM novels WHERE id=NEW.novel_id) THEN RAISE EXCEPTION 'Volume exceeds novel target' USING ERRCODE='23514'; END IF;
            IF EXISTS(SELECT 1 FROM volumes WHERE novel_id=NEW.novel_id AND id<>NEW.id AND start_chapter<=NEW.end_chapter AND end_chapter>=NEW.start_chapter)
              OR EXISTS(SELECT 1 FROM story_arcs WHERE parent_id=NEW.id AND (start_chapter<NEW.start_chapter OR end_chapter>NEW.end_chapter)) THEN
              RAISE EXCEPTION 'Volume range overlaps or excludes children' USING ERRCODE='23514'; END IF;
          ELSIF TG_TABLE_NAME='story_arcs' THEN
            SELECT start_chapter,end_chapter INTO pstart,pend FROM volumes WHERE novel_id=NEW.novel_id AND id=NEW.parent_id;
            IF NEW.start_chapter<pstart OR NEW.end_chapter>pend OR EXISTS(SELECT 1 FROM story_arcs WHERE novel_id=NEW.novel_id AND id<>NEW.id AND start_chapter<=NEW.end_chapter AND end_chapter>=NEW.start_chapter)
              OR EXISTS(SELECT 1 FROM chapters WHERE parent_id=NEW.id AND (sequence<NEW.start_chapter OR sequence>NEW.end_chapter)) THEN
              RAISE EXCEPTION 'Arc range invalid' USING ERRCODE='23514'; END IF;
          ELSIF TG_TABLE_NAME='chapters' THEN
            SELECT start_chapter,end_chapter INTO pstart,pend FROM story_arcs WHERE novel_id=NEW.novel_id AND id=NEW.parent_id;
            IF NEW.sequence<pstart OR NEW.sequence>pend THEN RAISE EXCEPTION 'Chapter outside arc range' USING ERRCODE='23514'; END IF;
          END IF;
          RETURN NEW;
        END; $$;
        CREATE OR REPLACE FUNCTION nf_outline_immutable() RETURNS trigger LANGUAGE plpgsql AS $$
        BEGIN
          IF TG_OP='UPDATE' AND OLD.status='building' AND NEW.status='sealed' AND (to_jsonb(OLD)-'status')=(to_jsonb(NEW)-'status') THEN
            IF (SELECT count(*) FROM plan_nodes WHERE outline_version_id=NEW.id) <> jsonb_array_length(NEW.content->'nodes') THEN
              RAISE EXCEPTION 'Incomplete structure snapshot' USING ERRCODE='23514'; END IF;
            IF EXISTS(SELECT 1 FROM jsonb_array_elements(NEW.content->'nodes') AS x(value) WHERE NOT EXISTS(SELECT 1 FROM plan_nodes p WHERE p.outline_version_id=NEW.id AND p.details=x.value)) THEN RAISE EXCEPTION 'Snapshot contents differ from version nodes' USING ERRCODE='23514'; END IF;
            RETURN NEW;
          END IF;
          RAISE EXCEPTION 'Structure versions are immutable' USING ERRCODE='23514';
        END; $$;
        CREATE OR REPLACE FUNCTION nf_plan_node_guard() RETURNS trigger LANGUAGE plpgsql AS $$
        DECLARE version_status text; parent_type text; parent_stable_id text; stable_table text; stable_id bigint; live_node jsonb;
        BEGIN
          IF TG_OP<>'INSERT' THEN RAISE EXCEPTION 'Plan nodes are immutable' USING ERRCODE='23514'; END IF;
          SELECT status INTO version_status FROM outline_versions WHERE novel_id=NEW.novel_id AND id=NEW.outline_version_id FOR UPDATE;
          IF version_status IS DISTINCT FROM 'building' THEN RAISE EXCEPTION 'Snapshot is sealed' USING ERRCODE='23514'; END IF;
          IF NEW.parent_plan_node_id IS NOT NULL THEN
            SELECT node_type,details->>'id' INTO parent_type,parent_stable_id FROM plan_nodes WHERE novel_id=NEW.novel_id AND outline_version_id=NEW.outline_version_id AND id=NEW.parent_plan_node_id;
            IF parent_stable_id IS DISTINCT FROM NEW.details->>'parent_id' THEN RAISE EXCEPTION 'Plan parent does not match snapshot' USING ERRCODE='23514'; END IF;
            IF parent_type IS DISTINCT FROM (CASE NEW.node_type WHEN 'story_arc' THEN 'volume' WHEN 'chapter' THEN 'story_arc' WHEN 'scene' THEN 'chapter' END) THEN
              RAISE EXCEPTION 'Invalid plan parent type' USING ERRCODE='23514'; END IF;
          END IF;
          stable_table := CASE NEW.node_type WHEN 'volume' THEN 'volumes' WHEN 'story_arc' THEN 'story_arcs' WHEN 'chapter' THEN 'chapters' WHEN 'scene' THEN 'scenes' END;
          stable_id := CASE NEW.node_type WHEN 'volume' THEN NEW.volume_id WHEN 'story_arc' THEN NEW.story_arc_id WHEN 'chapter' THEN NEW.chapter_id WHEN 'scene' THEN NEW.scene_id END;
          IF stable_table IS NULL THEN RAISE EXCEPTION 'Invalid node type' USING ERRCODE='23514'; END IF;
          EXECUTE format('SELECT to_jsonb(n) FROM %I n WHERE id=$1 AND novel_id=$2',stable_table) INTO live_node USING stable_id,NEW.novel_id;
          IF live_node IS NULL OR NEW.details->>'id' IS DISTINCT FROM stable_id::text OR NEW.details->>'type' IS DISTINCT FROM NEW.node_type
             OR NEW.details->>'parent_id' IS DISTINCT FROM live_node->>'parent_id'
             OR NEW.sequence::text IS DISTINCT FROM live_node->>'sequence'
             OR NEW.details->>'source_version_id' IS DISTINCT FROM live_node->>'source_version_id'
             OR NEW.details->>'title' IS DISTINCT FROM live_node->>'title'
             OR NEW.details->'details' IS DISTINCT FROM live_node->'details' THEN
            RAISE EXCEPTION 'Plan node does not match stable node' USING ERRCODE='23514';
          END IF;
          RETURN NEW;
        END; $$;
        CREATE OR REPLACE FUNCTION nf_novel_target_guard() RETURNS trigger LANGUAGE plpgsql AS $$
        BEGIN
          IF EXISTS(SELECT 1 FROM volumes WHERE novel_id=NEW.id AND end_chapter>NEW.target_chapters) THEN RAISE EXCEPTION 'Target excludes planned volumes' USING ERRCODE='23514'; END IF;
          RETURN NEW;
        END; $$;
        CREATE TRIGGER novel_target_guard BEFORE UPDATE OF target_chapters ON novels FOR EACH ROW EXECUTE FUNCTION nf_novel_target_guard();
        CREATE TRIGGER outline_immutable BEFORE UPDATE OR DELETE ON outline_versions FOR EACH ROW EXECUTE FUNCTION nf_outline_immutable();
        CREATE TRIGGER plan_node_guard BEFORE INSERT OR UPDATE OR DELETE ON plan_nodes FOR EACH ROW EXECUTE FUNCTION nf_plan_node_guard();
        SQL);
        foreach (['volumes', 'story_arcs', 'chapters', 'scenes'] as $table) {
            DB::statement("CREATE TRIGGER structure_guard BEFORE INSERT OR UPDATE ON {$table} FOR EACH ROW EXECUTE FUNCTION nf_structure_range_guard()");
        }
    }

    public function down(): void
    {
        DB::statement('DROP TRIGGER IF EXISTS novel_target_guard ON novels');
        DB::statement('DROP FUNCTION IF EXISTS nf_novel_target_guard()');
        foreach (['plan_nodes', 'outline_versions', 'arc_thread', 'threads', 'scenes', 'chapters', 'story_arcs', 'volumes'] as $table) {
            Schema::dropIfExists($table);
        }
        foreach (['nf_plan_node_guard', 'nf_outline_immutable', 'nf_structure_range_guard'] as $fn) {
            DB::statement("DROP FUNCTION IF EXISTS {$fn}()");
        }
    }
};
