#!/bin/sh
set -eu
ROOT=$(CDPATH= cd -- "$(dirname -- "$0")/.." && pwd)
exec /opt/homebrew/opt/redis/bin/redis-server "$ROOT/.local/redis.conf"
