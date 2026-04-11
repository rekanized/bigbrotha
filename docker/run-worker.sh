#!/bin/sh
set -eu

cd "${APP_ROOT:-/app}"

exec php artisan queue:work \
    --queue="${CAMERA_RECORDING_WORKER_QUEUE:-recordings,default,review-assets}" \
    --max-jobs="${CAMERA_RECORDING_WORKER_MAX_JOBS:-50}" \
    --max-time="${CAMERA_RECORDING_WORKER_MAX_TIME:-3600}" \
    --memory="${CAMERA_RECORDING_WORKER_MEMORY:-256}" \
    --verbose \
    --no-interaction