<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('novels', function (Blueprint $t) {
            $t->id();
            $t->foreignId('owner_id')->constrained('users')->restrictOnDelete();
            $t->string('title');
            $t->string('genre');
            $t->text('premise_raw');
            $t->unsignedInteger('target_chapters');
            $t->jsonb('chapter_length_policy');
            $t->jsonb('settings');
            $t->string('status')->default('draft');
            $t->string('previous_status')->nullable();
            $t->unsignedBigInteger('row_version')->default(1);
            $t->unsignedBigInteger('state_version')->default(0);
            $t->timestampTz('archived_at')->nullable();
            $t->timestampsTz();
            $t->index(['owner_id', 'status', 'id']);
            $t->index(['genre', 'updated_at']);
        });
        DB::statement("ALTER TABLE novels ADD CONSTRAINT novels_valid CHECK (target_chapters > 0 AND row_version > 0 AND state_version >= 0 AND status IN ('draft','planning','ready','generating','paused','completed','archived'))");
        DB::statement("ALTER TABLE novels ADD CONSTRAINT novels_length_valid CHECK (chapter_length_policy->'min' IS NOT NULL AND chapter_length_policy->'max' IS NOT NULL AND jsonb_typeof(chapter_length_policy->'min') = 'number' AND jsonb_typeof(chapter_length_policy->'max') = 'number' AND (chapter_length_policy->>'min')::integer > 0 AND (chapter_length_policy->>'max')::integer >= (chapter_length_policy->>'min')::integer)");
        Schema::create('premise_versions', function (Blueprint $t) {
            $t->id();
            $t->foreignId('novel_id')->constrained()->restrictOnDelete();
            $t->unsignedInteger('version');
            $t->text('premise_raw');
            $t->jsonb('input');
            $t->string('content_hash', 64);
            $t->text('reason');
            $t->timestampTz('created_at');
            $t->unique(['novel_id', 'version']);
            $t->unique(['novel_id', 'id']);
        });
        Schema::create('requirement_items', function (Blueprint $t) {
            $t->id();
            $t->foreignId('novel_id')->constrained()->restrictOnDelete();
            $t->unsignedInteger('current_version')->default(1);
            $t->timestampsTz();
            $t->unique(['novel_id', 'id']);
        });
        Schema::create('requirement_versions', function (Blueprint $t) {
            $t->id();
            $t->unsignedBigInteger('novel_id');
            $t->unsignedBigInteger('requirement_id');
            $t->unsignedBigInteger('source_version_id');
            $t->unsignedInteger('version');
            $t->text('source_quote');
            $t->text('content');
            $t->string('category');
            $t->string('strength');
            $t->boolean('critical')->default(false);
            $t->string('decision_status');
            $t->text('conflict')->nullable();
            $t->text('author_decision')->nullable();
            $t->text('reason');
            $t->foreignId('actor_id')->constrained('users')->restrictOnDelete();
            $t->timestampTz('created_at');
            $t->foreign(['novel_id', 'requirement_id'])->references(['novel_id', 'id'])->on('requirement_items')->restrictOnDelete();
            $t->foreign(['novel_id', 'source_version_id'])->references(['novel_id', 'id'])->on('premise_versions')->restrictOnDelete();
            $t->unique(['requirement_id', 'version']);
            $t->index(['novel_id', 'decision_status', 'strength']);
        });
        DB::statement("ALTER TABLE requirement_versions ADD CONSTRAINT requirement_values CHECK (version > 0 AND strength IN ('hard','preference','undecided') AND decision_status IN ('unresolved','accepted','conflict','waived') AND (decision_status <> 'waived' OR strength = 'preference'))");
        Schema::create('novel_commands', function (Blueprint $t) {
            $t->id();
            $t->foreignId('actor_id')->constrained('users')->restrictOnDelete();
            $t->string('novel_scope');
            $t->string('command');
            $t->string('key', 128);
            $t->string('request_hash', 64);
            $t->jsonb('response');
            $t->timestampTz('created_at');
            $t->unique(['actor_id', 'novel_scope', 'command', 'key']);
        });
        DB::unprepared(<<<'SQL'
        CREATE OR REPLACE FUNCTION nf_preserve_intent_version() RETURNS trigger LANGUAGE plpgsql AS $$
        BEGIN RAISE EXCEPTION 'Novel intent history is immutable'; END;
        $$;
        CREATE TRIGGER premise_immutable BEFORE UPDATE OR DELETE ON premise_versions FOR EACH ROW EXECUTE FUNCTION nf_preserve_intent_version();
        CREATE TRIGGER command_immutable BEFORE UPDATE OR DELETE ON novel_commands FOR EACH ROW EXECUTE FUNCTION nf_preserve_intent_version();
        CREATE TRIGGER requirement_immutable BEFORE UPDATE OR DELETE ON requirement_versions FOR EACH ROW EXECUTE FUNCTION nf_preserve_intent_version();
        SQL);
    }

    public function down(): void
    {
        Schema::dropIfExists('novel_commands');
        Schema::dropIfExists('requirement_versions');
        Schema::dropIfExists('requirement_items');
        Schema::dropIfExists('premise_versions');
        Schema::dropIfExists('novels');
        DB::statement('DROP FUNCTION IF EXISTS nf_preserve_intent_version()');
    }
};
