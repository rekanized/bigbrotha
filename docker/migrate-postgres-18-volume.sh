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
command -v gzip >/dev/null 2>&1 || fail "gzip is required."
docker info >/dev/null 2>&1 || fail "Docker daemon access is required. Run this script with Docker access."
[ -f "$ENV_FILE" ] || fail "Environment file not found: $ENV_FILE"
[ -f "$COMPOSE_FILE" ] || fail "Compose file not found: $COMPOSE_FILE"

if ! grep -Eq '^[[:space:]]*-[[:space:]]+db-data:/var/lib/postgresql[[:space:]]*$' "$COMPOSE_FILE"; then
    fail "The deployment Compose file must mount db-data at /var/lib/postgresql before migration."
fi

mkdir -p "$STATE_DIR"
chmod 700 "$STATE_DIR"

export BIGBROTHA_DOCKER_ENV_FILE="$ENV_FILE"

compose() {
    docker compose \
        --project-directory "$DEPLOY_DIR" \
        --env-file "$ENV_FILE" \
        -f "$COMPOSE_FILE" \
        "$@"
}

database_container="$(compose ps -q database)"
[ -n "$database_container" ] || fail "The current database container is not running. Start the existing deployment before migrating."

source_volume="$(docker inspect --format '{{range .Mounts}}{{if eq .Destination "/var/lib/postgresql"}}{{.Name}}{{end}}{{end}}' "$database_container")"
target_volume="$(docker inspect --format '{{range .Mounts}}{{if eq .Destination "/var/lib/postgresql/data"}}{{.Name}}{{end}}{{end}}' "$database_container")"

if [ -z "$source_volume" ]; then
    echo "The database container is not using the affected PostgreSQL 18 anonymous parent volume; no migration is needed."
    exit 0
fi

[ -n "$target_volume" ] || fail "Unable to identify the existing db-data volume from the running database container."
[ "$source_volume" != "$target_volume" ] || fail "Source and target database volumes unexpectedly resolve to the same volume."

docker run --rm \
    -v "$source_volume:/source:ro" \
    --entrypoint sh \
    postgres:18-alpine \
    -c 'test -s /source/18/docker/PG_VERSION && test "$(cat /source/18/docker/PG_VERSION)" = "18"' \
    || fail "The anonymous source volume does not contain a PostgreSQL 18 cluster at 18/docker."

backup_base="$STATE_DIR/postgres-before-volume-migration-$(date -u +'%Y%m%dT%H%M%SZ').sql"
backup_file="$backup_base.gz"

echo "Creating logical PostgreSQL backup at $backup_file ..."
docker exec "$database_container" sh -eu -c 'exec pg_dumpall --username="$POSTGRES_USER"' > "$backup_base"
[ -s "$backup_base" ] || fail "The logical PostgreSQL backup is empty."
gzip -9 "$backup_base"
gzip -t "$backup_file"

echo "Stopping the deployment for a consistent volume copy..."
compose down --remove-orphans

echo "Copying PostgreSQL 18 data from $source_volume to $target_volume ..."
docker run --rm \
    -v "$source_volume:/source:ro" \
    -v "$target_volume:/target" \
    --entrypoint sh \
    postgres:18-alpine \
    -eu -c '
        if [ -n "$(find /target -mindepth 1 -maxdepth 1 -print -quit)" ]; then
            echo "Target volume is not empty; refusing to overwrite it." >&2
            exit 1
        fi
        cp -a /source/. /target/
        test -s /target/18/docker/PG_VERSION
    '

echo "Starting the deployment with the corrected PostgreSQL volume mount..."
compose up -d

deadline=$(( $(date +%s) + 180 ))
while :; do
    database_container="$(compose ps -q database)"
    health="$(docker inspect --format '{{if .State.Health}}{{.State.Health.Status}}{{else}}{{.State.Status}}{{end}}' "$database_container" 2>/dev/null || true)"

    if [ "$health" = "healthy" ]; then
        break
    fi

    if [ "$health" = "unhealthy" ] || [ "$(date +%s)" -ge "$deadline" ]; then
        fail "The migrated database did not become healthy. The source volume and logical backup were preserved; inspect Compose logs before retrying."
    fi

    sleep 2
done

docker exec "$database_container" sh -eu -c \
    'psql --username="$POSTGRES_USER" --dbname="$POSTGRES_DB" --tuples-only --no-align --command="select count(*) from information_schema.tables where table_schema = '\''public'\'';"' \
    | grep -Eq '^[1-9][0-9]*$' \
    || fail "PostgreSQL is healthy, but no public application tables were found after migration."

echo "PostgreSQL volume migration completed successfully."
echo "Logical backup: $backup_file"
echo "Original anonymous volume retained for rollback: $source_volume"
