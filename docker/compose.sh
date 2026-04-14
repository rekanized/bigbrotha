#!/bin/sh
set -eu

ROOT_DIR="$(CDPATH= cd -- "$(dirname "$0")/.." && pwd)"
ENV_FILE="${BIGBROTHA_DOCKER_ENV_FILE:-$ROOT_DIR/.env.docker}"
EXAMPLE_ENV_FILE="$ROOT_DIR/.env.docker.example"

if [ ! -f "$ENV_FILE" ] && [ -f "$EXAMPLE_ENV_FILE" ]; then
    cp "$EXAMPLE_ENV_FILE" "$ENV_FILE"
fi

if [ -f "$ENV_FILE" ]; then
    set -a
    # shellcheck disable=SC1090
    . "$ENV_FILE"
    set +a
fi

project_name="${COMPOSE_PROJECT_NAME:-bigbrotha}"

docker_prefix=""

if ! docker info >/dev/null 2>&1; then
    if sudo -n docker info >/dev/null 2>&1; then
        docker_prefix="sudo -n"
    else
        echo "Docker requires elevated access on this host. Use sudo ./docker/compose.sh ... or configure Docker socket access for this user." >&2
        exit 1
    fi
fi

exec ${docker_prefix} docker compose \
    --project-name "$project_name" \
    --project-directory "$ROOT_DIR" \
    -f "$ROOT_DIR/docker-compose.yml" \
    --env-file "$ENV_FILE" \
    "$@"