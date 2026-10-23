<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('entities', function (Blueprint $t) {
            $t->id();
            $t->foreignId('novel_id')->constrained()->restrictOnDelete();
            $t->string('type');
            $t->string('name');
            $t->unsignedBigInteger('current_version_id')->nullable();
            $t->unsignedBigInteger('approved_version_id')->nullable();
            $t->boolean('retired')->default(false);
            $t->unsignedBigInteger('row_version')->default(1);
            $t->timestampsTz();
            $t->unique(['novel_id', 'id']);
            $t->index(['novel_id', 'type', 'id']);
        });
        DB::statement("ALTER TABLE entities ADD CONSTRAINT entity_type CHECK (type IN ('character','location','organization','item','ability','rule') AND row_version>0)");
        Schema::create('entity_definition_versions', function (Blueprint $t) {
            $t->id();
            $t->unsignedBigInteger('novel_id');
            $t->unsignedBigInteger('entity_id');
            $t->integer('version');
            $t->unsignedBigInteger('source_version_id');
            $t->text('source_quote');
            $t->unsignedBigInteger('alias_origin_version_id')->nullable();
            $t->string('status')->default('building');
            $t->jsonb('definition');
            $t->string('content_hash', 64);
            $t->text('reason');
            $t->foreignId('actor_id')->constrained('users')->restrictOnDelete();
            $t->timestampTz('created_at');
            $t->foreign(['novel_id', 'entity_id'])->references(['novel_id', 'id'])->on('entities')->restrictOnDelete();
            $t->foreign(['novel_id', 'source_version_id'])->references(['novel_id', 'id'])->on('premise_versions')->restrictOnDelete();
            $t->unique(['entity_id', 'version']);
            $t->unique(['novel_id', 'id']);
            $t->unique(['novel_id', 'entity_id', 'id']);
        });
        Schema::table('entity_definition_versions', function (Blueprint $t) {
            $t->foreign(['novel_id', 'alias_origin_version_id'])->references(['novel_id', 'id'])->on('entity_definition_versions')->restrictOnDelete();
        });
        Schema::table('entities', function (Blueprint $t) {
            foreach (['current_version_id', 'approved_version_id'] as $c) {
                $t->foreign(['novel_id', 'id', $c])->references(['novel_id', 'entity_id', 'id'])->on('entity_definition_versions')->restrictOnDelete();
            }
        });
        DB::statement("ALTER TABLE entity_definition_versions ADD CONSTRAINT definition_status CHECK (version>0 AND status IN ('building','sealed'))");
        foreach (['entity_aliases', 'world_rules', 'character_profiles', 'entity_initial_relations'] as $table) {
            Schema::create($table, function (Blueprint $t) use ($table) {
                $t->id();
                $t->unsignedBigInteger('novel_id');
                $t->unsignedBigInteger('entity_definition_version_id');
                $t->foreign(['novel_id', 'entity_definition_version_id'])->references(['novel_id', 'id'])->on('entity_definition_versions')->restrictOnDelete();
                if ($table === 'entity_aliases') {
                    $t->string('alias');
                    $t->string('normalized_alias');
                    $t->unique(['entity_definition_version_id', 'normalized_alias']);
                    $t->index(['novel_id', 'normalized_alias']);
                }
                if ($table === 'world_rules') {
                    $t->text('rule');
                    $t->text('scope');
                    $t->unique('entity_definition_version_id');
                }
                if ($table === 'character_profiles') {
                    $t->jsonb('profile');
                    $t->unique('entity_definition_version_id');
                }
                if ($table === 'entity_initial_relations') {
                    $t->unsignedBigInteger('target_definition_version_id');
                    $t->string('relation_type');
                    $t->text('description');
                    $t->foreign(['novel_id', 'target_definition_version_id'])->references(['novel_id', 'id'])->on('entity_definition_versions')->restrictOnDelete();
                    $t->unique(['entity_definition_version_id', 'target_definition_version_id', 'relation_type'], 'initial_relation_unique');
                    $t->index('target_definition_version_id');
                }
            });
        }
        Schema::create('entity_introduction_policies', function (Blueprint $t) {
            $t->id();
            $t->foreignId('novel_id')->constrained()->restrictOnDelete();
            $t->integer('version');
            $t->boolean('enabled');
            $t->string('schema_version');
            $t->jsonb('constraints');
            $t->integer('start_chapter');
            $t->integer('end_chapter');
            $t->integer('max_acceptances');
            $t->text('reason');
            $t->string('content_hash', 64);
            $t->foreignId('actor_id')->constrained('users')->restrictOnDelete();
            $t->timestampTz('created_at');
            $t->unique(['novel_id', 'version']);
            $t->unique(['novel_id', 'id']);
        });
        DB::statement('ALTER TABLE entity_introduction_policies ADD CONSTRAINT policy_range CHECK (version>0 AND start_chapter>0 AND end_chapter>=start_chapter AND max_acceptances BETWEEN 1 AND 1000)');
        Schema::create('entity_approvals', function (Blueprint $t) {
            $t->id();
            $t->unsignedBigInteger('novel_id');
            $t->unsignedBigInteger('entity_definition_version_id')->unique();
            $t->string('mode');
            $t->unsignedBigInteger('policy_version_id')->nullable();
            $t->text('reason');
            $t->foreignId('actor_id')->constrained('users')->restrictOnDelete();
            $t->timestampTz('created_at');
            $t->foreign(['novel_id', 'entity_definition_version_id'])->references(['novel_id', 'id'])->on('entity_definition_versions')->restrictOnDelete();
            $t->foreign(['novel_id', 'policy_version_id'])->references(['novel_id', 'id'])->on('entity_introduction_policies')->restrictOnDelete();
        });
        DB::statement("ALTER TABLE entity_approvals ADD CONSTRAINT approval_mode CHECK ((mode='manual' AND policy_version_id IS NULL) OR (mode='policy' AND policy_version_id IS NOT NULL))");
        DB::unprepared(<<<'SQL'
  CREATE OR REPLACE FUNCTION nf_preserve_world_history() RETURNS trigger LANGUAGE plpgsql AS $$ BEGIN
   RAISE EXCEPTION 'World definition history is immutable' USING ERRCODE='23514';
  END; $$;
  CREATE OR REPLACE FUNCTION nf_definition_seal() RETURNS trigger LANGUAGE plpgsql AS $$ BEGIN
   IF TG_OP='UPDATE' AND OLD.status='building' AND NEW.status='sealed' AND (to_jsonb(OLD)-'status')=(to_jsonb(NEW)-'status') THEN RETURN NEW; END IF;
   RAISE EXCEPTION 'Definition version is immutable' USING ERRCODE='23514';
  END; $$;
  CREATE OR REPLACE FUNCTION nf_definition_child() RETURNS trigger LANGUAGE plpgsql AS $$ DECLARE s text; BEGIN
   IF TG_OP<>'INSERT' THEN RAISE EXCEPTION 'Definition detail is immutable' USING ERRCODE='23514'; END IF;
   SELECT status INTO s FROM entity_definition_versions WHERE id=NEW.entity_definition_version_id AND novel_id=NEW.novel_id FOR UPDATE;
   IF s IS DISTINCT FROM 'building' THEN RAISE EXCEPTION 'Definition is sealed' USING ERRCODE='23514'; END IF;
   RETURN NEW;
  END; $$;
  CREATE OR REPLACE FUNCTION nf_entity_pointer() RETURNS trigger LANGUAGE plpgsql AS $$ BEGIN
   IF TG_OP='DELETE' THEN RAISE EXCEPTION 'Entities cannot be hard deleted' USING ERRCODE='23514'; END IF;
   IF TG_OP='UPDATE' AND (NEW.novel_id<>OLD.novel_id OR NEW.type<>OLD.type) THEN RAISE EXCEPTION 'Stable entity identity cannot change' USING ERRCODE='23514'; END IF;
   IF NEW.current_version_id IS NOT NULL AND NOT EXISTS(SELECT 1 FROM entity_definition_versions WHERE id=NEW.current_version_id AND novel_id=NEW.novel_id AND entity_id=NEW.id AND status='sealed') THEN RAISE EXCEPTION 'Current definition must be sealed and owned' USING ERRCODE='23514'; END IF;
   IF NEW.approved_version_id IS NOT NULL AND NOT EXISTS(SELECT 1 FROM entity_approvals WHERE entity_definition_version_id=NEW.approved_version_id AND novel_id=NEW.novel_id) THEN RAISE EXCEPTION 'Approved pointer requires approval' USING ERRCODE='23514'; END IF;
   RETURN NEW;
  END; $$;
  CREATE OR REPLACE FUNCTION nf_approval_guard() RETURNS trigger LANGUAGE plpgsql AS $$
  DECLARE d jsonb; s text; entity bigint; policy entity_introduction_policies%ROWTYPE; BEGIN
   PERFORM 1 FROM novels WHERE id=NEW.novel_id FOR UPDATE;
   SELECT definition,status,entity_id INTO d,s,entity FROM entity_definition_versions WHERE id=NEW.entity_definition_version_id AND novel_id=NEW.novel_id;
   IF s IS DISTINCT FROM 'sealed' OR COALESCE(d->>'conflict','')<>'' OR NOT EXISTS(SELECT 1 FROM entities WHERE id=entity AND current_version_id=NEW.entity_definition_version_id AND NOT retired) THEN RAISE EXCEPTION 'Only current conflict-free definition may be approved' USING ERRCODE='23514'; END IF;
   IF EXISTS(SELECT 1 FROM entity_initial_relations r LEFT JOIN entity_approvals a ON a.entity_definition_version_id=r.target_definition_version_id JOIN entity_definition_versions v ON v.id=r.target_definition_version_id JOIN entities e ON e.id=v.entity_id WHERE r.entity_definition_version_id=NEW.entity_definition_version_id AND (a.id IS NULL OR e.retired)) THEN RAISE EXCEPTION 'Initial relation target is not approved' USING ERRCODE='23514'; END IF;
   IF NEW.mode='policy' THEN
    SELECT * INTO policy FROM entity_introduction_policies WHERE novel_id=NEW.novel_id ORDER BY version DESC LIMIT 1;
    IF policy.schema_version IS DISTINCT FROM 'minor-v1' OR policy.constraints IS DISTINCT FROM '{"type":"character","role":"extra","criticality":"minor","allow_main_plot":false,"allow_abilities":false,"allow_rules":false,"allow_relations":false}'::jsonb OR policy.id IS NULL OR policy.id IS DISTINCT FROM NEW.policy_version_id OR NOT policy.enabled
      OR d->>'type' IS DISTINCT FROM 'character' OR d->>'criticality' IS DISTINCT FROM 'minor' OR d->'profile'->>'role' IS DISTINCT FROM 'extra'
      OR (d->>'affects_main_plot')::boolean IS DISTINCT FROM false OR COALESCE(d->'profile'->>'abilities','')<>'' OR COALESCE(d->>'rule','')<>'' OR jsonb_array_length(d->'relations')<>0
      OR (d->>'introduced_chapter')::int NOT BETWEEN policy.start_chapter AND policy.end_chapter
      OR (d->>'introduced_chapter')::int > (SELECT target_chapters FROM novels WHERE id=NEW.novel_id)
      OR (SELECT count(*) FROM entity_approvals WHERE policy_version_id=policy.id)>=policy.max_acceptances THEN RAISE EXCEPTION 'Introduction policy rejected' USING ERRCODE='23514'; END IF;
   END IF;
   RETURN NEW;
  END; $$;
  CREATE TRIGGER approval_guard BEFORE INSERT ON entity_approvals FOR EACH ROW EXECUTE FUNCTION nf_approval_guard();
  CREATE TRIGGER entity_pointer BEFORE INSERT OR UPDATE OR DELETE ON entities FOR EACH ROW EXECUTE FUNCTION nf_entity_pointer();
  CREATE TRIGGER definition_seal BEFORE UPDATE OR DELETE ON entity_definition_versions FOR EACH ROW EXECUTE FUNCTION nf_definition_seal();
  SQL);
        foreach (['entity_aliases', 'world_rules', 'character_profiles', 'entity_initial_relations'] as $table) {
            DB::statement("CREATE TRIGGER definition_child BEFORE INSERT OR UPDATE OR DELETE ON {$table} FOR EACH ROW EXECUTE FUNCTION nf_definition_child()");
        }
        foreach (['entity_introduction_policies', 'entity_approvals'] as $table) {
            DB::statement("CREATE TRIGGER world_history BEFORE UPDATE OR DELETE ON {$table} FOR EACH ROW EXECUTE FUNCTION nf_preserve_world_history()");
        }
    }

    public function down(): void
    {
        Schema::table('entities', function (Blueprint $t) {
            foreach (['current_version_id', 'approved_version_id'] as $c) {
                $t->dropForeign(['novel_id', 'id', $c]);
            }
        });
        foreach (['entity_approvals', 'entity_introduction_policies', 'entity_initial_relations', 'character_profiles', 'world_rules', 'entity_aliases', 'entity_definition_versions', 'entities'] as $table) {
            Schema::dropIfExists($table);
        }
        foreach (['nf_approval_guard', 'nf_entity_pointer', 'nf_definition_child', 'nf_definition_seal', 'nf_preserve_world_history'] as $fn) {
            DB::statement("DROP FUNCTION IF EXISTS {$fn}()");
        }
    }
};
