#!/usr/bin/env python3
"""使用一次性专用PostgreSQL验证真实并发写入下的备份一致性，不连接开发或共享库。"""

import json
import os
from pathlib import Path
import shutil
import tempfile
from unittest.mock import patch

import backup_snapshot
from runtime_common import ROOT, command, sql, manifest


def main():
    os.umask(0o077)
    if (ROOT / '.local').is_symlink():
        raise RuntimeError('拒绝软链私有目录')
    folder = Path(tempfile.mkdtemp(prefix='a02-snapshot-', dir=ROOT / '.local'))
    data, socket = folder / 'pg', folder / 'socket'
    socket.mkdir(mode=0o700)
    env = {key: value for key, value in os.environ.items() if not key.startswith('PG')}
    env.update(PGHOST=str(socket), PGPORT='55432', PGDATABASE='novel_forge_test',
               PGUSER='nf_a02_test', PGTZ='UTC', LC_ALL='C', PGCONNECT_TIMEOUT='5')
    started = False
    checks = []
    try:
        command(['initdb', '-D', data, '-U', 'nf_a02_test', '--auth-local=trust', '--auth-host=reject', '--encoding=UTF8', '--locale=C'], env=env)
        with (data / 'postgresql.conf').open('a') as handle:
            handle.write("\ncluster_name='novelforge-private'\nlisten_addresses=''\nport=55432\nunix_socket_permissions=0700\nunix_socket_directories='" + str(socket).replace("'", "''") + "'\n")
        started = True
        command(['pg_ctl', '-D', data, '-l', folder / 'postgres.log', '-w', 'start'], env=env)
        sql('CREATE DATABASE novel_forge_test;', dict(env, PGDATABASE='postgres'))
        # 测试DDL的表及全部新字段均带中文备注；生命周期仅存在于一次性测试库。
        sql("""CREATE TABLE a02_snapshot_probe(id bigint GENERATED ALWAYS AS IDENTITY PRIMARY KEY, body text NOT NULL);
            COMMENT ON TABLE a02_snapshot_probe IS '备份并发一致性隔离测试表';
            COMMENT ON COLUMN a02_snapshot_probe.id IS '测试记录编号';
            COMMENT ON COLUMN a02_snapshot_probe.body IS '测试正文内容';
            INSERT INTO a02_snapshot_probe(body) VALUES ('快照前内容');""", env)
        original = manifest(env)
        archive = folder / 'database.dump'
        raw_command = backup_snapshot.command
        def concurrent_write(arguments, **kwargs):
            if arguments[0] == 'pg_dump':
                # 清单与导出之间真实提交另一事务，复现旧实时比较会误报的竞争窗口。
                sql("UPDATE a02_snapshot_probe SET body='快照之后修改'; INSERT INTO a02_snapshot_probe(body) VALUES ('快照之后新增');", env)
            return raw_command(arguments, **kwargs)
        with patch.object(backup_snapshot, 'command', side_effect=concurrent_write):
            frozen = backup_snapshot.dump_with_reference(env, archive)
        assert frozen['tables'] == original['tables']
        assert frozen['tables'] != manifest(env)['tables']
        checks.append('导出与冻结表清单共用快照，真实并发提交不改变备份依据')
        assert frozen['sequences'][0]['last_value'] == 2
        sql("SELECT nextval('a02_snapshot_probe_id_seq');", env)
        assert frozen['sequences'] != manifest(env)['sequences']
        checks.append('序列以归档setval为准，备份后的序列推进不影响校验')
        # 只清理本次新建的测试表再恢复同一测试库；绝不连接共享实例。
        command(['pg_restore', '--exit-on-error', '--clean', '--if-exists', '--no-owner', '--no-privileges',
                 '--dbname=novel_forge_test', archive], env=env)
        assert manifest(env) == frozen
        checks.append('真实恢复与冻结清单逐表逐序列一致')
        assert sql("SELECT count(*) FROM pg_attribute WHERE attrelid='a02_snapshot_probe'::regclass AND attnum>0 AND col_description(attrelid,attnum) IS NOT NULL;", env).stdout.strip() == '2'
        checks.append('测试表与字段中文备注随恢复保留')
        sql("UPDATE a02_snapshot_probe SET body='故意损坏恢复内容';", env)
        assert manifest(env) != frozen
        checks.append('损坏恢复内容会被冻结摘要识别')
        assert sql("SELECT count(*) FROM pg_stat_activity WHERE datname=current_database() AND pid<>pg_backend_pid();", env).stdout.strip() == '0'
        checks.append('导出快照连接已关闭，无遗留事务')
        print(json.dumps({'passed': len(checks), 'checks': checks}, ensure_ascii=False))
    finally:
        if started:
            command(['pg_ctl', '-D', data, '-m', 'fast', '-w', 'stop'], env=env)
        shutil.rmtree(folder)


if __name__ == '__main__':
    main()
