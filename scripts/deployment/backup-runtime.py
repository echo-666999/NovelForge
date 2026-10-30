#!/usr/bin/env python3
"""备份项目专用开发库及私有产物；只清理超过7天且完整登记的本工具备份。"""

from datetime import datetime, timezone
import fcntl
import hashlib
import json
import os
from pathlib import Path
import re
import shutil
import subprocess
import sys
import tarfile
import tempfile
import time

from runtime_common import ROOT, RUNTIME, BACKUPS, command, pg_env
from backup_snapshot import dump_with_reference, archive_files, file_digest


class UnsafeTarget(RuntimeError):
    """明确错误的数据库目标不能按暂时不可用处理。"""


def wait_database(env, seconds=60, interval=2):
    # 每次尝试最多等60秒；单次连接也有超时，不因登录时数据库尚未启动而立即失败。
    deadline = time.monotonic() + seconds
    while time.monotonic() < deadline:
        try:
            result = command(['psql', '-X', '-At', '-v', 'ON_ERROR_STOP=1'], env=env,
                             input="SELECT current_database() || ':' || current_setting('cluster_name');",
                             check=False, timeout=min(10, max(0.1, deadline - time.monotonic())))
            if result.returncode == 0:
                if result.stdout.strip() != 'novel_forge:novelforge-private':
                    raise UnsafeTarget('拒绝备份非项目专用运行库')
                return
        except subprocess.TimeoutExpired:
            pass
        time.sleep(min(interval, max(0, deadline - time.monotonic())))
    raise RuntimeError('专用数据库等待超时')


def retry_backup(operation, ready, sleep=time.sleep):
    # 总共最多3次；失败不发布半成品，也不靠KeepAlive造成无限重启。
    for attempt in range(1, 4):
        try:
            ready()
            return operation(), attempt
        except UnsafeTarget:
            raise
        except (RuntimeError, OSError, subprocess.TimeoutExpired, tarfile.TarError):
            print(json.dumps({'event': 'backup_attempt_failed', 'attempt': attempt, 'limit': 3}), file=sys.stderr, flush=True)
            if attempt == 3:
                raise RuntimeError('备份3次尝试均失败，保留上一份成功备份；请检查私有服务日志') from None
            sleep([10, 30][attempt - 1])


def prune(backups, current, now):
    # 仅识别本工具命名与登记格式；缺标记、坏标记、软链和未满7天的目录均保留。
    for old in backups.iterdir():
        if old == current or old.is_symlink() or not old.is_dir() or not re.fullmatch(r'nf-runtime-\d{8}T\d{6}Z', old.name):
            continue
        marker = old / 'manifest.json'
        if marker.is_symlink() or not marker.is_file():
            continue
        try:
            previous = json.loads(marker.read_text())
            age = now - datetime.fromisoformat(previous['created_at']).timestamp()
        except (ValueError, KeyError, TypeError):
            continue
        if previous.get('kind') in ['novelforge-runtime-v1', 'novelforge-runtime-v2'] and age > 7 * 86400:
            shutil.rmtree(old)


def create_backup(env):
    staging = Path(tempfile.mkdtemp(prefix='.nf-runtime-', dir=BACKUPS))
    try:
        reference = dump_with_reference(env, staging / 'database.dump')
        # 不打包.env、APP_KEY、迁移凭据或日志；拒绝私有产物软链越界。
        private = ROOT / 'storage/app/private'
        for path in private.rglob('*'):
            if path.is_symlink():
                raise RuntimeError('私有产物包含软链，备份中止')
        with tarfile.open(staging / 'private.tar.gz', 'w:gz') as archive:
            archive.add(private, arcname='private', recursive=True)
        record = {'kind': 'novelforge-runtime-v2', 'created_at': datetime.now(timezone.utc).isoformat(),
                  'retention_days': 7, 'database': 'novel_forge', 'sha256': {},
                  'verification': {'database': reference, 'private_files': archive_files(staging / 'private.tar.gz')},
                  'consistency': 'tables_exported_snapshot_sequences_from_dump_files_from_archive'}
        for name in ['database.dump', 'private.tar.gz']:
            record['sha256'][name] = file_digest(staging / name)
        (staging / 'manifest.json').write_text(json.dumps(record, indent=2) + '\n')
        destination = BACKUPS / ('nf-runtime-' + datetime.now(timezone.utc).strftime('%Y%m%dT%H%M%SZ'))
        # 完整备份才原子发布；失败不删除上一份成功备份。
        if destination.exists():
            raise RuntimeError('同秒备份已存在，拒绝覆盖')
        staging.rename(destination)
        return destination
    finally:
        if staging.exists():
            shutil.rmtree(staging)


def main():
    os.umask(0o077)
    for path in [ROOT / '.local', RUNTIME, BACKUPS, ROOT / 'storage/app/private']:
        if path.is_symlink() or not path.is_dir():
            raise UnsafeTarget('备份要求已存在且非软链的私有目录')
    with (RUNTIME / 'backup.lock').open('a') as lock:
        # 等待和重试也受锁保护；重复手工触发不与正在执行的备份竞争。
        try:
            fcntl.flock(lock, fcntl.LOCK_EX | fcntl.LOCK_NB)
        except BlockingIOError:
            print(json.dumps({'status': 'skipped', 'reason': 'backup_already_running'}))
            return
        env = pg_env('migrator')
        destination, attempts = retry_backup(lambda: create_backup(env), lambda: wait_database(env))
        retention_ok = True
        try:
            prune(BACKUPS, destination, time.time())
            # 首次切换快照也遵守7天；必须与登记哈希一致才删除，不碰共享源库。
            source = BACKUPS / 'nf-a02-cutover-source.dump'
            record = json.loads((RUNTIME / 'cutover.json').read_text())
            created = datetime.fromisoformat(record['created_at']).timestamp()
            if source.is_file() and not source.is_symlink() and time.time() - created > 7 * 86400:
                if hashlib.sha256(source.read_bytes()).hexdigest() == record['source_backup_sha256']:
                    source.unlink()
        except (OSError, ValueError, KeyError):
            # 成功发布后清理失败不重新备份；明确报告保留策略未完成，后续成功任务会再清理。
            retention_ok = False
            print(json.dumps({'event': 'backup_retention_failed'}), file=sys.stderr)
        print(json.dumps({'backup': destination.name, 'retention_days': 7, 'attempts': attempts,
                          'retention_ok': retention_ok}), flush=True)
        if not retention_ok:
            raise RuntimeError('备份已发布，但保留清理未完成')


if __name__ == '__main__':
    main()
