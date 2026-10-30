#!/usr/bin/env python3
"""NF-A02 专用 PostgreSQL 实例验收：只创建 novel_forge_test，不连接开发库。"""

import argparse
from datetime import datetime, timezone
import json
import os
from pathlib import Path
import secrets
import shutil
import stat
import subprocess
import tempfile
import time

ROOT = Path(__file__).resolve().parents[2]
SCRIPTS = Path(__file__).resolve().parent


def main(schema=None):
    # 私有临时目录和独立 socket 防止误停共享实例；凭据只留在内存环境中。
    os.umask(0o077)
    base = ROOT / '.local'
    if base.is_symlink():
        raise RuntimeError('拒绝使用软链接私有目录')
    base.mkdir(exist_ok=True)
    workspace = Path(tempfile.mkdtemp(prefix='a02-', dir=base))
    data, sock = workspace / 'pg', workspace / 'socket'
    sock.mkdir(mode=0o700)
    env = {key: value for key, value in os.environ.items() if not key.startswith('PG')}
    env.update(PGHOST=str(sock), PGPORT='55432', PGDATABASE='novel_forge_test', PGUSER='nf_a02_admin')
    checks = []
    started = False

    def run(arguments, *, input=None, user=None, password=None, database=None, expect=True):
        local = dict(env)
        if user:
            local['PGUSER'] = user
        if password:
            local['PGPASSWORD'] = password
        if database:
            local['PGDATABASE'] = database
        result = subprocess.run(arguments, input=input, text=True, capture_output=True, env=local, timeout=90)
        if expect and result.returncode:
            # 不将数据库错误全文输出，避免将未来输入或凭据写入报告。
            raise RuntimeError('命令失败：' + Path(arguments[0]).name)
        return result

    def sql(statement, **kwargs):
        return run(['psql', '-X', '-v', 'ON_ERROR_STOP=1', '-At'], input=statement, **kwargs)

    def passed(name):
        checks.append(name)
        print('PASS ' + name, flush=True)

    try:
        run(['initdb', '-D', str(data), '-U', 'nf_a02_admin', '--auth-local=trust', '--auth-host=reject', '--encoding=UTF8', '--locale=C'])
        with (data / 'postgresql.conf').open('a') as handle:
            handle.write("\ncluster_name = 'novelforge-private'\nlisten_addresses = ''\nport = 55432\nunix_socket_directories = '" + str(sock).replace("'", "''") + "'\nunix_socket_permissions = 0700\n")
        # 与部署模板相同的按角色首匹配拒绝规则；仅测试管理员使用私有 socket trust。
        hba = (SCRIPTS / 'pg_hba.conf.example').read_text().replace('local novel_forge ', 'local novel_forge_test ')
        (data / 'pg_hba.conf').write_text(hba + '\nlocal all nf_a02_admin trust\n')
        # 启动命令超时也可能留下实例；先登记清理责任，禁止直接删运行中的数据目录。
        started = True
        run(['pg_ctl', '-D', str(data), '-l', str(workspace / 'postgres.log'), '-w', 'start'])
        sql('CREATE DATABASE novel_forge_test;', database='postgres')
        run(['psql', '-X', '-v', 'ON_ERROR_STOP=1', '-f', str(SCRIPTS / 'bootstrap-postgres.sql')])
        app_password, migration_password = secrets.token_hex(32), secrets.token_hex(32)
        sql("SET log_statement = 'none'; ALTER ROLE novel_forge_app PASSWORD '" + app_password + "'; ALTER ROLE novel_forge_migrator PASSWORD '" + migration_password + "';")
        app = {'user': 'novel_forge_app', 'password': app_password}
        migrator = {'user': 'novel_forge_migrator', 'password': migration_password}
        # 所有测试 DDL 均带中文表/字段备注；不增加业务迁移。
        sql("""
            CREATE TABLE a02_probe (id bigint GENERATED ALWAYS AS IDENTITY PRIMARY KEY, body text NOT NULL);
            COMMENT ON TABLE a02_probe IS '部署权限隔离验收临时表';
            COMMENT ON COLUMN a02_probe.id IS '测试记录主键';
            COMMENT ON COLUMN a02_probe.body IS '测试中文内容';
            ALTER TABLE a02_probe ADD COLUMN revision integer NOT NULL DEFAULT 1;
            COMMENT ON COLUMN a02_probe.revision IS '受控迁移版本';
            CREATE TABLE migrations (id integer PRIMARY KEY);
            COMMENT ON TABLE migrations IS '迁移记录权限验收临时表';
            COMMENT ON COLUMN migrations.id IS '测试迁移编号';
        """, **migrator)
        passed('迁移账户建表与增列，中文备注完整')
        run(['psql', '-X', '-f', str(SCRIPTS / 'grant-runtime.sql')], **migrator)
        result = sql("INSERT INTO a02_probe(body) VALUES ('中文权限验证'); UPDATE a02_probe SET body='备份恢复验证'; SELECT body FROM a02_probe; DELETE FROM a02_probe; INSERT INTO a02_probe(body) VALUES ('备份恢复验证');", **app)
        assert '备份恢复验证' in result.stdout
        assert sql("SELECT count(*) FROM pg_attribute WHERE attrelid='a02_probe'::regclass AND attnum>0 AND col_description(attrelid,attnum) IS NOT NULL", **app).stdout.strip() == '3'
        passed('应用账户真实 CRUD 与 identity 序列')
        for label, statement in [
            ('安装扩展', 'CREATE EXTENSION hstore;'),
            ('创建表', 'CREATE TABLE forbidden(id integer);'),
            ('修改表', 'ALTER TABLE a02_probe ADD COLUMN forbidden integer;'),
            ('清空表', 'TRUNCATE a02_probe;'),
            ('伪造迁移', 'INSERT INTO migrations VALUES (1);'),
            ('切换运维身份', 'SET ROLE novel_forge_migrator;'),
            ('创建数据库', 'CREATE DATABASE forbidden;'),
            ('创建临时表', 'CREATE TEMP TABLE forbidden(id integer);'),
        ]:
            result = sql(statement, expect=False, **app)
            assert result.returncode != 0 and ('permission denied' in result.stderr or 'must be owner' in result.stderr)
            passed('拒绝应用账户' + label)
        for credentials in [app, migrator]:
            for other in ['postgres', 'template1']:
                result = sql('SELECT current_database();', database=other, expect=False, **credentials)
                assert result.returncode != 0 and 'rejects connection' in result.stderr
        passed('应用与运维账户均不能连接其他数据库')
        assert sql("SELECT ('[1,2]'::vector <-> '[1,2]'::vector)=0 AND similarity('abc','abc')=1", **app).stdout.strip() == 't'
        passed('应用可使用运维预装的 vector 与 pg_trgm')
        # 可选导入当前测试库结构，证明实际表、索引、函数、触发器可由受限迁移账户建立。
        if schema:
            listing = run(['pg_restore', '-l', str(schema)]).stdout
            keep = [line for line in listing.splitlines() if ' EXTENSION ' not in line and ' SCHEMA - public ' not in line and ' COMMENT - SCHEMA public ' not in line]
            manifest = workspace / 'restore.list'
            manifest.write_text('\n'.join(keep) + '\n')
            sql('DROP TABLE migrations;', **migrator)
            run(['pg_restore', '--exit-on-error', '--no-owner', '--no-privileges', '--use-list', str(manifest), '--dbname', 'novel_forge_test', str(schema)], **migrator)
            run(['psql', '-X', '-f', str(SCRIPTS / 'grant-runtime.sql')], **migrator)
            assert int(sql("SELECT count(*) FROM pg_tables WHERE schemaname='public'", **app).stdout) > 50
            passed('当前业务结构由运维账户完整导入')
            # 再用真实业务表证明权限与现有外键兼容；回滚夹具，不保留测试账户。
            actual = sql("""
                BEGIN;
                INSERT INTO users(name,email,password) VALUES ('部署验收','a02@example.invalid','not-a-real-password');
                INSERT INTO novels(owner_id,title,genre,premise_raw,target_chapters,chapter_length_policy,settings)
                    SELECT id,'权限验收','测试','仅用于隔离验证',10,'{"min":100,"max":200}','{}'
                    FROM users WHERE email='a02@example.invalid';
                UPDATE novels SET title='业务表CRUD通过' WHERE title='权限验收';
                SELECT title FROM novels WHERE title='业务表CRUD通过';
                DELETE FROM novels WHERE title='业务表CRUD通过';
                DELETE FROM users WHERE email='a02@example.invalid';
                ROLLBACK;
            """, **app)
            assert '业务表CRUD通过' in actual.stdout
            passed('应用账户在真实 users/novels 表完成 CRUD')
        # 验证数据库重启后权限与测试内容保留；只重启本函数创建的实例。
        run(['pg_ctl', '-D', str(data), '-w', 'restart', '-l', str(workspace / 'postgres.log')])
        assert sql('SELECT body FROM a02_probe;', **app).stdout.strip() == '备份恢复验证'
        passed('专用 PostgreSQL 重启后数据与账户可用')
        backup_dir = base / 'backups' / 'nf-a02'
        # 不接受软链备份路径，防止意外写出授权目录。
        for candidate in [base, base / 'backups', backup_dir]:
            if candidate.is_symlink():
                raise RuntimeError('备份目录不能为软链接')
        backup_dir.mkdir(parents=True, exist_ok=True, mode=0o700)
        backup_dir.chmod(0o700)
        backup = backup_dir / ('nf-a02-test-' + datetime.now(timezone.utc).strftime('%Y%m%dT%H%M%SZ') + '-' + secrets.token_hex(3) + '.dump')
        run(['pg_dump', '-Fc', '--no-owner', '--no-privileges', '--table', 'public.a02_probe', '--file', str(backup)], **migrator)
        sql('DROP TABLE a02_probe;', **migrator)
        run(['pg_restore', '--exit-on-error', '--no-owner', '--no-privileges', '--dbname', 'novel_forge_test', str(backup)], **migrator)
        assert sql('SELECT body FROM a02_probe;', **app).stdout.strip() == '备份恢复验证'
        assert stat.S_IMODE(backup.stat().st_mode) == 0o600
        assert stat.S_IMODE(backup_dir.stat().st_mode) == 0o700
        passed('私有备份 0700/0600 与真实恢复内容核对')
        # 仅清理本工具的测试备份，不处理其他模块或正式备份。
        for old in backup_dir.glob('nf-a02-test-*.dump'):
            if not old.is_symlink() and old.is_file() and old.stat().st_mtime < time.time() - 7 * 86400:
                old.unlink()
        report = {'database': 'novel_forge_test', 'checks': checks, 'backup': backup.name, 'retention_days': 7, 'real_data': False}
        (backup_dir / 'latest-verification.json').write_text(json.dumps(report, ensure_ascii=False, indent=2) + '\n')
        print(json.dumps({'passed': len(checks), 'backup': backup.name}, ensure_ascii=False))
    finally:
        # 仅在停止成功后删除专用目录；停止失败保留现场并向调用者报错。
        if started:
            run(['pg_ctl', '-D', str(data), '-m', 'fast', '-w', 'stop'])
        shutil.rmtree(workspace)


if __name__ == '__main__':
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument('--schema', type=Path, help='从 novel_forge_test 导出的只含结构的 custom 格式文件')
    args = parser.parse_args()
    main(args.schema)
