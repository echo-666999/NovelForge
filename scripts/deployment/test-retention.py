"""备份保留策略的文件系统边界测试，只使用临时目录与人工标记。"""

from datetime import datetime, timezone
import importlib.util
import json
from pathlib import Path
import tempfile
import unittest

spec = importlib.util.spec_from_file_location('backup', Path(__file__).with_name('backup-runtime.py'))
backup = importlib.util.module_from_spec(spec)
spec.loader.exec_module(backup)


class RetentionTest(unittest.TestCase):
    def test_only_expired_owned_backup_is_deleted(self):
        # 模拟旧备份、近期备份、未完成/陌生目录和指向外部的软链。
        with tempfile.TemporaryDirectory() as folder:
            root = Path(folder)
            now = datetime(2026, 10, 29, tzinfo=timezone.utc).timestamp()
            for name, created, kind in [
                ('nf-runtime-20261001T000000Z', '2026-10-01T00:00:00+00:00', 'novelforge-runtime-v1'),
                ('nf-runtime-20261028T000000Z', '2026-10-28T00:00:00+00:00', 'novelforge-runtime-v1'),
                ('nf-runtime-20261002T000000Z', '2026-10-02T00:00:00+00:00', 'foreign-backup'),
                ('nf-runtime-20261005T000000Z', '2026-10-05T00:00:00+00:00', 'novelforge-runtime-v2'),
                ('unrelated', '2026-10-01T00:00:00+00:00', 'novelforge-runtime-v1'),
            ]:
                path = root / name
                path.mkdir()
                (path / 'manifest.json').write_text(json.dumps({'kind': kind, 'created_at': created}))
            incomplete = root / 'nf-runtime-20261003T000000Z'
            incomplete.mkdir()
            link = root / 'nf-runtime-20261004T000000Z'
            link.symlink_to(root / 'unrelated', target_is_directory=True)
            backup.prune(root, None, now)
            self.assertFalse((root / 'nf-runtime-20261001T000000Z').exists())
            self.assertFalse((root / 'nf-runtime-20261005T000000Z').exists())
            self.assertTrue((root / 'nf-runtime-20261028T000000Z').exists())
            self.assertTrue((root / 'nf-runtime-20261002T000000Z').exists())
            self.assertTrue((root / 'unrelated').exists())
            self.assertTrue(incomplete.exists())
            self.assertTrue(link.is_symlink())


if __name__ == '__main__':
    unittest.main()
