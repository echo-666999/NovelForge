#!/bin/sh
# 只读门禁先于测试套件；私有Redis缺失时不启动迁移、夹具或任何队列。
set -eu
ROOT=$(CDPATH= cd -- "$(dirname -- "$0")/../../../.." && pwd)
cd "$ROOT"
bash docs/ui/NF-UI08/scripts/preflight.sh
exec php -d memory_limit=512M vendor/bin/phpunit -c docs/ui/NF-UI16/ORDERED_SUITE.xml "$@"
