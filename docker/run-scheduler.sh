#!/bin/sh
set -eu

cd "${APP_ROOT:-/app}"

while :; do
    if ! php artisan schedule:run --verbose --no-interaction; then
        echo "$(date -u +'%Y-%m-%dT%H:%M:%SZ') schedule:run failed; continuing so recorder and other scheduled work can retry on the next loop." >&2
    fi

    sleep "${SCHEDULER_INTERVAL_SECONDS:-60}"
done