#!/bin/sh
set -eu

ROOT_DIR="$(CDPATH= cd -- "$(dirname "$0")/.." && pwd)"
ENV_FILE="${BIGBROTHA_DOCKER_ENV_FILE:-$ROOT_DIR/.env.docker}"
EXAMPLE_ENV_FILE="$ROOT_DIR/.env.docker.example"
STATE_DIR="$ROOT_DIR/.docker-state"

umask 077

if [ ! -f "$ENV_FILE" ] && [ -f "$EXAMPLE_ENV_FILE" ]; then
    cp "$EXAMPLE_ENV_FILE" "$ENV_FILE"
fi

if [ ! -f "$ENV_FILE" ]; then
    echo "Docker environment file not found: $ENV_FILE" >&2
    exit 1
fi

chmod 600 "$ENV_FILE"
mkdir -p "$STATE_DIR"
chmod 700 "$STATE_DIR"

if ! grep -Eq '^DB_PASSWORD=.+$' "$ENV_FILE"; then
    if command -v openssl >/dev/null 2>&1; then
        database_password="$(openssl rand -base64 32 | tr -d '\r\n')"
    else
        database_password="$(od -An -N 32 -tx1 /dev/urandom | tr -d ' \r\n')"
    fi

    temporary_env_file="$(mktemp "${ENV_FILE}.tmp.XXXXXX")"
    trap 'rm -f "$temporary_env_file"' EXIT HUP INT TERM
    awk -v password="$database_password" '
        BEGIN { replaced = 0 }
        /^DB_PASSWORD=/ { print "DB_PASSWORD=" password; replaced = 1; next }
        { print }
        END { if (!replaced) print "DB_PASSWORD=" password }
    ' "$ENV_FILE" > "$temporary_env_file"
    chmod 600 "$temporary_env_file"
    mv "$temporary_env_file" "$ENV_FILE"
    temporary_env_file=""
    trap - EXIT HUP INT TERM
    echo "Generated DB_PASSWORD in $ENV_FILE." >&2
fi

if docker info >/dev/null 2>&1; then
    export BIGBROTHA_DOCKER_ENV_FILE="$ENV_FILE"
    exec docker compose \
        --project-directory "$ROOT_DIR" \
        -f "$ROOT_DIR/docker-compose.yml" \
        --env-file "$ENV_FILE" \
        "$@"
fi

if sudo -n docker info >/dev/null 2>&1; then
    exec sudo -n env BIGBROTHA_DOCKER_ENV_FILE="$ENV_FILE" docker compose \
        --project-directory "$ROOT_DIR" \
        -f "$ROOT_DIR/docker-compose.yml" \
        --env-file "$ENV_FILE" \
        "$@"
fi

echo "Docker requires elevated access on this host. Use sudo ./docker/compose.sh ... or configure Docker socket access for this user." >&2
exit 1
