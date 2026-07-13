#!/bin/sh
set -eu

ROOT_DIR="$(CDPATH= cd -- "$(dirname "$0")/.." && pwd)"

exec "$ROOT_DIR/docker/compose.sh" \
    -f "$ROOT_DIR/docker-compose.build.yml" \
    up -d --build --remove-orphans "$@"
