<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        // 配置、密钥和证据分别保存，历史请求永远引用当时的不可变版本。
        DB::unprepared(<<<'SQL'
CREATE TABLE providers (
 id bigserial PRIMARY KEY, name varchar(100) NOT NULL UNIQUE,
 created_at timestamptz NOT NULL DEFAULT clock_timestamp()
);
CREATE TABLE provider_secrets (
 id bigserial PRIMARY KEY, provider_id bigint NOT NULL REFERENCES providers(id) ON DELETE RESTRICT,
 ciphertext text NOT NULL, created_at timestamptz NOT NULL DEFAULT clock_timestamp(), UNIQUE(provider_id,id)
);
CREATE TABLE provider_versions (
 id bigserial PRIMARY KEY, provider_id bigint NOT NULL REFERENCES providers(id) ON DELETE RESTRICT,
 version integer NOT NULL CHECK(version>0), interface_type varchar(30) NOT NULL CHECK(interface_type IN ('fake','json_http')),
 endpoint text NOT NULL, allowed_hosts jsonb NOT NULL, secret_id bigint,
 timeout_seconds integer NOT NULL CHECK(timeout_seconds BETWEEN 1 AND 30),
 connect_timeout_seconds integer NOT NULL CHECK(connect_timeout_seconds BETWEEN 1 AND timeout_seconds),
 requests_per_minute integer NOT NULL CHECK(requests_per_minute BETWEEN 1 AND 600),
 max_concurrency integer NOT NULL CHECK(max_concurrency BETWEEN 1 AND 20),
 enabled boolean NOT NULL, actor_id bigint NOT NULL REFERENCES users(id) ON DELETE RESTRICT,
 reason text NOT NULL, created_at timestamptz NOT NULL DEFAULT clock_timestamp(),
 UNIQUE(provider_id,version), UNIQUE(provider_id,id),
 FOREIGN KEY(provider_id,secret_id) REFERENCES provider_secrets(provider_id,id) ON DELETE RESTRICT
);
CREATE TABLE model_profiles (
 id bigserial PRIMARY KEY, provider_id bigint NOT NULL REFERENCES providers(id) ON DELETE RESTRICT,
 provider_model_id varchar(200) NOT NULL, created_at timestamptz NOT NULL DEFAULT clock_timestamp(),
 UNIQUE(provider_id,provider_model_id), UNIQUE(provider_id,id)
);
CREATE TABLE model_profile_versions (
 id bigserial PRIMARY KEY, provider_id bigint NOT NULL, model_profile_id bigint NOT NULL,
 version integer NOT NULL CHECK(version>0), declared_capabilities jsonb NOT NULL,
 verification_status varchar(30) NOT NULL DEFAULT 'unverified' CHECK(verification_status='unverified'),
 actor_id bigint NOT NULL REFERENCES users(id) ON DELETE RESTRICT, reason text NOT NULL,
 created_at timestamptz NOT NULL DEFAULT clock_timestamp(), UNIQUE(model_profile_id,version), UNIQUE(provider_id,id),
 FOREIGN KEY(provider_id,model_profile_id) REFERENCES model_profiles(provider_id,id) ON DELETE RESTRICT
);
CREATE TABLE ai_requests (
 id bigserial PRIMARY KEY, novel_id bigint NOT NULL, run_id bigint NOT NULL, step_id bigint NOT NULL,
 provider_id bigint NOT NULL REFERENCES providers(id) ON DELETE RESTRICT,
 provider_version_id bigint NOT NULL, model_profile_version_id bigint NOT NULL,
 logical_key varchar(128) NOT NULL, request_hash char(64) NOT NULL, lease_token bigint NOT NULL,
 status varchar(30) NOT NULL CHECK(status IN ('pending','succeeded','failed','unknown','quarantined')),
 error_code varchar(80), issues jsonb, provider_request_id varchar(200), http_status integer,
 usage jsonb, cost_status varchar(30) NOT NULL DEFAULT 'not_billable_fake' CHECK(cost_status='not_billable_fake'),
 created_at timestamptz NOT NULL DEFAULT clock_timestamp(), completed_at timestamptz,
 UNIQUE(step_id,logical_key),
 FOREIGN KEY(novel_id,run_id,step_id) REFERENCES generation_steps(novel_id,run_id,id) ON DELETE RESTRICT,
 FOREIGN KEY(provider_id,provider_version_id) REFERENCES provider_versions(provider_id,id) ON DELETE RESTRICT,
 FOREIGN KEY(provider_id,model_profile_version_id) REFERENCES model_profile_versions(provider_id,id) ON DELETE RESTRICT
);
CREATE INDEX c03_requests_rate ON ai_requests(provider_id,created_at);
CREATE INDEX c03_requests_active ON ai_requests(provider_id,status) WHERE status IN ('pending','unknown');
CREATE TABLE ai_request_evidence (
 id bigserial PRIMARY KEY, request_id bigint NOT NULL REFERENCES ai_requests(id) ON DELETE RESTRICT,
 kind varchar(20) NOT NULL CHECK(kind IN ('request','response')), ciphertext text NOT NULL,
 content_hash char(64) NOT NULL, redacted boolean NOT NULL, created_at timestamptz NOT NULL DEFAULT clock_timestamp(),
 UNIQUE(request_id,kind)
);
CREATE TABLE provider_commands (
 id bigserial PRIMARY KEY, actor_id bigint NOT NULL REFERENCES users(id) ON DELETE RESTRICT,
 command_key varchar(128) NOT NULL, request_hmac char(64) NOT NULL, response jsonb NOT NULL,
 created_at timestamptz NOT NULL DEFAULT clock_timestamp(), UNIQUE(actor_id,command_key)
);
SQL);
        foreach (['providers', 'provider_secrets', 'provider_versions', 'model_profiles', 'model_profile_versions', 'ai_request_evidence', 'provider_commands'] as $table) {
            DB::statement("CREATE TRIGGER c03_immutable BEFORE UPDATE OR DELETE ON {$table} FOR EACH ROW EXECUTE FUNCTION c01_immutable()");
        }
        DB::unprepared(<<<'SQL'
CREATE OR REPLACE FUNCTION c03_request_identity() RETURNS trigger LANGUAGE plpgsql AS $$
BEGIN
 IF TG_OP='DELETE' THEN RAISE EXCEPTION 'C03 request history'; END IF;
 IF OLD.status <> 'pending' OR (to_jsonb(NEW)-ARRAY['status','error_code','issues','provider_request_id','http_status','usage','completed_at']) IS DISTINCT FROM (to_jsonb(OLD)-ARRAY['status','error_code','issues','provider_request_id','http_status','usage','completed_at']) THEN RAISE EXCEPTION 'C03 immutable request'; END IF;
 RETURN NEW;
END $$;
CREATE TRIGGER c03_request_frozen BEFORE UPDATE OR DELETE ON ai_requests FOR EACH ROW EXECUTE FUNCTION c03_request_identity();
SQL);
        // 中文备注由数据库本身保存，覆盖每张新表及每个字段，不只写在源码中。
        $tables = ['providers' => '供应商稳定身份', 'provider_secrets' => '供应商加密密钥历史', 'provider_versions' => '供应商不可变安全配置版本', 'model_profiles' => '模型稳定身份', 'model_profile_versions' => '模型能力声明版本（未验证）', 'ai_requests' => '模型网关逻辑请求记录', 'ai_request_evidence' => '加密且脱敏的请求响应原始证据', 'provider_commands' => '供应商配置命令幂等记录'];
        $columns = ['id' => '记录主键', 'name' => '供应商名称', 'created_at' => '创建时间', 'provider_id' => '所属供应商标识', 'ciphertext' => '使用应用密钥加密的密文', 'version' => '递增版本号', 'interface_type' => '接口类型：离线模拟或通用结构化协议', 'endpoint' => '允许请求的完整端点地址', 'allowed_hosts' => '精确匹配的主机白名单', 'secret_id' => '同供应商密钥版本引用（不返回前端）', 'timeout_seconds' => '请求总超时秒数', 'connect_timeout_seconds' => '连接超时秒数', 'requests_per_minute' => '每分钟请求数上限', 'max_concurrency' => '最大在途请求数', 'enabled' => '配置是否启用（不代表获准真实调用）', 'actor_id' => '操作管理员标识', 'reason' => '配置变更原因', 'provider_model_id' => '供应商声明的模型名称', 'model_profile_id' => '模型稳定标识', 'declared_capabilities' => '声明能力及来源说明（不代表实测）', 'verification_status' => '能力验证状态，当前固定未验证', 'novel_id' => '所属小说标识', 'run_id' => '所属运行标识', 'step_id' => '所属阶段尝试标识', 'provider_version_id' => '冻结供应商配置版本', 'model_profile_version_id' => '冻结模型能力版本', 'logical_key' => '阶段内逻辑请求幂等键', 'request_hash' => '规范化请求身份摘要', 'lease_token' => '发起请求时的阶段隔离令牌', 'status' => '请求生命周期状态', 'error_code' => '脱敏的机器错误码', 'issues' => '结构化校验问题及路径', 'provider_request_id' => '供应商响应请求标识', 'http_status' => '响应状态码', 'usage' => '原样保留的计量证据，未提供时为空', 'cost_status' => '计费可信状态，当前仅离线模拟不计费', 'completed_at' => '结果落盘时间', 'request_id' => '所属网关请求标识', 'kind' => '请求或响应证据类型', 'content_hash' => '脱敏证据明文摘要', 'redacted' => '是否替换了敏感内容', 'command_key' => '配置操作幂等键', 'request_hmac' => '含敏感输入的加钥摘要，避免保存密钥原文', 'response' => '首次操作的安全投影响应'];
        foreach ($tables as $table => $comment) {
            DB::statement("COMMENT ON TABLE {$table} IS '".$comment."'");
            foreach (DB::select('SELECT column_name FROM information_schema.columns WHERE table_schema=current_schema() AND table_name=?', [$table]) as $column) {
                DB::statement("COMMENT ON COLUMN {$table}.{$column->column_name} IS '".$columns[$column->column_name]."'");
            }
        }
    }

    public function down(): void
    {
        DB::unprepared('DROP TABLE provider_commands, ai_request_evidence, ai_requests, model_profile_versions, model_profiles, provider_versions, provider_secrets, providers; DROP FUNCTION c03_request_identity();');
    }
};
