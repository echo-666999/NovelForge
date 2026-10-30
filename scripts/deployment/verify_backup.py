"""只按备份自身冻结清单验证恢复，不重启服务或读取实时业务数据作比较。"""

import argparse
import fcntl
import json
import os
from pathlib import Path
import tempfile

from backup_snapshot import load_reference, archive_files
from runtime_common import RUNTIME, BACKUPS, assert_private, sql, pg_env, restore, manifest


def verify_bundle(bundle):
    if bundle.is_symlink() or bundle.resolve().parent != BACKUPS.resolve():
        raise RuntimeError('只允许项目私有备份目录下的完整备份')
    record = load_reference(bundle)
    expected = record['verification']
    assert_private()
    with (RUNTIME / 'restore.lock').open('a') as lock:
        # 只允许一个恢复验收，不能覆盖其他进程正在使用的同名测试库。
        fcntl.flock(lock, fcntl.LOCK_EX | fcntl.LOCK_NB)
        if sql("SELECT count(*) FROM pg_database WHERE datname='novel_forge_test';").stdout.strip() != '0':
            raise RuntimeError('专用实例已有测试库，拒绝覆盖')
        sql('CREATE DATABASE novel_forge_test OWNER novel_forge_migrator;')
        try:
            test_env = pg_env(database='novel_forge_test')
            sql('CREATE EXTENSION vector; CREATE EXTENSION pg_trgm;', test_env)
            restore(bundle / 'database.dump', database='novel_forge_test')
            restored = manifest(test_env)
            if restored != expected['database']:
                raise RuntimeError('数据库恢复内容与备份冻结清单不一致')
        finally:
            sql('DROP DATABASE novel_forge_test;')
    with tempfile.TemporaryDirectory(prefix='restore-files-', dir=RUNTIME) as folder:
        files = archive_files(bundle / 'private.tar.gz', Path(folder))
        if files != expected['private_files']:
            raise RuntimeError('私有产物恢复内容与备份冻结清单不一致')
    result = {'backup': bundle.name, 'restore_tables': len(restored['tables']),
              'restore_sequences': len(restored['sequences']),
              'restore_rows': sum(item['rows'] for item in restored['tables']),
              'private_files_restored': len(files), 'backup_sha256': record['sha256'],
              'reference': 'frozen_backup_manifest_v2'}
    (RUNTIME / 'backup-verification.json').write_text(json.dumps(result, ensure_ascii=False, indent=2) + '\n')
    return result


if __name__ == '__main__':
    os.umask(0o077)
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument('bundle', type=Path)
    args = parser.parse_args()
    print(json.dumps(verify_bundle(args.bundle), ensure_ascii=False))
