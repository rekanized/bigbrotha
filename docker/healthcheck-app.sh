#!/bin/sh
set -eu

APP_ROOT="${APP_ROOT:-/app}"
APP_BOOTSTRAP_MARKER="${APP_BOOTSTRAP_MARKER:-$APP_ROOT/storage/app/private/bootstrap/app.ready}"

[ -f "$APP_BOOTSTRAP_MARKER" ]

cd "$APP_ROOT"

php artisan camera-recordings:healthcheck app --no-interaction >/dev/null

php -r '
    $connection = @fsockopen("127.0.0.1", 9000, $errno, $error, 2);

    if (!is_resource($connection)) {
        fwrite(STDERR, sprintf("php-fpm not reachable on 127.0.0.1:9000: %s\n", $error ?: "connection failed"));
        exit(1);
    }

    fclose($connection);
'