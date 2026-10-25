<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        // 策略、评审、人工意见均追加保存；正式剧情门禁不开放。
        DB::unprepared(<<<'SQL'
CREATE TABLE review_policy_versions (
 id bigserial PRIMARY KEY, key varchar(100) NOT NULL, version int NOT NULL CHECK(version>0), style_version_id bigint NOT NULL REFERENCES style_versions(id),
 content jsonb NOT NULL, content_hash char(64) NOT NULL, actor_id bigint NOT NULL REFERENCES users(id), reason text NOT NULL, created_at timestamptz NOT NULL DEFAULT clock_timestamp(), UNIQUE(key,version)
);
CREATE TABLE review_policy_states (
 id bigserial PRIMARY KEY, policy_version_id bigint NOT NULL REFERENCES review_policy_versions(id), status varchar(20) NOT NULL CHECK(status IN ('published','retired')), actor_id bigint NOT NULL REFERENCES users(id), reason text NOT NULL, created_at timestamptz NOT NULL DEFAULT clock_timestamp()
);
CREATE TABLE reviews (
 id bigserial PRIMARY KEY, novel_id bigint NOT NULL, chapter_version_id bigint NOT NULL, extraction_id bigint NOT NULL, policy_version_id bigint NOT NULL REFERENCES review_policy_versions(id),
 source_id bigint NOT NULL, body_hash char(64) NOT NULL, context_hash char(64) NOT NULL, validation_hash char(64) NOT NULL,
 frozen_input jsonb NOT NULL, input_hash char(64) NOT NULL, output jsonb NOT NULL, output_hash char(64) NOT NULL, report jsonb NOT NULL,
 reviewer_type varchar(20) NOT NULL CHECK(reviewer_type IN ('offline','human')), run_id bigint, artifact_id bigint UNIQUE,
 actor_id bigint NOT NULL REFERENCES users(id), reason text NOT NULL, created_at timestamptz NOT NULL DEFAULT clock_timestamp(), UNIQUE(novel_id,id),
 FOREIGN KEY(novel_id,chapter_version_id) REFERENCES chapter_versions(novel_id,id), FOREIGN KEY(novel_id,extraction_id) REFERENCES extraction_attempts(novel_id,id),
 FOREIGN KEY(novel_id,source_id) REFERENCES source_documents(novel_id,id), FOREIGN KEY(novel_id,run_id,artifact_id) REFERENCES generation_artifacts(novel_id,run_id,id), CHECK((run_id IS NULL)=(artifact_id IS NULL))
);
CREATE INDEX e04_reviews_history ON reviews(novel_id,chapter_version_id,id DESC);
CREATE TABLE review_issues (
 id bigserial PRIMARY KEY, novel_id bigint NOT NULL, review_id bigint NOT NULL, evidence_id bigint, category varchar(40) NOT NULL,
 severity varchar(20) NOT NULL CHECK(severity IN ('blocking','warning','info')), origin varchar(20) NOT NULL CHECK(origin IN ('hard','model','rule')),
 message text NOT NULL, suggested_fix text NOT NULL, details jsonb NOT NULL, UNIQUE(novel_id,review_id,id),
 FOREIGN KEY(novel_id,review_id) REFERENCES reviews(novel_id,id), FOREIGN KEY(novel_id,evidence_id) REFERENCES evidence_spans(novel_id,id), CHECK(origin='hard' OR evidence_id IS NOT NULL)
);
CREATE TABLE review_issue_decisions (
 id bigserial PRIMARY KEY, novel_id bigint NOT NULL, review_id bigint NOT NULL, issue_id bigint NOT NULL, decision varchar(20) NOT NULL CHECK(decision IN ('accepted','rejected')),
 actor_id bigint NOT NULL REFERENCES users(id), reason text NOT NULL, created_at timestamptz NOT NULL DEFAULT clock_timestamp(), FOREIGN KEY(novel_id,review_id,issue_id) REFERENCES review_issues(novel_id,review_id,id)
);
CREATE OR REPLACE FUNCTION e04_scope() RETURNS trigger LANGUAGE plpgsql AS $$
BEGIN
 IF TG_TABLE_NAME='reviews' THEN
  IF NOT EXISTS(SELECT 1 FROM extraction_attempts e WHERE e.novel_id=NEW.novel_id AND e.id=NEW.extraction_id AND e.chapter_version_id=NEW.chapter_version_id AND e.source_id=NEW.source_id AND e.body_hash=NEW.body_hash) THEN RAISE EXCEPTION 'E04 extraction source mismatch'; END IF;
  IF NEW.run_id IS NOT NULL AND NOT EXISTS(SELECT 1 FROM generation_artifacts a JOIN generation_runs r ON r.id=a.run_id WHERE a.id=NEW.artifact_id AND a.run_id=NEW.run_id AND a.novel_id=NEW.novel_id AND a.type='review_candidate' AND r.frozen_config->>'schema_version'='review-v1' AND r.frozen_config->>'chapter_version_id'=NEW.chapter_version_id::text AND r.frozen_config->>'body_hash'=NEW.body_hash AND a.content_hash=NEW.output_hash AND a.content=NEW.output AND NEW.reviewer_type='offline' AND r.frozen_config->'review_input'=NEW.frozen_input) THEN RAISE EXCEPTION 'E04 generation origin mismatch'; END IF;
 ELSIF TG_TABLE_NAME='review_issues' THEN
  IF NEW.evidence_id IS NOT NULL AND NOT EXISTS(SELECT 1 FROM reviews r JOIN evidence_spans e ON e.novel_id=r.novel_id AND e.source_version_id=r.source_id WHERE r.id=NEW.review_id AND r.novel_id=NEW.novel_id AND e.id=NEW.evidence_id AND e.source_hash=r.body_hash) THEN RAISE EXCEPTION 'E04 issue evidence mismatch'; END IF;
 ELSE
  IF NOT EXISTS(SELECT 1 FROM review_issues i WHERE i.id=NEW.issue_id AND i.review_id=NEW.review_id AND i.novel_id=NEW.novel_id AND i.origin<>'hard' AND i.category<>'structure' AND i.severity<>'blocking') THEN RAISE EXCEPTION 'E04 hard issue cannot be waived'; END IF;
 END IF;
 RETURN NEW;
END $$;
SQL);
        $comments = [
            'review_policy_versions' => ['不可变七维评审策略版本', ['id' => '策略版本标识', 'key' => '策略稳定键', 'version' => '递增版本号', 'style_version_id' => '与正文生成共用的文风版本', 'content' => '冻结七维阈值、规则与例外', 'content_hash' => '策略内容哈希', 'actor_id' => '创建管理员', 'reason' => '新版本及阈值调整原因', 'created_at' => '创建时间']],
            'review_policy_states' => ['策略发布与退役的追加历史', ['id' => '生命周期记录标识', 'policy_version_id' => '策略版本', 'status' => '发布或退役状态', 'actor_id' => '操作管理员', 'reason' => '生命周期原因', 'created_at' => '操作时间']],
            'reviews' => ['绑定正文、提取、上下文与策略的不可变候选评审', ['id' => '评审标识', 'novel_id' => '所属小说', 'chapter_version_id' => '实际章节正文版本', 'extraction_id' => '完整E03候选校验', 'policy_version_id' => '冻结策略版本', 'source_id' => '实际章节来源', 'body_hash' => '正文哈希', 'context_hash' => '评审完整上下文哈希', 'validation_hash' => 'E03校验产物哈希', 'frozen_input' => '冻结正文及规则上下文', 'input_hash' => '冻结输入哈希', 'output' => '原始评审响应', 'output_hash' => '响应内容哈希', 'report' => '硬门禁和文学七维结果', 'reviewer_type' => '离线回放或人工评分', 'run_id' => '可选离线网关运行', 'artifact_id' => '可选精确响应产物', 'actor_id' => '发起管理员', 'reason' => '发起原因', 'created_at' => '评审时间']],
            'review_issues' => ['可定位评审问题与保留的硬约束诊断', ['id' => '问题标识', 'novel_id' => '所属小说', 'review_id' => '所属评审', 'evidence_id' => '本次正文的精确引文；硬错误可无单句', 'category' => '问题分类', 'severity' => '阻塞、警告或提示', 'origin' => '硬门禁、响应或规则信号', 'message' => '问题解释', 'suggested_fix' => '局部修复建议', 'details' => '冲突事实或规则诊断']],
            'review_issue_decisions' => ['非阻塞文学问题的人工接受或拒绝意见', ['id' => '意见标识', 'novel_id' => '所属小说', 'review_id' => '所属评审', 'issue_id' => '非阻塞文学问题', 'decision' => '接受或拒绝提示', 'actor_id' => '操作管理员', 'reason' => '明确人工理由', 'created_at' => '决定时间']],
        ];
        foreach ($comments as $table => [$description, $columns]) {
            DB::statement("COMMENT ON TABLE {$table} IS '{$description}'");
            foreach ($columns as $column => $comment) {
                DB::statement("COMMENT ON COLUMN {$table}.{$column} IS '{$comment}'");
            }
            DB::statement("CREATE TRIGGER e04_immutable BEFORE UPDATE OR DELETE ON {$table} FOR EACH ROW EXECUTE FUNCTION c01_immutable()");
            if (in_array($table, ['reviews', 'review_issue_decisions'])) {
                DB::statement("CREATE TRIGGER e04_scope BEFORE INSERT ON {$table} FOR EACH ROW EXECUTE FUNCTION e04_scope()");
            }
            DB::statement("CREATE TRIGGER e04_audit AFTER INSERT ON {$table} FOR EACH ROW EXECUTE FUNCTION c07_audit_capture()");
        }
        DB::statement('CREATE TRIGGER e04_scope BEFORE INSERT ON review_issues FOR EACH ROW EXECUTE FUNCTION e04_scope()');
    }

    public function down(): void
    {
        // 仅撤销本轮候选评审表；不触碰正文、来源或正式数据。
        DB::unprepared('DROP TABLE review_issue_decisions, review_issues, reviews, review_policy_states, review_policy_versions; DROP FUNCTION e04_scope();');
    }
};
