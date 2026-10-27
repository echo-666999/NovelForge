#!/bin/sh
# 验收前只读检查：缺少独立测试Redis时直接失败，不把开发Redis作为替代。
set -eu
ROOT=$(CDPATH= cd -- "$(dirname -- "$0")/../../../.." && pwd)
cd "$ROOT"
if [ ! -S "$ROOT/.local/redis-testing.sock" ]; then
    echo '未发现项目独立测试Redis；请先运行 scripts/start-testing-redis.sh。' >&2
    exit 1
fi
if [ "$(redis-cli -s "$ROOT/.local/redis-testing.sock" ping)" != 'PONG' ]; then
    echo '独立测试Redis尚未就绪。' >&2
    exit 1
fi
# 既有测试引导会核验DB_URL、真实数据库名、测试存储及队列配置，失败时禁止继续。
APP_ENV=testing php artisan list --raw >/dev/null
printf '%s\n' '测试库引导与独立Redis已就绪；此检查不启动队列、不清理数据库。'
