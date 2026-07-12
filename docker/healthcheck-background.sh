#!/bin/sh
set -eu

APP_ROOT="${APP_ROOT:-/app}"

cd "$APP_ROOT"

/usr/bin/supervisorctl -c /etc/supervisor/background.conf status scheduler | grep -q 'RUNNING'

worker_processes="${CAMERA_RECORDING_WORKER_PROCESSES:-1}"
running_workers="$(/usr/bin/supervisorctl -c /etc/supervisor/background.conf status 'worker:*' | grep -c 'RUNNING' || true)"

[ "$running_workers" -eq "$worker_processes" ]

php artisan camera-recordings:healthcheck worker --no-interaction >/dev/null
php artisan camera-recordings:healthcheck scheduler --no-interaction >/dev/null
