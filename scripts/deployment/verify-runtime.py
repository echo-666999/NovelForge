#!/usr/bin/env python3
"""对已启用的本项目运行服务做实际验收；恢复演练仅使用专用实例的测试库。"""

import json
import os
from pathlib import Path
import re
import secrets
import shutil

from runtime_common import ROOT, RUNTIME, BACKUPS, command, pg_env, sql, wait_for, assert_private
from verify_backup import verify_bundle

DOMAIN = 'gui/' + str(os.getuid())
PHP = shutil.which('php')
BRIDGE = ROOT / 'scripts/deployment/runtime-bridge.php'
APPLICATION = ['worker-production', 'worker-derived', 'scheduler']


def pid(name):
    result = command(['launchctl', 'print', DOMAIN + '/local.novelforge.' + name], check=False)
    match = re.search(r'\bpid = (\d+)', result.stdout)
    return int(match.group(1)) if match else None


def main():
    os.umask(0o077)
    assert_private()
    evidence = {}
    # 只读检查实际应用权限；拒绝能力的写入测试已在隔离测试库验收，不对开发库试建表。
    permissions = sql("""SELECT NOT rolsuper AND NOT rolcreatedb AND NOT rolcreaterole AND NOT rolreplication AND NOT rolbypassrls
        AND NOT has_database_privilege(current_user,current_database(),'CREATE')
        AND NOT has_database_privilege(current_user,current_database(),'TEMP')
        AND NOT has_schema_privilege(current_user,'public','CREATE')
        AND NOT has_table_privilege(current_user,'public.migrations','INSERT')
        AND NOT has_table_privilege(current_user,'public.novels','TRUNCATE')
        AND has_table_privilege(current_user,'public.novels','SELECT')
        AND has_table_privilege(current_user,'public.novels','INSERT')
        AND has_table_privilege(current_user,'public.novels','UPDATE')
        AND has_table_privilege(current_user,'public.novels','DELETE')
        FROM pg_roles WHERE rolname=current_user;""", pg_env('app')).stdout.strip()
    if permissions != 't':
        raise RuntimeError('实际应用权限不符合部署要求')
    for role in ['app', 'migrator']:
        for database in ['postgres', 'template1']:
            result = sql('SELECT 1;', pg_env(role, database), check=False)
            if result.returncode == 0 or 'rejects connection' not in result.stderr:
                raise RuntimeError('跨库拒绝检查不通过')
    evidence['least_privilege_and_cross_database_rejection'] = True
    before = (RUNTIME / 'environment.before').read_text()
    current = (ROOT / '.env').read_text()
    if re.search(r'^APP_KEY=.*$', before, re.M).group() != re.search(r'^APP_KEY=.*$', current, re.M).group():
        raise RuntimeError('APP_KEY发生变化')
    evidence['app_key_preserved'] = True
    print('PASS 实际运行账户、跨库拒绝与APP_KEY保留', flush=True)

    # 短维护窗口内先停止本项目消费者，避免在依赖重启时制造业务任务失败。
    command([PHP, 'artisan', 'down', '--retry=60'])
    stopped = []
    try:
        for name in APPLICATION:
            command(['launchctl', 'bootout', DOMAIN + '/local.novelforge.' + name])
            stopped.append(name)
        for name, signal in [('postgres', 'SIGINT'), ('redis', 'SIGTERM')]:
            old = pid(name)
            command(['launchctl', 'kill', signal, DOMAIN + '/local.novelforge.' + name])
            wait_for(lambda: pid(name) is not None and pid(name) != old)
            if name == 'postgres':
                wait_for(lambda: sql('SELECT 1;', pg_env('app'), check=False).returncode == 0)
            else:
                wait_for(lambda: command([PHP, BRIDGE, 'health'], check=False).returncode == 0)
        evidence['dedicated_postgres_and_redis_restarted'] = True
    finally:
        for name in stopped:
            command(['launchctl', 'bootstrap', DOMAIN, Path.home() / ('Library/LaunchAgents/local.novelforge.' + name + '.plist')])
        command([PHP, 'artisan', 'up'])
    for name in APPLICATION:
        wait_for(lambda: pid(name) is not None)
    print('PASS 专用PostgreSQL/Redis重启并恢复应用服务', flush=True)
    token = 'a02-runtime-' + secrets.token_hex(12)
    try:
        command([PHP, BRIDGE, 'probe-send', token])
        wait_for(lambda: command([PHP, BRIDGE, 'probe-check', token], check=False).returncode == 0)
        old = {name: pid(name) for name in APPLICATION[:2]}
        command([PHP, 'artisan', 'queue:restart'])
        for name in old:
            wait_for(lambda: pid(name) is not None and pid(name) != old[name])
        command([PHP, BRIDGE, 'probe-clear', token])
        command([PHP, BRIDGE, 'probe-send', token])
        wait_for(lambda: command([PHP, BRIDGE, 'probe-check', token], check=False).returncode == 0)
        evidence['worker_restart_and_consumption'] = True
    finally:
        command([PHP, BRIDGE, 'probe-clear', token])
    scheduled = command([PHP, 'artisan', 'schedule:run', '--no-ansi'])
    if scheduled.stdout.count('DONE') != 3 or 'FAIL' in scheduled.stdout:
        raise RuntimeError('三个分钟调度命令未全部完成')
    evidence['scheduled_commands_done'] = 3
    print('PASS 长期Worker消费、自动拉起与三项调度', flush=True)

    # 精确验证本次成功发布的包，不按目录排序猜测，也不再读取运行库/实时文件做比较。
    completed = command([shutil.which('python3'), ROOT / 'scripts/deployment/backup-runtime.py'])
    receipt = json.loads(completed.stdout)
    if 'backup' not in receipt:
        raise RuntimeError('本次备份未发布，请等待现有备份任务结束后重新验证')
    evidence.update(verify_bundle(BACKUPS / receipt['backup']))
    evidence['service_pids'] = {name: pid(name) for name in ['postgres', 'redis', *APPLICATION]}
    (RUNTIME / 'verification.json').write_text(json.dumps(evidence, ensure_ascii=False, indent=2) + '\n')
    print('PASS 实际运行备份：全表/序列与私有产物恢复一致，测试库已删除', flush=True)


if __name__ == '__main__':
    main()
