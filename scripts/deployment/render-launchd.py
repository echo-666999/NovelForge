#!/usr/bin/env python3
"""生成 Mac 当前登录用户的运行模板；只写文件，不注册或重启服务。"""

import argparse
import os
from pathlib import Path
import plistlib
import shutil


def render(root, php, destination):
    # 不把环境口令写入 plist；PHP 从目标项目的私有环境文件读取配置。
    if not root.is_absolute() or not (root / 'artisan').is_file():
        raise ValueError('需要有效项目的绝对路径')
    if not php.is_absolute() or not os.access(php, os.X_OK):
        raise ValueError('需要可执行 PHP 的绝对路径')
    if destination.exists():
        raise ValueError('拒绝覆盖已有目录，请使用新的输出目录')
    destination.mkdir(parents=True, mode=0o700)
    jobs = {
        'worker-production': ['queue:work', 'redis', '--queue=planning,generation,review', '--sleep=1', '--tries=1', '--timeout=60'],
        'worker-derived': ['queue:work', 'redis', '--queue=memory,export,maintenance', '--sleep=1', '--tries=1', '--timeout=60'],
        'scheduler': ['schedule:work'],
    }
    for name, arguments in jobs.items():
        label = 'local.novelforge.' + name
        # 只向项目私有目录写运行日志；75秒退出窗口长于60秒任务超时。
        config = {
            'Label': label, 'ProgramArguments': [str(php), str(root / 'artisan'), *arguments],
            'WorkingDirectory': str(root), 'RunAtLoad': True, 'KeepAlive': True,
            'ThrottleInterval': 10, 'ExitTimeOut': 75, 'Umask': 0o077,
            # launchd 不继承终端 PATH；显式保留本机 PHP/Redis 工具所在目录。
            'EnvironmentVariables': {'LC_ALL': 'C', 'PATH': ':'.join(dict.fromkeys([
                str(php.parent), str(Path(shutil.which('redis-cli') or '/usr/bin/redis-cli').parent),
                '/usr/bin', '/bin', '/usr/sbin', '/sbin',
            ]))},
            'StandardOutPath': str(root / '.local' / 'services' / (name + '.out.log')),
            'StandardErrorPath': str(root / '.local' / 'services' / (name + '.err.log')),
        }
        with (destination / (label + '.plist')).open('xb') as handle:
            plistlib.dump(config, handle)
        (destination / (label + '.plist')).chmod(0o600)


if __name__ == '__main__':
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument('--root', type=Path, default=Path(__file__).resolve().parents[2])
    parser.add_argument('--php', type=Path, default=Path(shutil.which('php') or '/missing-php'))
    parser.add_argument('--output', type=Path, required=True)
    args = parser.parse_args()
    render(args.root, args.php, args.output)
    print('已生成3份 launchd 模板；尚未注册运行。')
