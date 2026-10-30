"""备份等待、有限重试、失败保留和冻结文件清单的隔离边界测试。"""

import contextlib
import fcntl
import importlib.util
import io
import json
from pathlib import Path
import subprocess
import tarfile
import tempfile
import unittest
from unittest.mock import Mock, patch

from backup_snapshot import archive_files, load_reference

spec = importlib.util.spec_from_file_location('backup', Path(__file__).with_name('backup-runtime.py'))
backup = importlib.util.module_from_spec(spec)
spec.loader.exec_module(backup)


class BackupControlsTest(unittest.TestCase):
    def test_waits_until_database_ready(self):
        # 模拟数据库比登录任务晚就绪，不能因首次连接失败就放弃备份。
        failures = [subprocess.CompletedProcess([], 1, '', '')] * 2
        ready = subprocess.CompletedProcess([], 0, 'novel_forge:novelforge-private\n', '')
        with patch.object(backup, 'command', side_effect=failures + [ready]) as call, patch.object(backup.time, 'sleep'):
            backup.wait_database({})
            self.assertEqual(call.call_count, 3)

    def test_wait_has_deadline(self):
        with patch.object(backup, 'command', return_value=subprocess.CompletedProcess([], 1, '', '')) as call:
            with self.assertRaisesRegex(RuntimeError, '等待超时'):
                backup.wait_database({}, seconds=0.01, interval=0.01)
            self.assertGreaterEqual(call.call_count, 1)

    def test_wrong_database_is_not_retried(self):
        wrong = subprocess.CompletedProcess([], 0, 'other:shared\n', '')
        operation = Mock()
        with patch.object(backup, 'command', return_value=wrong) as call:
            with self.assertRaises(backup.UnsafeTarget):
                backup.retry_backup(operation, lambda: backup.wait_database({}), sleep=Mock())
            call.assert_called_once()
            operation.assert_not_called()

    def test_recovers_after_ready_timeout(self):
        ready = Mock(side_effect=[RuntimeError('not ready'), None])
        sleep = Mock()
        with contextlib.redirect_stderr(io.StringIO()):
            result = backup.retry_backup(lambda: 'completed', ready, sleep=sleep)
        self.assertEqual(result, ('completed', 2))
        sleep.assert_called_once_with(10)

    def test_transient_dump_failure_retries_then_succeeds(self):
        operation = Mock(side_effect=[OSError('disk temporarily unavailable'), 'completed'])
        with contextlib.redirect_stderr(io.StringIO()):
            self.assertEqual(backup.retry_backup(operation, Mock(), sleep=Mock()), ('completed', 2))

    def test_three_failures_are_bounded_and_redacted(self):
        operation = Mock(side_effect=RuntimeError('secret-do-not-log'))
        sleep = Mock()
        log = io.StringIO()
        with contextlib.redirect_stderr(log), self.assertRaisesRegex(RuntimeError, '3次尝试均失败'):
            backup.retry_backup(operation, Mock(), sleep=sleep)
        self.assertEqual(operation.call_count, 3)
        self.assertEqual([call.args[0] for call in sleep.call_args_list], [10, 30])
        self.assertNotIn('secret-do-not-log', log.getvalue())

    def test_failed_dump_does_not_publish_or_remove_previous_backup(self):
        with tempfile.TemporaryDirectory() as directory:
            root = Path(directory)
            previous = root / 'previous.dump'
            previous.write_bytes(b'keep')
            def fail(env, destination):
                destination.write_bytes(b'partial')
                raise RuntimeError('interrupted')
            with patch.object(backup, 'BACKUPS', root), patch.object(backup, 'dump_with_reference', side_effect=fail):
                with self.assertRaises(RuntimeError):
                    backup.create_backup({})
            self.assertEqual(list(root.iterdir()), [previous])
            self.assertEqual(previous.read_bytes(), b'keep')

    def test_file_restore_uses_archive_even_when_live_file_changes(self):
        with tempfile.TemporaryDirectory() as directory:
            root = Path(directory)
            private = root / 'private'
            private.mkdir()
            live = private / '正文.txt'
            live.write_text('备份时正文')
            archive = root / 'private.tar.gz'
            with tarfile.open(archive, 'w:gz') as bundle:
                bundle.add(private, arcname='private')
            frozen = archive_files(archive)
            live.write_text('备份之后的正常修改')
            restored = root / 'restored'
            restored.mkdir()
            self.assertEqual(archive_files(archive, restored), frozen)
            self.assertEqual((restored / 'private/正文.txt').read_text(), '备份时正文')

    def test_symlink_archive_is_rejected(self):
        with tempfile.TemporaryDirectory() as directory:
            archive = Path(directory) / 'bad.tar.gz'
            with tarfile.open(archive, 'w:gz') as bundle:
                member = tarfile.TarInfo('private/link')
                member.type = tarfile.SYMTYPE
                member.linkname = '/tmp'
                bundle.addfile(member)
            with self.assertRaises(RuntimeError):
                archive_files(archive)

    def test_old_manifest_never_falls_back_to_live_comparison(self):
        with tempfile.TemporaryDirectory() as directory:
            root = Path(directory)
            (root / 'manifest.json').write_text(json.dumps({'kind': 'novelforge-runtime-v1'}))
            with self.assertRaisesRegex(RuntimeError, '旧备份没有冻结校验清单'):
                load_reference(root)

    def test_corrupt_backup_is_rejected_before_restore(self):
        with tempfile.TemporaryDirectory() as directory:
            root = Path(directory)
            (root / 'database.dump').write_bytes(b'damaged')
            (root / 'private.tar.gz').write_bytes(b'placeholder')
            (root / 'manifest.json').write_text(json.dumps({'kind': 'novelforge-runtime-v2',
                'verification': {}, 'sha256': {'database.dump': 'wrong', 'private.tar.gz': 'wrong'}}))
            with self.assertRaisesRegex(RuntimeError, '备份文件校验失败'):
                load_reference(root)

    def test_overlapping_backup_is_skipped_before_database_access(self):
        # 使用真实文件锁验证手工触发不会和已运行的定时任务同时进入数据库。
        with tempfile.TemporaryDirectory() as directory:
            root = Path(directory)
            runtime = root / '.local/runtime'
            backups = root / '.local/backups/nf-a02'
            for path in [runtime, backups, root / 'storage/app/private']:
                path.mkdir(parents=True)
            with (runtime / 'backup.lock').open('a') as lock:
                fcntl.flock(lock, fcntl.LOCK_EX)
                with patch.object(backup, 'ROOT', root), patch.object(backup, 'RUNTIME', runtime), patch.object(backup, 'BACKUPS', backups):
                    with patch.object(backup, 'pg_env') as connection, contextlib.redirect_stdout(io.StringIO()) as output:
                        backup.main()
                        connection.assert_not_called()
                        self.assertEqual(json.loads(output.getvalue())['status'], 'skipped')


if __name__ == '__main__':
    unittest.main()
