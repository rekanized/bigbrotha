#!/bin/sh
set -eu

cd "${APP_ROOT:-/app}"

WORKER_HEARTBEAT_FILE="${CAMERA_RECORDING_WORKER_HEARTBEAT_PATH:-${APP_ROOT:-/app}/storage/app/private/bootstrap/recordings-worker.heartbeat}"
worker_instance_id="${CAMERA_RECORDING_WORKER_INSTANCE_ID:-${1:-worker}}"
worker_instance_id="$(printf '%s' "$worker_instance_id" | tr -cd 'A-Za-z0-9._-')"
worker_instance_id="${worker_instance_id:-worker}"
WORKER_HEARTBEAT_FILE="$(dirname "$WORKER_HEARTBEAT_FILE")/recordings-worker-${worker_instance_id}.heartbeat"

export CAMERA_RECORDING_WORKER_INSTANCE_ID="$worker_instance_id"

mkdir -p "$(dirname "$WORKER_HEARTBEAT_FILE")"
date -u +'%Y-%m-%dT%H:%M:%SZ' > "$WORKER_HEARTBEAT_FILE"

exec php artisan queue:work \
    --queue="${CAMERA_RECORDING_WORKER_QUEUE:-recordings,default,review-assets}" \
    --max-jobs="${CAMERA_RECORDING_WORKER_MAX_JOBS:-50}" \
    --max-time="${CAMERA_RECORDING_WORKER_MAX_TIME:-3600}" \
    --memory="${CAMERA_RECORDING_WORKER_MEMORY:-256}" \
    --verbose \
    --no-interaction
