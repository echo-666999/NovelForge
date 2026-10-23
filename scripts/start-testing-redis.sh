#!/bin/sh
set -eu
ROOT=$(CDPATH= cd -- "$(dirname -- "$0")/.." && pwd)
if [ -e "$ROOT/.local/redis-testing.sock" ]; then
    echo 'Testing Redis socket already exists; verify its owner before restarting.' >&2
    exit 1
fi
mkdir -p "$ROOT/.local/redis-testing"
chmod 700 "$ROOT/.local/redis-testing"
exec redis-server --port 0 --unixsocket "$ROOT/.local/redis-testing.sock" --unixsocketperm 700 --dir "$ROOT/.local/redis-testing" --save '' --appendonly no --daemonize no
