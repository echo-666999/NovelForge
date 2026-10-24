<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        // 每次重组另存清单；不覆盖旧运行的文本、来源或容量结论。
        DB::unprepared(<<<'SQL'
CREATE TABLE context_manifests (
 id bigserial PRIMARY KEY, novel_id bigint NOT NULL, run_id bigint NOT NULL, step_id bigint NOT NULL,
 actor_id bigint NOT NULL REFERENCES users(id), command_key varchar(128) NOT NULL,
 request_hash char(64) NOT NULL, input jsonb NOT NULL, payload jsonb NOT NULL, content_hash char(64) NOT NULL,
 created_at timestamptz NOT NULL DEFAULT clock_timestamp(),
 UNIQUE(actor_id,step_id,command_key),
 FOREIGN KEY(novel_id,run_id,step_id) REFERENCES generation_steps(novel_id,run_id,id)
);
CREATE INDEX context_step_history ON context_manifests(step_id,id DESC);
CREATE TRIGGER d06_context_immutable BEFORE UPDATE OR DELETE ON context_manifests FOR EACH ROW EXECUTE FUNCTION c01_immutable();
COMMENT ON TABLE context_manifests IS '不可变候选上下文组装清单及最终文本；不代表正式生成资格';
SQL);
        $comments = ['id' => '清单主键', 'novel_id' => '所属小说', 'run_id' => '所属候选运行分支', 'step_id' => '绑定阶段尝试', 'actor_id' => '组装管理员', 'command_key' => '组装幂等键', 'request_hash' => '组装输入哈希', 'input' => '重组参数快照，不含凭据', 'payload' => '逐项来源、分区、裁剪、容量与最终文本快照', 'content_hash' => '完整清单规范化哈希', 'created_at' => '组装时间'];
        foreach ($comments as $column => $comment) {
            DB::statement("COMMENT ON COLUMN context_manifests.{$column} IS '{$comment}'");
        }
    }

    public function down(): void
    {
        DB::statement('DROP TABLE context_manifests');
    }
};
