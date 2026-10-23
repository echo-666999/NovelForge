<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('requirement_versions', function (Blueprint $t) {
            $t->unique(['novel_id', 'id'], 'b04_requirement_scope_unique');
        });
        Schema::create('blueprint_versions', function (Blueprint $t) {
            $t->id();
            $t->unsignedBigInteger('novel_id');
            $t->integer('version');
            $t->unsignedBigInteger('source_version_id');
            $t->unsignedBigInteger('outline_version_id');
            $t->unsignedBigInteger('base_version_id')->nullable();
            $t->string('status')->default('building');
            $t->string('schema_version')->default('blueprint-v1');
            $t->jsonb('content');
            $t->jsonb('impact');
            $t->string('content_hash', 64);
            $t->string('dependency_hash', 64);
            $t->text('reason');
            $t->foreignId('actor_id')->constrained('users')->restrictOnDelete();
            $t->timestampTz('created_at');
            $t->foreign('novel_id')->references('id')->on('novels')->restrictOnDelete();
            $t->unique(['novel_id', 'id']);
            $t->unique(['novel_id', 'version']);
            foreach (['source_version_id' => 'premise_versions', 'outline_version_id' => 'outline_versions', 'base_version_id' => 'blueprint_versions'] as $c => $table) {
                $t->foreign(['novel_id', $c])->references(['novel_id', 'id'])->on($table)->restrictOnDelete();
            }
        });
        DB::statement("ALTER TABLE blueprint_versions ADD CONSTRAINT blueprint_status CHECK (version>0 AND status IN ('building','sealed'))");
        foreach (['ending_contracts', 'blueprint_thread_versions', 'blueprint_entity_refs', 'blueprint_requirement_coverages', 'blueprint_validations', 'blueprint_approvals'] as $table) {
            Schema::create($table, function (Blueprint $t) use ($table) {
                $t->id();
                $t->unsignedBigInteger('novel_id');
                $t->unsignedBigInteger('blueprint_version_id');
                $t->foreign(['novel_id', 'blueprint_version_id'])->references(['novel_id', 'id'])->on('blueprint_versions')->restrictOnDelete();
                $t->unique(['novel_id', 'id']);
                if ($table === 'ending_contracts') {
                    $t->integer('version');
                    $t->jsonb('content');
                    $t->string('content_hash', 64);
                    $t->unique('blueprint_version_id');
                    $t->unique(['novel_id', 'version']);
                }
                if ($table === 'blueprint_thread_versions') {
                    $t->unsignedBigInteger('thread_id');
                    $t->string('kind');
                    $t->jsonb('content');
                    $t->foreign(['novel_id', 'thread_id'])->references(['novel_id', 'id'])->on('threads')->restrictOnDelete();
                    $t->unique(['blueprint_version_id', 'thread_id']);
                }
                if ($table === 'blueprint_entity_refs') {
                    $t->unsignedBigInteger('entity_definition_version_id');
                    $t->foreign(['novel_id', 'entity_definition_version_id'])->references(['novel_id', 'id'])->on('entity_definition_versions')->restrictOnDelete();
                    $t->unique(['blueprint_version_id', 'entity_definition_version_id'], 'blueprint_entity_unique');
                }
                if ($table === 'blueprint_requirement_coverages') {
                    $t->unsignedBigInteger('requirement_version_id');
                    $t->string('field');
                    $t->text('quote');
                    $t->foreign(['novel_id', 'requirement_version_id'])->references(['novel_id', 'id'])->on('requirement_versions')->restrictOnDelete();
                    $t->unique(['blueprint_version_id', 'requirement_version_id'], 'blueprint_coverage_unique');
                }
                if ($table === 'blueprint_validations') {
                    $t->foreignId('actor_id')->constrained('users')->restrictOnDelete();
                    $t->text('reason');
                    $t->jsonb('report');
                    $t->string('report_hash', 64);
                    $t->timestampTz('created_at');
                    $t->index(['blueprint_version_id', 'id']);
                }
                if ($table === 'blueprint_approvals') {
                    $t->unsignedBigInteger('validation_id');
                    $t->foreign(['novel_id', 'validation_id'])->references(['novel_id', 'id'])->on('blueprint_validations')->restrictOnDelete();
                    $t->string('report_hash', 64);
                    $t->text('reason');
                    $t->foreignId('actor_id')->constrained('users')->restrictOnDelete();
                    $t->timestampTz('created_at');
                    $t->unique('blueprint_version_id');
                }
            });
        }
        DB::statement("ALTER TABLE blueprint_thread_versions ADD CONSTRAINT blueprint_thread_kind CHECK (kind IN ('main','sub'))");
        DB::unprepared(<<<'SQL'
  CREATE OR REPLACE FUNCTION nf_blueprint_seal() RETURNS trigger LANGUAGE plpgsql AS $$ BEGIN
   IF TG_OP='UPDATE' AND OLD.status='building' AND NEW.status='sealed' AND (to_jsonb(OLD)-'status')=(to_jsonb(NEW)-'status') THEN
    IF NOT EXISTS(SELECT 1 FROM ending_contracts WHERE blueprint_version_id=NEW.id AND content=NEW.content->'ending') OR (SELECT count(*) FROM blueprint_thread_versions WHERE blueprint_version_id=NEW.id)<>jsonb_array_length(NEW.content->'threads') THEN RAISE EXCEPTION 'Incomplete blueprint' USING ERRCODE='23514'; END IF;
    IF (SELECT count(*) FROM blueprint_entity_refs WHERE blueprint_version_id=NEW.id)<>jsonb_array_length(NEW.content->'entity_version_ids') OR (SELECT count(*) FROM blueprint_requirement_coverages WHERE blueprint_version_id=NEW.id)<>jsonb_array_length(NEW.content->'coverages')
      OR EXISTS(SELECT 1 FROM jsonb_array_elements(NEW.content->'threads') x WHERE NOT EXISTS(SELECT 1 FROM blueprint_thread_versions t WHERE t.blueprint_version_id=NEW.id AND t.content=x))
      OR EXISTS(SELECT 1 FROM jsonb_array_elements_text(NEW.content->'entity_version_ids') x WHERE NOT EXISTS(SELECT 1 FROM blueprint_entity_refs e WHERE e.blueprint_version_id=NEW.id AND e.entity_definition_version_id::text=x))
      OR EXISTS(SELECT 1 FROM jsonb_array_elements(NEW.content->'coverages') x WHERE NOT EXISTS(SELECT 1 FROM blueprint_requirement_coverages c WHERE c.blueprint_version_id=NEW.id AND c.requirement_version_id::text=x->>'requirement_version_id' AND c.field=x->>'field' AND c.quote=x->>'quote')) THEN RAISE EXCEPTION 'Blueprint materialization mismatch' USING ERRCODE='23514'; END IF;
    RETURN NEW;
   END IF;
   RAISE EXCEPTION 'Blueprint history is immutable' USING ERRCODE='23514';
  END; $$;
  CREATE OR REPLACE FUNCTION nf_blueprint_child() RETURNS trigger LANGUAGE plpgsql AS $$ DECLARE s text; BEGIN
   IF TG_OP<>'INSERT' THEN RAISE EXCEPTION 'Blueprint records are immutable' USING ERRCODE='23514'; END IF;
   SELECT status INTO s FROM blueprint_versions WHERE id=NEW.blueprint_version_id AND novel_id=NEW.novel_id FOR UPDATE;
   IF TG_TABLE_NAME IN ('blueprint_validations','blueprint_approvals') THEN
    IF s IS DISTINCT FROM 'sealed' THEN RAISE EXCEPTION 'Blueprint not sealed' USING ERRCODE='23514'; END IF;
    IF TG_TABLE_NAME='blueprint_approvals' THEN
     IF NOT EXISTS(SELECT 1 FROM blueprint_validations WHERE id=NEW.validation_id AND blueprint_version_id=NEW.blueprint_version_id AND report_hash=NEW.report_hash AND report->>'passed'='true') THEN RAISE EXCEPTION 'Approval requires matching passed validation' USING ERRCODE='23514'; END IF;
    END IF;
   ELSIF s IS DISTINCT FROM 'building' THEN RAISE EXCEPTION 'Blueprint is sealed' USING ERRCODE='23514'; END IF;
   RETURN NEW;
  END; $$;
  CREATE TRIGGER blueprint_seal BEFORE UPDATE OR DELETE ON blueprint_versions FOR EACH ROW EXECUTE FUNCTION nf_blueprint_seal();
  SQL);
        foreach (['ending_contracts', 'blueprint_thread_versions', 'blueprint_entity_refs', 'blueprint_requirement_coverages', 'blueprint_validations', 'blueprint_approvals'] as $table) {
            DB::statement("CREATE TRIGGER blueprint_child BEFORE INSERT OR UPDATE OR DELETE ON {$table} FOR EACH ROW EXECUTE FUNCTION nf_blueprint_child()");
        }
    }

    public function down(): void
    {
        foreach (['blueprint_approvals', 'blueprint_validations', 'blueprint_requirement_coverages', 'blueprint_entity_refs', 'blueprint_thread_versions', 'ending_contracts', 'blueprint_versions'] as $table) {
            Schema::dropIfExists($table);
        }DB::statement('DROP FUNCTION IF EXISTS nf_blueprint_child()');
        DB::statement('DROP FUNCTION IF EXISTS nf_blueprint_seal()');
        Schema::table('requirement_versions', function (Blueprint $t) {
            $t->dropUnique('b04_requirement_scope_unique');
        });
    }
};
