<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        // 隔离只追加运维决定，不修改未知请求、费用和原始证据。
        DB::unprepared(<<<'SQL'
CREATE TABLE ai_request_isolations (
 id bigserial PRIMARY KEY,
 request_id bigint NOT NULL UNIQUE REFERENCES ai_requests(id),
 actor_id bigint NOT NULL REFERENCES users(id),
 reason text NOT NULL CHECK(length(reason) BETWEEN 10 AND 2000),
 created_at timestamptz NOT NULL DEFAULT clock_timestamp()
);
COMMENT ON TABLE ai_request_isolations IS '未知请求人工隔离记录，仅释放新运行并发占用，不核销费用或允许重发';
COMMENT ON COLUMN ai_request_isolations.id IS '隔离记录编号';
COMMENT ON COLUMN ai_request_isolations.request_id IS '保留未知结果的原请求编号';
COMMENT ON COLUMN ai_request_isolations.actor_id IS '确认隔离的操作用户';
COMMENT ON COLUMN ai_request_isolations.reason IS '隔离原因与授权依据';
COMMENT ON COLUMN ai_request_isolations.created_at IS '隔离记录创建时间';
CREATE TRIGGER f01_isolation_immutable BEFORE UPDATE OR DELETE ON ai_request_isolations FOR EACH ROW EXECUTE FUNCTION c01_immutable();
SQL);
    }

    public function down(): void
    {
        // 已使用的隔离历史不能随迁移回滚丢失。
        if (DB::table('ai_request_isolations')->exists()) {
            throw new RuntimeException('存在请求隔离历史，拒绝删除审计证据');
        }
        DB::statement('DROP TABLE ai_request_isolations');
    }
};
