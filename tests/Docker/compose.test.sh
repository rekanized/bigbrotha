#!/bin/sh
# Validate Compose merges without starting containers or reading deployment secrets.
set -eu

ROOT_DIR="$(CDPATH= cd -- "$(dirname "$0")/../.." && pwd)"
export BIGBROTHA_DOCKER_ENV_FILE="$ROOT_DIR/.env.docker.example"
export APP_URL=https://example.invalid DB_PASSWORD=validation-only
export COMPOSE_PROJECT_NAME=bigbrotha-compose-check
export BIGBROTHA_APP_IMAGE=example.invalid/bigbrotha:published
export BIGBROTHA_BUILD_IMAGE= BIGBROTHA_DEV_IMAGE=
export MEDIAMTX_ICE_PORT=18190

compose() {
    docker compose --project-directory "$ROOT_DIR" \
        --env-file "$BIGBROTHA_DOCKER_ENV_FILE" \
        -f "$ROOT_DIR/docker-compose.yml" "$@"
}

check_stack() {
    expected_image="$1"
    shift
    services="$(compose "$@" config --services | sort)"
    expected_services="$(printf '%s\n' app background database relay)"
    [ "$services" = "$expected_services" ] || {
        echo "Unexpected services in Compose configuration." >&2
        exit 1
    }

    for role in app background relay; do
        # Compose includes each role's dependencies in this output.
        actual_images="$(compose "$@" config --images "$role" | sort -u)"
        expected_images="$(printf '%s\n' "$expected_image" postgres:18-alpine | sort -u)"
        [ "$actual_images" = "$expected_images" ] || {
            echo "Unexpected images for $role: $actual_images" >&2
            exit 1
        }
    done
    [ "$(compose "$@" config --images database)" = postgres:18-alpine ]

    # A shell override must reach Laravel as well as the published relay ports.
    resolved="$(compose "$@" config)"
    printf '%s\n' "$resolved" | grep -q 'MEDIAMTX_ICE_PORT: "18190"'
}

check_stack "$BIGBROTHA_APP_IMAGE"
check_stack bigbrotha:local -f "$ROOT_DIR/docker-compose.build.yml"
check_stack bigbrotha-dev:local -f "$ROOT_DIR/docker-compose.dev.yml"

export BIGBROTHA_BUILD_IMAGE=bigbrotha-build-check:custom
export BIGBROTHA_DEV_IMAGE=bigbrotha-dev-check:custom
check_stack "$BIGBROTHA_BUILD_IMAGE" -f "$ROOT_DIR/docker-compose.build.yml"
check_stack "$BIGBROTHA_DEV_IMAGE" -f "$ROOT_DIR/docker-compose.dev.yml"

# Missing deployment inputs must fail instead of silently selecting defaults.
if APP_URL= compose config --quiet >/dev/null 2>&1; then
    echo "Compose accepted an empty APP_URL." >&2
    exit 1
fi
if DB_PASSWORD= compose config --quiet >/dev/null 2>&1; then
    echo "Compose accepted an empty DB_PASSWORD." >&2
    exit 1
fi

echo "Compose checks passed: four services, shared role images, isolated build tags, required deployment inputs."
