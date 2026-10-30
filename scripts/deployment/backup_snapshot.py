"""冻结备份校验依据；表快照、归档序列与文件内容分别保持可追溯边界。"""

from contextlib import contextmanager
import hashlib
import json
from pathlib import Path
import re
import selectors
import subprocess
import tarfile
import tempfile

from runtime_common import command, manifest


def digest(stream):
    # 分块计算摘要，避免备份文件增大后整文件占用内存。
    value = hashlib.sha256()
    for block in iter(lambda: stream.read(1024 * 1024), b''):
        value.update(block)
    return value.hexdigest()


def file_digest(path):
    with path.open('rb') as stream:
        return digest(stream)


@contextmanager
def exported_snapshot(env):
    # 保持只读事务存活，直到pg_dump及表清单均已导入同一个快照。
    with tempfile.TemporaryFile(mode='w+') as errors:
        process = subprocess.Popen(['psql', '-X', '-qAt', '-v', 'ON_ERROR_STOP=1'],
                                   env=env, stdin=subprocess.PIPE, stdout=subprocess.PIPE,
                                   stderr=errors, text=True)
        try:
            process.stdin.write('BEGIN ISOLATION LEVEL REPEATABLE READ READ ONLY; SELECT pg_export_snapshot();\n')
            process.stdin.flush()
            with selectors.DefaultSelector() as selector:
                selector.register(process.stdout, selectors.EVENT_READ)
                if not selector.select(timeout=15):
                    raise RuntimeError('导出快照等待超时')
            snapshot = process.stdout.readline().strip()
            if not re.fullmatch(r'[0-9A-Fa-f]+-[0-9A-Fa-f]+-\d+', snapshot):
                raise RuntimeError('无法导出有效数据库快照')
            yield snapshot
        finally:
            # 所有异常路径都结束快照事务，避免遗留会话阻碍数据库清理。
            try:
                process.communicate('ROLLBACK;\n\\q\n', timeout=10)
            except (subprocess.TimeoutExpired, BrokenPipeError):
                process.kill()
                process.communicate()


def archived_sequences(archive):
    # 序列不受MVCC快照保护，必须读取pg_dump实际保存的setval，不能再读实时序列。
    listing = command(['pg_restore', '-l', archive]).stdout
    entries = [line for line in listing.splitlines() if ' SEQUENCE SET ' in line]
    with tempfile.NamedTemporaryFile(mode='w', dir=archive.parent) as handle:
        handle.write('\n'.join(entries) + '\n')
        handle.flush()
        output = command(['pg_restore', '--data-only', '--use-list', handle.name, '--file=-', archive]).stdout
    values = []
    for line in output.splitlines():
        if line.startswith('SELECT pg_catalog.setval('):
            # 当前项目仅使用public下的普通snake_case序列名；未知格式直接阻断发布。
            match = re.fullmatch(r"SELECT pg_catalog\.setval\('public\.([a-z_][a-z0-9_]*)', (-?\d+), (true|false)\);", line)
            if not match:
                raise RuntimeError('备份序列格式不受支持')
            values.append({'sequence': match[1], 'last_value': int(match[2]), 'is_called': match[3] == 'true'})
    if len(values) != len(entries) or len({item['sequence'] for item in values}) != len(values):
        raise RuntimeError('备份序列清单不完整')
    return sorted(values, key=lambda item: item['sequence'])


def dump_with_reference(env, archive):
    with exported_snapshot(env) as snapshot:
        reference = manifest(env, snapshot=snapshot, include_sequences=False)
        command(['pg_dump', '-Fc', '--no-owner', '--no-privileges', '--snapshot=' + snapshot,
                 '--lock-wait-timeout=15000', '--file', archive], env=env)
    reference['sequences'] = archived_sequences(archive)
    return reference


def archive_files(archive, destination=None):
    # 摘要取自归档字节；可选实际恢复到隔离目录，永不读取当前运行产物作为比较依据。
    files = {}
    with tarfile.open(archive, 'r:gz') as bundle:
        seen = set()
        for member in bundle:
            relative = Path(member.name)
            if (relative.is_absolute() or '..' in relative.parts or not relative.parts
                    or relative.parts[0] != 'private' or not (member.isdir() or member.isfile())
                    or member.name in seen):
                raise RuntimeError('私有备份包含不安全或重复路径')
            seen.add(member.name)
            if member.isdir():
                if destination:
                    (destination / relative).mkdir(parents=True, exist_ok=True, mode=0o700)
                continue
            key = str(relative.relative_to('private'))
            with bundle.extractfile(member) as stream:
                files[key] = digest(stream)
            if destination:
                target = destination / relative
                target.parent.mkdir(parents=True, exist_ok=True, mode=0o700)
                with bundle.extractfile(member) as source, target.open('xb') as target_file:
                    for block in iter(lambda: source.read(1024 * 1024), b''):
                        target_file.write(block)
                if file_digest(target) != files[key]:
                    raise RuntimeError('私有文件恢复内容不一致')
    return files


def load_reference(bundle):
    record = json.loads((bundle / 'manifest.json').read_text())
    if record.get('kind') != 'novelforge-runtime-v2' or 'verification' not in record:
        raise RuntimeError('旧备份没有冻结校验清单，请使用原手册恢复；禁止回退到实时库比较')
    if set(record.get('sha256', {})) != {'database.dump', 'private.tar.gz'}:
        raise RuntimeError('备份文件清单不完整')
    for name, expected in record['sha256'].items():
        if (bundle / name).is_symlink() or file_digest(bundle / name) != expected:
            raise RuntimeError('备份文件校验失败')
    return record
