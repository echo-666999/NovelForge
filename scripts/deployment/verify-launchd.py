#!/usr/bin/env python3
"""在当前 Mac 会话临时托管测试 Worker 和调度器，验证后卸载自己的服务。"""

import importlib.util
import json
import os
from pathlib import Path
import plistlib
import re
import secrets
import shutil
import subprocess
import tempfile
import time

ROOT = Path(__file__).resolve().parents[2]


def main():
    os.umask(0o077)
    if (ROOT / '.local').is_symlink():
        raise RuntimeError('拒绝使用软链接私有目录')
    folder = Path(tempfile.mkdtemp(prefix='a02-launchd-', dir=ROOT / '.local'))
    token = 'a02-' + secrets.token_hex(12)
    domain = 'gui/' + str(os.getuid())
    loaded = []
    completed = False
    env = dict(os.environ, APP_ENV='testing')
    php = str(Path(shutil.which('php')).resolve())

    def run(arguments, check=True):
        result = subprocess.run(arguments, cwd=ROOT, env=env, capture_output=True, text=True, timeout=30)
        if check and result.returncode:
            raise RuntimeError('测试命令失败：' + Path(arguments[0]).name)
        return result

    def probe(action, check=True):
        return run([php, str(ROOT / 'scripts/deployment/launchd-probe.php'), action, token], check)

    def pid(label):
        result = run(['launchctl', 'print', domain + '/' + label], False)
        match = re.search(r'\bpid = (\d+)', result.stdout)
        return int(match.group(1)) if match else None

    def until(predicate):
        deadline = time.monotonic() + 45
        while time.monotonic() < deadline:
            value = predicate()
            if value:
                return value
            time.sleep(0.25)
        raise RuntimeError('服务验证超时')

    try:
        # 复用交付的模板生成器，只将验收实例改为 testing 环境和独立标签。
        spec = importlib.util.spec_from_file_location('renderer', ROOT / 'scripts/deployment/render-launchd.py')
        renderer = importlib.util.module_from_spec(spec)
        spec.loader.exec_module(renderer)
        renderer.render(ROOT, Path(php), folder / 'templates')
        probe('clear')
        labels = {}
        for name in ['worker-derived', 'scheduler']:
            template = folder / 'templates' / ('local.novelforge.' + name + '.plist')
            with template.open('rb') as handle:
                config = plistlib.load(handle)
            label = 'local.novelforge.' + token + '.' + name
            labels[name] = label
            config['EnvironmentVariables']['APP_ENV'] = 'testing'
            config.update(Label=label,
                          StandardOutPath=str(folder / (name + '.out.log')),
                          StandardErrorPath=str(folder / (name + '.err.log')))
            if name == 'worker-derived':
                config['ProgramArguments'] = [php, str(ROOT / 'scripts/deployment/launchd-probe.php'), 'worker', token]
            with template.open('wb') as handle:
                plistlib.dump(config, handle)
            run(['plutil', '-lint', str(template)])
            run(['launchctl', 'bootstrap', domain, str(template)])
            loaded.append(label)
            until(lambda: pid(label))
        # 使用真实 Redis 投递与缓存确认，进程存在本身不算消费成功。
        worker = labels['worker-derived']
        first = pid(worker)
        probe('enqueue')
        until(lambda: probe('ack', False).returncode == 0)
        print('PASS launchd Worker 真实投递与消费', flush=True)
        probe('restart')
        until(lambda: pid(worker) and pid(worker) != first)
        probe('enqueue')
        until(lambda: probe('ack', False).returncode == 0)
        print('PASS queue:restart 后 launchd 拉起新 PID 并继续消费', flush=True)
        scheduler = labels['scheduler']
        first_scheduler = pid(scheduler)
        run(['launchctl', 'kill', 'SIGTERM', domain + '/' + scheduler])
        until(lambda: pid(scheduler) and pid(scheduler) != first_scheduler)
        # 独立执行一次真实调度批次并检查退出码；后台实例重启另以 PID 证明。
        scheduled = run([php, str(ROOT / 'artisan'), 'schedule:run', '--no-ansi'])
        if scheduled.stdout.count('DONE') != 3 or 'FAIL' in scheduled.stdout:
            raise RuntimeError('三个已注册调度命令未全部成功完成')
        print('PASS 调度批次执行及 launchd 调度器重启', flush=True)
        report = {'checks': 3, 'environment': 'testing', 'worker_pid_changed': True, 'scheduler_pid_changed': True}
        (ROOT / '.local/backups/nf-a02/launchd-verification.json').write_text(json.dumps(report, indent=2) + '\n')
        completed = True
    finally:
        # 不操作其他标签；卸载失败保留现场，不将清理失败误报为成功。
        failures = []
        for label in reversed(loaded):
            if run(['launchctl', 'bootout', domain + '/' + label], False).returncode:
                failures.append(label)
        probe('clear', False)
        if failures:
            raise RuntimeError('测试服务卸载失败，需检查专属标签：' + ', '.join(failures))
        if completed:
            shutil.rmtree(folder)
        else:
            # 失败保留私有诊断日志，不打印潜在敏感异常正文。
            print('诊断保留于：' + str(folder), flush=True)


if __name__ == '__main__':
    main()
