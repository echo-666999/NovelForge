#!/usr/bin/env python3
"""收紧当前项目私有目录权限，不递归修改文件内容或其他项目。"""

import os
from pathlib import Path

ROOT = Path(__file__).resolve().parents[2]


def main():
    # 先检查所有目标与父目录，拒绝软链或其他用户的目录，再开始变更。
    directories = [ROOT / item for item in [
        '.local', '.local/services', '.local/backups', '.local/backups/nf-a02',
        'storage/app/private', 'storage/logs',
    ]]
    secrets = [ROOT / name for name in ['.env', '.env.testing'] if (ROOT / name).exists()]
    for target in directories + secrets:
        for path in [target, *target.parents]:
            if path == ROOT.parent:
                break
            if path.is_symlink() or (path.exists() and path.stat().st_uid != os.getuid()):
                raise RuntimeError('拒绝软链或非当前用户所有的目标')
    for directory in directories:
        directory.mkdir(parents=True, exist_ok=True, mode=0o700)
        directory.chmod(0o700)
    for secret in secrets:
        secret.chmod(0o600)
    print('项目私有目录已设为0700，现有环境文件已设为0600。')


if __name__ == '__main__':
    main()
