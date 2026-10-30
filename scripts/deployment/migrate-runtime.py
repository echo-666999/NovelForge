#!/usr/bin/env python3
"""只在项目专用实例临时使用迁移身份；不修改Web环境或缓存迁移凭据。"""

import json
import os
import shutil

from runtime_common import ROOT, RUNTIME, assert_private, command, pg_env


def main():
    assert_private()
    if (ROOT / 'bootstrap/cache/config.php').exists():
        raise RuntimeError('迁移拒绝配置缓存，避免缓存连接覆盖临时运维身份')
    credentials = json.loads((RUNTIME / 'credentials.json').read_text())
    # 仅对子进程注入；进程退出后Web与Worker仍读取应用账户。
    env = dict(os.environ, DB_CONNECTION='pgsql', DB_URL='', DB_HOST=str(RUNTIME / 'socket'),
               DB_PORT='55432', DB_DATABASE='novel_forge', DB_USERNAME='novel_forge_migrator',
               DB_PASSWORD=credentials['migrator'])
    command([shutil.which('php'), 'artisan', 'migrate', '--force', '--no-interaction'], env=env)
    command(['psql', '-X', '-v', 'ON_ERROR_STOP=1', '-f', ROOT / 'scripts/deployment/grant-runtime.sql'], env=pg_env('migrator'))
    print('受控迁移已完成；运行账户授权已复核，Web凭据未改变。')


if __name__ == '__main__':
    main()
