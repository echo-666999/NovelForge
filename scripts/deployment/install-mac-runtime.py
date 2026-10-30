#!/usr/bin/env python3
"""分阶段建立本项目专用实例、切换开发连接并安装当前用户长期服务。"""

import argparse
from datetime import datetime, timezone
import getpass
import hashlib
import importlib.util
import json
import os
from pathlib import Path
import plistlib
import re
import secrets
import shutil

from runtime_common import ROOT, RUNTIME, BACKUPS, command, pg_env, sql, manifest, restore, wait_for, assert_private

DOMAIN = 'gui/' + str(os.getuid())
AGENTS = Path.home() / 'Library/LaunchAgents'
PHP = Path(shutil.which('php')).resolve()
SCRIPT = Path(__file__).resolve().parent


def service(name, arguments, *, keep=True, calendar=None):
    # 登录时由系统读取用户 LaunchAgents；不修改任何其他标签或系统级配置。
    label = 'local.novelforge.' + name
    destination = AGENTS / (label + '.plist')
    if destination.exists() or destination.is_symlink():
        raise RuntimeError('已有服务文件，拒绝覆盖：' + label)
    config = {'Label': label, 'ProgramArguments': [str(x) for x in arguments],
              'WorkingDirectory': str(ROOT), 'RunAtLoad': True, 'Umask': 0o077,
              'ExitTimeOut': 75, 'ThrottleInterval': 10,
              # Mac后台会话可能没有locale；显式C避免PostgreSQL启动时进入系统多线程locale初始化。
              'EnvironmentVariables': {'LC_ALL': 'C', 'PATH': str(PHP.parent) + ':/opt/homebrew/bin:/usr/bin:/bin:/usr/sbin:/sbin'},
              'StandardOutPath': str(ROOT / '.local/services' / (name + '.out.log')),
              'StandardErrorPath': str(ROOT / '.local/services' / (name + '.err.log'))}
    if keep:
        config['KeepAlive'] = True
    if calendar:
        config['StartCalendarInterval'] = calendar
    with destination.open('xb') as handle:
        plistlib.dump(config, handle)
    destination.chmod(0o600)
    command(['plutil', '-lint', destination])
    command(['launchctl', 'bootstrap', DOMAIN, destination])
    return label


def prepare():
    # 持久实例只允许首次初始化；失败现场留在私有目录，不能自动覆盖已有数据。
    if (RUNTIME / 'postgres').exists():
        raise RuntimeError('专用实例目录已存在，拒绝重复初始化')
    if not (RUNTIME / 'source-connection.json').is_file():
        raise RuntimeError('需要先核实并保存原开发连接')
    for name in ['socket', 'manifests']:
        (RUNTIME / name).mkdir(mode=0o700)
    credentials = {'app': secrets.token_hex(32), 'migrator': secrets.token_hex(32)}
    with (RUNTIME / 'credentials.json').open('x') as handle:
        json.dump(credentials, handle)
    command(['initdb', '-D', RUNTIME / 'postgres', '-U', getpass.getuser(), '--auth-local=peer', '--auth-host=reject', '--encoding=UTF8', '--locale=C'])
    with (RUNTIME / 'postgres/postgresql.conf').open('a') as handle:
        handle.write("\ncluster_name = 'novelforge-private'\nlisten_addresses = ''\nport = 55432\nunix_socket_permissions = 0700\nunix_socket_directories = '" + str(RUNTIME / 'socket').replace("'", "''") + "'\n")
    hba = (SCRIPT / 'pg_hba.conf.example').read_text()
    (RUNTIME / 'postgres/pg_hba.conf').write_text(hba + '\nlocal all ' + getpass.getuser() + ' peer\n')
    service('postgres', [shutil.which('postgres'), '-D', RUNTIME / 'postgres'])
    finish_prepare()


def finish_prepare():
    # 启动环境修复后可继续初始化；已有数据库/角色时仍由SQL拒绝，不覆盖数据。
    wait_for(lambda: sql('SELECT 1;', pg_env(database='postgres'), check=False).returncode == 0)
    credentials = json.loads((RUNTIME / 'credentials.json').read_text())
    sql('CREATE DATABASE novel_forge;', pg_env(database='postgres'))
    command(['psql', '-X', '-v', 'ON_ERROR_STOP=1', '-f', SCRIPT / 'bootstrap-postgres.sql'], env=pg_env())
    # 随机口令只经stdin发送，既不放argv，也不打印或写进受版本管理文件。
    sql("SET log_statement='none'; ALTER ROLE novel_forge_app PASSWORD '" + credentials['app'] + "'; ALTER ROLE novel_forge_migrator PASSWORD '" + credentials['migrator'] + "';")
    print('专用 PostgreSQL 与两类数据库账户已建立；共享数据库未改动。', flush=True)


def cutover():
    assert_private()
    if (RUNTIME / 'cutover.json').exists() or (RUNTIME / 'environment.before').exists():
        raise RuntimeError('已存在切换记录，拒绝重复切换')
    if (ROOT / 'bootstrap/cache/config.php').exists():
        raise RuntimeError('检测到配置缓存，须先按运行手册处理')
    if (ROOT / 'storage/framework/down').exists():
        raise RuntimeError('项目已处维护状态，拒绝接管未知维护窗口')
    if sql("SELECT to_regclass('public.migrations') IS NULL;").stdout.strip() != 't':
        raise RuntimeError('目标库不是全新运行库')
    source = pg_env(source=True)
    # 只允许预期扩展；有额外扩展时先审核，不自行下载或安装系统组件。
    extensions = sql("SELECT extname FROM pg_extension WHERE extname <> 'plpgsql' ORDER BY extname;", source).stdout.split()
    if extensions != ['pg_trgm', 'vector']:
        raise RuntimeError('来源存在未审核的扩展')
    command([PHP, 'artisan', 'down', '--retry=60'])
    changed = False
    try:
        # 等待源库已有写请求结束；维护期间不允许有遗留消费者继续写来源。
        active = sql("SELECT count(*) FROM pg_stat_activity WHERE datname=current_database() AND pid<>pg_backend_pid() AND backend_type='client backend' AND state<>'idle';", source).stdout.strip()
        if active != '0':
            raise RuntimeError('源库仍有活动连接，拒绝在不确定状态下复制')
        shutil.copy2(ROOT / '.env', RUNTIME / 'environment.before')
        (RUNTIME / 'environment.before').chmod(0o600)
        before = manifest(source)
        archive = BACKUPS / 'nf-a02-cutover-source.dump'
        if archive.exists():
            raise RuntimeError('已有切换备份，拒绝覆盖')
        command(['pg_dump', '-Fc', '--no-owner', '--no-privileges', '--file', archive], env=source)
        restore(archive)
        # 恢复完成后逐表逐序列比较，源库再次取摘要检测复制窗口内意外写入。
        restored = manifest(pg_env('migrator'))
        if before != restored or before != manifest(source):
            raise RuntimeError('来源与恢复副本不一致，禁止切换')
        command(['psql', '-X', '-v', 'ON_ERROR_STOP=1', '-f', SCRIPT / 'grant-runtime.sql'], env=pg_env('migrator'))
        (RUNTIME / 'manifests/cutover.json').write_text(json.dumps(before, ensure_ascii=False, indent=2) + '\n')
        credentials = json.loads((RUNTIME / 'credentials.json').read_text())
        changes = {'DB_CONNECTION': 'pgsql', 'DB_URL': '', 'DB_HOST': str(RUNTIME / 'socket'),
                   'DB_PORT': '55432', 'DB_DATABASE': 'novel_forge', 'DB_USERNAME': 'novel_forge_app',
                   'DB_PASSWORD': credentials['app']}
        content = (ROOT / '.env').read_text()
        for key, value in changes.items():
            line = key + '=' + json.dumps(value)
            if re.search(r'^' + key + '=', content, re.M):
                content = re.sub(r'^' + key + '=.*$', lambda _: line, content, flags=re.M)
            else:
                content += '\n' + line + '\n'
        temporary = RUNTIME / 'environment.next'
        temporary.write_text(content)
        temporary.chmod(0o600)
        temporary.replace(ROOT / '.env')
        changed = True
        command([PHP, SCRIPT / 'runtime-bridge.php', 'health'])
        record = {'database': 'novel_forge', 'tables': len(before['tables']), 'sequences': len(before['sequences']),
                  'rows': sum(item['rows'] for item in before['tables']), 'all_data_verified': True, 'source_untouched': True,
                  'created_at': datetime.now(timezone.utc).isoformat(), 'source_backup_sha256': hashlib.sha256(archive.read_bytes()).hexdigest()}
        (RUNTIME / 'cutover.json').write_text(json.dumps(record, indent=2) + '\n')
        print(json.dumps(record), flush=True)
    except Exception:
        # 尚未启用新消费者，健康检查失败可安全恢复旧环境；保留备份供诊断。
        if changed:
            shutil.copyfile(RUNTIME / 'environment.before', ROOT / '.env')
            (ROOT / '.env').chmod(0o600)
        raise
    finally:
        command([PHP, 'artisan', 'up'])


def enable():
    if not (RUNTIME / 'cutover.json').exists():
        raise RuntimeError('必须先完成数据核对与开发连接切换')
    command([PHP, SCRIPT / 'runtime-bridge.php', 'health'])
    # Redis沿用既有专属目录、口令与AOF；只改变前台运行方式以交给launchd。
    redis_config = json.loads((RUNTIME / 'source-redis.json').read_text())
    redis_env = dict(os.environ, REDISCLI_AUTH=redis_config.get('password') or '')
    redis_cli = [shutil.which('redis-cli'), '-h', redis_config['host'], '-p', str(redis_config['port'])]
    actual = command(redis_cli + ['--json', 'CONFIG', 'GET', 'dir', 'pidfile', 'appendonly'], env=redis_env)
    paths = json.loads(actual.stdout)
    if paths.get('dir') != str(ROOT / '.local/redis') or paths.get('pidfile') != str(ROOT / '.local/redis.pid') or paths.get('appendonly') != 'yes':
        raise RuntimeError('Redis归属或持久化条件不符，拒绝接管')
    label_file = AGENTS / 'local.novelforge.redis.plist'
    if label_file.exists():
        raise RuntimeError('已有Redis托管文件，拒绝覆盖')
    # 先正常关闭本项目Redis以落盘，再由launchd启动同一配置，保留全部队列。
    command(redis_cli + ['SHUTDOWN'], env=redis_env)
    service('redis', [shutil.which('redis-server'), ROOT / '.local/redis.conf', '--daemonize', 'no', '--logfile', ''])
    wait_for(lambda: command(redis_cli + ['PING'], env=redis_env, check=False).stdout.strip() == 'PONG')
    spec = importlib.util.spec_from_file_location('renderer', SCRIPT / 'render-launchd.py')
    renderer = importlib.util.module_from_spec(spec)
    spec.loader.exec_module(renderer)
    templates = RUNTIME / 'application-plists'
    renderer.render(ROOT, PHP, templates)
    for template in templates.glob('*.plist'):
        destination = AGENTS / template.name
        if destination.exists():
            raise RuntimeError('已有应用服务文件，拒绝覆盖')
        with destination.open('xb') as handle:
            handle.write(template.read_bytes())
        destination.chmod(0o600)
        command(['launchctl', 'bootstrap', DOMAIN, destination])
    # 每日03:15使用本机时区；RunAtLoad补做登录后首次备份。
    service('backup', [shutil.which('python3'), SCRIPT / 'backup-runtime.py'], keep=False, calendar={'Hour': 3, 'Minute': 15})
    print('已注册6项当前用户长期服务：PostgreSQL、Redis、两个Worker、调度器及每日备份。', flush=True)


if __name__ == '__main__':
    os.umask(0o077)
    for path in [ROOT / '.local', RUNTIME, BACKUPS, AGENTS]:
        if path.is_symlink() or not path.is_dir() or path.stat().st_uid != os.getuid():
            raise RuntimeError('需要当前用户所有且非软链的专用目录')
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument('phase', choices=['prepare', 'finish-prepare', 'cutover', 'enable'])
    args = parser.parse_args()
    {'prepare': prepare, 'finish-prepare': finish_prepare, 'cutover': cutover, 'enable': enable}[args.phase]()
