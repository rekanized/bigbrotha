#!/bin/sh
set -eu

cd "${APP_ROOT:-/app}"

while :; do
    php artisan schedule:run --verbose --no-interaction
    sleep "${SCHEDULER_INTERVAL_SECONDS:-60}"
done