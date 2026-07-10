#!/bin/sh
set -eu

cd "${APP_ROOT:-/app}"

SCHEDULER_HEARTBEAT_FILE="${CAMERA_RECORDING_SCHEDULER_HEARTBEAT_PATH:-${APP_ROOT:-/app}/storage/app/private/bootstrap/recordings-scheduler.heartbeat}"

mkdir -p "$(dirname "$SCHEDULER_HEARTBEAT_FILE")"

interval_seconds="${SCHEDULER_INTERVAL_SECONDS:-60}"

case "$interval_seconds" in
    ''|*[!0-9]*)
        echo "SCHEDULER_INTERVAL_SECONDS must be a positive integer, got: $interval_seconds" >&2
        exit 1
        ;;
esac

if [ "$interval_seconds" -lt 1 ]; then
    echo "SCHEDULER_INTERVAL_SECONDS must be at least 1." >&2
    exit 1
fi

if [ "${CAMERA_RECORDING_BOOTSTRAP_TICK:-true}" = "true" ]; then
    if ! php artisan camera-recordings:tick --no-interaction; then
        echo "$(date -u +'%Y-%m-%dT%H:%M:%SZ') initial camera-recordings:tick failed; continuing so the recurring scheduler loop can retry." >&2
    fi

    date -u +'%Y-%m-%dT%H:%M:%SZ' > "$SCHEDULER_HEARTBEAT_FILE"
fi

while :; do
    if ! php artisan schedule:run --verbose --no-interaction; then
        echo "$(date -u +'%Y-%m-%dT%H:%M:%SZ') schedule:run failed; continuing so recorder and other scheduled work can retry on the next loop." >&2
    fi

    date -u +'%Y-%m-%dT%H:%M:%SZ' > "$SCHEDULER_HEARTBEAT_FILE"

    now_epoch="$(date +%s)"
    sleep_seconds=$(( interval_seconds - (now_epoch % interval_seconds) ))
    sleep "$sleep_seconds"
done
