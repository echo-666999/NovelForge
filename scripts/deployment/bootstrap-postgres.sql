\set ON_ERROR_STOP on
-- 仅在专用实例的新目标库运行；已有角色时直接失败，绝不改写共享实例的账户。
BEGIN;
DO $$ BEGIN
    IF current_setting('cluster_name') <> 'novelforge-private' THEN
        RAISE EXCEPTION '只允许显式标记的 NovelForge 专用实例，禁止共享实例';
    END IF;
    IF current_database() NOT IN ('novel_forge', 'novel_forge_test') THEN
        RAISE EXCEPTION '拒绝非 NovelForge 目标库';
    END IF;
    IF current_setting('server_version_num')::integer NOT BETWEEN 180000 AND 189999 THEN
        RAISE EXCEPTION '需要 PostgreSQL 18';
    END IF;
END $$;
CREATE ROLE novel_forge_migrator LOGIN NOSUPERUSER NOCREATEDB NOCREATEROLE NOREPLICATION NOBYPASSRLS;
CREATE ROLE novel_forge_app LOGIN NOSUPERUSER NOCREATEDB NOCREATEROLE NOREPLICATION NOBYPASSRLS;
COMMENT ON ROLE novel_forge_migrator IS 'NovelForge 运维迁移账户，仅用于受控结构变更';
COMMENT ON ROLE novel_forge_app IS 'NovelForge 应用账户，仅用于业务读写';
-- 扩展由管理员预装；应用账户既不是库所有者，也没有数据库 CREATE 权限。
CREATE EXTENSION IF NOT EXISTS vector;
CREATE EXTENSION IF NOT EXISTS pg_trgm;
SELECT format('ALTER DATABASE %I OWNER TO novel_forge_migrator', current_database()) \gexec
SELECT format('REVOKE ALL ON DATABASE %I FROM PUBLIC', current_database()) \gexec
SELECT format('GRANT CONNECT ON DATABASE %I TO novel_forge_app', current_database()) \gexec
REVOKE ALL ON SCHEMA public FROM PUBLIC;
GRANT USAGE, CREATE ON SCHEMA public TO novel_forge_migrator;
GRANT USAGE ON SCHEMA public TO novel_forge_app;
-- 后续迁移必须以迁移账户执行，否则这些默认权限不会自动生效。
ALTER DEFAULT PRIVILEGES FOR ROLE novel_forge_migrator IN SCHEMA public
    GRANT SELECT, INSERT, UPDATE, DELETE ON TABLES TO novel_forge_app;
ALTER DEFAULT PRIVILEGES FOR ROLE novel_forge_migrator IN SCHEMA public
    GRANT USAGE, SELECT ON SEQUENCES TO novel_forge_app;
ALTER DEFAULT PRIVILEGES FOR ROLE novel_forge_migrator
    REVOKE EXECUTE ON FUNCTIONS FROM PUBLIC;
ALTER DEFAULT PRIVILEGES FOR ROLE novel_forge_migrator IN SCHEMA public
    GRANT EXECUTE ON FUNCTIONS TO novel_forge_app;
COMMIT;
-- 不在 SQL 或命令参数内保存口令；运维通过 psql 的 \password 分别设置 SCRAM 口令。
