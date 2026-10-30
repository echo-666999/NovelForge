\set ON_ERROR_STOP on
-- 每次迁移后由迁移账户执行；不授予 TRUNCATE、TRIGGER 或结构修改权限。
BEGIN;
DO $$ BEGIN
    IF current_database() NOT IN ('novel_forge', 'novel_forge_test')
        OR current_user <> 'novel_forge_migrator' THEN
        RAISE EXCEPTION '需要目标库的运维迁移账户';
    END IF;
END $$;
GRANT SELECT, INSERT, UPDATE, DELETE ON ALL TABLES IN SCHEMA public TO novel_forge_app;
GRANT USAGE, SELECT ON ALL SEQUENCES IN SCHEMA public TO novel_forge_app;
-- 应用不能伪造迁移已完成；未来每次迁移后必须重新执行本文件。
SELECT 'REVOKE ALL ON TABLE public.migrations FROM novel_forge_app'
    WHERE to_regclass('public.migrations') IS NOT NULL \gexec
COMMIT;
