#!/bin/sh
set -eu

ROOT_DIR="$(CDPATH= cd -- "$(dirname "$0")/.." && pwd)"
ENV_FILE="${BIGBROTHA_DOCKER_ENV_FILE:-$ROOT_DIR/.env.docker}"
EXAMPLE_ENV_FILE="$ROOT_DIR/.env.docker.example"
STATE_DIR="$ROOT_DIR/.docker-state"

mkdir -p "$STATE_DIR"
chmod 755 "$STATE_DIR"

if [ ! -f "$ENV_FILE" ] && [ -f "$EXAMPLE_ENV_FILE" ]; then
    cp "$EXAMPLE_ENV_FILE" "$ENV_FILE"
fi

if [ -f "$ENV_FILE" ]; then
    set -a
    # shellcheck disable=SC1090
    . "$ENV_FILE"
    set +a
fi

worker_replicas="${CAMERA_RECORDING_WORKER_PROCESSES:-1}"

case "$worker_replicas" in
    ''|*[!0-9]*)
        echo "CAMERA_RECORDING_WORKER_PROCESSES must be a positive integer, got: $worker_replicas" >&2
        exit 1
        ;;
esac

if [ "$worker_replicas" -lt 1 ]; then
    echo "CAMERA_RECORDING_WORKER_PROCESSES must be at least 1." >&2
    exit 1
fi

exec docker compose --env-file "$ENV_FILE" up -d --build --scale worker="$worker_replicas" "$@"