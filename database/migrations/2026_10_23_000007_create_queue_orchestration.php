<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        // PostgreSQL 是任务意图与租约的事实源，Redis 只承载可丢失的通知。
        DB::unprepared(<<<'SQL'
ALTER TABLE generation_steps ADD COLUMN fencing_token bigint NOT NULL DEFAULT 0,
 ADD COLUMN lease_owner uuid, ADD COLUMN lease_until timestamptz, ADD COLUMN heartbeat_at timestamptz,
 ADD CONSTRAINT c02_token_positive CHECK (fencing_token >= 0);
CREATE INDEX c02_expired_steps ON generation_steps(lease_until) WHERE status='running';
CREATE TABLE outbox_events (
 id bigserial PRIMARY KEY, novel_id bigint NOT NULL, run_id bigint NOT NULL,
 scope_key varchar(80) NOT NULL, retry_of integer NOT NULL DEFAULT 0,
 queue varchar(32) NOT NULL CHECK(queue IN ('planning','generation','review','memory','export','maintenance')),
 created_at timestamptz NOT NULL DEFAULT clock_timestamp(), published_at timestamptz,
 next_delivery_at timestamptz NOT NULL DEFAULT clock_timestamp(), delivery_count integer NOT NULL DEFAULT 0,
 last_error varchar(80), UNIQUE(run_id,scope_key,retry_of),
 CHECK(retry_of BETWEEN 0 AND 2),
 FOREIGN KEY(novel_id,run_id) REFERENCES generation_runs(novel_id,id) ON DELETE RESTRICT
);
CREATE INDEX c02_outbox_delivery ON outbox_events(next_delivery_at,id);
CREATE TABLE consumer_receipts (
 event_id bigint PRIMARY KEY REFERENCES outbox_events(id) ON DELETE RESTRICT,
 completed_at timestamptz NOT NULL DEFAULT clock_timestamp()
);
CREATE TRIGGER c02_receipt_immutable BEFORE UPDATE OR DELETE ON consumer_receipts FOR EACH ROW EXECUTE FUNCTION c01_immutable();
CREATE TABLE generation_book_slots (
 novel_id bigint PRIMARY KEY REFERENCES novels(id) ON DELETE RESTRICT,
 run_id bigint NOT NULL UNIQUE,
 FOREIGN KEY(novel_id,run_id) REFERENCES generation_runs(novel_id,id) ON DELETE RESTRICT
);
CREATE OR REPLACE FUNCTION c01_frozen_identity() RETURNS trigger LANGUAGE plpgsql AS $$
BEGIN
 IF TG_OP='DELETE' THEN RAISE EXCEPTION 'C01 immutable history'; END IF;
 IF (to_jsonb(NEW) - ARRAY['status','error_code','updated_at','checkpoint_artifact_id','fencing_token','lease_owner','lease_until','heartbeat_at']) IS DISTINCT FROM (to_jsonb(OLD) - ARRAY['status','error_code','updated_at','checkpoint_artifact_id','fencing_token','lease_owner','lease_until','heartbeat_at']) THEN
  RAISE EXCEPTION 'C01 frozen identity';
 END IF;
 IF TG_TABLE_NAME='generation_steps' THEN
  IF OLD.status IN ('succeeded','failed','deferred','blocked','canceled','quarantined') AND NEW IS DISTINCT FROM OLD THEN RAISE EXCEPTION 'C01 terminal attempt'; END IF;
  IF NEW.fencing_token < OLD.fencing_token THEN RAISE EXCEPTION 'C02 token cannot decrease'; END IF;
 END IF;
 RETURN NEW;
END $$;
CREATE OR REPLACE FUNCTION c02_outbox_identity() RETURNS trigger LANGUAGE plpgsql AS $$
BEGIN
 IF TG_OP='DELETE' THEN RAISE EXCEPTION 'C02 durable intent'; END IF;
 IF (to_jsonb(NEW) - ARRAY['published_at','next_delivery_at','delivery_count','last_error']) IS DISTINCT FROM (to_jsonb(OLD) - ARRAY['published_at','next_delivery_at','delivery_count','last_error']) THEN RAISE EXCEPTION 'C02 immutable intent'; END IF;
 RETURN NEW;
END $$;
CREATE TRIGGER c02_outbox_frozen BEFORE UPDATE OR DELETE ON outbox_events FOR EACH ROW EXECUTE FUNCTION c02_outbox_identity();
SQL);
    }

    public function down(): void
    {
        // 回滚先移除依赖；恢复 C01 触发器定义，不能留下引用已删除列的函数。
        DB::unprepared('DROP TABLE consumer_receipts, outbox_events, generation_book_slots; DROP FUNCTION c02_outbox_identity(); DROP INDEX c02_expired_steps; ALTER TABLE generation_steps DROP COLUMN fencing_token, DROP COLUMN lease_owner, DROP COLUMN lease_until, DROP COLUMN heartbeat_at;');
        $migration = file_get_contents(__DIR__.'/2026_10_23_000006_create_generation_foundation.php');
        preg_match('/CREATE OR REPLACE FUNCTION c01_frozen_identity\(\).*?END \$\$;/s', $migration, $matches);
        DB::unprepared($matches[0]);
    }
};
