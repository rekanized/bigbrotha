#!/bin/sh
set -eu

ROOT_DIR="$(CDPATH= cd -- "$(dirname "$0")/.." && pwd)"
ENV_FILE="${BIGBROTHA_DOCKER_ENV_FILE:-$ROOT_DIR/.env.docker}"
EXAMPLE_ENV_FILE="$ROOT_DIR/.env.docker.example"
STATE_DIR="$ROOT_DIR/.docker-state"

umask 077

mkdir -p "$STATE_DIR"
chmod 700 "$STATE_DIR"

if [ ! -f "$ENV_FILE" ] && [ -f "$EXAMPLE_ENV_FILE" ]; then
    cp "$EXAMPLE_ENV_FILE" "$ENV_FILE"
fi

chmod 600 "$ENV_FILE"

exec "$ROOT_DIR/docker/compose.sh" \
    -f "$ROOT_DIR/docker-compose.build.yml" \
    up -d --build --remove-orphans "$@"
