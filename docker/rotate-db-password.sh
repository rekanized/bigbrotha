#!/bin/sh

set -eu

DEPLOY_DIR="${BIGBROTHA_DEPLOY_DIR:-$(CDPATH= cd -- "$(dirname "$0")/.." && pwd)}"
ENV_FILE="${BIGBROTHA_DOCKER_ENV_FILE:-$DEPLOY_DIR/.env.docker}"
COMPOSE_FILE="$DEPLOY_DIR/docker-compose.yml"
STATE_DIR="$DEPLOY_DIR/.docker-state"

umask 077

fail() {
    echo "$1" >&2
    exit 1
}

command -v docker >/dev/null 2>&1 || fail "Docker is required."
command -v openssl >/dev/null 2>&1 || fail "openssl is required."
docker info >/dev/null 2>&1 || fail "Docker daemon access is required. Run this script with Docker access."
[ -f "$ENV_FILE" ] || fail "Environment file not found: $ENV_FILE"
[ -f "$COMPOSE_FILE" ] || fail "Compose file not found: $COMPOSE_FILE"

export BIGBROTHA_DOCKER_ENV_FILE="$ENV_FILE"

compose() {
    docker compose \
        --project-directory "$DEPLOY_DIR" \
        --env-file "$ENV_FILE" \
        -f "$COMPOSE_FILE" \
        "$@"
}

database_container="$(compose ps -q database)"
[ -n "$database_container" ] || fail "The database container is not running."

database_user="$(docker exec "$database_container" sh -c 'printf %s "$POSTGRES_USER"')"

case "$database_user" in
    ''|[0-9]*|*[!A-Za-z0-9_]*)
        fail "The configured PostgreSQL role name is not supported by this guarded rotation script."
        ;;
esac

grep -q '^DB_PASSWORD=' "$ENV_FILE" || fail "DB_PASSWORD is missing from $ENV_FILE"
[ "$(grep -c '^DB_PASSWORD=' "$ENV_FILE")" -eq 1 ] || fail "DB_PASSWORD must occur exactly once in $ENV_FILE"

new_password="$(openssl rand -base64 36 | tr -d '\r\n')"
backup_file="$STATE_DIR/env-before-db-password-rotation-$(date -u +'%Y%m%dT%H%M%SZ')"
temporary_file="$ENV_FILE.tmp.$$"

mkdir -p "$STATE_DIR"
chmod 700 "$STATE_DIR"
cp "$ENV_FILE" "$backup_file"
chmod 600 "$backup_file"

awk -v replacement="DB_PASSWORD=$new_password" '
    /^DB_PASSWORD=/ { print replacement; next }
    { print }
' "$ENV_FILE" > "$temporary_file"
chmod 600 "$temporary_file"

echo "Rotating the PostgreSQL application role password..."
printf 'ALTER ROLE "%s" PASSWORD '\''%s'\'';\n' "$database_user" "$new_password" \
    | docker exec -i "$database_container" sh -eu -c \
        'exec psql --username="$POSTGRES_USER" --dbname="$POSTGRES_DB" --set=ON_ERROR_STOP=1'

mv "$temporary_file" "$ENV_FILE"
chmod 600 "$ENV_FILE"

echo "Recreating application services with the rotated credential..."
compose up -d --force-recreate --remove-orphans database app relay background

deadline=$(( $(date +%s) + 180 ))
while :; do
    unhealthy="$(compose ps --format json | grep -c '"Health":"unhealthy"' || true)"
    starting="$(compose ps --format json | grep -c '"Health":"starting"' || true)"

    if [ "$unhealthy" -gt 0 ]; then
        fail "At least one service became unhealthy after password rotation. The previous environment file is preserved at $backup_file."
    fi

    if [ "$starting" -eq 0 ]; then
        break
    fi

    if [ "$(date +%s)" -ge "$deadline" ]; then
        fail "Timed out waiting for services after password rotation. The previous environment file is preserved at $backup_file."
    fi

    sleep 2
done

compose exec -T app php artisan about --only=environment >/dev/null

echo "Database password rotation completed successfully."
echo "Previous environment backup: $backup_file"
