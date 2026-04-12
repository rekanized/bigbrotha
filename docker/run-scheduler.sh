#!/bin/sh
set -eu

cd "${APP_ROOT:-/app}"

SCHEDULER_HEARTBEAT_FILE="${CAMERA_RECORDING_SCHEDULER_HEARTBEAT_PATH:-${APP_ROOT:-/app}/storage/app/private/bootstrap/recordings-scheduler.heartbeat}"

mkdir -p "$(dirname "$SCHEDULER_HEARTBEAT_FILE")"

while :; do
    if ! php artisan schedule:run --verbose --no-interaction; then
        echo "$(date -u +'%Y-%m-%dT%H:%M:%SZ') schedule:run failed; continuing so recorder and other scheduled work can retry on the next loop." >&2
    fi

    date -u +'%Y-%m-%dT%H:%M:%SZ' > "$SCHEDULER_HEARTBEAT_FILE"

    sleep "${SCHEDULER_INTERVAL_SECONDS:-60}"
done