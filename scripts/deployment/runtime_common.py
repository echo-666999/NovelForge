"""Mac 专用运行实例的公共操作；所有异常只输出阶段名称，避免泄露连接密钥。"""

import getpass
import json
import os
from pathlib import Path
import re
import subprocess
import time

ROOT = Path(__file__).resolve().parents[2]
RUNTIME = ROOT / '.local/runtime'
BACKUPS = ROOT / '.local/backups/nf-a02'
DATABASE = 'novel_forge'


def command(arguments, *, env=None, input=None, check=True, timeout=120):
    result = subprocess.run([str(x) for x in arguments], cwd=ROOT, env=env,
                            input=input, capture_output=True, text=True, timeout=timeout)
    if check and result.returncode:
        raise RuntimeError('操作失败：' + Path(arguments[0]).name + '；未输出可能含敏感数据的原始错误')
    return result


def pg_env(role='admin', database=DATABASE, source=False):
    # 清除继承的 PG* 连接覆盖项，所有连接必须由本项目私有描述文件明确指定。
    env = {k: v for k, v in os.environ.items() if not k.startswith('PG')}
    if source:
        config = json.loads((RUNTIME / 'source-connection.json').read_text())
        if config['driver'] != 'pgsql' or config['database'] != DATABASE:
            raise RuntimeError('来源必须是 NovelForge 开发库')
        env.update(PGHOST=str(config['host']), PGPORT=str(config['port']),
                   PGUSER=config['username'], PGPASSWORD=config.get('password') or '')
    else:
        env.update(PGHOST=str(RUNTIME / 'socket'), PGPORT='55432', PGUSER=getpass.getuser())
        if role != 'admin':
            credentials = json.loads((RUNTIME / 'credentials.json').read_text())
            env.update(PGUSER='novel_forge_' + role, PGPASSWORD=credentials[role])
    if database not in [DATABASE, 'novel_forge_test', 'postgres', 'template1']:
        raise RuntimeError('不允许的数据库目标')
    env['PGDATABASE'] = database
    env['PGCONNECT_TIMEOUT'] = '5'
    # 时间戳按同一时区序列化，避免源/目标服务器时区差异造成摘要误判。
    env['PGTZ'] = 'UTC'
    return env


def sql(statement, env=None, check=True):
    return command(['psql', '-X', '-v', 'ON_ERROR_STOP=1', '-At'],
                   env=env or pg_env(), input=statement, check=check)


def assert_private(env=None):
    result = sql("SELECT current_database() || ':' || current_setting('cluster_name');", env).stdout.strip()
    if result != DATABASE + ':novelforge-private':
        raise RuntimeError('拒绝操作非项目专用运行实例')


def manifest(env, snapshot=None, include_sequences=True):
    # 在一致只读快照中比较每张表的完整行摘要；不输出任何小说正文或用户数据。
    transaction = 'BEGIN ISOLATION LEVEL REPEATABLE READ READ ONLY;\n'
    if snapshot is not None:
        if not re.fullmatch(r'[0-9A-Fa-f-]+', snapshot):
            raise RuntimeError('非法数据库快照标识')
        transaction += "SET TRANSACTION SNAPSHOT '" + snapshot + "';\n"
    catalog = sql(transaction + """SELECT format(
        'SELECT json_build_object(''table'', %L, ''rows'', count(*), ''digest'', md5(coalesce(string_agg(md5(row_to_json(t)::text), '''' ORDER BY md5(row_to_json(t)::text)), ''''))) FROM %I.%I t;',
        tablename, schemaname, tablename) FROM pg_tables WHERE schemaname='public' ORDER BY tablename;
        COMMIT;""", env).stdout
    queries = '\n'.join(line for line in catalog.splitlines() if line.startswith('SELECT '))
    output = sql(transaction + queries + '\nCOMMIT;', env).stdout
    tables = [json.loads(line) for line in output.splitlines() if line.startswith('{')]
    if not include_sequences:
        return {'tables': tables}
    queries = sql("""SELECT format('SELECT json_build_object(''sequence'', %L, ''last_value'', last_value, ''is_called'', is_called) FROM %I.%I;',
        c.relname,n.nspname,c.relname) FROM pg_class c JOIN pg_namespace n ON n.oid=c.relnamespace
        WHERE c.relkind='S' AND n.nspname='public' ORDER BY c.relname;""", env).stdout
    sequences = [json.loads(line) for line in sql(queries, env).stdout.splitlines() if line.startswith('{')]
    return {'tables': tables, 'sequences': sequences}


def restore(archive, database=DATABASE):
    # 扩展预先由管理员安装；不恢复超级用户所有权、ACL或扩展注释。
    import tempfile
    listing = command(['pg_restore', '-l', archive]).stdout
    lines = [line for line in listing.splitlines() if ' EXTENSION ' not in line
             and ' SCHEMA - public ' not in line and ' COMMENT - SCHEMA public ' not in line]
    with tempfile.NamedTemporaryFile(mode='w', dir=RUNTIME, suffix='.list') as handle:
        handle.write('\n'.join(lines) + '\n')
        handle.flush()
        # 管理员连接仅用于 SET ROLE；实际结构和数据操作以受限迁移身份进行。
        command(['pg_restore', '--exit-on-error', '--no-owner', '--no-privileges',
                 '--role=novel_forge_migrator', '--use-list', handle.name, '--dbname', database, archive],
                env=pg_env(database=database))


def wait_for(predicate, seconds=45):
    deadline = time.monotonic() + seconds
    while time.monotonic() < deadline:
        if predicate():
            return
        time.sleep(0.3)
    raise RuntimeError('等待项目服务就绪超时')
