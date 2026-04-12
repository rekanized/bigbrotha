#!/bin/sh
set -eu

APP_ROOT="${APP_ROOT:-/app}"

cd "$APP_ROOT"

exec php artisan camera-recordings:healthcheck worker --no-interaction >/dev/null