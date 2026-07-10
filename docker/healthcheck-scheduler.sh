#!/bin/sh
set -eu

APP_ROOT="${APP_ROOT:-/app}"

cd "$APP_ROOT"

exec php artisan camera-recordings:healthcheck scheduler --no-interaction >/dev/null