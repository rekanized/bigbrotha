#!/bin/sh
# Validate a fresh, plain Compose setup without reading deployment secrets.
set -eu

ROOT_DIR="$(CDPATH= cd -- "$(dirname "$0")/../.." && pwd)"
CHECK_DIR="$(mktemp -d "${TMPDIR:-/tmp}/bigbrotha-compose-check.XXXXXX")"
trap 'rm -rf "$CHECK_DIR"' EXIT HUP INT TERM

cp "$ROOT_DIR/docker-compose.yml" "$ROOT_DIR/docker-compose.build.yml" \
    "$ROOT_DIR/docker-compose.dev.yml" "$CHECK_DIR/"
sed -e 's|^APP_URL=$|APP_URL=https://example.invalid|' \
    -e 's|^DB_PASSWORD=$|DB_PASSWORD=validation-only|' \
    "$ROOT_DIR/.env.example" > "$CHECK_DIR/.env"
cat >> "$CHECK_DIR/.env" <<'ENV'
COMPOSE_PROJECT_NAME=bigbrotha-compose-check
BIGBROTHA_APP_IMAGE=example.invalid/bigbrotha:published
WEB_PORT=18082
MEDIAMTX_ICE_PORT=18190
DB_DATABASE=compose_check
DB_USERNAME=compose_check
ENV

# Keep host configuration out of this fixture, including shell overrides.
unset BIGBROTHA_DOCKER_ENV_FILE APP_URL DB_PASSWORD COMPOSE_PROJECT_NAME \
    COMPOSE_FILE COMPOSE_ENV_FILES COMPOSE_DISABLE_ENV_FILE \
    BIGBROTHA_APP_IMAGE BIGBROTHA_BUILD_IMAGE BIGBROTHA_DEV_IMAGE \
    MEDIAMTX_ICE_PORT WEB_PORT DB_DATABASE DB_USERNAME
cd "$CHECK_DIR"

compose() {
    docker compose "$@"
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

    # Plain Compose must read .env for interpolation and application runtime.
    resolved="$(compose "$@" config)"
    printf '%s\n' "$resolved" | grep -q 'APP_URL: https://example.invalid'
    printf '%s\n' "$resolved" | grep -q 'DB_PASSWORD: validation-only'
    printf '%s\n' "$resolved" | grep -q 'POSTGRES_PASSWORD: validation-only'
    printf '%s\n' "$resolved" | grep -q 'POSTGRES_DB: compose_check'
    printf '%s\n' "$resolved" | grep -q 'POSTGRES_USER: compose_check'
    printf '%s\n' "$resolved" | grep -q 'MEDIAMTX_ICE_PORT: "18190"'
    printf '%s\n' "$resolved" | grep -q 'published: "18082"'
    printf '%s\n' "$resolved" | grep -q 'published: "18190"'
    printf '%s\n' "$resolved" | grep -q 'name: bigbrotha-compose-check_app-storage'
    printf '%s\n' "$resolved" | grep -q 'name: bigbrotha-compose-check_db-data'
    printf '%s\n' "$resolved" | grep -q 'target: /app/bootstrap-persist'
    printf '%s\n' "$resolved" | grep -Fq "source: $CHECK_DIR/.docker-state"
    printf '%s\n' "$resolved" | grep -q 'target: /var/lib/postgresql$'
}

check_stack example.invalid/bigbrotha:published
check_stack bigbrotha:local -f docker-compose.yml -f docker-compose.build.yml
check_stack bigbrotha-dev:local -f docker-compose.yml -f docker-compose.dev.yml

BIGBROTHA_BUILD_IMAGE=bigbrotha-build-check:custom \
    check_stack bigbrotha-build-check:custom -f docker-compose.yml -f docker-compose.build.yml
BIGBROTHA_DEV_IMAGE=bigbrotha-dev-check:custom \
    check_stack bigbrotha-dev-check:custom -f docker-compose.yml -f docker-compose.dev.yml

# A shell override must reach Laravel as well as the published relay ports.
resolved="$(MEDIAMTX_ICE_PORT=28190 compose config)"
printf '%s\n' "$resolved" | grep -q 'MEDIAMTX_ICE_PORT: "28190"'
printf '%s\n' "$resolved" | grep -q 'published: "28190"'

# Renaming legacy configuration must resolve to the identical stack, including
# credentials, project/volume names, key mount, and runtime settings.
cp .env .env.docker
# Ignore Compose's trailing x-* source extensions: their env_file path changes,
# while the resolved service environment and persistent mounts must not.
legacy_config="$(BIGBROTHA_DOCKER_ENV_FILE=.env.docker compose --env-file .env.docker config | sed '/^x-/,$d')"
current_config="$(compose config | sed '/^x-/,$d')"
[ "$legacy_config" = "$current_config" ] || {
    echo "Migrating .env.docker to .env changed the resolved deployment." >&2
    printf '%s\n' "$legacy_config" > legacy-config.yml
    printf '%s\n' "$current_config" > current-config.yml
    diff -u legacy-config.yml current-config.yml >&2 || true
    exit 1
}

# Existing deployments can link .env to their untouched private configuration.
mv .env .env.original
ln -s .env.docker .env
[ "$current_config" = "$(compose config | sed '/^x-/,$d')" ] || {
    echo "Linking the existing configuration changed the resolved deployment." >&2
    exit 1
}

# Custom configuration must supply both interpolation and service environment.
sed -e 's|https://example.invalid|https://custom.example.invalid|' \
    -e 's|validation-only|custom-validation-only|' .env > .env.custom
resolved="$(BIGBROTHA_DOCKER_ENV_FILE=.env.custom compose --env-file .env.custom config)"
printf '%s\n' "$resolved" | grep -q 'APP_URL: https://custom.example.invalid'
printf '%s\n' "$resolved" | grep -q 'DB_PASSWORD: custom-validation-only'
printf '%s\n' "$resolved" | grep -q 'POSTGRES_PASSWORD: custom-validation-only'

# Missing deployment inputs must fail instead of silently selecting defaults.
if APP_URL= compose config --quiet >/dev/null 2>&1; then
    echo "Compose accepted an empty APP_URL." >&2
    exit 1
fi
if DB_PASSWORD= compose config --quiet >/dev/null 2>&1; then
    echo "Compose accepted an empty DB_PASSWORD." >&2
    exit 1
fi

echo "Compose checks passed: plain .env setup, four services, image overrides, required inputs, and preserved existing deployment configuration."
